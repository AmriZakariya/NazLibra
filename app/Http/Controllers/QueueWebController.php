<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\QueueEntry;
use App\Models\Tenant;
use App\Support\AppModules;
use App\Support\Locale;
use App\Support\OnlineBooking;
use App\Support\TenantContext;
use App\Support\WalkInQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The walk-in queue in the back office, and its public screen. */
class QueueWebController extends Controller
{
    public function index(): View
    {
        $tenant = $this->tenant();

        return view('queue.index', [
            'tenant' => $tenant,
            'board' => WalkInQueue::board($tenant),
            'team' => OnlineBooking::staff($tenant),
            'services' => Item::where('tenant_id', $tenant->id)->where('type', 'service')->whereNotIn('status', ['archived', 'inactive'])
                ->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id')])
                ->orderBy('title')->get(['id', 'title']),
            'tvUrl' => route('queue.tv', ['token' => WalkInQueue::tvToken($tenant)]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'item_id' => ['nullable', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'staff_user_id' => ['nullable', 'integer'],
        ]);
        $entry = WalkInQueue::add($tenant, $data);

        return back()->with('status', 'Client n° '.$entry->ticket_number.' ajouté à la file.');
    }

    /** « Appeler le suivant » for one barber. */
    public function call(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate(['staff_user_id' => ['required', 'integer']]);
        $entry = WalkInQueue::callNext($tenant, (int) $data['staff_user_id']);

        return back()->with('status', $entry ? 'Client n° '.$entry->ticket_number.' appelé.' : 'Personne n’attend ce barbier.');
    }

    public function seat(Request $request, QueueEntry $entry): RedirectResponse
    {
        $tenant = $this->tenant();
        $entry = WalkInQueue::seat($tenant, $entry, $request->user());

        return back()->with('status', 'Client n° '.$entry->ticket_number.' installé.');
    }

    public function leave(QueueEntry $entry): RedirectResponse
    {
        $entry = WalkInQueue::leave($this->tenant(), $entry);

        return back()->with('status', 'Client n° '.$entry->ticket_number.' parti.');
    }

    /** A new secret address for the public screen (the old one stops working). */
    public function regenerateTv(): RedirectResponse
    {
        WalkInQueue::tvToken($this->tenant(), regenerate: true);

        return back()->with('status', 'Nouvelle adresse de l’écran public.');
    }

    public function tv(Request $request, string $token): View
    {
        $tenant = $this->publicTenant($request, $token);

        return view('queue.tv', [
            'tenant' => $tenant,
            'data' => WalkInQueue::tvPayload($tenant),
            'dataUrl' => route('queue.tv.data', ['token' => $token]),
            'locale' => app()->getLocale(),
            'dir' => Locale::dir(app()->getLocale()),
        ]);
    }

    public function tvData(Request $request, string $token): JsonResponse
    {
        return response()->json(WalkInQueue::tvPayload($this->publicTenant($request, $token)))
            ->header('Cache-Control', 'no-store');
    }

    private function publicTenant(Request $request, string $token): Tenant
    {
        $tenant = TenantContext::require($request, null, false);
        Locale::apply($tenant);
        abort_unless(AppModules::enabled($tenant, 'queue') && AppModules::enabled($tenant, 'appointments'), 404);
        abort_unless(hash_equals((string) data_get($tenant->settings, 'queue.tv_token', ''), $token), 404);

        return $tenant;
    }

    private function tenant(): Tenant
    {
        $tenant = TenantContext::require(request(), auth()->user());
        abort_unless(AppModules::enabled($tenant, 'queue') && AppModules::enabled($tenant, 'appointments'), 404);

        return $tenant;
    }
}
