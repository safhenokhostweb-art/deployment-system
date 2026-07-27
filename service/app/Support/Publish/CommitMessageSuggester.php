<?php

namespace App\Support\Publish;

/**
 * Phase 5.2 §4: a deterministic, rule-based suggestion — never an LLM
 * call, never invented content the operator can't predict. Shown as a
 * pre-filled, EDITABLE field in Approval mode (the operator always sees
 * and can override it before anything is committed); used as-is only in
 * Auto mode, where no one is reviewing it.
 */
class CommitMessageSuggester
{
    private const BUCKET_LABELS = [
        'migration' => 'database migrations',
        'blade' => 'UI',
        'php' => 'Laravel service',
        'frontend' => 'frontend assets',
        'misc' => 'other',
    ];

    /**
     * @param array<int, string> $changedFiles
     */
    public function suggest(array $changedFiles): string
    {
        $present = [];
        foreach ($changedFiles as $file) {
            $present[$this->bucketFor($file)] = true;
        }

        $labels = [];
        foreach (self::BUCKET_LABELS as $key => $label) {
            if (isset($present[$key])) {
                $labels[] = $label;
            }
        }

        if ($labels === []) {
            return 'Auto update: project changes';
        }

        return 'Auto update: ' . $this->joinNaturally($labels) . ' changes';
    }

    private function bucketFor(string $file): string
    {
        // A rename is reported as "old/path -> new/path" — bucket by the
        // new (resulting) path.
        $file = str_contains($file, ' -> ') ? trim(substr($file, strpos($file, ' -> ') + 4)) : $file;
        $file = str_replace('\\', '/', $file);

        if (str_starts_with($file, 'database/migrations/')) {
            return 'migration';
        }

        if (str_ends_with($file, '.blade.php')) {
            return 'blade';
        }

        if (str_ends_with($file, '.php')) {
            return 'php';
        }

        if (preg_match('/\.(js|jsx|ts|tsx|css|scss)$/', $file) === 1) {
            return 'frontend';
        }

        return 'misc';
    }

    /**
     * @param array<int, string> $labels
     */
    private function joinNaturally(array $labels): string
    {
        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' and ' . $last;
    }
}
