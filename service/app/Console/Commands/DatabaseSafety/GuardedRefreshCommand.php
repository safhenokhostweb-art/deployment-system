<?php

namespace App\Console\Commands\DatabaseSafety;

use App\Support\Database\DestructiveCommandGuard;
use Illuminate\Database\Console\Migrations\RefreshCommand;

/** Overrides `migrate:refresh` — see GuardedFreshCommand's docblock for why this mechanism. */
final class GuardedRefreshCommand extends RefreshCommand
{
    public function handle()
    {
        DestructiveCommandGuard::assertSafe($this->getName());

        return parent::handle();
    }
}
