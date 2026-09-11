<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop's logo and colours live on the web and never reached its terminals,
 * so every till in every client looked identical.
 */
class TenantBrandingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
    }

    /** @param array<string, mixed> $settings */
    private function withSettings(array $settings): void
    {
        $this->tenant->update(['settings' => array_replace_recursive($this->tenant->settings ?? [], $settings)]);
    }

    private function branding(): array
    {
        return $this->withToken($this->user->createToken('t')->plainTextToken)
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->getJson('/api/v1/sync/settings')
            ->assertOk()
            ->json('branding');
    }

    public function test_a_shop_with_no_branding_gets_nulls_not_a_missing_key(): void
    {
        // Null means "keep your own palette". A missing key would make the
        // app guess whether the server is simply too old to send any.
        $branding = $this->branding();

        $this->assertArrayHasKey('logo_url', $branding);
        $this->assertArrayHasKey('primary', $branding);
        $this->assertArrayHasKey('accent', $branding);
    }

    public function test_the_logo_is_absolute_so_a_terminal_can_fetch_it(): void
    {
        // A relative path would resolve against the API host in some builds
        // and against nothing at all in others.
        $this->withSettings(['company_profile' => ['store_logo' => 'logos/logo-123.png']]);

        $this->assertSame(asset('logos/logo-123.png'), $this->branding()['logo_url']);
        $this->assertStringStartsWith('http', $this->branding()['logo_url']);
    }

    public function test_no_logo_reads_as_null_rather_than_a_url_to_nowhere(): void
    {
        $this->withSettings(['company_profile' => ['store_logo' => '']]);

        $this->assertNull($this->branding()['logo_url']);
    }

    public function test_the_theme_colours_reach_the_terminal(): void
    {
        $this->withSettings(['theme' => ['primary' => '#8B1E3F', 'accent' => '#c9a227']]);

        $branding = $this->branding();

        $this->assertSame('#8B1E3F', $branding['primary']);
        $this->assertSame('#C9A227', $branding['accent'], 'normalised to upper case');
    }

    public function test_a_malformed_colour_is_refused_rather_than_forwarded(): void
    {
        // The app cannot parse it, and a till that fails to theme is far
        // better than one that fails to start.
        foreach (['red', '#FFF', '#GGGGGG', 'rgb(1,2,3)', '#8B1E3F00', ''] as $bad) {
            $this->withSettings(['theme' => ['primary' => $bad]]);

            $this->assertNull($this->branding()['primary'], "'$bad' should not reach a terminal");
        }
    }

    public function test_surrounding_whitespace_is_forgiven(): void
    {
        // The value arrives from a web form, so a stray space is a typo
        // rather than a different colour.
        $this->withSettings(['theme' => ['primary' => '  #8B1E3F  ']]);

        $this->assertSame('#8B1E3F', $this->branding()['primary']);
    }

    public function test_the_rest_of_the_web_palette_is_deliberately_withheld(): void
    {
        // Background, surface and text are designed for a desktop page.
        // Letting them set a till's colours could produce an unreadable
        // screen mid-service.
        $this->withSettings(['theme' => [
            'primary' => '#8B1E3F',
            'background' => '#FFFFFF',
            'text' => '#FFFFFF',
            'surface_color' => '#FFFFFF',
        ]]);

        $this->assertSame(['logo_url', 'primary', 'accent'], array_keys($this->branding()));
    }
}
