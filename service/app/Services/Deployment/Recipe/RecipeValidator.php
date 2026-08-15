<?php

namespace App\Services\Deployment\Recipe;

/**
 * Validates and normalizes a decoded `Project.deployment_recipe` JSON
 * value against the fixed RecipeStepType catalog. Mirrors
 * ProjectController::validateProject()'s hand-rolled `pipeline` checks
 * (abort(422, message) on the first problem found) rather than Laravel's
 * request-validation rule objects, because each step type has a
 * genuinely different required-parameter shape — a single rule set can't
 * express that cleanly.
 *
 * Accepts two equivalent shapes, both valid JSON for a `json` column:
 *   - a flat array of steps: [{type: "shell", ...}, ...]
 *     (matches the deployment_recipe column's own docblock — the whole-
 *     deployment timeout defaults to DEFAULT_TOTAL_TIMEOUT_SECONDS)
 *   - a wrapped object: {"timeout_seconds": 3600, "steps": [...]}
 *     (opt in only when the whole-deployment timeout needs overriding)
 *
 * Every step is normalized to exactly {type, name, timeout_seconds,
 * ...type-specific params} — no extra/unknown keys survive validation,
 * so nothing an admin didn't explicitly author can reach the agent.
 */
class RecipeValidator
{
    public const DEFAULT_STEP_TIMEOUT_SECONDS = 900;
    public const MAX_STEP_TIMEOUT_SECONDS = 7200;
    public const DEFAULT_TOTAL_TIMEOUT_SECONDS = 7200;
    public const MAX_TOTAL_TIMEOUT_SECONDS = 21600;

    /** Agent-local restart_service/verify_service_active names never come from the Hub — this is only a shape check. */
    private const SERVICE_NAME_PATTERN = '/^[a-zA-Z0-9_.@-]{1,255}$/';
    private const VERSION_PATTERN = '/^\d+(\.\d+){0,2}$/';

    /**
     * @param mixed $decoded the already json_decode(..., true)'d value of deployment_recipe
     * @return array{timeout_seconds: int, steps: array<int, array<string, mixed>>}
     */
    public function validate(mixed $decoded): array
    {
        if (!is_array($decoded) || $decoded === []) {
            abort(422, 'Deployment recipe must be a non-empty JSON array of steps, or an object with a non-empty "steps" array.');
        }

        $isWrapped = array_key_exists('steps', $decoded) && !array_is_list($decoded);

        $totalTimeout = self::DEFAULT_TOTAL_TIMEOUT_SECONDS;
        $rawSteps = $decoded;

        if ($isWrapped) {
            $unknown = array_diff(array_keys($decoded), ['timeout_seconds', 'steps']);
            if ($unknown !== []) {
                abort(422, 'Deployment recipe object only accepts "timeout_seconds" and "steps": unexpected key(s) ' . implode(', ', $unknown) . '.');
            }

            if (array_key_exists('timeout_seconds', $decoded)) {
                $totalTimeout = $this->validateInt($decoded['timeout_seconds'], 1, self::MAX_TOTAL_TIMEOUT_SECONDS, 'Recipe timeout_seconds');
            }

            $rawSteps = $decoded['steps'];
        }

        if (!is_array($rawSteps) || $rawSteps === [] || !array_is_list($rawSteps)) {
            abort(422, 'Deployment recipe "steps" must be a non-empty JSON array.');
        }

        $steps = [];
        foreach ($rawSteps as $index => $step) {
            $steps[] = $this->validateStep($step, $index);
        }

        $hasMutatingStep = collect($steps)->contains(fn (array $step) => in_array($step['type'], RecipeStepType::MUTATING, true));
        if (!$hasMutatingStep) {
            abort(422, 'Deployment recipe has no step that actually changes anything — every step is a verify_* check. Add at least one build/activation step.');
        }

        return ['timeout_seconds' => $totalTimeout, 'steps' => $steps];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStep(mixed $step, int $index): array
    {
        $label = "Recipe step #{$index}";

        if (!is_array($step) || !isset($step['type']) || !is_string($step['type'])) {
            abort(422, "{$label}: must be an object with a \"type\" field.");
        }

        $type = $step['type'];
        if (!in_array($type, RecipeStepType::ALL, true)) {
            abort(422, "{$label}: unknown step type \"{$type}\". Allowed types: " . implode(', ', RecipeStepType::ALL) . '.');
        }

        $label = "{$label} ({$type})";

        $name = $step['name'] ?? $type;
        if (!is_string($name) || $name === '' || mb_strlen($name) > 255) {
            abort(422, "{$label}: \"name\" must be a non-empty string of at most 255 characters.");
        }

        $timeoutSeconds = array_key_exists('timeout_seconds', $step)
            ? $this->validateInt($step['timeout_seconds'], 1, self::MAX_STEP_TIMEOUT_SECONDS, "{$label}: timeout_seconds")
            : self::DEFAULT_STEP_TIMEOUT_SECONDS;

        $commonKeys = ['type', 'name', 'timeout_seconds'];
        $params = $this->validateParamsForType($type, $step, $label);

        $unknown = array_diff(array_keys($step), $commonKeys, array_keys($params));
        if ($unknown !== []) {
            abort(422, "{$label}: unexpected key(s) " . implode(', ', $unknown) . '.');
        }

        return array_merge(['type' => $type, 'name' => $name, 'timeout_seconds' => $timeoutSeconds], $params);
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed> only the type-specific, already-validated params
     */
    private function validateParamsForType(string $type, array $step, string $label): array
    {
        return match ($type) {
            RecipeStepType::SHELL => [
                'command' => $this->requireNonEmptyString($step, 'command', $label, 4000),
                'working_directory' => $this->optionalNonEmptyString($step, 'working_directory', $label),
            ],
            RecipeStepType::CHECKOUT_RELEASE => [
                'source_repository' => $this->requireNonEmptyString($step, 'source_repository', $label),
            ],
            RecipeStepType::VERIFY_RUNTIME => [
                'runtime' => $this->requireOneOf($step, 'runtime', ['node', 'npm', 'php'], $label),
                'min_version' => $this->requirePattern($step, 'min_version', self::VERSION_PATTERN, $label, 'a version like "20.9.0"'),
            ],
            RecipeStepType::VERIFY_DISK_SPACE => [
                'path' => $this->requireNonEmptyString($step, 'path', $label),
                'min_free_mb' => $this->requireIntField($step, 'min_free_mb', 1, 1_000_000, $label),
            ],
            RecipeStepType::VERIFY_PATH_EXISTS => [
                'path' => $this->requireNonEmptyString($step, 'path', $label),
                'kind' => array_key_exists('kind', $step) ? $this->requireOneOf($step, 'kind', ['file', 'directory'], $label) : 'file',
            ],
            RecipeStepType::VERIFY_FILE_NOT_CONTAINS => [
                'path' => $this->requireNonEmptyString($step, 'path', $label),
                'forbidden' => $this->requireNonEmptyString($step, 'forbidden', $label, 500),
            ],
            RecipeStepType::INSTALL_DEPENDENCIES => [
                'working_directory' => $this->requireNonEmptyString($step, 'working_directory', $label),
                'manager' => array_key_exists('manager', $step) ? $this->requireOneOf($step, 'manager', ['npm'], $label) : 'npm',
                'mode' => array_key_exists('mode', $step) ? $this->requireOneOf($step, 'mode', ['ci', 'install'], $label) : 'ci',
            ],
            RecipeStepType::RUN_SCRIPT => [
                'working_directory' => $this->requireNonEmptyString($step, 'working_directory', $label),
                'script' => $this->requirePattern($step, 'script', '/^[\w:.-]{1,100}$/', $label, 'an npm script name (letters, digits, ":", ".", "-", "_")'),
                'env' => $this->validateEnvMap($step['env'] ?? [], $label),
            ],
            RecipeStepType::PRISMA_MIGRATE => [
                'working_directory' => $this->requireNonEmptyString($step, 'working_directory', $label),
                'mode' => array_key_exists('mode', $step) ? $this->requireOneOf($step, 'mode', ['deploy', 'status'], $label) : 'deploy',
                'allow_destructive' => array_key_exists('allow_destructive', $step) ? $this->requireBool($step, 'allow_destructive', $label) : false,
            ],
            RecipeStepType::ASSEMBLE_NEXT_STANDALONE => [
                'app_path' => $this->requireNonEmptyString($step, 'app_path', $label),
            ],
            RecipeStepType::ACTIVATE_RELEASE => [],
            RecipeStepType::RESTART_SERVICE => [
                'service' => $this->requirePattern($step, 'service', self::SERVICE_NAME_PATTERN, $label, 'a systemd unit name'),
            ],
            RecipeStepType::VERIFY_SERVICE_ACTIVE => [
                'service' => $this->requirePattern($step, 'service', self::SERVICE_NAME_PATTERN, $label, 'a systemd unit name'),
            ],
            RecipeStepType::HTTP_HEALTH_CHECK => [
                'url' => $this->requirePattern($step, 'url', '#^https?://\S+$#', $label, 'an http(s) URL'),
                'expected_status' => $this->requireIntField($step, 'expected_status', 100, 599, $label, 200),
                'request_timeout_seconds' => $this->requireIntField($step, 'request_timeout_seconds', 1, 120, $label, 10),
                'retries' => $this->requireIntField($step, 'retries', 0, 30, $label, 3),
                'retry_delay_seconds' => $this->requireIntField($step, 'retry_delay_seconds', 0, 60, $label, 2),
                'must_not_contain' => $this->optionalNonEmptyString($step, 'must_not_contain', $label),
            ],
            RecipeStepType::SET_OWNERSHIP => [
                'path' => $this->requireNonEmptyString($step, 'path', $label),
                'owner' => $this->requirePattern($step, 'owner', '/^[a-zA-Z0-9_.-]{1,64}(:[a-zA-Z0-9_.-]{1,64})?$/', $label, 'a "user" or "user:group" name'),
            ],
            default => abort(422, "{$label}: unknown step type."),
        };
    }

    private function requireNonEmptyString(array $step, string $key, string $label, int $maxLength = 1000): string
    {
        $value = $step[$key] ?? null;
        if (!is_string($value) || $value === '' || mb_strlen($value) > $maxLength || str_contains($value, "\0")) {
            abort(422, "{$label}: \"{$key}\" is required and must be a non-empty string (max {$maxLength} chars).");
        }

        return $value;
    }

    private function optionalNonEmptyString(array $step, string $key, string $label, int $maxLength = 1000): ?string
    {
        if (!array_key_exists($key, $step) || $step[$key] === null) {
            return null;
        }

        return $this->requireNonEmptyString($step, $key, $label, $maxLength);
    }

    private function requirePattern(array $step, string $key, string $pattern, string $label, string $expectation): string
    {
        $value = $step[$key] ?? null;
        if (!is_string($value) || $value === '' || !preg_match($pattern, $value)) {
            abort(422, "{$label}: \"{$key}\" must be {$expectation}.");
        }

        return $value;
    }

    /**
     * @param array<int, string> $allowed
     */
    private function requireOneOf(array $step, string $key, array $allowed, string $label): string
    {
        $value = $step[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            abort(422, "{$label}: \"{$key}\" must be one of " . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    private function requireIntField(array $step, string $key, int $min, int $max, string $label, ?int $default = null): int
    {
        if (!array_key_exists($key, $step) || $step[$key] === null) {
            if ($default !== null) {
                return $default;
            }
            abort(422, "{$label}: \"{$key}\" is required.");
        }

        return $this->validateInt($step[$key], $min, $max, "{$label}: {$key}");
    }

    private function validateInt(mixed $value, int $min, int $max, string $fieldLabel): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            abort(422, "{$fieldLabel} must be an integer.");
        }

        $intValue = (int) $value;
        if ($intValue < $min || $intValue > $max) {
            abort(422, "{$fieldLabel} must be between {$min} and {$max}.");
        }

        return $intValue;
    }

    private function requireBool(array $step, string $key, string $label): bool
    {
        $value = $step[$key] ?? null;
        if (!is_bool($value)) {
            abort(422, "{$label}: \"{$key}\" must be a boolean.");
        }

        return $value;
    }

    /**
     * `env` values are passed to the agent's proc_open() `env` argument —
     * a real array the OS assigns directly to the child process's
     * environment block, never concatenated into a shell command string
     * — so this only needs to validate shape, not shell-escape anything.
     *
     * @return array<string, string>
     */
    private function validateEnvMap(mixed $env, string $label): array
    {
        if (!is_array($env)) {
            abort(422, "{$label}: \"env\" must be an object of NAME: \"value\" pairs.");
        }

        $validated = [];
        foreach ($env as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                abort(422, "{$label}: env var name \"{$name}\" is invalid — must match [A-Za-z_][A-Za-z0-9_]*.");
            }
            if (!is_string($value) || mb_strlen($value) > 2000 || str_contains($value, "\0")) {
                abort(422, "{$label}: env var \"{$name}\" must be a string value (max 2000 chars).");
            }
            $validated[$name] = $value;
        }

        return $validated;
    }
}
