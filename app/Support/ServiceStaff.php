<?php

namespace App\Support;

use App\Models\Item;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who does which service. A service nobody was assigned to is done by
 * everyone, so a shop that never touches it keeps booking exactly as before;
 * once people are ticked, only they are offered for it — on the public page,
 * in the agenda forms, and when « peu importe » picks someone.
 */
final class ServiceStaff
{
    /**
     * The people assigned to [item], or null when it is done by everyone.
     *
     * @return list<int>|null
     */
    public static function idsFor(Item $item): ?array
    {
        $ids = DB::table('item_staff')->where('item_id', $item->id)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return $ids === [] ? null : $ids;
    }

    /**
     * item id => list of user ids (only services that are restricted), for
     * the forms that filter their staff list by service.
     *
     * @return array<int, list<int>>
     */
    public static function map(Tenant $tenant): array
    {
        $map = [];
        foreach (DB::table('item_staff')->where('tenant_id', $tenant->id)->orderBy('user_id')->get(['item_id', 'user_id']) as $row) {
            $map[(int) $row->item_id][] = (int) $row->user_id;
        }

        return $map;
    }

    /**
     * Replaces the people assigned to [item] with [ids] (only members of the
     * shop); an empty list means everyone does it.
     *
     * @param  array<int, int|string>  $ids
     */
    public static function set(Tenant $tenant, Item $item, array $ids): void
    {
        $team = $tenant->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_unique(array_intersect(array_map('intval', $ids), $team)));

        DB::transaction(function () use ($tenant, $item, $ids): void {
            DB::table('item_staff')->where('item_id', $item->id)->delete();
            foreach ($ids as $id) {
                DB::table('item_staff')->insert([
                    'tenant_id' => $tenant->id, 'item_id' => $item->id, 'user_id' => $id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // The app learns it with the item: its cursor is the item's updated_at.
            $item->newQuery()->whereKey($item->id)->update(['updated_at' => now()]);
        });
    }

    /**
     * Of [team], who does [item]: everyone when it is unrestricted (or when
     * there is no service yet).
     *
     * @param  Collection<int, int>  $team
     * @return Collection<int, int>
     */
    public static function allowed(?Item $item, Collection $team): Collection
    {
        $ids = $item ? self::idsFor($item) : null;

        return $ids === null ? $team->values() : $team->filter(fn (int $id) => in_array($id, $ids, true))->values();
    }
}
