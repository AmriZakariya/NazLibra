<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * « Adresse souhaitée » read as a street address: « Atlas Shop » asked for
 * casablanca.castlitpos.com. The field now says it is the space's web link,
 * the street address has its own field, and the team can correct the link
 * before approving.
 */
class SignupLinkFieldTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        Queue::fake();
        Bus::fake();
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Atlas Shop',
            'contact_name' => 'Contact Test',
            'email' => 'contact@example.com',
            'desired_subdomain' => 'atlasshop',
            'currency' => 'MAD',
            'country' => 'MA',
            'city' => 'Casablanca',
        ], $overrides);
    }

    public function test_the_field_says_it_is_a_web_link_not_a_street_address(): void
    {
        $html = $this->get('/?lang=fr')->assertOk()->getContent();

        $this->assertStringContainsString('<label for="desired_subdomain" class="link-label">Lien de votre caisse', $html);
        $this->assertStringContainsString('<span class="subdomain-pre">https://</span>', $html);
        $this->assertStringContainsString('pas l’adresse de la boutique', $html);
        $this->assertStringNotContainsString('Adresse souhaitée', $html);
    }

    public function test_the_link_comes_right_after_the_business_name(): void
    {
        $html = $this->get('/?lang=fr')->assertOk()->getContent();

        $business = strpos($html, 'id="business_name"');
        $link = strpos($html, 'data-link-fld');
        $activity = strpos($html, 'id="activity"');
        $this->assertTrue($business < $link && $link < $activity);
    }

    public function test_a_refused_link_opens_the_field(): void
    {
        $this->post('/inscription', $this->form(['desired_subdomain' => 'x']))->assertRedirect();

        $this->assertMatchesRegularExpression('/<div class="fld link-fld is-open" data-link-fld>/', $this->get('/?lang=fr')->getContent());
    }

    public function test_the_street_address_has_its_own_field_and_is_kept(): void
    {
        $this->get('/?lang=fr')->assertOk()->assertSee('id="address" name="address"', false)->assertSee('Adresse de la boutique');

        $this->post('/inscription', $this->form(['address' => '12 rue Atlas, Maârif']))->assertRedirect(route('castlit.subscribe.success'));

        $this->assertSame('12 rue Atlas, Maârif', Subscription::query()->latest('id')->value('address'));
    }

    public function test_the_street_address_reaches_the_new_space(): void
    {
        $this->artisan('castlit:install-tenant', [
            '--payload' => base64_encode(json_encode([
                'business_name' => 'Atlas Shop', 'contact_name' => 'Contact', 'email' => 'atlas@example.test',
                'country' => 'MA', 'city' => 'Casablanca', 'address' => '12 rue Atlas, Maârif',
            ])),
            '--subdomain' => 'atlasshop',
        ])->assertSuccessful();

        // Where Paramètres › Société reads and saves it.
        $this->assertSame('12 rue Atlas, Maârif', Tenant::firstOrFail()->address);
    }

    public function test_the_team_can_correct_the_link_before_approving(): void
    {
        $this->post('/inscription', $this->form(['desired_subdomain' => 'casablanca']))->assertRedirect();
        $sub = Subscription::query()->latest('id')->firstOrFail();
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)->get(route('castlit.admin.show', $sub))->assertOk()->assertSee('data-link-edit', false);

        $this->actingAs($admin)->post(route('castlit.admin.subdomain', $sub), ['desired_subdomain' => ' Atlas-Shop '])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('atlasshop', $sub->fresh()->desired_subdomain);
    }

    public function test_the_corrected_link_must_be_free_and_not_reserved(): void
    {
        $this->post('/inscription', $this->form(['desired_subdomain' => 'casablanca']))->assertRedirect();
        $this->post('/inscription', $this->form(['desired_subdomain' => 'atlasshop', 'email' => 'other@example.com']))->assertRedirect();
        $sub = Subscription::query()->where('desired_subdomain', 'casablanca')->firstOrFail();
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)->post(route('castlit.admin.subdomain', $sub), ['desired_subdomain' => 'atlasshop'])
            ->assertSessionHasErrors('desired_subdomain');
        $this->actingAs($admin)->post(route('castlit.admin.subdomain', $sub), ['desired_subdomain' => 'www'])
            ->assertSessionHasErrors('desired_subdomain');
        $this->assertSame('casablanca', $sub->fresh()->desired_subdomain);
    }

    public function test_an_approved_request_keeps_its_link(): void
    {
        $this->post('/inscription', $this->form())->assertRedirect();
        $sub = Subscription::query()->latest('id')->firstOrFail();
        $sub->update(['status' => Subscription::STATUS_APPROVED]);

        $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->post(route('castlit.admin.subdomain', $sub), ['desired_subdomain' => 'other'])
            ->assertStatus(422);
        $this->assertSame('atlasshop', $sub->fresh()->desired_subdomain);
    }
}
