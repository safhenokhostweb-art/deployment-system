<?php

namespace App\Console\Commands\DatabaseSafety;

use App\Support\Database\DestructiveCommandGuard;
use Illuminate\Database\Console\Migrations\ResetCommand;

/** Overrides `migrate:reset` — see GuardedFreshCommand's docblock for why this mechanism. */
final class GuardedResetCommand extends ResetCommand
{
    public function handle()
    {
        DestructiveCommandGuard::assertSafe($this->getName());

        return parent::handle();
    }
}
