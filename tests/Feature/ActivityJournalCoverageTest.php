<?php

namespace Tests\Feature;

use App\Http\Middleware\RecordApiActivity;
use App\Http\Middleware\RecordUserActivity;
use App\Models\User;
use App\Support\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The journal is how we understand what users do and why something broke.
 * Background requests (the catalogue import), 38 app routes, every failure,
 * downloads and page views used to be missing. Everything is recorded now,
 * except automatic traffic named in one list.
 */
class ActivityJournalCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $this->seed();

        return User::first();
    }

    private function last(?string $kind = null): object
    {
        return DB::table('audit_logs')->when($kind, fn ($q) => $q->where('kind', $kind))->latest('id')->firstOrFail();
    }

    private function props(object $log): array
    {
        return json_decode((string) $log->properties, true) ?: [];
    }

    public function test_every_write_route_is_recorded_unless_named_as_automatic(): void
    {
        $router = app('router');
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
                continue;
            }
            $uri = $route->uri();
            if (Str::is(['telescope/*', 'telescope', 'livewire/*', '_debugbar/*'], $uri)) {
                continue;
            }
            $middleware = $router->gatherRouteMiddleware($route);
            $web = in_array(RecordUserActivity::class, $middleware, true);
            $api = in_array(RecordApiActivity::class, $middleware, true);
            $named = in_array($route->getName(), RecordUserActivity::NOT_RECORDED, true)
                || in_array($uri, RecordApiActivity::NOT_RECORDED, true);

            if (! $web && ! $api && ! $named) {
                $missing[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $missing, 'Write routes the activity journal never sees');
    }

    public function test_an_import_sent_in_the_background_is_recorded_with_its_result(): void
    {
        $owner = $this->owner();
        $file = UploadedFile::fake()->createWithContent('categories.csv', "name\nPapeterie\nCartables\n\n");

        $this->actingAs($owner)
            ->post(route('catalog.import'), ['catalog_file' => $file, 'kind' => 'categories'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertRedirect();

        $log = $this->last();
        $this->assertSame('catalog.import', $log->action);
        $this->assertSame('Import catalogue', $log->friendly_action);
        $this->assertSame('ok', $log->outcome);
        $this->assertMatchesRegularExpression('/^Catégories: \d créée\(s\), \d mise\(s\) à jour/u', $log->outcome_message);
        $this->assertSame('[fichier categories.csv]', $this->props($log)['payload']['catalog_file']);
    }

    public function test_a_refused_action_records_why(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('catalog.categories.store'), ['name' => ''])->assertRedirect();

        $log = $this->last();
        $this->assertSame('catalog.categories.store', $log->action);
        $this->assertSame('failed', $log->outcome);
        $this->assertNotEmpty($this->props($log)['errors']['name'] ?? null);
        $this->assertNotEmpty($log->outcome_message);
    }

    public function test_a_crash_records_the_exception(): void
    {
        $owner = $this->owner();
        Route::middleware('web')->post('/__crash', fn () => throw new \RuntimeException('Le tiroir a pris feu'))->name('test.crash');

        $this->actingAs($owner)->post('/__crash')->assertStatus(500);

        $log = $this->last();
        $this->assertSame('failed', $log->outcome);
        $this->assertStringContainsString('RuntimeException : Le tiroir a pris feu', $log->outcome_message);
        $this->assertSame(\RuntimeException::class, $this->props($log)['exception']['class']);
    }

    public function test_logins_are_recorded_failed_ones_too_without_the_password(): void
    {
        $owner = $this->owner();

        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'wrong-one'])->assertRedirect();
        $failed = $this->last();
        $this->assertSame(['auth', 'failed', $owner->id], [$failed->kind, $failed->outcome, (int) $failed->user_id]);
        $this->assertStringNotContainsString('wrong-one', (string) $failed->properties);

        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password'])->assertRedirect();
        $ok = $this->last('auth');
        $this->assertSame(['ok', $owner->id], [$ok->outcome, (int) $ok->user_id]);
        $this->assertSame('Connexion', $ok->friendly_action);
    }

    public function test_a_file_handed_out_is_a_download(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get(route('catalog.import.example', 'items'))->assertOk();

        $log = $this->last();
        $this->assertSame('download', $log->kind);
        $this->assertSame('catalog.import.example', $log->action);
    }

    public function test_every_app_write_is_recorded_even_routes_that_never_opted_in(): void
    {
        $owner = $this->owner();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->assertOk()->json('token');
        $this->assertSame(['auth', 'ok', 'Connexion (application)'], [$this->last()->kind, $this->last()->outcome, $this->last()->friendly_action]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->withHeaders(['X-App-Version' => '1.0.13'])
            ->postJson('/api/v1/contacts', ['name' => 'Client du test', 'type' => 'customer'])->assertSuccessful();
        $created = $this->last();
        $this->assertSame(['action', 'ok', 'Création client', '1.0.13'], [$created->kind, $created->outcome, $created->friendly_action, $created->app_version]);

        $this->withToken($token)->postJson('/api/v1/contacts', ['name' => ''])->assertStatus(422);
        $refused = $this->last();
        $this->assertSame('failed', $refused->outcome);
        $this->assertArrayHasKey('name', $this->props($refused)['errors']);
    }

    public function test_a_failed_app_login_is_recorded_against_the_account_tried(): void
    {
        $owner = $this->owner();

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'nope', 'device_name' => 'till'])->assertStatus(422);

        $log = $this->last();
        $this->assertSame(['auth', 'failed', $owner->id], [$log->kind, $log->outcome, (int) $log->user_id]);
        $this->assertSame(\App\Support\RequestSnapshot::MASK, $this->props($log)['payload']['password']);
    }

    public function test_the_screens_the_app_opened_ride_along_as_page_views(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $owner = $this->owner();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
        $this->app['auth']->forgetGuards();

        $screens = rawurlencode(json_encode([
            ['s' => '/pos', 't' => '2026-11-10T11:58:00Z'],
            ['s' => '/catalogue/item/42', 't' => '2026-11-10T11:59:00Z'],
        ]));
        $this->withToken($token)->withHeaders(['X-App-Screens' => $screens])->getJson('/api/v1/auth/me')->assertOk();

        $views = DB::table('audit_logs')->where('kind', 'view')->orderBy('id')->get();
        $this->assertSame(['Écran · Pos', 'Écran · Catalogue › Item'], $views->pluck('friendly_action')->all());
        $this->assertSame('2026-11-10 11:59:00', Carbon::parse($views[1]->created_at)->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'api.get')->count(), 'reads are not actions');
    }

    public function test_page_views_go_after_30_days_and_actions_stay(): void
    {
        $owner = $this->owner();
        $row = fn (string $kind, int $days) => DB::table('audit_logs')->insert([
            'tenant_id' => $owner->current_tenant_id, 'user_id' => $owner->id, 'action' => 'x', 'kind' => $kind,
            'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
        ]);
        $row('view', 31);
        $row('view', 5);
        $row('action', 400);

        $this->assertSame(1, ActivityRecorder::pruneViews());
        $this->assertSame(1, DB::table('audit_logs')->where('kind', 'view')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('kind', 'action')->where('action', 'x')->count(), 'a year-old action stays');
    }
}
