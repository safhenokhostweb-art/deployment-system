<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (SSH auth scoping): scopes SSH identity to the project's
 * `github-safhe` Host alias for Artifact Builder git transports only —
 * without modifying the global `~/.ssh/config` or any other project's SSH
 * behavior.
 *
 * The canonical GitHub SSH-over-443 transport (`ssh://git@ssh.github.com:443/...`)
 * resolves the `ssh.github.com` host against `~/.ssh/config`, which on some
 * machines has no IdentityFile authorized for that host — only the
 * `github-safhe` alias carries the correct key.
 *
 * This class:
 *   1. Discovers the IdentityFile (and IdentitiesOnly / ProxyCommand) from
 *      the `github-safhe` alias via `ssh -G` — the private key bytes are
 *      never read, copied, or logged; only the file-system path is learned.
 *   2. Writes a temporary SSH config that maps `ssh.github.com` and
 *      `github.com` to the discovered IdentityFile, with `IdentitiesOnly yes`
 *      to suppress default key attempts.
 *   3. Sets `GIT_SSH_COMMAND` to `ssh -F <temp-config>` so the git fetch
 *      inside the Artifact Builder uses the correct identity.
 *
 * The global `~/.ssh/config` is never modified.  Both the ephemeral
 * `GIT_SSH_COMMAND` env var and the temp file are cleaned up in
 * deactivate(), which is invoked automatically via __destruct when the
 * ScopedSshConfig instance goes out of scope at the end of
 * prepareRemoteCommit() — whether by normal return or by exception.
 */
final class ScopedSshConfig
{
    private ?string $tempConfigPath = null;
    private ?string $previousEnvValue = null;
    private bool $envVarSet = false;

    public function __construct(
        private readonly GitManager $git,
        private readonly ?Logger $logger = null,
        private readonly string $githubAlias = 'github-safhe',
    ) {
    }

    /**
     * Activates the scoped SSH config for subsequent `git` invocations.
     *
     * Safe to call multiple times — only the first call has an effect.
     * Silently no-ops (with a log entry) if `ssh -G` fails, `ssh` is not
     * installed, or no IdentityFile is configured for the alias, falling
     * back to the agent's default SSH transport.
     */
    public function activate(): void
    {
        if ($this->tempConfigPath !== null || $this->envVarSet) {
            return;
        }

        $settings = $this->discoverSshSettings();
        if ($settings['identity_files'] === []) {
            $this->logger?->info('ScopedSshConfig: no IdentityFile discoverable for alias ' . $this->githubAlias . '; falling back to default SSH transport (transport=ssh).');

            return;
        }

        $this->tempConfigPath = tempnam(sys_get_temp_dir(), 'la-ssh-scope-');
        if ($this->tempConfigPath === false) {
            $this->tempConfigPath = null;
            $this->logger?->warn('ScopedSshConfig: could not allocate a temp file for scoped SSH config; falling back to default SSH transport.');

            return;
        }

        $config = $this->buildTempConfig($settings);
        file_put_contents($this->tempConfigPath, $config);

        // Capture the previous value so deactivate() can restore it exactly.
        $previous = getenv('GIT_SSH_COMMAND');
        $this->previousEnvValue = $previous === false ? null : $previous;
        putenv('GIT_SSH_COMMAND=ssh -F ' . escapeshellarg($this->tempConfigPath));
        $this->envVarSet = true;

        $this->logger?->info('ScopedSshConfig: GitHub SSH fetch scoped to alias ' . $this->githubAlias . ' (transport=ssh).');
    }

    /**
     * Deactivates the scoped SSH config, restoring the previous env var
     * and deleting the temporary config file.
     *
     * Always safe to call — idempotent and never throws.  Invoked via
     * __destruct when the ScopedSshConfig goes out of scope, but callers
     * may invoke it explicitly for deterministic cleanup.
     */
    public function deactivate(): void
    {
        if ($this->envVarSet) {
            $this->envVarSet = false;

            if ($this->previousEnvValue !== null) {
                putenv('GIT_SSH_COMMAND=' . $this->previousEnvValue);
            } else {
                // unset — restores the state where GIT_SSH_COMMAND was not set
                putenv('GIT_SSH_COMMAND');
            }
        }

        if ($this->tempConfigPath !== null) {
            @unlink($this->tempConfigPath);
            $this->tempConfigPath = null;
        }
    }

    public function __destruct()
    {
        try {
            $this->deactivate();
        } catch (\Throwable) {
            // Suppress — a destructor must never throw, especially when
            // the original exception (if any) is the one that needs to
            // propagate.
        }
    }

    /**
     * Runs `ssh -G <alias>` and parses the resolved settings.
     *
     * @return array{identity_files: list<string>, identities_only: bool, proxy_command: ?string}
     */
    private function discoverSshSettings(): array
    {
        $result = $this->git->run(
            ['ssh', '-G', $this->githubAlias],
            sys_get_temp_dir(),
            10
        );

        if ($result['exitCode'] !== 0) {
            return ['identity_files' => [], 'identities_only' => false, 'proxy_command' => null];
        }

        $identityFiles = [];
        $identitiesOnly = false;
        $proxyCommand = null;

        foreach (explode("\n", (string) ($result['output'] ?? '')) as $line) {
            $line = trim($line);
            // Each line is "key value"; the key is always lowercase from ssh -G
            $parts = explode(' ', $line, 2);
            if (count($parts) < 2) {
                continue;
            }

            $key = strtolower($parts[0]);
            $value = trim($parts[1]);

            if ($key === 'identityfile') {
                $identityFiles[] = $value;
            } elseif ($key === 'identitiesonly') {
                $identitiesOnly = $value === 'yes';
            } elseif ($key === 'proxycommand') {
                $proxyCommand = $value;
            }
        }

        return [
            'identity_files' => $identityFiles,
            'identities_only' => $identitiesOnly,
            'proxy_command' => $proxyCommand,
        ];
    }

    /**
     * @param array{identity_files: list<string>, identities_only: bool, proxy_command: ?string} $settings
     */
    private function buildTempConfig(array $settings): string
    {
        // Our Host entry comes FIRST in the config so that SSH's
        // first-match-wins rule gives our IdentityFile / IdentitiesOnly
        // precedence over any user-level `Host *` or `Host github.com`
        // entries that may reference a different key.
        $lines = ['Host ssh.github.com github.com'];

        if ($settings['identities_only']) {
            $lines[] = '    IdentitiesOnly yes';
        }

        foreach ($settings['identity_files'] as $file) {
            $lines[] = '    IdentityFile ' . $file;
        }

        if ($settings['proxy_command'] !== null) {
            $lines[] = '    ProxyCommand ' . $settings['proxy_command'];
        }

        // Pull in the rest of the user's SSH config (ciphers, MACs,
        // StrictHostKeyChecking, UserKnownHostsFile, etc.) so the scoped
        // identity can still benefit from settings the user configured for
        // github-safhe without duplicating them or modifying ~/.ssh/config.
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        $home = is_string($home) && $home !== '' ? str_replace('\\', '/', rtrim($home, '/\\')) : '';
        $sshConfig = $home . '/.ssh/config';
        if ($home !== '' && file_exists($sshConfig)) {
            $lines[] = '';
            $lines[] = 'Include ' . $sshConfig;
        }

        return implode("\n", $lines) . "\n";
    }
}
