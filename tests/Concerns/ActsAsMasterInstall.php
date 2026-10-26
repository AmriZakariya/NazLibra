<?php

namespace Tests\Concerns;

/**
 * Boots the application as the castlitpos.com master install.
 *
 * The marketing and signup routes are registered at boot from
 * CASTLIT_MASTER, so config() set inside a test comes too late — it has to be
 * in the environment before the app starts. And it has to come OUT again:
 * the environment outlives the test, and every test that ran afterwards in
 * the same process booted as the master, where `/` is the landing page and
 * the `dashboard` route no longer exists.
 */
trait ActsAsMasterInstall
{
    protected function setUp(): void
    {
        putenv('CASTLIT_MASTER=true');
        $_ENV['CASTLIT_MASTER'] = 'true';
        $_SERVER['CASTLIT_MASTER'] = 'true';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('CASTLIT_MASTER');
        unset($_ENV['CASTLIT_MASTER'], $_SERVER['CASTLIT_MASTER']);
    }
}
