<?php

declare(strict_types=1);

namespace App\Services\Sentinel\Readiness\Transport;

/**
 * PowershellObservationParser - converts the raw, bounded stdout of an
 * allowlisted PowerShell script into typed PHP values.
 *
 * It trusts NOTHING about the source. Every field is validated by shape
 * and by range before it is accepted. Any doubt at all yields a
 * rejection, and a rejection means the caller must report UNKNOWN -
 * never PASS.
 *
 * The parser never sees caller-controlled script source: the transport
 * only runs scripts from PowershellAllowedScripts, and the parser only
 * reads the PowershellExecutionResult those scripts produce.
 *
 * Native text that does not parse is never surfaced to a caller. The
 * parser returns a rejection reason that is a stable code.
 */
final class PowershellObservationParser
{
    /**
     * Parse KEY=VALUE lines into a string map.
     *
     * Rules:
     *   - Only the first '=' is a separator; later ones are data.
     *   - Blank lines are skipped.
     *   - Lines without a key character are skipped.
     *   - A line whose value contains a NUL byte is dropped.
     *   - Duplicate keys keep the FIRST occurrence, so a script cannot
     *     override an earlier authoritative value with a later one.
     *
     * @return array<string, string>
     */
    public function parseLines(string $stdout): array
    {
        $out = [];

        $rawLines = preg_split("/\r\n|\r|\n/", $stdout);

        if ($rawLines === false) {
            return [];
        }

        foreach ($rawLines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $sep = strpos($line, '=');

            if ($sep === false || $sep === 0) {
                continue;
            }

            $key = substr($line, 0, $sep);

            $value = substr($line, $sep + 1);

            if (! preg_match('/^[A-Za-z0-9_]+$/', $key)) {
                continue;
            }

            if (str_contains($value, "\0")) {
                continue;
            }

            if (isset($out[$key])) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Read a boolean flag emitted as '0' or '1'.
     *
     * @return array{ok: bool, value: bool}
     */
    public function parseFlag(array $fields, string $key): array
    {
        if (! isset($fields[$key])) {
            return ['ok' => false, 'value' => false];
        }

        if ($fields[$key] === '1') {
            return ['ok' => true, 'value' => true];
        }

        if ($fields[$key] === '0') {
            return ['ok' => true, 'value' => false];
        }

        return ['ok' => false, 'value' => false];
    }

    /**
     * Read a non-negative integer emitted as decimal digits.
     *
     * @return array{ok: bool, value: int}
     */
    public function parseNonNegativeInt(array $fields, string $key): array
    {
        if (! isset($fields[$key])) {
            return ['ok' => false, 'value' => 0];
        }

        $raw = $fields[$key];

        if (! preg_match('/^[0-9]{1,20}$/', $raw)) {
            return ['ok' => false, 'value' => 0];
        }

        $asInt = filter_var($raw, FILTER_VALIDATE_INT);

        if ($asInt === false) {
            return ['ok' => false, 'value' => 0];
        }

        if ($asInt < 0) {
            return ['ok' => false, 'value' => 0];
        }

        return ['ok' => true, 'value' => $asInt];
    }

    /**
     * Read a bounded text field emitted as a plain string.
     *
     * @return array{ok: bool, value: string}
     */
    public function parseBoundedText(array $fields, string $key, int $maxLength): array
    {
        if (! isset($fields[$key])) {
            return ['ok' => false, 'value' => ''];
        }

        $raw = $fields[$key];

        if ($raw === '') {
            return ['ok' => false, 'value' => ''];
        }

        if (strlen($raw) > $maxLength) {
            return ['ok' => false, 'value' => ''];
        }

        return ['ok' => true, 'value' => $raw];
    }

    /**
     * Read a Windows filesystem identifier, case-normalized.
     *
     * @return array{ok: bool, value: string}
     */
    public function parseFilesystemName(array $fields, string $key): array
    {
        $text = $this->parseBoundedText($fields, $key, 32);

        if (! $text['ok']) {
            return ['ok' => false, 'value' => ''];
        }

        $upper = strtoupper($text['value']);

        $allowed = ['NTFS', 'FAT32', 'EXFAT', 'REFS', 'CDFS', 'UDF'];

        if (! in_array($upper, $allowed, true)) {
            return ['ok' => false, 'value' => ''];
        }

        return ['ok' => true, 'value' => $upper];
    }

    /**
     * Read a Windows volume type token emitted by the filesystem script.
     *
     * Win32_Volume DriveType 3 is fixed; anything else is reported as
     * removable so the fixed-volume policy can reject it.
     *
     * @return array{ok: bool, value: string}
     */
    public function parseVolumeType(array $fields, string $key): array
    {
        if (! isset($fields[$key])) {
            return ['ok' => false, 'value' => ''];
        }

        if ($fields[$key] === 'fixed') {
            return ['ok' => true, 'value' => 'fixed'];
        }

        if ($fields[$key] === 'removable') {
            return ['ok' => true, 'value' => 'removable'];
        }

        return ['ok' => false, 'value' => ''];
    }

    /**
     * Read a canonical path emitted by [System.IO.Path]::GetFullPath().
     *
     * A canonical path is accepted only when it is absolute, starts
     * with a drive letter, and is free of traversal fragments. The
     * caller-supplied probe path is never trusted to be canonical on
     * its own.
     *
     * @return array{ok: bool, canonicalPath: string}
     */
    public function parseCanonicalPath(array $fields, string $key): array
    {
        $text = $this->parseBoundedText($fields, $key, 260);

        if (! $text['ok']) {
            return ['ok' => false, 'canonicalPath' => ''];
        }

        $path = $text['value'];

        if (! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return ['ok' => false, 'canonicalPath' => ''];
        }

        $normalized = str_replace('/', '\\', $path);

        $parts = explode('\\', $normalized);

        foreach ($parts as $part) {
            if ($part === '..') {
                return ['ok' => false, 'canonicalPath' => ''];
            }
        }

        return ['ok' => true, 'canonicalPath' => $normalized];
    }

    /**
     * Read a Windows service state token.
     *
     * @return array{ok: bool, value: string}
     */
    public function parseServiceState(array $fields, string $key): array
    {
        if (! isset($fields[$key])) {
            return ['ok' => false, 'value' => ''];
        }

        $allowed = ['Running', 'Stopped', 'StartPending', 'StopPending', 'Paused', 'ContinuePending'];

        if (! in_array($fields[$key], $allowed, true)) {
            return ['ok' => false, 'value' => ''];
        }

        return ['ok' => true, 'value' => $fields[$key]];
    }
}
