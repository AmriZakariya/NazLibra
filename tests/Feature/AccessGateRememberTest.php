<?php

namespace Tests\Feature;

use App\Support\AccessGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The access code was remembered only for the login session (two hours), so
 * the same browser was asked for it again the next morning. A browser is now
 * remembered, and still checked with the master at each new visit.
 */
class AccessGateRememberTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'atlas.castlitpos.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['castlit.is_master' => false, 'castlit.main_domain' => 'castlitpos.com']);
    }

    private function onSpace(): static
    {
        return $this->withServerVariables(['HTTP_HOST' => self::HOST]);
    }

    private function remembered(string $sub = 'atlas', string $code = 'K7M2QX'): static
    {
        return $this->withCookie(AccessGate::COOKIE, (string) json_encode(['sub' => $sub, 'code' => $code]));
    }

    public function test_the_code_entered_once_remembers_the_browser(): void
    {
        Http::fake(['*/api/v1/public/client-access' => Http::response(['ok' => true])]);

        $response = $this->onSpace()->post('http://'.self::HOST.'/acces', ['code' => 'k7m2qx'])->assertRedirect();

        $response->assertCookie(AccessGate::COOKIE);
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === AccessGate::COOKIE);
        $this->assertGreaterThan(now()->addDays(170)->timestamp, $cookie->getExpiresTime(), 'months, not the session');
    }

    public function test_next_day_without_a_session_the_browser_goes_straight_to_login(): void
    {
        Http::fake(['*/api/v1/public/client-access' => Http::response(['ok' => true])]);

        $this->onSpace()->remembered()->get('http://'.self::HOST.'/connexion')->assertOk();

        // Checked again with the master, as this visitor.
        Http::assertSent(fn ($request) => $request['subdomain'] === 'atlas' && $request['code'] === 'K7M2QX'
            && $request['channel'] === 'web');
    }

    public function test_a_regenerated_code_sends_the_browser_back_to_the_gate(): void
    {
        Http::fake(['*/api/v1/public/client-access' => Http::response(['ok' => false, 'message' => 'Code de vérification invalide.'], 422)]);

        $this->onSpace()->remembered()->get('http://'.self::HOST.'/connexion')
            ->assertRedirect(route('castlit.access.show'))
            ->assertCookieExpired(AccessGate::COOKIE);
    }

    public function test_a_blocked_space_sends_it_back_too(): void
    {
        Http::fake(['*/api/v1/public/client-access' => Http::response(['ok' => false], 403)]);

        $this->onSpace()->remembered()->get('http://'.self::HOST.'/connexion')
            ->assertRedirect(route('castlit.access.show'));
    }

    public function test_an_unreachable_master_does_not_lock_the_shop_out(): void
    {
        Http::fake(['*/api/v1/public/client-access' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->onSpace()->remembered()->get('http://'.self::HOST.'/connexion')->assertOk();

        Http::fake(['*/api/v1/public/client-access' => Http::response(['message' => 'Too Many Attempts.'], 429)]);
        $this->onSpace()->remembered()->get('http://'.self::HOST.'/connexion')->assertOk();
    }

    public function test_a_cookie_from_another_space_opens_nothing(): void
    {
        Http::fake(['*/api/v1/public/client-access' => Http::response(['ok' => true])]);

        $this->onSpace()->remembered('nour')->get('http://'.self::HOST.'/connexion')
            ->assertRedirect(route('castlit.access.show'));
        Http::assertNothingSent();
    }

    public function test_once_cleared_the_master_is_not_asked_again_this_visit(): void
    {
        Http::fake(['*/api/v1/public/client-access' => Http::response(['ok' => true])]);

        $this->onSpace()->remembered()->get('http://'.self::HOST.'/connexion')->assertOk();
        $this->onSpace()->remembered()->get('http://'.self::HOST.'/connexion')->assertOk();

        Http::assertSentCount(1);
    }
}
