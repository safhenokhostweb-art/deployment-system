<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Root-cause fix: pipeline steps and operation commands are admin-authored
 * shell STRINGS (e.g. `git fetch origin {branch} && git reset --hard
 * {commit}`) with placeholders substituted in via strtr() before being
 * handed to Executor's proc_open($command, ...) — which, because the
 * command is a string rather than an argv array, is interpreted by the
 * shell. Converting Executor itself to argv-array execution would break
 * the `&&`/`|` chaining every existing Project.pipeline step already
 * relies on — a real architecture change, not a bug fix. The actual bug
 * is narrower: the substituted VALUES (a branch name, a working
 * directory path, a configured php_binary path — none of them written by
 * the admin authoring the template) were never shell-escaped, so a branch
 * name containing a shell metacharacter (git allows an apostrophe in a
 * ref name; it does not require quoting spaces or metacharacters out) can
 * corrupt the resulting command line — literally the
 * "unexpected EOF while looking for matching '" class of error, and,
 * unescaped, an unbounded command-injection vector. escapeshellarg() on
 * each dynamic value, applied before strtr(), closes both without
 * changing how a pipeline step's own `&&`/`|`/etc. are authored.
 */
final class ShellPlaceholders
{
    /**
     * @param array<string, string> $replacements
     * @return array<string, string>
     */
    public static function escape(array $replacements): array
    {
        $escaped = [];

        foreach ($replacements as $placeholder => $value) {
            $escaped[$placeholder] = escapeshellarg($value);
        }

        return $escaped;
    }
}
