<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A connected GitHub App installation (docs/PHASE_4E_GITHUB_PROJECT_WIZARD_REPORT.md).
 * Deliberately holds no token — every API call generates a fresh,
 * short-lived installation access token on demand via GitHubAppClient.
 */
class GitHubAccount extends Model
{
    use HasFactory;

    // Laravel's default snake_case convention turns "GitHubAccount" into
    // "git_hub_accounts" (it inserts an underscore before every
    // capital-after-lowercase, including inside "GitHub" itself) — not
    // the "github_accounts" table this migration actually created.
    protected $table = 'github_accounts';

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'installation_id',
        'account_login',
        'account_type',
        'status',
        'connected_by',
        'connected_at',
        'last_tested_at',
    ];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }
}
