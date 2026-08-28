<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Structured, read-only parser for:
 *
 *   git status --porcelain=v2 -z --branch --ahead-behind --untracked-files=all
 *
 * Deliberately separate from GitManager::changedFiles()'s path-only `git
 * status --porcelain -z` parser (Phase 5.2, see its own docblock) — that
 * parser exists to produce `git add` pathspecs and intentionally throws
 * away everything except the path. This one exists to report the actual
 * structured Git facts (status/staged/tracked per file, plus branch/
 * upstream/ahead-behind) for the Workspace Inventory scanner, and never
 * feeds a `git add`/`git commit` call.
 *
 * Porcelain v2 with `-z` NUL-terminates every record, including branch
 * header lines. A rename/copy record ("2 ...") is the one shape that
 * spans TWO NUL-terminated fields — the new path, then a NUL, then the
 * old path — because porcelain v2's line-oriented form separates them
 * with a tab, and `-z` swaps every separator (including that one) for a
 * NUL rather than leaving some of them as literal tabs. Every other
 * record type occupies exactly one NUL-terminated field. Getting this
 * wrong (treating "2 ..." as a single-field record) would silently
 * consume the following record's field AS this record's old_path.
 */
final class GitStatusPorcelainV2Parser
{
    /**
     * @return array{
     *     branch: array{oid: ?string, head: ?string, detached: bool, upstream: ?string, ahead: ?int, behind: ?int},
     *     entries: array<int, array{path: string, old_path: ?string, status: string, index_status: ?string, worktree_status: ?string, staged: bool, tracked: bool}>
     * }
     */
    public static function parse(string $rawOutput): array
    {
        $branch = [
            'oid' => null,
            'head' => null,
            'detached' => false,
            'upstream' => null,
            'ahead' => null,
            'behind' => null,
        ];
        $entries = [];

        if ($rawOutput === '') {
            return ['branch' => $branch, 'entries' => $entries];
        }

        $fields = explode("\0", $rawOutput);
        // -z terminates (not separates) every record, so the final field
        // after the last NUL is always an empty string — drop only that
        // one; every other empty field would indicate a genuinely
        // malformed record and is skipped defensively below instead.
        if ($fields !== [] && end($fields) === '') {
            array_pop($fields);
        }

        $count = count($fields);
        $i = 0;
        while ($i < $count) {
            $field = $fields[$i];

            if ($field === '') {
                $i++;
                continue;
            }

            $type = $field[0];

            if ($type === '#') {
                self::parseHeader($field, $branch);
                $i++;
                continue;
            }

            if ($type === '1') {
                $entries[] = self::parseOrdinary($field);
                $i++;
                continue;
            }

            if ($type === '2') {
                $oldPath = $fields[$i + 1] ?? null;
                $entries[] = self::parseRenameOrCopy($field, $oldPath);
                $i += 2;
                continue;
            }

            if ($type === 'u') {
                $entries[] = self::parseUnmerged($field);
                $i++;
                continue;
            }

            if ($type === '?') {
                $entries[] = self::parseUntracked($field);
                $i++;
                continue;
            }

            // '!' (ignored — never requested here, --ignored is never
            // passed) or any unrecognized record shape (e.g. a byte-cap
            // truncated trailing record): skip rather than let one
            // malformed record abort the whole scan.
            $i++;
        }

        return ['branch' => $branch, 'entries' => $entries];
    }

    /**
     * @param array{oid: ?string, head: ?string, detached: bool, upstream: ?string, ahead: ?int, behind: ?int} $branch
     */
    private static function parseHeader(string $field, array &$branch): void
    {
        if (!str_starts_with($field, '# branch.')) {
            return; // e.g. "# stash 1" or another header this scanner doesn't use
        }

        $rest = substr($field, strlen('# branch.'));
        $spacePos = strpos($rest, ' ');
        if ($spacePos === false) {
            return;
        }

        $key = substr($rest, 0, $spacePos);
        $value = substr($rest, $spacePos + 1);

        switch ($key) {
            case 'oid':
                // "(initial)" on an unborn branch (no commits yet) — a
                // valid state, not an error; head_sha is simply unknown.
                $branch['oid'] = $value === '(initial)' ? null : $value;
                break;
            case 'head':
                if ($value === '(detached)') {
                    $branch['detached'] = true;
                    $branch['head'] = null;
                } else {
                    $branch['head'] = $value;
                }
                break;
            case 'upstream':
                $branch['upstream'] = $value;
                break;
            case 'ab':
                if (preg_match('/^\+(\d+) -(\d+)$/', $value, $matches) === 1) {
                    $branch['ahead'] = (int) $matches[1];
                    $branch['behind'] = (int) $matches[2];
                }
                break;
        }
    }

    /**
     * "1 XY sub mH mI mW hH hI path" — 8 fixed space-separated fields
     * before path; explode with limit 9 keeps path (which may itself
     * contain spaces) intact as the final element.
     */
    private static function parseOrdinary(string $field): array
    {
        $parts = explode(' ', $field, 9);
        $xy = $parts[1] ?? '..';
        $path = $parts[8] ?? '';

        return self::buildEntry($path, null, self::charAt($xy, 0), self::charAt($xy, 1), true, self::statusLabel($xy));
    }

    /**
     * "2 XY sub mH mI mW hH hI Xscore path" then old_path as the
     * following NUL-terminated field (see class docblock) — 9 fixed
     * space-separated fields before path; explode with limit 10.
     */
    private static function parseRenameOrCopy(string $field, ?string $oldPath): array
    {
        $parts = explode(' ', $field, 10);
        $xy = $parts[1] ?? '..';
        $path = $parts[9] ?? '';

        return self::buildEntry($path, $oldPath, self::charAt($xy, 0), self::charAt($xy, 1), true, self::statusLabel($xy));
    }

    /**
     * "u XY sub m1 m2 m3 mW h1 h2 h3 path" — 10 fixed space-separated
     * fields before path; explode with limit 11. Every combination the
     * "u" record type can carry (DD, AU, UD, UA, DU, AA, UU) represents
     * an unresolved conflict, by definition of it being a "u" line at
     * all — unlike ordinary/rename entries, status is not derived from
     * which individual letters appear.
     */
    private static function parseUnmerged(string $field): array
    {
        $parts = explode(' ', $field, 11);
        $xy = $parts[1] ?? 'UU';
        $path = $parts[10] ?? '';

        return self::buildEntry($path, null, self::charAt($xy, 0), self::charAt($xy, 1), true, 'conflicted');
    }

    /** "? path" — no XY code at all; an untracked file has no index side. */
    private static function parseUntracked(string $field): array
    {
        return [
            'path' => substr($field, 2),
            'old_path' => null,
            'status' => 'untracked',
            'index_status' => null,
            'worktree_status' => null,
            'staged' => false,
            'tracked' => false,
        ];
    }

    private static function buildEntry(string $path, ?string $oldPath, string $indexChar, string $worktreeChar, bool $tracked, string $status): array
    {
        return [
            'path' => $path,
            'old_path' => $oldPath,
            'status' => $status,
            'index_status' => $indexChar,
            'worktree_status' => $worktreeChar,
            'staged' => $indexChar !== '.',
            'tracked' => $tracked,
        ];
    }

    /**
     * Collapses a two-sided XY code into one overall label, favoring the
     * more consequential side when index and worktree disagree (e.g.
     * staged-modified then worktree-deleted reports as "deleted", not
     * "modified"). Per-side detail is never lost — index_status/
     * worktree_status above expose the raw two characters separately for
     * any caller that needs them.
     */
    private static function statusLabel(string $xy): string
    {
        $x = self::charAt($xy, 0);
        $y = self::charAt($xy, 1);

        if ($x === 'D' || $y === 'D') {
            return 'deleted';
        }
        if ($x === 'A' || $y === 'A') {
            return 'added';
        }
        if ($x === 'R' || $y === 'R') {
            return 'renamed';
        }
        if ($x === 'C' || $y === 'C') {
            return 'copied';
        }
        if ($x === 'T' || $y === 'T') {
            return 'typechange';
        }

        return 'modified';
    }

    private static function charAt(string $s, int $i): string
    {
        return $s[$i] ?? '.';
    }
}
