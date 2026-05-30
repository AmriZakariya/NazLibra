<?php

namespace Tests\Feature;

use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_and_unit_status_can_be_managed_from_settings(): void
    {
        $this->seed();

        $this->post(route('catalog.taxes.store'), [
            'name' => 'TVA 14%',
            'rate' => 14,
            'description' => 'Taux réduit',
            'is_active' => '1',
        ])->assertRedirect();

        $tax = Tax::where('name', 'TVA 14%')->firstOrFail();

        $this->put(route('catalog.taxes.update', $tax), [
            'name' => 'TVA 14%',
            'rate' => 14,
            'description' => 'Taux réduit inactif',
        ])->assertRedirect();

        $this->assertFalse($tax->refresh()->is_active);

        $this->post(route('catalog.units.store'), [
            'name' => 'Boîte',
            'description' => 'Conditionnement',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertTrue(Unit::where('name', 'Boîte')->firstOrFail()->is_active);
    }

    public function test_settings_payment_country_state_and_tax_group_records_are_persisted(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $this->post(route('settings.payment-types.store'), [
            'name' => 'Bon école',
            'code' => 'school_voucher',
            'description' => 'Paiement par bon',
            'is_active' => '1',
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'payment-types']));

        $this->post(route('settings.countries.store'), [
            'name' => 'France',
            'code' => 'FR',
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'countries']));

        $this->post(route('settings.states.store'), [
            'name' => 'Île-de-France',
            'code' => 'IDF',
            'country' => 'France',
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'states']));

        $this->post(route('settings.tax-groups.store'), [
            'name' => 'TVA mixte',
            'rate' => 20,
            'secondary_taxes' => 'TVA 7%',
            'is_active' => '1',
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'taxes']));

        $settings = $tenant->refresh()->settings;

        $this->assertSame('Bon école', collect($settings['payment_types'])->firstWhere('key', 'school_voucher')['name']);
        $this->assertSame('France', collect($settings['countries'])->firstWhere('key', 'FR')['name']);
        $this->assertSame('Île-de-France', collect($settings['states'])->firstWhere('key', 'IDF')['name']);
        $this->assertSame('TVA mixte', collect($settings['tax_groups'])->firstWhere('name', 'TVA mixte')['name']);
    }
}
