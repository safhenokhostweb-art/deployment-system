<?php

namespace App\Console\Commands\DatabaseSafety;

use App\Support\Database\DestructiveCommandGuard;
use Illuminate\Database\Console\Migrations\RollbackCommand;

/** Overrides `migrate:rollback` — see GuardedFreshCommand's docblock for why this mechanism. */
final class GuardedRollbackCommand extends RollbackCommand
{
    public function handle()
    {
        DestructiveCommandGuard::assertSafe($this->getName());

        return parent::handle();
    }
}
