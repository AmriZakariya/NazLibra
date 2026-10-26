<?php

namespace Tests\Feature;

use App\Http\Controllers\Castlit\ClientAdminController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The master's "Git pull" button migrates the master's own database.
 *
 * Client updates always ran migrate --force; this button only cleared
 * caches. A change to a master table — the origin columns on subscriptions —
 * therefore left every signup on the website and in the app failing on an
 * unknown column until someone reached the host over SSH.
 *
 * The deploy itself extracts a GitHub tarball OVER the code base, so it is
 * not driven end to end here: the helper is tested directly, and both deploy
 * paths are held to calling it.
 */
class MasterDeployMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): ?string
    {
        $method = new \ReflectionMethod(ClientAdminController::class, 'migrateMaster');

        return $method->invoke(app(ClientAdminController::class));
    }

    public function test_a_clean_migration_reports_nothing(): void
    {
        $this->assertNull($this->migrate());
    }

    public function test_a_failing_migration_is_reported_not_swallowed(): void
    {
        // The cache clears around it are best-effort; this is not. Code that
        // expects a column the database lacks is a broken site.
        Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn("SQLSTATE[42S21]: Duplicate column name 'source'\n");

        $this->assertSame("SQLSTATE[42S21]: Duplicate column name 'source'", $this->migrate());
    }

    public function test_a_failure_with_no_output_still_says_something(): void
    {
        Artisan::shouldReceive('call')->once()->andReturn(2);
        Artisan::shouldReceive('output')->once()->andReturn('');

        $this->assertSame('code de sortie 2', $this->migrate());
    }

    public function test_an_exception_is_reported_too(): void
    {
        Artisan::shouldReceive('call')->once()->andThrow(new \RuntimeException('connexion refusée'));

        $this->assertSame('connexion refusée', $this->migrate());
    }

    public function test_both_deploy_paths_migrate_before_clearing_caches(): void
    {
        $source = file_get_contents((new \ReflectionClass(ClientAdminController::class))->getFileName());
        $pull = Str::before(Str::after($source, 'public function pullFromGit('), "\n    }\n");

        // Two deploy paths — git, and the GitHub tarball this host actually
        // uses — and each must migrate, then rebuild the caches.
        preg_match_all('/\$this->migrateMaster\(\)/', $pull, $migrations, PREG_OFFSET_CAPTURE);
        preg_match_all("/runArtisan\\(\\['optimize:clear'\\]\\)/", $pull, $clears, PREG_OFFSET_CAPTURE);

        $this->assertCount(2, $migrations[0], 'each deploy path must migrate the master');
        $this->assertCount(2, $clears[0]);

        foreach ([0, 1] as $path) {
            $this->assertLessThan(
                $clears[0][$path][1],
                $migrations[0][$path][1],
                'migrate before the caches are rebuilt, in deploy path '.($path + 1),
            );
        }
    }
}
