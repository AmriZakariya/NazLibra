<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Imprimer / PDF » on a report printed the whole app on a receipt-width
 * page. Paper gets A4, a header saying what it is, and the report alone.
 */
class ReportPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_printed_report_says_what_whom_and_when(): void
    {
        $this->seed();
        $tenant = Tenant::first();
        $client = Contact::where('kind', 'client')->firstOrFail();
        Sale::create([
            'tenant_id' => $tenant->id, 'contact_id' => $client->id, 'number' => 'BL-PRINT-1', 'status' => 'paid',
            'payment_method' => 'cash', 'total_amount' => 6.5, 'subtotal_amount' => 6.5, 'sold_at' => '2026-11-08 11:19:00',
        ]);

        $page = $this->actingAs(User::first())->get(route('module', [
            'module' => 'reports', 'section' => 'profit-loss',
            'from' => '2026-11-01', 'to' => '2026-11-09', 'customer_id' => $client->id,
        ]))->assertOk();

        $page->assertSee('data-report-print-head', false)
            ->assertSee('Période : du 01/11/2026', false)
            ->assertSee('au 09/11/2026', false)
            ->assertSee('Client : '.$client->name, false)
            ->assertSee('report-kpis', false)
            // Menus, filters and buttons stay on screen.
            ->assertSee('app-collapsible-menu mt-6 print-hide', false);

        if ($dir = getenv('PRINT_CHECK_DIR')) {
            file_put_contents($dir.'/report.html', $page->getContent());
        }
    }

    public function test_paper_is_a4_and_only_a_receipt_asks_for_80mm(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/@page\s*\{\s*size:\s*A4 portrait;/', $css);
        $this->assertStringNotContainsString('size: 80mm auto', $css, 'the receipt format lives in app.js, for the receipt print only');

        $js = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("'@page { size: 80mm auto; margin: 0; }'", $js);
    }
}
