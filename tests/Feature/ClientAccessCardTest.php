<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * The client's page gathers what they need to get in: the name and code the
 * app asks for, and the logins of the two accounts the platform created.
 */
class ClientAccessCardTest extends TestCase
{
    use ActsAsMasterInstall;
    use RefreshDatabase;

    private function subscription(array $install = []): Subscription
    {
        $s = Subscription::create([
            'business_name' => 'Spa Paris', 'contact_name' => 'Majda', 'email' => 'majda@example.com',
            'desired_subdomain' => 'spaparis', 'currency' => 'EUR', 'status' => Subscription::STATUS_APPROVED,
        ]);
        if ($install !== null) {
            TenantInstall::create(array_merge([
                'subscription_id' => $s->id, 'subdomain' => 'spaparis', 'domain' => 'spaparis.castlitpos.com',
                'docroot' => '/tmp/none', 'status' => TenantInstall::STATUS_LIVE, 'access_code' => 'KNS32K',
                'owner_email' => 'majda@example.com', 'owner_password' => 'Own3r-Secret',
                'support_email' => 'support@spaparis.castlitpos.com', 'support_password' => 'Supp0rt-Secret',
            ], $install));
        }

        return $s;
    }

    private function page(Subscription $s)
    {
        return $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->get('/castlit-admin/'.$s->id)
            ->assertOk();
    }

    public function test_the_card_shows_the_code_the_name_and_both_accounts(): void
    {
        $page = $this->page($this->subscription());

        $page->assertSee('data-access-card', false)
            ->assertSee('<code class="secret code-big" data-access-code>KNS32K</code>', false)
            ->assertSee('<code class="secret" data-client-name>spaparis</code>', false)
            ->assertSee('Compte principal (propriétaire)')
            ->assertSee('majda@example.com')
            ->assertSee('Compte administrateur')
            ->assertSee('support@spaparis.castlitpos.com')
            // Passwords are there to reveal, masked until asked for.
            ->assertSee('data-secret="Own3r-Secret"', false)
            ->assertSee('data-secret="Supp0rt-Secret"', false)
            ->assertDontSee('data-owner-missing', false);

        // "Tout copier" carries the whole sheet, ready to paste.
        $html = $page->getContent();
        $this->assertStringContainsString('Code d’accès : KNS32K', html_entity_decode($html));
        $this->assertStringContainsString('Mot de passe : Supp0rt-Secret', html_entity_decode($html));
        // Each password appears once in the page as a row, not twice.
        $this->assertSame(1, substr_count($html, 'data-secret="Own3r-Secret"'));
    }

    public function test_a_password_never_kept_says_so_and_points_to_the_reset(): void
    {
        $page = $this->page($this->subscription(['owner_password' => null]));

        $page->assertSee('data-owner-missing', false)
            ->assertSee('Non conservé')
            ->assertSee('Nouveau mot de passe propriétaire');
    }

    public function test_a_space_without_a_code_shows_a_dash_and_creates_none(): void
    {
        $s = $this->subscription(['access_code' => null]);

        $this->page($s)->assertDontSee('data-access-code', false)
            ->assertSee('créé au prochain envoi des accès');

        $this->assertNull($s->install->fresh()->access_code);
    }

    public function test_a_request_without_a_space_has_no_card(): void
    {
        $s = Subscription::create([
            'business_name' => 'Spa Lyon', 'contact_name' => 'Majda', 'email' => 'lyon@example.com',
            'desired_subdomain' => 'spalyon', 'currency' => 'EUR',
        ]);

        $this->page($s)->assertDontSee('data-access-card', false);
    }
}
