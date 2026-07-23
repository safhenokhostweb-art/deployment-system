<?php

namespace App\Support;

/**
 * Translates the fixed, enumerable set of literal English strings that
 * Services/Controllers hardcode into abort() calls, ->with('status', ...)
 * flashes, and one ->withErrors([...]) call. Those call sites cannot be
 * touched this phase (Controller/Service changes are out of scope), so this
 * is the presentation-layer seam: raw English in, looked-up translation
 * (via lang/{locale}/messages.php) out. Unknown strings pass through
 * unchanged, so a message this table doesn't know about degrades to
 * English rather than disappearing.
 */
class DashboardMessageTranslator
{
    /**
     * @return array<string, string>
     */
    private static function errorKeysByPattern(): array
    {
        return [
            '/^Only pending_approval deployments can be approved\.$/' => 'deployment_approve_guard',
            '/^Only pending_approval or queued deployments can be cancelled\.$/' => 'deployment_cancel_guard',
            "/^Project '.*' has an Environment configured but no active deployment targets\\.$/" => 'deployment_no_targets',
            '/^Can only roll back to a previously successful deployment\.$/' => 'rollback_not_succeeded',
            '/^No earlier successful deployment to roll back to\.$/' => 'rollback_no_earlier',
            '/^Can only roll back a target to a previously successful task\.$/' => 'rollback_target_not_succeeded',
            '/^No earlier successful deployment for this target to roll back to\.$/' => 'rollback_target_no_earlier',
            "/^Unknown health check type '.*'\\.$/" => 'health_check_unknown_type',
            '/^Only a pending agent can be approved\.$/' => 'agent_approve_guard',
            '/^This agent has not completed its registration call yet/' => 'agent_approve_not_registered',
            '/^Only a pending agent can be rejected\.$/' => 'agent_reject_guard',
            '/^Only an approved agent can be disabled\.$/' => 'agent_disable_guard',
            '/^Only a disabled agent can be re-enabled\.$/' => 'agent_reenable_guard',
            '/^Pipeline must be a non-empty JSON array/' => 'pipeline_invalid_json',
            '/^Every pipeline step needs a name and a command\.$/' => 'pipeline_step_incomplete',
            '/^Invalid credentials\.$/' => 'invalid_credentials',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function flashKeysByPattern(): array
    {
        return [
            '/^Registration token issued\. Copy both values now/' => 'agent_registration_issued',
            '/^Agent approved\. Copy the credentials below now/' => 'agent_approved',
            '/^Pending agent rejected and removed\.$/' => 'agent_rejected',
            '/^Agent disabled — it can no longer authenticate\.$/' => 'agent_disabled',
            '/^Agent re-enabled\.$/' => 'agent_reenabled',
            '/^Deployment approved and queued\.$/' => 'deployment_approved',
            '/^Deployment cancelled\.$/' => 'deployment_cancelled',
            '/^Rollback queued for this target\.$/' => 'rollback_target_queued',
            '/^Rollback queued\.$/' => 'rollback_queued',
            '/^Health check completed\.$/' => 'health_check_completed',
            '/^Project created\. Configure the GitHub webhook/' => 'project_created',
            '/^Webhook secret rotated\./' => 'webhook_rotated',
            '/^Project updated\.$/' => 'project_updated',
            '/^Deployment queued\.$/' => 'deployment_queued',
            '/^Server registered\. Copy the credentials below now/' => 'server_registered',
            '/^Credentials rotated\. Update config\.php/' => 'server_credentials_rotated',
        ];
    }

    public static function translateError(string $message): string
    {
        return self::translate($message, self::errorKeysByPattern(), 'messages.errors');
    }

    public static function translateFlash(string $message): string
    {
        return self::translate($message, self::flashKeysByPattern(), 'messages.flash');
    }

    /**
     * @param array<string, string> $patterns
     */
    private static function translate(string $message, array $patterns, string $namespace): string
    {
        foreach ($patterns as $pattern => $key) {
            if (preg_match($pattern, $message) === 1) {
                return __($namespace . '.' . $key, self::extractReplacements($message, $key));
            }
        }

        return $message;
    }

    /**
     * @return array<string, string>
     */
    private static function extractReplacements(string $message, string $key): array
    {
        return match ($key) {
            'deployment_no_targets' => ['name' => self::between($message, "Project '", "' has an Environment")],
            'health_check_unknown_type' => ['type' => self::between($message, "Unknown health check type '", "'.")],
            'agent_registration_issued' => ['minutes' => self::between($message, 'expires in ', ' minutes.')],
            default => [],
        };
    }

    private static function between(string $haystack, string $start, string $end): string
    {
        $startPos = strpos($haystack, $start);
        if ($startPos === false) {
            return '';
        }
        $startPos += strlen($start);
        $endPos = strpos($haystack, $end, $startPos);
        if ($endPos === false) {
            return '';
        }

        return substr($haystack, $startPos, $endPos - $startPos);
    }
}
