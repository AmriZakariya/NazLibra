<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Models\User;
use App\Notifications\TenantProvisionedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * The email a client gets when their space goes live.
 *
 * It used to carry the web address and the login only. The mobile app asks
 * for the client name and a verification code before it shows a login screen,
 * so a client who installed the app was stuck until someone read them the
 * code over the phone.
 */
class WelcomeEmailTest extends TestCase
{
    use ActsAsMasterInstall;
    use RefreshDatabase;

    private function install(array $overrides = []): TenantInstall
    {
        $sub = Subscription::create([
            'business_name' => 'Spa Test',
            'contact_name' => "Contact O'Test",
            'email' => 'owner@example.com',
            'desired_subdomain' => 'spatest',
            'currency' => 'MAD',
            'status' => Subscription::STATUS_APPROVED,
        ]);

        return TenantInstall::create(array_merge([
            'subscription_id' => $sub->id,
            'subdomain' => 'spatest',
            'domain' => 'spatest.castlitpos.com',
            'status' => TenantInstall::STATUS_LIVE,
            'owner_email' => 'owner@example.com',
            'access_code' => 'K7QM3X',
        ], $overrides));
    }

    /** @return array{0: string, 1: string} the HTML and the plain-text bodies */
    private function render(TenantInstall $install, ?string $password = null): array
    {
        $mail = (new TenantProvisionedNotification($install, $install->subscription, $password))
            ->toMail(new AnonymousNotifiable);

        [$html, $text] = $mail->view;

        return [view($html, $mail->viewData)->render(), view($text, $mail->viewData)->render()];
    }

    public function test_both_versions_carry_the_client_name_and_the_code(): void
    {
        [$html, $text] = $this->render($this->install());

        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('spatest', $body);
            $this->assertStringContainsString('K7QM3X', $body);
            $this->assertStringContainsString('Code de vérification', $body);
            $this->assertStringContainsString('Nom du client', $body);
            $this->assertStringContainsString('owner@example.com', $body);
            $this->assertStringContainsString('https://spatest.castlitpos.com', $body);
        }

        // In the highlighted box, not just the inbox preview line.
        $this->assertMatchesRegularExpression('/class="code"[^>]*>K7QM3X</', $html);
    }

    public function test_it_says_how_to_get_the_app(): void
    {
        config(['castlit.brand.play_store' => 'https://play.example/castlit']);

        [$html, $text] = $this->render($this->install());

        $this->assertStringContainsString('href="https://play.example/castlit"', $html);
        $this->assertStringContainsString('https://play.example/castlit', $text);
        $this->assertStringContainsString("J'ai déjà un compte", $text);
    }

    public function test_no_store_link_is_drawn_when_none_is_configured(): void
    {
        config(['castlit.brand.play_store' => '', 'castlit.brand.app_store' => '']);

        [$html] = $this->render($this->install());

        $this->assertStringNotContainsString('Google Play', $html);
        $this->assertStringNotContainsString('App Store', $html);
    }

    public function test_a_space_without_a_code_gets_one_rather_than_a_blank(): void
    {
        $install = $this->install(['access_code' => null]);

        [$html] = $this->render($install);

        $code = $install->fresh()->access_code;
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', (string) $code);
        $this->assertStringContainsString($code, $html);
    }

    public function test_the_provisional_password_is_shown_only_when_there_is_one(): void
    {
        $install = $this->install();

        [$html, $text] = $this->render($install, 'Tmp-Pass-42');
        $this->assertStringContainsString('Tmp-Pass-42', $html);
        $this->assertStringContainsString('Tmp-Pass-42', $text);

        [$html, $text] = $this->render($install);
        $this->assertStringContainsString('Mot de passe oublié', $html);
        $this->assertStringContainsString('Mot de passe oublié', $text);
    }

    public function test_the_text_version_is_not_html_escaped(): void
    {
        [, $text] = $this->render($this->install());

        $this->assertStringContainsString("Contact O'Test", $text);
        $this->assertStringNotContainsString('&#039;', $text);
    }

    public function test_the_html_version_escapes_what_the_client_typed(): void
    {
        $install = $this->install();
        $install->subscription->forceFill(['business_name' => '<b>Spa</b>'])->save();

        [$html] = $this->render($install->fresh());

        $this->assertStringNotContainsString('<b>Spa</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Spa&lt;/b&gt;', $html);
    }

    public function test_resending_the_access_sends_the_code_too(): void
    {
        Notification::fake();
        $install = $this->install();

        $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->post('/castlit-admin/'.$install->subscription_id.'/renvoyer-acces')
            ->assertSessionHas('success');

        Notification::assertSentOnDemand(
            TenantProvisionedNotification::class,
            fn (TenantProvisionedNotification $n): bool => $n->details()['code'] === 'K7QM3X'
                && $n->details()['clientName'] === 'spatest',
        );
    }
}
