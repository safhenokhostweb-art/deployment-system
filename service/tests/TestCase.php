<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Database Safety Hardening (Phase S1): the second of two independent
     * layers protecting the test suite itself — see tests/bootstrap.php
     * for the first (a raw env-var check that runs before Laravel even
     * boots). This one re-checks AFTER the framework is fully booted, via
     * its own environment/config resolution rather than raw env vars, so
     * a bug specifically in how Laravel resolves those (not just a
     * missing/wrong env var) is caught too. Runs before every single
     * test, not once per suite — cheap, and the strongest guarantee
     * available against a rogue prior test having changed config
     * mid-suite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!app()->environment('testing')) {
            self::fail('Refusing to run: app()->environment() resolved to "' . app()->environment() . '", not "testing" — the test suite must never run against a non-testing environment.');
        }

        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}.database");
        if ($connection !== 'sqlite' || $database !== ':memory:') {
            self::fail("Refusing to run: the resolved database target is '{$connection}:{$database}', not sqlite ':memory:' — the test suite must never run against a persistent database.");
        }
    }
}
