<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Location;
use App\Models\Role;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SetupController extends Controller
{
    // ─── Guards ───────────────────────────────────────────────────────────────

    private function guardNotConfigured(): void
    {
        if (Tenant::exists()) {
            abort(404);
        }
    }

    private function guardAuthorized(): void
    {
        $this->guardNotConfigured();

        if (! session('setup_authorized')) {
            redirect(route('setup.index'))->send();
            exit;
        }
    }

    // ─── Step 1: Secret ───────────────────────────────────────────────────────

    public function index(): View|RedirectResponse
    {
        $this->guardNotConfigured();

        if (session('setup_authorized')) {
            return redirect(route('setup.store'));
        }

        return view('setup', ['step' => 'secret']);
    }

    public function storeSecret(Request $request): RedirectResponse
    {
        $this->guardNotConfigured();

        $request->validate(['secret' => ['required', 'string']]);

        $expected = env('SETUP_SECRET');

        if (! $expected || $request->input('secret') !== $expected) {
            return back()->withErrors(['secret' => 'Code secret incorrect.']);
        }

        session(['setup_authorized' => true]);

        return redirect(route('setup.store'));
    }

    // ─── Step 2: Store info ───────────────────────────────────────────────────

    public function showStore(): View
    {
        $this->guardAuthorized();

        return view('setup', ['step' => 'store', 'data' => session('setup.store', [])]);
    }

    public function storeStore(Request $request): RedirectResponse
    {
        $this->guardAuthorized();

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:100'],
            'email'         => ['nullable', 'email', 'max:100'],
            'phone'         => ['nullable', 'string', 'max:30'],
            'address'       => ['nullable', 'string', 'max:200'],
            'currency'      => ['required', 'string', 'max:5'],
            'timezone'      => ['required', 'string', 'max:60'],
            'language'      => ['required', 'in:fr,ar'],
            'business_mode' => ['required', 'in:bookstore,retail,service'],
        ]);

        session(['setup.store' => $data]);

        return redirect(route('setup.owner'));
    }

    // ─── Step 3: Owner account ────────────────────────────────────────────────

    public function showOwner(): View
    {
        $this->guardAuthorized();

        return view('setup', ['step' => 'owner', 'data' => session('setup.owner', [])]);
    }

    public function storeOwner(Request $request): RedirectResponse
    {
        $this->guardAuthorized();

        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:100'],
            'email'                 => ['required', 'email', 'max:100'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        session(['setup.owner' => ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]]);

        return redirect(route('setup.locations'));
    }

    // ─── Step 4: Locations ────────────────────────────────────────────────────

    public function showLocations(): View
    {
        $this->guardAuthorized();

        return view('setup', [
            'step' => 'locations',
            'data' => session('setup.locations', [['name' => '', 'address' => '', 'phone' => '']]),
        ]);
    }

    public function storeLocations(Request $request): RedirectResponse
    {
        $this->guardAuthorized();

        $data = $request->validate([
            'locations'          => ['required', 'array', 'min:1'],
            'locations.*.name'   => ['required', 'string', 'max:100'],
            'locations.*.address'=> ['nullable', 'string', 'max:200'],
            'locations.*.phone'  => ['nullable', 'string', 'max:30'],
        ]);

        session(['setup.locations' => $data['locations']]);

        return redirect(route('setup.categories'));
    }

    // ─── Step 5: Categories ───────────────────────────────────────────────────

    public function showCategories(): View
    {
        $this->guardAuthorized();

        return view('setup', ['step' => 'categories', 'data' => session('setup.categories', [])]);
    }

    public function storeCategories(Request $request): RedirectResponse
    {
        $this->guardAuthorized();

        $data = $request->validate([
            'categories'   => ['nullable', 'array'],
            'categories.*' => ['nullable', 'string', 'max:100'],
        ]);

        $cats = array_values(array_filter(array_map('trim', $data['categories'] ?? [])));
        session(['setup.categories' => $cats]);

        return redirect(route('setup.review'));
    }

    // ─── Step 6: Review ───────────────────────────────────────────────────────

    public function review(): View|RedirectResponse
    {
        $this->guardAuthorized();

        if (! session('setup.store') || ! session('setup.owner')) {
            return redirect(route('setup.store'));
        }

        return view('setup', [
            'step'       => 'review',
            'store'      => session('setup.store'),
            'owner'      => session('setup.owner'),
            'locations'  => session('setup.locations', []),
            'categories' => session('setup.categories', []),
        ]);
    }

    // ─── Commit ───────────────────────────────────────────────────────────────

    public function commit(): RedirectResponse
    {
        $this->guardAuthorized();

        if (! session('setup.store') || ! session('setup.owner')) {
            return redirect(route('setup.store'));
        }

        $storeData     = session('setup.store');
        $ownerData     = session('setup.owner');
        $locationsData = session('setup.locations', []);
        $categoriesData= session('setup.categories', []);

        DB::transaction(function () use ($storeData, $ownerData, $locationsData, $categoriesData): void {
            $slug     = Str::slug($storeData['name']) ?: 'store';
            $locale   = $storeData['language'] === 'ar' ? 'ar_MA' : 'fr_MA';
            $storeKey = $slug;

            $locationsList = collect($locationsData)
                ->filter(fn ($l) => ! empty($l['name']))
                ->map(fn ($loc) => [
                    'key'      => Str::slug($loc['name']) ?: $storeKey,
                    'name'     => $loc['name'],
                    'type'     => 'store',
                    'address'  => $loc['address'] ?? null,
                    'phone'    => $loc['phone'] ?? null,
                    'manager'  => '',
                    'is_active'=> true,
                ])->values()->all();

            if (empty($locationsList)) {
                $locationsList = [[
                    'key'      => $storeKey,
                    'name'     => $storeData['name'],
                    'type'     => 'store',
                    'address'  => $storeData['address'] ?? null,
                    'phone'    => $storeData['phone'] ?? null,
                    'manager'  => '',
                    'is_active'=> true,
                ]];
            }

            $storeNames = array_column($locationsList, 'name');

            $tenant = Tenant::create([
                'name'     => $storeData['name'],
                'slug'     => $slug,
                'mode'     => $storeData['business_mode'],
                'plan'     => 'pro',
                'currency' => $storeData['currency'],
                'locale'   => $locale,
                'timezone' => $storeData['timezone'],
                'phone'    => $storeData['phone'] ?? null,
                'email'    => $storeData['email'] ?? null,
                'address'  => $storeData['address'] ?? null,
                'settings' => [
                    'receipt_header'  => $storeData['name'],
                    'languages'       => $storeData['language'] === 'ar' ? ['ar', 'fr'] : ['fr', 'ar'],
                    'current_store'   => $storeKey,
                    'stores'          => $locationsList,
                    'company_profile' => [
                        'store_code'         => strtoupper(substr($slug, 0, 8)),
                        'store_name'         => $storeData['name'],
                        'business_mode'      => $storeData['business_mode'],
                        'email'              => $storeData['email'] ?? null,
                        'phone'              => $storeData['phone'] ?? null,
                        'country'            => 'Maroc',
                        'timezone'           => $storeData['timezone'],
                        'date_format'        => 'dd/mm/yyyy',
                        'time_format'        => '24',
                        'currency'           => $storeData['currency'],
                        'currency_placement' => 'Right',
                        'decimals'           => 2,
                        'qty_decimals'       => 2,
                        'language_id'        => $storeData['language'],
                    ],
                    'number_format' => [
                        'currency_placement' => 'Right',
                        'decimals'           => 2,
                        'qty_decimals'       => 2,
                        'round_off'          => false,
                    ],
                    'payment_types' => [
                        ['key' => 'cash',     'name' => 'Espèces',        'code' => 'cash',     'description' => 'Paiement comptoir', 'is_active' => true],
                        ['key' => 'card',     'name' => 'Carte',          'code' => 'card',     'description' => 'Paiement TPE',      'is_active' => true],
                        ['key' => 'transfer', 'name' => 'Virement',       'code' => 'transfer', 'description' => 'Paiement bancaire', 'is_active' => true],
                        ['key' => 'advance',  'name' => 'Avance client',  'code' => 'advance',  'description' => 'Solde client',      'is_active' => true],
                    ],
                    'pos' => [
                        'editable_price'               => true,
                        'allow_sale_edit'               => true,
                        'allow_oversell'                => false,
                        'show_out_of_stock'             => false,
                        'show_cash_drawer_navbar'       => true,
                        'require_adjustment_reason'     => true,
                        'update_cost_on_purchase'       => true,
                        'low_stock_dashboard'           => true,
                        'auto_reorder_draft'            => false,
                        'inventory_cycle_days'          => 30,
                        'default_min_stock_threshold'   => 3,
                    ],
                ],
            ]);

            // System roles
            foreach ([
                ['Owner',     'owner',    ['*'],                                                                                                                                              true],
                ['Manager',   'manager',  ['dashboard.view', 'items.*', 'sales.*', 'online_orders.*', 'purchases.*', 'contacts.*', 'finance.*', 'reports.view', 'settings.theme'],          false],
                ['Caissier',  'cashier',  ['dashboard.view', 'sales.view', 'sales.create', 'online_orders.view', 'online_orders.create', 'contacts.create', 'items.view'],                  false],
                ['Stockiste', 'stockist', ['dashboard.view', 'items.*', 'stock.adjust', 'stock.transfer', 'purchases.view', 'purchases.receive'],                                            false],
            ] as [$roleName, $roleKey, $permissions, $isSystem]) {
                Role::create([
                    'tenant_id'  => $tenant->id,
                    'name'       => $roleName,
                    'key'        => $roleKey,
                    'permissions'=> $permissions,
                    'is_system'  => $isSystem,
                ]);
            }

            $owner = User::create([
                'current_tenant_id' => $tenant->id,
                'name'              => $ownerData['name'],
                'email'             => $ownerData['email'],
                'password'          => Hash::make($ownerData['password']),
                'avatar_color'      => '#3157D5',
                'is_active'         => true,
            ]);

            $tenant->users()->attach($owner->id, [
                'role'         => 'owner',
                'store_access' => json_encode(array_values($storeNames)),
            ]);

            // Locations
            foreach ($locationsData as $idx => $loc) {
                if (empty($loc['name'])) {
                    continue;
                }
                Location::create([
                    'tenant_id'  => $tenant->id,
                    'name'       => $loc['name'],
                    'type'       => 'store',
                    'address'    => $loc['address'] ?? null,
                    'phone'      => $loc['phone'] ?? null,
                    'is_default' => $idx === 0,
                    'is_active'  => true,
                ]);
            }

            if (empty($locationsData)) {
                Location::create([
                    'tenant_id'  => $tenant->id,
                    'name'       => $storeData['name'],
                    'type'       => 'store',
                    'address'    => $storeData['address'] ?? null,
                    'phone'      => $storeData['phone'] ?? null,
                    'is_default' => true,
                    'is_active'  => true,
                ]);
            }

            // Categories
            foreach ($categoriesData as $catName) {
                if (empty($catName)) {
                    continue;
                }
                Category::create([
                    'tenant_id' => $tenant->id,
                    'name'      => $catName,
                    'slug'      => Str::slug($catName),
                ]);
            }

            // Defaults
            foreach ([['Pièce', 'Unité standard'], ['Pack', 'Lot composé'], ['Boîte', 'Conditionnement'], ['Service', 'Prestation non physique']] as [$n, $d]) {
                Unit::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $n], ['description' => $d]);
            }

            foreach ([['Sans TVA', 0, true], ['TVA 20%', 20, true], ['TVA 7%', 7, false]] as [$n, $rate, $active]) {
                Tax::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $n], ['rate' => $rate, 'is_active' => $active]);
            }

            FinancialAccount::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Caisse principale'],
                [
                    'store_key'       => $storeKey,
                    'type'            => 'cash',
                    'holder_name'     => $storeData['name'],
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'description'     => 'Compte caisse initial.',
                    'is_active'       => true,
                ],
            );
        });

        session()->forget(['setup_authorized', 'setup.store', 'setup.owner', 'setup.locations', 'setup.categories']);
        session()->flash('setup_complete', true);

        return redirect(route('setup.done'));
    }

    // ─── Done ─────────────────────────────────────────────────────────────────

    public function done(): View|RedirectResponse
    {
        if (! session('setup_complete') && ! Tenant::exists()) {
            return redirect(route('setup.index'));
        }

        return view('setup', ['step' => 'done']);
    }
}
