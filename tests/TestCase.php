<?php

namespace Tests;

use App\Services\Sessions;
use App\Services\Users;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

/**
 * Tests run against TEST_DATABASE_URL, whose name must end in _test. The
 * first test wipes it and applies the shared migrations; each test then runs
 * in a transaction that is rolled back.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    private const DEFAULT_URL = 'postgres://tadmor:tadmor@127.0.0.1:5432/tadmor_php_test?sslmode=disable';

    private static bool $migrated = false;

    public function createApplication()
    {
        $url = getenv('TEST_DATABASE_URL') ?: self::DEFAULT_URL;
        putenv("DATABASE_URL=$url");
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $url;

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        if (! self::$migrated) {
            // On an application of its own, before DatabaseTransactions opens
            // this test's transaction, which would roll the schema back.
            $app = $this->createApplication();
            $console = $app->make(Kernel::class);
            foreach (['tadmor:resetdb', 'tadmor:migrate'] as $command) {
                if ($console->call($command) !== 0) {
                    throw new RuntimeException("$command failed: ".$console->output());
                }
            }
            $app->flush();
            self::$migrated = true;
        }
        parent::setUp();
    }

    /** Create a user and return a cookie header array for their session. */
    protected function loginAs(string $email = 'admin@example.test', bool $isAdmin = true): array
    {
        $user = Users::addOrReset($email, 'Test User', 'password1', $isAdmin);

        return [Sessions::COOKIE => Sessions::create($user)];
    }
}
