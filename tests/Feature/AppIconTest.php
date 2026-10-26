<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AppIcon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * A shop's name and icon in the web app.
 *
 * Every client saw "LibrairePro" in the browser tab and "LP" in the sidebar,
 * and the icon was a blank blue square: it was committed under public/icons,
 * so every update replaced a shop's own icon with it, and the default meant
 * to show the shop's initials looked for a font that was never deployed.
 */
class AppIconTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private string $storage;

    /** @var list<string> */
    private array $publicFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/appicon-'.bin2hex(random_bytes(4));
        mkdir($this->storage.'/app', 0755, true);
        $this->app->useStoragePath($this->storage);

        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->tenant->update(['name' => 'spa paris']);
        $this->user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storage);
        foreach ($this->publicFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function icon(): AppIcon
    {
        return app(AppIcon::class);
    }

    /** A solid [$w]×[$h] PNG of one colour. */
    private function png(int $w, int $h, array $rgb, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, $transparent
            ? imagecolorallocatealpha($image, 0, 0, 0, 127)
            : imagecolorallocate($image, ...$rgb));
        if ($transparent) {
            imagefilledrectangle($image, (int) ($w * 0.25), (int) ($h * 0.25), (int) ($w * 0.75), (int) ($h * 0.75), imagecolorallocate($image, ...$rgb));
        }
        $path = $this->storage.'/src-'.bin2hex(random_bytes(3)).'.png';
        imagepng($image, $path);

        return $path;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgbAt(string $png, int $x, int $y): array
    {
        $c = imagecolorat(imagecreatefrompng($png), $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }

    private function postIcon(array $fields): \Illuminate\Testing\TestResponse
    {
        // The company form as a browser submits it: every field, icon aside.
        return $this->actingAs($this->user)->post(route('settings.company.update'), array_merge([
            'store_name' => $this->tenant->fresh()->name,
            'store_code' => 'ATLAS-CASA',
            'mobile' => '+212 661 00 00 00',
            'email' => 'centre@atlas.test',
            'phone' => '+212 522 00 00 00',
            'cnss' => 'CNSS-123',
            'rc' => 'RC-456',
            'gst_no' => 'ICE-999',
            'vat_no' => 'TVA-20',
            'pan_no' => 'IF-333',
            'store_website' => 'https://atlas.test',
            'show_signature' => '1',
            'signature' => '/signatures/owner.png',
            'bank_details' => 'Banque test - RIB 123',
            'country' => 'Maroc',
            'state' => 'Casablanca-Settat',
            'city' => 'Casablanca',
            'postcode' => '20000',
            'address' => 'Avenue Mohammed V',
            'timezone' => 'Africa/Casablanca',
            'date_format' => 'dd/mm/yyyy',
            'time_format' => '24',
            'currency' => 'MAD',
            'currency_placement' => 'Right',
            'decimals' => '2',
            'qty_decimals' => '2',
            'language_id' => 'fr',
            'round_off' => '1',
            'default_account_id' => 'cash-main',
            'sales_discount' => '5',
            'sales_invoice_format_id' => '3',
            'pos_invoice_format_id' => '1',
            'mrp_column' => '1',
            'change_return' => '1',
            'previous_balance_bit' => '1',
            'number_to_words' => 'Default',
            'sales_invoice_footer_text' => 'Merci pour votre achat.',
            't_and_c_status' => '1',
            't_and_c_status_pos' => '1',
            'invoice_terms' => 'Articles non repris après 48h.',
            'toggle_header_footer' => '1',
            'category_init' => 'CAT',
            'item_init' => 'IT',
            'supplier_init' => 'SUP',
            'purchase_init' => 'PUR',
            'purchase_return_init' => 'PR',
            'customer_init' => 'CUS',
            'sales_init' => 'SAL',
            'sales_return_init' => 'SR',
            'expense_init' => 'EXP',
            'accounts_init' => 'ACC',
            'quotation_init' => 'QUO',
            'money_transfer_init' => 'MT',
            'sales_payment_init' => 'SP',
            'sales_return_payment_init' => 'SRP',
            'purchase_payment_init' => 'PP',
            'purchase_return_payment_init' => 'PRP',
            'expense_payment_init' => 'EP',
            'cust_advance_init' => 'ADV',
        ], $fields));
    }

    // ── Initials ──────────────────────────────────────────────────────────────

    public function test_initials_come_from_the_shop_name(): void
    {
        $this->assertSame('SP', AppIcon::initials('spa paris'));
        $this->assertSame('Z', AppIcon::initials('Zara'));
        $this->assertSame('LD', AppIcon::initials("  l'atelier   du pain "));
        $this->assertSame('C', AppIcon::initials(''));
        $this->assertSame(2, mb_strlen(AppIcon::initials('مكتبة النور')));
    }

    public function test_the_default_icon_actually_draws_the_letters(): void
    {
        $this->assertNotNull(AppIcon::font(), 'the bundled font is missing, so the letters cannot be drawn');

        $path = $this->icon()->path($this->tenant, 192);
        $image = imagecreatefrompng($path);

        $white = 0;
        for ($x = 40; $x < 152; $x += 2) {
            for ($y = 40; $y < 152; $y += 2) {
                $c = imagecolorat($image, $x, $y);
                if ((($c >> 16) & 0xFF) > 240 && (($c >> 8) & 0xFF) > 240 && ($c & 0xFF) > 240) {
                    $white++;
                }
            }
        }

        $this->assertGreaterThan(50, $white, 'the initials icon is a blank square');
        $this->assertSame([192, 192], array_slice(getimagesize($path), 0, 2));
    }

    public function test_a_renamed_shop_gets_a_new_icon_and_a_new_url(): void
    {
        $before = $this->icon()->url($this->tenant, 192);
        $this->tenant->update(['name' => 'Beauté Tanger']);

        $this->assertNotSame($before, $this->icon()->url($this->tenant->fresh(), 192));
    }

    // ── Where it lives ────────────────────────────────────────────────────────

    public function test_icons_live_with_the_shops_data_not_with_the_code(): void
    {
        $path = $this->icon()->path($this->tenant, 32);

        $this->assertStringStartsWith(storage_path('app/branding/'), $path);
        $this->assertStringNotContainsString(public_path(), $path);
    }

    public function test_deploys_never_copy_icons_or_logos_between_shops(): void
    {
        $manage = file_get_contents(base_path('deploy/manage.sh'));
        $provision = file_get_contents(base_path('deploy/provision.sh'));

        // An update must not overwrite what the shop uploaded…
        $this->assertStringContainsString("--exclude='./storage'", $manage);
        $this->assertStringContainsString("--exclude='./public/logos'", $manage);
        // …and a new shop must not start with the master's.
        $this->assertStringContainsString("--exclude='./storage/app/branding'", $provision);
        $this->assertStringContainsString("--exclude='./public/logos'", $provision);
        $this->assertStringContainsString("--exclude='./public/icons'", $provision);
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    public function test_an_uploaded_icon_becomes_the_shops_icon(): void
    {
        $this->postIcon(['app_icon' => $this->upload($this->png(600, 600, [200, 30, 30]))])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $tenant = $this->tenant->fresh();
        $this->assertSame(AppIcon::SOURCE_CUSTOM, $this->icon()->source($tenant));
        $this->assertSame('custom', $tenant->settings['company_profile']['app_icon']);

        // A square, opaque image is already an icon: used edge to edge.
        $path = $this->icon()->path($tenant, 512);
        $this->assertSame([512, 512], array_slice(getimagesize($path), 0, 2));
        $this->assertSame([200, 30, 30], $this->rgbAt($path, 2, 2));
    }

    public function test_a_wide_logo_is_fitted_not_stretched(): void
    {
        $this->icon()->store($this->tenant, $this->png(400, 100, [20, 120, 60]));
        $path = $this->icon()->path($this->tenant, 512);

        $this->assertSame([20, 120, 60], $this->rgbAt($path, 256, 256), 'the logo is centred');
        $this->assertSame([255, 255, 255], $this->rgbAt($path, 256, 60), 'above it is margin, not a stretched logo');
        $this->assertSame([255, 255, 255], $this->rgbAt($path, 10, 256), 'with a margin at the sides too');
    }

    public function test_a_transparent_logo_sits_on_white_rather_than_black(): void
    {
        $this->icon()->store($this->tenant, $this->png(512, 512, [10, 10, 10], transparent: true));
        $path = $this->icon()->path($this->tenant, 192);

        $this->assertSame([255, 255, 255], $this->rgbAt($path, 4, 4));
    }

    public function test_an_unreadable_file_is_refused_and_the_current_icon_kept(): void
    {
        $this->icon()->store($this->tenant, $this->png(300, 300, [200, 30, 30]));
        $fake = $this->storage.'/fake.png';
        file_put_contents($fake, 'not an image');

        $this->postIcon(['app_icon' => new UploadedFile($fake, 'fake.png', 'image/png', null, true)])
            ->assertSessionHasErrors('app_icon');

        $this->assertSame(AppIcon::SOURCE_CUSTOM, $this->icon()->source($this->tenant->fresh()));
    }

    public function test_removing_the_icon_falls_back_to_the_initials(): void
    {
        $this->icon()->store($this->tenant, $this->png(300, 300, [200, 30, 30]));

        $this->postIcon(['remove_app_icon' => '1'])->assertSessionHasNoErrors();

        $tenant = $this->tenant->fresh();
        $this->assertSame(AppIcon::SOURCE_INITIALS, $this->icon()->source($tenant));
        $this->assertNull($tenant->settings['company_profile']['app_icon']);
    }

    public function test_without_an_icon_the_document_logo_is_used(): void
    {
        $dir = public_path('logos');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $logo = $dir.'/test-logo-'.bin2hex(random_bytes(3)).'.png';
        copy($this->png(300, 120, [30, 60, 200]), $logo);
        $this->publicFiles[] = $logo;

        $this->tenant->update(['settings' => array_replace_recursive($this->tenant->settings ?? [], [
            'company_profile' => ['store_logo' => 'logos/'.basename($logo)],
        ])]);

        $tenant = $this->tenant->fresh();
        $this->assertSame(AppIcon::SOURCE_LOGO, $this->icon()->source($tenant));
        $this->assertSame([30, 60, 200], $this->rgbAt($this->icon()->path($tenant, 192), 96, 96));
    }

    // ── Where the shop sees it ────────────────────────────────────────────────

    public function test_the_icon_route_serves_the_shops_icon(): void
    {
        $response = $this->get('/app-icon/192')->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_tab_and_sidebar_name_the_shop_not_the_product(): void
    {
        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<title>\s*Tableau de bord · spa paris\s*<\/title>/', $html);
        $this->assertStringNotContainsString('LibrairePro', $html);
        $this->assertMatchesRegularExpression('/data-sidebar-logo>SP</', $html);
        $this->assertStringNotContainsString('>LP<', $html);
        $this->assertStringNotContainsString('SaaS ', $html);
    }

    public function test_an_uploaded_icon_replaces_the_initials_in_the_sidebar(): void
    {
        $this->icon()->store($this->tenant, $this->png(300, 300, [200, 30, 30]));

        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-sidebar-logo><img src="[^"]*app-icon\/192\?v=/', $html);
    }

    public function test_icon_links_carry_a_version_so_browsers_refresh_them(): void
    {
        $manifest = $this->get('/manifest.json')->assertOk()->json();

        $this->assertSame('spa paris', $manifest['name']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertStringContainsString('?v=', $icon['src']);
        }
    }

    public function test_the_settings_page_previews_the_icon(): void
    {
        $this->actingAs($this->user)
            ->get(route('module', ['module' => 'settings', 'section' => 'company']))
            ->assertOk()
            ->assertSee('Logo de l\'application', false)
            ->assertSee('data-app-icon-card', false)
            ->assertSee('Tableau de bord · spa paris')
            ->assertSee('Logo des documents');
    }
}
