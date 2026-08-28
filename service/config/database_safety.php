<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Protected destructive commands
    |--------------------------------------------------------------------------
    |
    | Every command listed here must pass DestructiveCommandGuard::assertSafe()
    | (wired via a CommandStarting listener — see AppServiceProvider::boot())
    | before it is allowed to run at all.
    |
    | migrate:reset and migrate:refresh drop tables the same way
    | migrate:fresh does; migrate:rollback runs migrations' down() methods
    | against the live schema, which can just as easily drop a real table.
    | Plain `migrate` (forward-only, additive — never drops anything) is
    | deliberately NOT included: blocking it would break every normal
    | deploy/setup flow (see composer.json's own "setup" script) for no
    | safety benefit.
    |
    */
    'protected_commands' => [
        'migrate:fresh',
        'migrate:reset',
        'migrate:refresh',
        'migrate:rollback',
        'db:wipe',
    ],

    /*
    |--------------------------------------------------------------------------
    | Disposable test database paths
    |--------------------------------------------------------------------------
    |
    | Absolute sqlite file paths (besides ':memory:') explicitly approved as
    | disposable targets for the protected commands above. Empty by default —
    | only ':memory:' is ever safe out of the box, matching this project's
    | own phpunit.xml. Add an entry here only for a genuine, deliberately
    | disposable file-based test database; anything not listed here (and not
    | ':memory:') is denied — including, unconditionally, the real
    | development database at database_path('database.sqlite').
    |
    */
    'disposable_test_database_paths' => [],

];
