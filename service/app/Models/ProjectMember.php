<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4-G resource-level authorization
 * (docs/PHASE_4G_RESOURCE_AUTHORIZATION_REPORT.md). A row here grants a
 * non-admin User access to one specific Project — see ProjectPolicy for
 * how this composes with the Phase 4-F role/permission matrix.
 */
class ProjectMember extends Model
{
    use HasFactory;

    protected $table = 'project_user';

    protected $fillable = [
        'project_id',
        'user_id',
        'project_role',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
