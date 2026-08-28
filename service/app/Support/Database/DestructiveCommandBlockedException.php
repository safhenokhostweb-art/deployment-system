<?php

namespace App\Support\Database;

/**
 * Thrown by DestructiveCommandGuard::assertSafe() — see its own docblock.
 * A plain \RuntimeException subclass so it renders through Artisan's
 * normal (safe, no route/session involved) console exception handling
 * with no special-casing required anywhere else.
 */
final class DestructiveCommandBlockedException extends \RuntimeException
{
}
