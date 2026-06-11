<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Loan;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $tenant = Tenant::updateOrCreate(
                ['slug' => 'librairie-atlas'],
                [
                    'name' => 'Librairie Atlas',
                    'mode' => 'hybrid',
                    'plan' => 'pro',
                    'phone' => '+212 522 44 10 20',
                    'email' => 'contact@librairie-atlas.ma',
                    'ice' => '001982736000089',
                    'address' => '24 Avenue Mohammed V, Casablanca',
                    'settings' => [
                        'receipt_header' => 'Librairie Atlas - Casablanca',
                        'tax_rate' => 0.2,
                        'languages' => ['fr', 'ar'],
                        'pos_offline_cache_days' => 14,
                        'theme_preset' => 'default',
                        'theme' => [
                            'primary' => '#3157D5',
                            'accent' => '#0F9F8A',
                            'success' => '#16A34A',
                            'warning' => '#D97706',
                            'danger' => '#E11D48',
                            'info' => '#0284C7',
                            'background' => '#F4F7FB',
                            'surface_color' => '#FFFFFF',
                            'surface_muted' => '#EEF3F8',
                            'text' => '#101828',
                            'muted' => '#64748B',
                            'border' => '#D7DEE9',
                            'font_scale' => '1',
                            'density' => 'comfortable',
                            'radius' => '12',
                        ],
                    ],
                ],
            );

            $owner = User::factory()->create([
                'current_tenant_id' => $tenant->id,
                'name' => 'Amina El Idrissi',
                'email' => 'amina@librairie-atlas.ma',
                'password' => 'password',
                'phone' => '+212 661 22 33 44',
                'avatar_color' => '#4F46E5',
            ]);

            $cashier = User::factory()->create([
                'current_tenant_id' => $tenant->id,
                'name' => 'Youssef Benali',
                'email' => 'caisse@librairie-atlas.ma',
                'password' => 'password',
                'phone' => '+212 662 90 40 11',
                'avatar_color' => '#0EA5E9',
            ]);

            $tenant->users()->syncWithoutDetaching([
                $owner->id => ['role' => 'owner', 'permissions' => json_encode(['*'])],
                $cashier->id => ['role' => 'cashier', 'permissions' => json_encode(['sales.view', 'sales.create', 'items.view'])],
            ]);

            foreach ([
                ['Owner', 'owner', ['*']],
                ['Manager', 'manager', ['dashboard.view', 'items.*', 'sales.*', 'purchases.*', 'reports.view']],
                ['Caissier', 'cashier', ['sales.view', 'sales.create', 'contacts.create']],
                ['Bibliothécaire', 'librarian', ['loans.*', 'contacts.view', 'items.view']],
                ['Stockiste', 'stockist', ['items.*', 'purchases.view', 'purchases.receive']],
            ] as [$name, $key, $permissions]) {
                DB::table('roles')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'key' => $key],
                    ['name' => $name, 'permissions' => json_encode($permissions), 'created_at' => now(), 'updated_at' => now()],
                );
            }

            $categories = collect([
                ['Scolaire', 'scolaire', '#4F46E5', 'graduation-cap'],
                ['Mathématiques', 'mathematiques', '#0891B2', 'calculator', 'scolaire'],
                ['Français', 'francais', '#DB2777', 'languages', 'scolaire'],
                ['Papeterie', 'papeterie', '#D97706', 'notebook-tabs'],
                ['Romans', 'romans', '#16A34A', 'book-open'],
                ['Services', 'services', '#64748B', 'receipt'],
            ])->mapWithKeys(function (array $data) use ($tenant) {
                $parent = $data[4] ?? null;
                $category = Category::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'slug' => $data[1]],
                    [
                        'parent_id' => $parent ? Category::where('tenant_id', $tenant->id)->where('slug', $parent)->value('id') : null,
                        'name' => $data[0],
                        'icon' => $data[3],
                        'color' => $data[2],
                        'loan_duration_days' => $data[1] === 'romans' ? 21 : 14,
                        'daily_fine_amount' => $data[1] === 'romans' ? 1.50 : 2.00,
                    ],
                );

                return [$data[1] => $category];
            });

            $brands = collect(['Hatier', 'Hachette', 'Nathan', 'Al Manahil', 'Clairefontaine'])
                ->mapWithKeys(fn (string $name) => [
                    $name => Brand::updateOrCreate(['tenant_id' => $tenant->id, 'name' => $name], ['type' => $name === 'Clairefontaine' ? 'brand' : 'publisher']),
                ]);

            $units = collect([
                ['Pièce', 'Unité de vente standard'],
                ['Pack', 'Lot composé de plusieurs pièces'],
                ['Service', 'Prestation non physique'],
            ])->mapWithKeys(fn (array $row) => [
                $row[0] => Unit::updateOrCreate(['tenant_id' => $tenant->id, 'name' => $row[0]], ['description' => $row[1]]),
            ]);

            $taxes = collect([
                ['TVA 20%', 20],
                ['Sans TVA', 0],
                ['TVA 7%', 7, false],
            ])->mapWithKeys(fn (array $row) => [
                $row[0] => Tax::updateOrCreate(['tenant_id' => $tenant->id, 'name' => $row[0]], ['rate' => $row[1], 'is_active' => $row[2] ?? true]),
            ]);

            $clients = collect([
                ['Sara Berrada', 'individual', '+212 661 10 20 30', ['VIP'], 120],
                ['École Al Massira', 'school', '+212 522 88 40 10', ['school', 'wholesale'], 2500],
                ['Nabil Amrani', 'individual', '+212 667 93 18 22', ['membre'], 0],
            ])->map(fn (array $client) => Contact::create([
                'tenant_id' => $tenant->id,
                'kind' => 'client',
                'name' => $client[0],
                'client_type' => $client[1],
                'phone' => $client[2],
                'email' => str($client[0])->ascii()->lower()->replace(' ', '.').'@example.ma',
                'tags' => $client[3],
                'advance_balance' => $client[4],
                'membership_expires_at' => now()->addMonths(8),
            ]));

            $supplier = Contact::create([
                'tenant_id' => $tenant->id,
                'kind' => 'supplier',
                'name' => 'Distribution Maghreb Livre',
                'client_type' => 'company',
                'phone' => '+212 522 70 60 50',
                'email' => 'commande@dml.ma',
                'ice' => '002110945000031',
                'outstanding_balance' => 18400,
                'tags' => ['priority', 'rentrée'],
            ]);

            $items = collect([
                ['Manuel Mathématiques 6e AEP', '9789954711132', 'MATH-6AEP', 'mathematiques', 'Al Manahil', 62, 89, 18, 8, 'Rayon A-02', 'Scolaire'],
                ['Cahier 96 pages grands carreaux', null, 'PAP-C96-GC', 'papeterie', 'Clairefontaine', 4.5, 8, 240, 40, 'Îlot rentrée', 'Papeterie'],
                ['Le Petit Prince', '9782070612758', 'ROM-PP-001', 'romans', 'Hachette', 38, 65, 4, 5, 'Rayon R-01', 'Antoine de Saint-Exupéry'],
                ['Bescherelle Conjugaison', '9782401052355', 'FR-BES-001', 'francais', 'Hatier', 42, 72, 9, 6, 'Rayon F-03', 'Référence'],
                ['Service impression A4 noir/blanc', null, 'SRV-PRINT-A4', 'services', null, 0, 1, 9999, 0, 'Caisse', 'Service'],
            ])->map(function (array $row, int $index) use ($tenant, $categories, $brands, $units, $taxes) {
                $isService = $row[3] === 'services';

                return Item::create([
                    'tenant_id' => $tenant->id,
                    'category_id' => $categories[$row[3]]->id,
                    'brand_id' => $row[4] ? $brands[$row[4]]->id : null,
                    'unit_id' => $isService ? $units['Service']->id : $units['Pièce']->id,
                    'tax_id' => $taxes['TVA 20%']->id,
                    'type' => $isService ? 'service' : ($row[3] === 'papeterie' ? 'supply' : 'book'),
                    'item_code' => 'IT'.now()->format('ym').str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'item_group' => 'Single',
                    'title' => $row[0],
                    'isbn' => $row[1],
                    'barcode' => $row[2],
                    'sku' => $row[2],
                    'hsn' => $row[3] === 'papeterie' ? 'PAP' : null,
                    'author' => $row[10],
                    'editor' => $row[4],
                    'edition_year' => $row[3] === 'services' ? null : '2026',
                    'theme' => $row[3] === 'services' ? null : $row[3],
                    'description' => 'Article prêt pour vente rapide, import CSV, étiquetage et suivi de stock.',
                    'price' => $row[5],
                    'tax_type' => 'Inclusive',
                    'purchase_price' => $row[5],
                    'sale_price' => $row[6],
                    'reseller_sale_price' => round($row[6] * 0.9, 2),
                    'mrp' => $row[6],
                    'warehouse' => 'Oubra store',
                    'opening_stock' => $row[7],
                    'stock_quantity' => $row[7],
                    'min_stock_threshold' => $row[8],
                    'location' => $row[9],
                    'metadata' => ['isbn_fetch_source' => $row[1] ? 'cache-google-books' : null],
                ]);
            });

            ItemVariant::create([
                'item_id' => $items[1]->id,
                'tenant_id' => $tenant->id,
                'name' => 'Couverture bleue',
                'attributes' => ['couleur' => 'Bleu', 'format' => 'A4'],
                'barcode' => 'PAP-C96-GC-BLU',
                'purchase_price' => 4.50,
                'sale_price' => 8.00,
                'stock_quantity' => 80,
            ]);

            $salesData = [
                [0, $clients[0]->id, 'cash', 312.00],
                [0, $clients[1]->id, 'mixed', 1870.00],
                [1, null, 'card', 148.00],
                [2, $clients[2]->id, 'advance', 65.00],
                [7, $clients[1]->id, 'transfer', 4200.00],
            ];

            foreach ($salesData as $index => [$daysAgo, $clientId, $method, $total]) {
                $sale = Sale::create([
                    'tenant_id' => $tenant->id,
                    'contact_id' => $clientId,
                    'user_id' => $cashier->id,
                    'number' => 'VTE-'.now()->format('ymd').'-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'status' => 'paid',
                    'payment_method' => $method,
                    'subtotal_amount' => $total,
                    'discount_amount' => $index === 1 ? 80 : 0,
                    'tax_amount' => round($total * 0.2 / 1.2, 2),
                    'total_amount' => $total,
                    'sold_at' => Carbon::now()->subDays($daysAgo)->setTime(10 + $index, 15),
                    'metadata' => ['receipt_channel' => $index % 2 === 0 ? 'print' : 'whatsapp'],
                ]);

                $sale->items()->create([
                    'item_id' => $items[$index % $items->count()]->id,
                    'name' => $items[$index % $items->count()]->title,
                    'quantity' => max(1, (int) floor($total / max(1, $items[$index % $items->count()]->sale_price))),
                    'unit_price' => $items[$index % $items->count()]->sale_price,
                    'total_price' => $total,
                ]);
            }

            $purchase = Purchase::create([
                'tenant_id' => $tenant->id,
                'supplier_id' => $supplier->id,
                'number' => 'ACH-'.now()->format('ymd').'-0001',
                'status' => 'ordered',
                'total_amount' => 12680,
                'ordered_at' => now()->subDays(3),
                'expected_at' => now()->addDays(4),
                'metadata' => ['priority' => 'rentrée scolaire'],
            ]);

            $purchase->items()->createMany([
                ['item_id' => $items[0]->id, 'quantity_ordered' => 120, 'quantity_received' => 0, 'unit_cost' => 62],
                ['item_id' => $items[3]->id, 'quantity_ordered' => 80, 'quantity_received' => 0, 'unit_cost' => 42],
            ]);

            Loan::create([
                'tenant_id' => $tenant->id,
                'member_id' => $clients[2]->id,
                'item_id' => $items[2]->id,
                'user_id' => $owner->id,
                'status' => 'borrowed',
                'loaned_at' => now()->subDays(18),
                'due_at' => now()->addDays(3),
            ]);

            Loan::create([
                'tenant_id' => $tenant->id,
                'member_id' => $clients[0]->id,
                'item_id' => $items[3]->id,
                'user_id' => $owner->id,
                'status' => 'overdue',
                'loaned_at' => now()->subDays(23),
                'due_at' => now()->subDays(5),
                'fine_amount' => 10,
            ]);

            DB::table('expenses')->insert([
                ['tenant_id' => $tenant->id, 'category' => 'Loyer', 'label' => 'Loyer magasin mai', 'amount' => 8500, 'spent_at' => now()->startOfMonth(), 'created_at' => now(), 'updated_at' => now()],
                ['tenant_id' => $tenant->id, 'category' => 'Marketing', 'label' => 'Affiches rentrée scolaire', 'amount' => 1200, 'spent_at' => now()->subDays(6), 'created_at' => now(), 'updated_at' => now()],
            ]);

            DB::table('coupons')->insert([
                'tenant_id' => $tenant->id,
                'code' => 'RENTREE10',
                'type' => 'percent',
                'value' => 10,
                'expires_at' => now()->addMonths(4)->toDateString(),
                'uses_count' => 14,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                ['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'action' => 'tenant.onboarded', 'subject_type' => Tenant::class, 'subject_id' => $tenant->id, 'properties' => json_encode(['plan' => 'pro']), 'created_at' => now()->subDays(9), 'updated_at' => now()->subDays(9)],
                ['tenant_id' => $tenant->id, 'user_id' => $cashier->id, 'action' => 'sale.created', 'subject_type' => Sale::class, 'subject_id' => 1, 'properties' => json_encode(['channel' => 'pos']), 'created_at' => now()->subMinutes(45), 'updated_at' => now()->subMinutes(45)],
            ]);
        });
    }
}
