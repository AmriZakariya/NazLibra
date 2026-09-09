<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The shared host forwards HTTPS through a proxy, so Laravel can see
        // an HTTP request and generate http:// form actions. Its HTTP→HTTPS
        // redirect then turns POST requests into GET requests, breaking every
        // POST form (e.g. the platform-admin client actions → 404). Force HTTPS
        // URL generation on any non-local environment — not just when APP_ENV
        // happens to be exactly "production" — so this can't silently regress.
        // 'testing' is excluded alongside 'local': the reason above is a
        // shared host's HTTPS proxy, and the test suite has no proxy. Forcing
        // it there only made generated URLs disagree with APP_URL, which is
        // how a test ends up asserting a hardcoded scheme.
        if (! app()->environment(['local', 'testing'])) {
            URL::forceScheme('https');
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $destructiveCommands = [
                'db:wipe',
                'migrate:fresh',
                'migrate:refresh',
                'migrate:reset',
                'migrate:rollback',
            ];

            if (! in_array($event->command, $destructiveCommands, true)) {
                return;
            }

            if (app()->environment('testing') || filter_var(env('ALLOW_DESTRUCTIVE_DB_RESET', false), FILTER_VALIDATE_BOOL)) {
                return;
            }

            throw new RuntimeException(
                "Commande bloquée pour protéger les données importées. ".
                "Si vous voulez vraiment réinitialiser la base, relancez avec ALLOW_DESTRUCTIVE_DB_RESET=true."
            );
        });
    }
}
