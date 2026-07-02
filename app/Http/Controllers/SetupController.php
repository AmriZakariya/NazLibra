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
use App\Models\VirtualDevice;
use App\Models\VirtualDeviceSession;
use App\Support\BusinessMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SetupController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function isMaintenance(): bool
    {
        return Tenant::exists();
    }

    private function setupSecret(): ?string
    {
        $secret = config('app.setup_secret');

        return is_string($secret) && trim($secret) !== '' ? $secret : null;
    }

    private function setupView(string $step, array $data = []): View
    {
        return view('setup', array_merge([
            'step'                  => $step,
            'isMaintenance'         => $this->isMaintenance(),
            'businessModes'         => BusinessMode::all(),
            'categoryPresets'       => $this->categoryPresets(),
            'setupSecretConfigured' => $this->setupSecret() !== null,
        ], $data));
    }

    private function guardAuthorized(): void
    {
        if (! session('setup_authorized')) {
            redirect(route('setup.index'))->send();
            exit;
        }
    }

    /** Pre-fill session from current tenant DB state (only missing keys). */
    private function preloadFromTenant(Tenant $tenant): void
    {
        $profile = $tenant->settings['company_profile'] ?? [];
        $mode = BusinessMode::current($tenant)['key'];

        if (! session('setup.store')) {
            session(['setup.store' => [
                'name'          => $tenant->name,
                'email'         => $tenant->email ?? '',
                'phone'         => $tenant->phone ?? '',
                'address'       => $tenant->address ?? '',
                'currency'      => $tenant->currency ?? 'MAD',
                'timezone'      => $tenant->timezone ?? 'Africa/Casablanca',
                'language'      => $profile['language_id'] ?? 'fr',
                'business_mode' => $mode,
            ]]);
        }

        if (! session('setup.owner')) {
            $ownerUser = $tenant->users()->wherePivot('role', 'owner')->first();
            session(['setup.owner' => [
                'name'     => $ownerUser?->name ?? '',
                'email'    => $ownerUser?->email ?? '',
                'password' => '',
            ]]);
        }

        if (! session('setup.locations')) {
            $locs = Location::where('tenant_id', $tenant->id)
                ->orderBy('is_default', 'desc')
                ->orderBy('id')
                ->get()
                ->map(fn ($l) => ['name' => $l->name, 'address' => $l->address ?? '', 'phone' => $l->phone ?? ''])
                ->all();

            session(['setup.locations' => $locs ?: [['name' => '', 'address' => '', 'phone' => '']]]);
        }

        if (! session('setup.categories')) {
            $cats = Category::where('tenant_id', $tenant->id)
                ->orderBy('name')
                ->pluck('name')
                ->all();

            session(['setup.categories' => $cats ?: $this->defaultCategoriesForMode($mode)]);
        }

        if (! session('setup.devices')) {
            $devices = VirtualDevice::where('tenant_id', $tenant->id)
                ->orderBy('type')
                ->orderBy('name')
                ->get()
                ->map(fn (VirtualDevice $device) => [
                    'name' => $device->name,
                    'code' => $device->code,
                    'type' => $device->type ?: 'other',
                    'description' => $device->description ?? '',
                    'is_active' => (bool) $device->is_active,
                ])
                ->all();

            session(['setup.devices' => [
                'enabled' => (bool) data_get($tenant->settings, 'features.virtual_devices', true),
                'devices' => $devices ?: $this->defaultVirtualDevices(),
            ]]);
        }
    }

    // ─── Step 1: Secret ───────────────────────────────────────────────────────

    public function index(): View|RedirectResponse
    {
        if (session('setup_authorized')) {
            return redirect(route('setup.store'));
        }

        return $this->setupView('secret');
    }

    public function storeSecret(Request $request): RedirectResponse
    {
        $request->validate(['secret' => ['required', 'string']]);

        $expected = $this->setupSecret();

        if (! $expected) {
            return back()->withErrors(['secret' => 'SETUP_SECRET n’est pas configuré. Ajoutez SETUP_SECRET dans .env puis exécutez php artisan optimize:clear.']);
        }

        if (! hash_equals($expected, (string) $request->input('secret'))) {
            return back()->withErrors(['secret' => 'Code secret incorrect.']);
        }

        session(['setup_authorized' => true]);

        // Pre-populate session from existing tenant so forms are pre-filled
        if ($tenant = Tenant::first()) {
            $this->preloadFromTenant($tenant);
        }

        return redirect(route('setup.store'));
    }

    // ─── Step 2: Store info ───────────────────────────────────────────────────

    public function showStore(): View
    {
        $this->guardAuthorized();

        return $this->setupView('store', [
            'data'           => session('setup.store', []),
        ]);
    }

    public function storeStore(Request $request): RedirectResponse
    {
        $this->guardAuthorized();

        if (! BusinessMode::accepts($request->input('business_mode'))) {
            return back()
                ->withErrors(['business_mode' => 'Type d’activité invalide.'])
                ->withInput();
        }

        $request->merge([
            'business_mode' => BusinessMode::normalize($request->input('business_mode')),
            'currency' => Str::upper((string) $request->input('currency', 'MAD')),
        ]);

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:100'],
            'email'          => ['nullable', 'email', 'max:100'],
            'phone'          => ['nullable', 'string', 'max:30'],
            'address'        => ['nullable', 'string', 'max:200'],
            'currency'       => ['required', 'string', 'size:3'],
            'timezone'       => ['required', 'timezone:all'],
            'language'       => ['required', 'in:fr,ar'],
            'business_mode'  => ['required', Rule::in(array_keys(BusinessMode::all()))],
            'costing_method' => ['required', 'in:lifo,fifo,wac'],
        ]);

        session(['setup.store' => $data]);

        return redirect(route('setup.owner'));
    }

    // ─── Step 3: Owner account ────────────────────────────────────────────────

    public function showOwner(): View
    {
        $this->guardAuthorized();

        return $this->setupView('owner', [
            'data'          => session('setup.owner', []),
        ]);
    }

    public function storeOwner(Request $request): RedirectResponse
    {
        $this->guardAuthorized();

        $isMaintenance = $this->isMaintenance();

        $rules = [
            'name'                  => ['required', 'string', 'max:100'],
            'email'                 => ['required', 'email', 'max:100'],
            'password'              => [$isMaintenance ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['nullable', 'string'],
        ];

        $data = $request->validate($rules);

        session(['setup.owner' => [
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'] ?? '',
        ]]);

        return redirect(route('setup.locations'));
    }

    // ─── Step 4: Locations ────────────────────────────────────────────────────

    public function showLocations(): View
    {
        $this->guardAuthorized();

        return $this->setupView('locations', [
            'data'          => session('setup.locations', [['name' => '', 'address' => '', 'phone' => '']]),
        ]);
    }

    public function storeLocations(Request $request): RedirectResponse
    {
        $this->guardAuthorized();

        $data = $request->validate([
            'locations'           => ['required', 'array', 'min:1'],
            'locations.*.name'    => ['required', 'string', 'max:100'],
            'locations.*.address' => ['nullable', 'string', 'max:200'],
            'locations.*.phone'   => ['nullable', 'string', 'max:30'],
        ]);

        session(['setup.locations' => $data['locations']]);

        return redirect(route('setup.categories'));
    }

    // ─── Step 5: Categories ───────────────────────────────────────────────────

    public function showCategories(): View
    {
        $this->guardAuthorized();

        $mode = BusinessMode::normalize(session('setup.store.business_mode', BusinessMode::defaultKey()));

        return $this->setupView('categories', [
            'data'          => session('setup.categories', $this->defaultCategoriesForMode($mode)),
            'selectedMode'  => $mode,
        ]);
    }

    public function storeCategories(Request $request): RedirectResponse
    {
        $this->guardAuthorized();

        $data = $request->validate([
            'categories'   => ['nullable', 'array'],
            'categories.*' => ['nullable', 'string', 'max:100'],
        ]);

        $seen = [];
        $cats = [];
        foreach ($data['categories'] ?? [] as $category) {
            $category = trim((string) $category);
            $key = Str::lower($category);
            if ($category === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $cats[] = $category;
        }
        session(['setup.categories' => $cats]);

        return redirect(route('setup.devices'));
    }

    // ─── Step 6: Virtual devices ─────────────────────────────────────────────

    public function showDevices(): View
    {
        $this->guardAuthorized();

        return $this->setupView('devices', [
            'data' => session('setup.devices', [
                'enabled' => true,
                'devices' => $this->defaultVirtualDevices(),
            ]),
        ]);
    }

    public function storeDevices(Request $request): RedirectResponse
    {
        $this->guardAuthorized();

        $enabled = $request->boolean('enabled');

        $data = $request->validate([
            'devices' => ['nullable', 'array'],
            'devices.*.name' => [$enabled ? 'required' : 'nullable', 'string', 'max:120'],
            'devices.*.code' => ['nullable', 'string', 'max:80'],
            'devices.*.type' => ['nullable', Rule::in(['computer', 'tablet', 'mobile', 'other'])],
            'devices.*.description' => ['nullable', 'string', 'max:500'],
            'devices.*.is_active' => ['nullable', 'boolean'],
        ]);

        $seen = [];
        $devices = [];
        foreach ($data['devices'] ?? [] as $idx => $device) {
            $name = trim((string) ($device['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $code = Str::slug((string) ($device['code'] ?? '')) ?: Str::slug($name) ?: 'device';
            $base = $code;
            $suffix = 2;
            while (isset($seen[$code])) {
                $code = $base.'-'.$suffix;
                $suffix++;
            }
            $seen[$code] = true;

            $devices[] = [
                'name' => $name,
                'code' => $code,
                'type' => $device['type'] ?? 'other',
                'description' => trim((string) ($device['description'] ?? '')),
                'is_active' => array_key_exists('is_active', $device) ? (bool) $device['is_active'] : true,
            ];
        }

        if ($enabled && empty($devices)) {
            return back()
                ->withErrors(['devices' => 'Ajoutez au moins un appareil virtuel actif ou désactivez le module.'])
                ->withInput();
        }

        session(['setup.devices' => [
            'enabled' => $enabled,
            'devices' => $devices,
        ]]);

        return redirect(route('setup.review'));
    }

    // ─── Step 7: Review ───────────────────────────────────────────────────────

    public function review(): View|RedirectResponse
    {
        $this->guardAuthorized();

        if (! session('setup.store') || ! session('setup.owner')) {
            return redirect(route('setup.store'));
        }

        return $this->setupView('review', [
            'store'         => session('setup.store'),
            'owner'         => session('setup.owner'),
            'locations'     => session('setup.locations', []),
            'categories'    => session('setup.categories', []),
            'devices'       => session('setup.devices', ['enabled' => true, 'devices' => $this->defaultVirtualDevices()]),
        ]);
    }

    // ─── Commit ───────────────────────────────────────────────────────────────

    public function commit(): RedirectResponse
    {
        $this->guardAuthorized();

        if (! session('setup.store') || ! session('setup.owner')) {
            return redirect(route('setup.store'));
        }

        $storeData      = session('setup.store');
        $ownerData      = session('setup.owner');
        $locationsData  = session('setup.locations', []);
        $categoriesData = session('setup.categories', []);
        $devicesData    = session('setup.devices', ['enabled' => true, 'devices' => $this->defaultVirtualDevices()]);

        if ($this->isMaintenance()) {
            $this->updateExisting($storeData, $ownerData, $locationsData, $categoriesData, $devicesData);
        } else {
            $this->createFresh($storeData, $ownerData, $locationsData, $categoriesData, $devicesData);
        }

        session()->forget(['setup_authorized', 'setup.store', 'setup.owner', 'setup.locations', 'setup.categories', 'setup.devices']);
        session()->flash('setup_complete', true);
        session()->flash('setup_was_maintenance', $this->isMaintenance());

        return redirect(route('setup.done'));
    }

    // ─── Done ─────────────────────────────────────────────────────────────────

    public function done(): View|RedirectResponse
    {
        if (! session('setup_complete')) {
            return redirect(route('setup.index'));
        }

        return $this->setupView('done', [
            'isMaintenance' => session('setup_was_maintenance', false),
        ]);
    }

    // ─── DB operations ────────────────────────────────────────────────────────

    private function createFresh(array $storeData, array $ownerData, array $locationsData, array $categoriesData, array $devicesData): void
    {
        DB::transaction(function () use ($storeData, $ownerData, $locationsData, $categoriesData, $devicesData): void {
            $storeData['business_mode'] = BusinessMode::normalize($storeData['business_mode'] ?? null);
            $categoriesData = $categoriesData ?: $this->defaultCategoriesForMode($storeData['business_mode']);
            [$tenant, $slug, $storeNames, $locationsList] = $this->buildTenant($storeData, $locationsData);

            // System roles
            foreach ($this->defaultRoles() as [$roleName, $roleKey, $permissions, $isSystem]) {
                Role::create(['tenant_id' => $tenant->id, 'name' => $roleName, 'key' => $roleKey, 'permissions' => $permissions, 'is_system' => $isSystem]);
            }

            // Owner user
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
                if (empty($loc['name'])) continue;
                Location::create(['tenant_id' => $tenant->id, 'name' => $loc['name'], 'type' => 'store', 'address' => $loc['address'] ?? null, 'phone' => $loc['phone'] ?? null, 'is_default' => $idx === 0, 'is_active' => true]);
            }

            if (empty(array_filter(array_column($locationsData, 'name')))) {
                Location::create(['tenant_id' => $tenant->id, 'name' => $storeData['name'], 'type' => 'store', 'address' => $storeData['address'] ?? null, 'phone' => $storeData['phone'] ?? null, 'is_default' => true, 'is_active' => true]);
            }

            // Categories
            foreach ($categoriesData as $catName) {
                if (empty($catName)) continue;
                Category::create(['tenant_id' => $tenant->id, 'name' => $catName, 'slug' => Str::slug($catName)]);
            }

            // Defaults
            $this->seedDefaults($tenant, $slug, $storeData['business_mode']);
            $this->syncVirtualDevices($tenant, $devicesData);
        });
    }

    private function updateExisting(array $storeData, array $ownerData, array $locationsData, array $categoriesData, array $devicesData): void
    {
        $tenant = Tenant::firstOrFail();

        DB::transaction(function () use ($tenant, $storeData, $ownerData, $locationsData, $categoriesData, $devicesData): void {
            $storeData['business_mode'] = BusinessMode::normalize($storeData['business_mode'] ?? null);
            $categoriesData = $categoriesData ?: $this->defaultCategoriesForMode($storeData['business_mode']);
            $businessMode = BusinessMode::get($storeData['business_mode']);
            $slug     = Str::slug($storeData['name']) ?: Str::slug($tenant->name);
            $locale   = $storeData['language'] === 'ar' ? 'ar_MA' : 'fr_MA';
            $storeKey = $slug;

            $locationsList = collect($locationsData)
                ->filter(fn ($l) => ! empty($l['name']))
                ->map(fn ($loc) => ['key' => Str::slug($loc['name']) ?: $storeKey, 'name' => $loc['name'], 'type' => 'store', 'address' => $loc['address'] ?? null, 'phone' => $loc['phone'] ?? null, 'manager' => '', 'is_active' => true])
                ->values()
                ->all();

            // Merge with existing settings to avoid overwriting unrelated keys
            $existingSettings = $tenant->settings ?? [];
            $mergedSettings = array_merge($existingSettings, [
                'receipt_header'  => $storeData['name'],
                'languages'       => $storeData['language'] === 'ar' ? ['ar', 'fr'] : ['fr', 'ar'],
                'current_store'   => $storeKey,
                'stores'          => $locationsList ?: ($existingSettings['stores'] ?? []),
                'company_profile' => array_merge($existingSettings['company_profile'] ?? [], [
                    'store_code'         => strtoupper(substr($slug, 0, 8)),
                    'store_name'         => $storeData['name'],
                    'business_mode'      => $storeData['business_mode'],
                    'email'              => $storeData['email'] ?? null,
                    'phone'              => $storeData['phone'] ?? null,
                    'timezone'           => $storeData['timezone'],
                    'currency'           => $storeData['currency'],
                    'language_id'        => $storeData['language'],
                ]),
                'catalog' => array_merge($existingSettings['catalog'] ?? [], [
                    'label' => $businessMode['catalog_label'],
                    'primary_item' => $businessMode['primary_item'],
                    'search_placeholder' => $businessMode['search_placeholder'],
                    'type_labels' => $businessMode['type_labels'],
                ]),
            ]);

            $tenant->update([
                'name'          => $storeData['name'],
                'mode'          => $storeData['business_mode'],
                'business_mode' => $storeData['business_mode'],
                'currency'      => $storeData['currency'],
                'locale'        => $locale,
                'timezone'      => $storeData['timezone'],
                'phone'         => $storeData['phone'] ?? null,
                'email'         => $storeData['email'] ?? null,
                'address'       => $storeData['address'] ?? null,
                'settings'      => $mergedSettings,
            ]);

            // Update owner user
            $ownerUser = $tenant->users()->wherePivot('role', 'owner')->first();
            if ($ownerUser) {
                $userPayload = ['name' => $ownerData['name'], 'email' => $ownerData['email']];
                if (! empty($ownerData['password'])) {
                    $userPayload['password'] = Hash::make($ownerData['password']);
                }
                $ownerUser->update($userPayload);

                // Update store_access on pivot
                $storeNames = array_column($locationsList, 'name');
                if (! empty($storeNames)) {
                    $tenant->users()->updateExistingPivot($ownerUser->id, ['store_access' => json_encode($storeNames)]);
                }
            }

            // Sync roles: ensure all system roles exist
            foreach ($this->defaultRoles() as [$roleName, $roleKey, $permissions, $isSystem]) {
                Role::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'key' => $roleKey],
                    ['name' => $roleName, 'permissions' => $permissions, 'is_system' => $isSystem]
                );
            }

            // Upsert locations: update existing by name, create new ones
            foreach ($locationsData as $idx => $loc) {
                if (empty($loc['name'])) continue;
                $existing = Location::where('tenant_id', $tenant->id)->where('name', $loc['name'])->first();
                if ($existing) {
                    $existing->update(['address' => $loc['address'] ?? null, 'phone' => $loc['phone'] ?? null]);
                } else {
                    $isFirst = $idx === 0 && ! Location::where('tenant_id', $tenant->id)->where('is_default', true)->exists();
                    Location::create(['tenant_id' => $tenant->id, 'name' => $loc['name'], 'type' => 'store', 'address' => $loc['address'] ?? null, 'phone' => $loc['phone'] ?? null, 'is_default' => $isFirst, 'is_active' => true]);
                }
            }

            // Sync categories: soft-delete removed, create new
            $newNames = array_values(array_filter(array_map('trim', $categoriesData)));
            $existing = Category::where('tenant_id', $tenant->id)->pluck('name')->all();
            $toDelete = array_diff($existing, $newNames);
            $toCreate = array_diff($newNames, $existing);

            if (! empty($toDelete)) {
                Category::where('tenant_id', $tenant->id)->whereIn('name', $toDelete)->delete();
            }
            foreach ($toCreate as $catName) {
                Category::create(['tenant_id' => $tenant->id, 'name' => $catName, 'slug' => Str::slug($catName)]);
            }

            $this->syncVirtualDevices($tenant, $devicesData);
        });
    }

    // ─── Shared helpers ───────────────────────────────────────────────────────

    private function categoryPresets(): array
    {
        return [
            'library' => $this->defaultCategoriesForMode('library'),
            'restaurant' => $this->defaultCategoriesForMode('restaurant'),
            'coffee' => $this->defaultCategoriesForMode('coffee'),
            'pharmacy' => $this->defaultCategoriesForMode('pharmacy'),
            'drugstore' => $this->defaultCategoriesForMode('drugstore'),
            'retail' => $this->defaultCategoriesForMode('retail'),
        ];
    }

    private function defaultCategoriesForMode(?string $mode): array
    {
        return match (BusinessMode::normalize($mode)) {
            'restaurant' => ['Entrées', 'Plats', 'Menus', 'Desserts', 'Boissons', 'Suppléments'],
            'coffee' => ['Cafés', 'Thés', 'Boissons froides', 'Pâtisseries', 'Snacks', 'Formules'],
            'pharmacy' => ['Médicaments', 'Parapharmacie', 'Hygiène', 'Bébé', 'Compléments', 'Services'],
            'drugstore' => ['Droguerie', 'Quincaillerie', 'Maison', 'Bricolage', 'Nettoyage', 'Services'],
            'retail' => ['Produits', 'Accessoires', 'Services', 'Promotions', 'Nouveautés', 'Divers'],
            default => ['Romans', 'Scolaire', 'Papeterie', 'Fournitures', 'Services', 'Informatique'],
        };
    }

    private function defaultVirtualDevices(): array
    {
        return [
            ['name' => 'Caisse Web 1', 'code' => 'web-pos-01', 'type' => 'computer', 'description' => 'Terminal navigateur principal', 'is_active' => true],
            ['name' => 'Caisse Web 2', 'code' => 'web-pos-02', 'type' => 'computer', 'description' => 'Terminal navigateur secondaire', 'is_active' => true],
            ['name' => 'Mobile POS 1', 'code' => 'mobile-pos-01', 'type' => 'mobile', 'description' => 'Terminal mobile 1', 'is_active' => true],
            ['name' => 'Mobile POS 2', 'code' => 'mobile-pos-02', 'type' => 'mobile', 'description' => 'Terminal mobile 2', 'is_active' => true],
            ['name' => 'Mobile POS 3', 'code' => 'mobile-pos-03', 'type' => 'mobile', 'description' => 'Terminal mobile 3', 'is_active' => true],
        ];
    }

    private function syncVirtualDevices(Tenant $tenant, array $devicesData): void
    {
        $enabled = array_key_exists('enabled', $devicesData) ? (bool) $devicesData['enabled'] : true;
        $settings = $tenant->settings ?? [];
        $settings['features'] = array_merge($settings['features'] ?? [], [
            'virtual_devices' => $enabled,
        ]);
        $tenant->update(['settings' => $settings]);

        if (! $enabled) {
            VirtualDeviceSession::where('tenant_id', $tenant->id)
                ->whereNull('disconnected_at')
                ->update([
                    'disconnected_at' => now(),
                    'disconnect_reason' => 'module_disabled',
                    'updated_at' => now(),
                ]);

            return;
        }

        $seen = [];
        foreach (($devicesData['devices'] ?? []) ?: $this->defaultVirtualDevices() as $device) {
            $name = trim((string) ($device['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $code = Str::slug((string) ($device['code'] ?? '')) ?: Str::slug($name) ?: 'device';
            $base = $code;
            $suffix = 2;
            while (isset($seen[$code])) {
                $code = $base.'-'.$suffix;
                $suffix++;
            }
            $seen[$code] = true;

            VirtualDevice::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                [
                    'name' => $name,
                    'type' => $device['type'] ?? 'other',
                    'description' => $device['description'] ?? null,
                    'is_active' => array_key_exists('is_active', $device) ? (bool) $device['is_active'] : true,
                ],
            );
        }
    }

    private function uniqueTenantSlug(string $base): string
    {
        $slug = $base;
        $suffix = 2;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function buildTenant(array $storeData, array $locationsData): array
    {
        $storeData['business_mode'] = BusinessMode::normalize($storeData['business_mode'] ?? null);
        $businessMode = BusinessMode::get($storeData['business_mode']);
        $slug     = $this->uniqueTenantSlug(Str::slug($storeData['name']) ?: 'store');
        $locale   = $storeData['language'] === 'ar' ? 'ar_MA' : 'fr_MA';
        $storeKey = $slug;

        $locationsList = collect($locationsData)
            ->filter(fn ($l) => ! empty($l['name']))
            ->map(fn ($loc) => ['key' => Str::slug($loc['name']) ?: $storeKey, 'name' => $loc['name'], 'type' => 'store', 'address' => $loc['address'] ?? null, 'phone' => $loc['phone'] ?? null, 'manager' => '', 'is_active' => true])
            ->values()
            ->all();

        if (empty($locationsList)) {
            $locationsList = [['key' => $storeKey, 'name' => $storeData['name'], 'type' => 'store', 'address' => $storeData['address'] ?? null, 'phone' => $storeData['phone'] ?? null, 'manager' => '', 'is_active' => true]];
        }

        $storeNames = array_column($locationsList, 'name');

        $tenant = Tenant::create([
            'name'          => $storeData['name'],
            'slug'          => $slug,
            'mode'          => $storeData['business_mode'],
            'business_mode' => $storeData['business_mode'],
            'plan'          => 'pro',
            'currency'      => $storeData['currency'],
            'locale'        => $locale,
            'timezone'      => $storeData['timezone'],
            'phone'         => $storeData['phone'] ?? null,
            'email'         => $storeData['email'] ?? null,
            'address'       => $storeData['address'] ?? null,
            'settings'      => [
                'receipt_header'  => $storeData['name'],
                'languages'       => $storeData['language'] === 'ar' ? ['ar', 'fr'] : ['fr', 'ar'],
                'current_store'   => $storeKey,
                'stores'          => $locationsList,
                'company_profile' => ['store_code' => strtoupper(substr($slug, 0, 8)), 'store_name' => $storeData['name'], 'business_mode' => $storeData['business_mode'], 'email' => $storeData['email'] ?? null, 'phone' => $storeData['phone'] ?? null, 'country' => 'Maroc', 'timezone' => $storeData['timezone'], 'date_format' => 'dd/mm/yyyy', 'time_format' => '24', 'currency' => $storeData['currency'], 'currency_placement' => 'Right', 'decimals' => 2, 'qty_decimals' => 2, 'language_id' => $storeData['language']],
                'catalog'         => ['label' => $businessMode['catalog_label'], 'primary_item' => $businessMode['primary_item'], 'search_placeholder' => $businessMode['search_placeholder'], 'type_labels' => $businessMode['type_labels']],
                'number_format'   => ['currency_placement' => 'Right', 'decimals' => 2, 'qty_decimals' => 2, 'round_off' => false],
                'payment_types'   => [
                    ['key' => 'cash', 'name' => 'Espèces', 'code' => 'cash', 'description' => 'Paiement comptoir', 'is_active' => true],
                    ['key' => 'card', 'name' => 'Carte', 'code' => 'card', 'description' => 'Paiement TPE', 'is_active' => true],
                    ['key' => 'transfer', 'name' => 'Virement', 'code' => 'transfer', 'description' => 'Paiement bancaire', 'is_active' => true],
                    ['key' => 'advance', 'name' => 'Avance client', 'code' => 'advance', 'description' => 'Solde client', 'is_active' => true],
                ],
                'features'  => ['virtual_devices' => true],
                'inventory' => ['costing_method' => $storeData['costing_method'] ?? 'lifo'],
                'store' => ['business_activity' => \App\Support\ItemTypes::activityFromBusinessMode($storeData['business_mode'])],
                'pos' => ['editable_price' => true, 'allow_sale_edit' => true, 'allow_oversell' => false, 'show_out_of_stock' => false, 'show_cash_drawer_navbar' => true, 'require_adjustment_reason' => true, 'update_cost_on_purchase' => true, 'low_stock_dashboard' => true, 'auto_reorder_draft' => false, 'inventory_cycle_days' => 30, 'default_min_stock_threshold' => 3],
            ],
        ]);

        return [$tenant, $slug, $storeNames, $locationsList];
    }

    private function defaultRoles(): array
    {
        return [
            ['Owner',     'owner',    ['*'],                                                                                                                                              true],
            ['Manager',   'manager',  ['dashboard.view', 'items.*', 'sales.*', 'online_orders.*', 'purchases.*', 'contacts.*', 'finance.*', 'reports.view', 'settings.theme'],          false],
            ['Caissier',  'cashier',  ['dashboard.view', 'sales.view', 'sales.create', 'online_orders.view', 'online_orders.create', 'contacts.create', 'items.view'],                  false],
            ['Stockiste', 'stockist', ['dashboard.view', 'items.*', 'stock.adjust', 'stock.transfer', 'purchases.view', 'purchases.receive'],                                            false],
        ];
    }

    private function seedDefaults(Tenant $tenant, string $storeKey, ?string $mode = null): void
    {
        $unitDefaults = match (BusinessMode::normalize($mode)) {
            'restaurant', 'coffee' => [
                ['Pièce', 'Unité standard'],
                ['Portion', 'Portion servie'],
                ['Menu', 'Formule ou menu composé'],
                ['Service', 'Prestation non physique'],
            ],
            default => [
                ['Pièce', 'Unité standard'],
                ['Pack', 'Lot composé'],
                ['Boîte', 'Conditionnement'],
                ['Service', 'Prestation non physique'],
            ],
        };

        foreach ($unitDefaults as [$n, $d]) {
            Unit::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $n], ['description' => $d]);
        }
        foreach ([['Sans TVA', 0, true], ['TVA 20%', 20, true], ['TVA 7%', 7, false]] as [$n, $rate, $active]) {
            Tax::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $n], ['rate' => $rate, 'is_active' => $active]);
        }
        FinancialAccount::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Caisse principale'],
            ['store_key' => $storeKey, 'type' => 'cash', 'holder_name' => $tenant->name, 'opening_balance' => 0, 'current_balance' => 0, 'description' => 'Compte caisse initial.', 'is_active' => true],
        );
    }
}
