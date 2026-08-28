<?php

namespace App\Console\Commands\DatabaseSafety;

use App\Support\Database\DestructiveCommandGuard;
use Illuminate\Database\Console\WipeCommand;

/** Overrides `db:wipe` — see GuardedFreshCommand's docblock for why this mechanism. */
final class GuardedWipeCommand extends WipeCommand
{
    public function handle()
    {
        DestructiveCommandGuard::assertSafe($this->getName());

        return parent::handle();
    }
}
