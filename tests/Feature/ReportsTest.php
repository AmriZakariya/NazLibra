<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_profit_loss_and_sales_payments_render(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'reports', 'section' => 'profit-loss']))
            ->assertOk()
            ->assertSee('Rapport de profits et pertes')
            ->assertSee('Profit / perte net');

        $this->get(route('module', ['module' => 'reports', 'section' => 'sales-payments']))
            ->assertOk()
            ->assertSee('Ventes et paiements')
            ->assertSee('N° paiement');
    }

    public function test_report_legacy_routes_redirect_to_new_sections(): void
    {
        $this->seed();

        $this->get('/reports/profit_loss')
            ->assertRedirect('/modules/reports?section=profit-loss');

        $this->get('/reports/sales_summary')
            ->assertRedirect('/modules/reports?section=sales-summary');
    }
}
