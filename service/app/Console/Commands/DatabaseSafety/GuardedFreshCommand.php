<?php

namespace App\Console\Commands\DatabaseSafety;

use App\Support\Database\DestructiveCommandGuard;
use Illuminate\Database\Console\Migrations\FreshCommand;

/**
 * Overrides Laravel's built-in `migrate:fresh` with a version that runs
 * DestructiveCommandGuard::assertSafe() first — see AppServiceProvider::boot()
 * for how/why this is registered via Artisan::starting() rather than a
 * CommandStarting event listener: CommandStarting is never dispatched
 * for any command invoked with `--env=testing`, because Laravel treats
 * that flag as "this is a test run" (app()->runningUnitTests() becomes
 * true) and skips the Symfony console event bridge entirely — verified
 * by direct testing, not assumed. That is the exact flag the original
 * incident's command used, so relying on CommandStarting alone would
 * have left this class of accident only partially fixed.
 */
final class GuardedFreshCommand extends FreshCommand
{
    public function handle()
    {
        DestructiveCommandGuard::assertSafe($this->getName());

        return parent::handle();
    }
}
