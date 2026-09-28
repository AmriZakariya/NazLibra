<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class StoreLocations
{
    public function available(Tenant $tenant, ?User $user): Builder
    {
        $query = Location::where('tenant_id', $tenant->id)->where('is_active', true);
        $membership = $user ? $tenant->users()->whereKey($user->id)->first()?->pivot : null;
        if (! $membership) {
            return $query->whereRaw('1 = 0');
        }
        $system = $membership->role === 'owner' || Role::where('tenant_id', $tenant->id)
            ->where('key', $membership->role)->where('is_system', true)->exists();
        $names = json_decode($membership->store_access ?? '[]', true) ?: [];
        // An empty access list means all stores in the existing user settings UI.
        if (! $system && $names !== []) {
            $query->whereIn('name', $names);
        }
        return $query;
    }

    public function catalog(Tenant $tenant, ?User $user): \Illuminate\Support\Collection
    {
        return $this->available($tenant, $user)->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (Location $location) => [
                'key' => (string) $location->id, 'name' => $location->name,
                'type' => $location->type, 'is_active' => true,
                'is_default' => $location->is_default,
            ]);
    }
}
