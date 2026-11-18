<?php

namespace App\Http\Controllers;

use App\Exceptions\LiabilityException;
use App\Models\Contact;
use App\Models\CustomerPackage;
use App\Models\Item;
use App\Models\ServicePackage;
use App\Models\Tenant;
use App\Services\Packages\PackageService;
use App\Support\AppModules;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Prepaid packages in the back office: the shop's offers, and what each customer still has. */
class PackageWebController extends Controller
{
    public function __construct(private readonly PackageService $packages) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant();
        $status = (string) $request->query('status', 'active');
        $q = trim((string) $request->query('q', ''));

        CustomerPackage::where('tenant_id', $tenant->id)->where('status', CustomerPackage::ACTIVE)
            ->whereNotNull('expires_at')->whereDate('expires_at', '<', PackageService::today($tenant)->toDateString())
            ->update(['status' => CustomerPackage::EXPIRED]);

        $owned = CustomerPackage::where('tenant_id', $tenant->id)
            ->when($status !== 'all' && $status !== '', fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$q.'%')
                ->orWhereHas('contact', fn ($contact) => $contact->where('name', 'like', '%'.$q.'%')->orWhere('phone', 'like', '%'.$q.'%'))))
            ->with('contact:id,name,phone')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('packages.index', [
            'tenant' => $tenant,
            'definitions' => ServicePackage::where('tenant_id', $tenant->id)->with(['item:id,title', 'variant:id,name'])->orderBy('name')->get(),
            'owned' => $owned,
            'status' => $status,
            'q' => $q,
            'services' => Item::where('tenant_id', $tenant->id)->where('type', 'service')->whereNotIn('status', ['archived', 'inactive'])
                ->with(['variants' => fn ($v) => $v->where('is_active', true)->orderBy('sort_order')->orderBy('id')])->orderBy('title')->get(['id', 'title']),
        ]);
    }

    private function rules(Tenant $tenant): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'item_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('items', 'id')->where('tenant_id', $tenant->id)->where('type', 'service')],
            'variant_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('item_variants', 'id')],
            'sessions' => ['required', 'integer', 'min:1', 'max:500'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** A variant must be one of the chosen service's own. */
    private function variantOf(array $data): ?int
    {
        if (empty($data['variant_id'])) {
            return null;
        }
        $ok = \App\Models\ItemVariant::whereKey($data['variant_id'])->where('item_id', $data['item_id'])->exists();
        abort_unless($ok, 422, 'Variante inconnue pour ce service.');

        return (int) $data['variant_id'];
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate($this->rules($tenant));
        ServicePackage::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'item_id' => $data['item_id'],
            'variant_id' => $this->variantOf($data),
            'sessions' => $data['sessions'],
            'price' => $data['price'],
            'validity_days' => $data['validity_days'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('status', 'Forfait enregistré.');
    }

    public function update(Request $request, ServicePackage $package): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($package->tenant_id === $tenant->id, 404);
        $data = $request->validate($this->rules($tenant));
        // What customers already bought is frozen on their own rows: this
        // only changes what is offered from now on.
        $package->update([
            'name' => $data['name'],
            'item_id' => $data['item_id'],
            'variant_id' => $this->variantOf($data),
            'sessions' => $data['sessions'],
            'price' => $data['price'],
            'validity_days' => $data['validity_days'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Forfait mis à jour.');
    }

    public function destroy(ServicePackage $package): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($package->tenant_id === $tenant->id, 404);
        $package->delete();

        return back()->with('status', 'Forfait supprimé. Les forfaits déjà vendus restent valables.');
    }

    public function cancel(Request $request, CustomerPackage $customerPackage): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($customerPackage->tenant_id === $tenant->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        try {
            $this->packages->cancel($tenant, $customerPackage, $data['reason'], $request->user()?->id);
        } catch (LiabilityException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Forfait annulé.');
    }

    public function adjust(Request $request, CustomerPackage $customerPackage): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($customerPackage->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'sessions' => ['required', 'integer', 'between:-500,500', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);
        try {
            $this->packages->adjust($tenant, $customerPackage, (int) $data['sessions'], $data['reason'], $request->user()?->id, $data['expires_at'] ?? null);
        } catch (LiabilityException $e) {
            return back()->withErrors(['sessions' => $e->getMessage()]);
        }

        return back()->with('status', 'Forfait ajusté.');
    }

    /** The web till: the packages a customer can use today, and the offers on sale. */
    public function forContact(Contact $contact): JsonResponse
    {
        $tenant = $this->tenant();
        abort_unless($contact->tenant_id === $tenant->id, 404);

        return response()->json([
            'ok' => true,
            'packages' => CustomerPackage::where('tenant_id', $tenant->id)->where('contact_id', $contact->id)
                ->where('status', CustomerPackage::ACTIVE)->where('sessions_remaining', '>', 0)->get()
                ->map(fn (CustomerPackage $p) => $this->packages->payload($tenant, $p))
                ->filter(fn ($p) => $p['status'] === CustomerPackage::ACTIVE)->values(),
        ]);
    }

    private function tenant(): Tenant
    {
        $tenant = TenantContext::require(request(), auth()->user());
        abort_unless(AppModules::enabled($tenant, 'packages'), 404);

        return $tenant;
    }
}
