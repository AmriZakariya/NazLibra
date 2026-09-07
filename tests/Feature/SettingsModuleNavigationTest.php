<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\PrinterGroup;
use App\Models\VirtualDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SettingsModuleNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_overview_renders_grouped_configuration_center(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'settings']))
            ->assertOk()
            ->assertDontSee('Centre de configuration')
            ->assertSee('Store & activité')
            ->assertSee('Compte & équipe')
            ->assertSee('Terminaux virtuels')
            ->assertSee('Appareils')
            ->assertSee('Référentiels & communication');
    }

    public function test_virtual_devices_settings_section_renders_device_status(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        VirtualDevice::create([
            'tenant_id' => $tenant->id,
            'name' => 'Caisse web test',
            'code' => 'web-test',
            'type' => 'computer',
            'is_active' => true,
        ]);

        $this->get(route('module', ['module' => 'settings', 'section' => 'virtual-devices']))
            ->assertOk()
            ->assertSee('Appareils virtuels')
            ->assertSee('Chaque navigateur, caisse ou mobile doit choisir un terminal une seule fois')
            ->assertSee('Caisse web test')
            ->assertSee('Ajouter / modifier');
    }

    public function test_navbar_command_search_indexes_printer_groups_settings_section(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'settings', 'section' => 'overview']))
            ->assertOk()
            ->assertSee('Groupes d’impression')
            ->assertSee('section=printer-groups', false)
            ->assertSee('data-command-aliases=', false)
            ->assertSee('groupe groupes impression', false);
    }

    public function test_printer_groups_show_even_before_any_printer_is_synced(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        PrinterGroup::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'virtual_device_id' => null,
            'name' => 'Cuisine principale',
            'is_receipt_group' => false,
            'print_mode' => 'primary_fallback',
        ]);

        $this->get(route('module', ['module' => 'settings', 'section' => 'printer-groups']))
            ->assertOk()
            ->assertSee('Cuisine principale')
            ->assertSee('Aucune imprimante liée.')
            ->assertDontSee('Aucune imprimante synchronisée');
    }

    public function test_store_section_shows_online_store_status_and_public_url(): void
    {
        $this->seed();

        // Enabling online ordering needs three separate things to be true, so
        // the settings card states them together with the public URL.
        $response = $this->get(route('module', ['module' => 'settings', 'section' => 'store']))
            ->assertOk()
            ->assertSee('Boutique en ligne')
            ->assertSee('Adresse publique')
            ->assertSee(route('storefront.index'))
            ->assertSee('Module « Commandes en ligne » activé', false)
            ->assertSee('Boutique publique activée');

        // The seeded tenant has the module on, the shop on and articles flagged
        // visible, so all three conditions hold and the card reports it live.
        $response->assertSee('En ligne')
            ->assertSee('visibles en ligne');
    }

    public function test_store_section_flags_online_store_as_incomplete_without_visible_items(): void
    {
        $this->seed();

        // A shop that is "enabled" but has no visible article renders an empty
        // storefront and looks broken, so the card must not call it live.
        \App\Models\Item::query()->update(['online_store_visible' => false]);

        $this->get(route('module', ['module' => 'settings', 'section' => 'store']))
            ->assertOk()
            ->assertSee('Incomplète', false)
            ->assertSee('0 article visible en ligne');
    }
}
