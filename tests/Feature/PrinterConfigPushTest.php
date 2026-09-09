<?php

namespace Tests\Feature;

use App\Models\Printer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A terminal pushing its printer configuration.
 *
 * The PAX built-in printer was refused outright with a 422: connection_type
 * was validated against ['tcp','bluetooth','usb'] and the column was a MySQL
 * ENUM of the same three. Then the app sent Dart's camelCase enum name,
 * "paxInternal", where the wire format is snake_case everywhere else — and the
 * whole config was rejected again, so the terminal silently lost its printer.
 */
class PrinterConfigPushTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private VirtualDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::first();
        $this->user = User::first();
        $this->device = VirtualDevice::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Caisse comptoir',
            'code' => 'POS-01',
            'type' => 'pos',
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function push(array $overrides = [])
    {
        return $this->withToken($this->user->createToken('t')->plainTextToken)
            ->withHeader('X-Virtual-Device-Id', (string) $this->device->id)
            ->postJson('/api/v1/printers/push-config', [
                'printers' => [
                    array_merge([
                        'id' => 'b160b89e-61d4-48fe-bcfa-b465a04d328a',
                        'name' => 'Imprimante intégrée',
                        'role' => 'receipt',
                        'connection_type' => 'pax_internal',
                        'address' => null,
                        'port' => 9100,
                        'paper_width' => 58,
                        'encoding' => 'CP437',
                        'cut_paper' => true,
                        'copies' => 1,
                        'auto_print_on_checkout' => false,
                    ], $overrides),
                ],
            ]);
    }

    public function test_the_pax_built_in_printer_is_accepted(): void
    {
        $this->push()->assertOk();

        $this->assertDatabaseHas('printers', [
            'name' => 'Imprimante intégrée',
            'connection_type' => 'pax_internal',
            'role' => 'receipt',
            'paper_width' => 58,
        ]);
    }

    public function test_the_camel_case_spelling_is_accepted_and_normalised(): void
    {
        // What an app build already in the field sends. Refusing it would make
        // that terminal lose its printer setup on every sync.
        $this->push(['connection_type' => 'paxInternal'])->assertOk();

        // Stored canonically, so the database only ever holds one spelling.
        $this->assertDatabaseHas('printers', [
            'connection_type' => 'pax_internal',
        ]);
        $this->assertDatabaseMissing('printers', [
            'connection_type' => 'paxInternal',
        ]);
    }

    public function test_a_kitchen_printer_keeps_its_role(): void
    {
        $this->push(['role' => 'kitchen'])->assertOk();

        $this->assertDatabaseHas('printers', ['role' => 'kitchen']);
    }

    public function test_a_printer_with_no_role_becomes_a_receipt_printer(): void
    {
        // An app build that predates the column. A single printer in a shop
        // prints receipts.
        $payload = [
            'id' => 'c260b89e-61d4-48fe-bcfa-b465a04d328a',
            'name' => 'Sans rôle',
            'connection_type' => 'tcp',
            'address' => '192.168.1.50',
        ];

        $this->withToken($this->user->createToken('t')->plainTextToken)
            ->withHeader('X-Virtual-Device-Id', (string) $this->device->id)
            ->postJson('/api/v1/printers/push-config', ['printers' => [$payload]])
            ->assertOk();

        $this->assertDatabaseHas('printers', [
            'name' => 'Sans rôle',
            'role' => 'receipt',
        ]);
    }

    public function test_the_other_transports_still_work(): void
    {
        foreach (['tcp', 'bluetooth', 'usb'] as $type) {
            $this->push(['connection_type' => $type])->assertOk();
        }
    }

    public function test_a_genuinely_unknown_transport_is_still_refused(): void
    {
        // Tolerating a known alias must not turn into accepting anything.
        $this->push(['connection_type' => 'carrier_pigeon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('printers.0.connection_type');
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $this->push(['role' => 'sommelier'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('printers.0.role');
    }

    public function test_the_config_comes_back_with_the_role_and_transport(): void
    {
        // The terminal pulls this back after a reinstall; if the role did not
        // survive, a receipt printer would return as something else and
        // silently stop printing receipts.
        $response = $this->push()->assertOk();

        $printers = $response->json('printers');
        $this->assertNotEmpty($printers);
        $this->assertSame('pax_internal', $printers[0]['connection_type']);
        $this->assertSame('receipt', $printers[0]['role']);
    }

    public function test_pushing_twice_leaves_one_printer(): void
    {
        $this->push()->assertOk();
        $this->push()->assertOk();

        $this->assertSame(1, Printer::withTrashed()->count());
    }
}
