<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to boot against anything but in-memory SQLite, so RefreshDatabase
     * can never wipe the MySQL development database (for example via a stale config cache).
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException("Tests must run on in-memory SQLite, not [{$connection}].");
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Any HTTP call a test hasn't explicitly faked fails instead of reaching Gemini.
        Http::preventStrayRequests();

        // Stripe's SDK has its own HTTP client, so it needs its own guard.
        ApiRequestor::setHttpClient(new FakeStripeHttpClient);
    }
}
