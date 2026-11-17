<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The web terminal picker's groups: one per kind of terminal, in a fixed
 * order, free terminals before busy ones. Phone terminals come last and
 * folded — they are claimed from the app, never from a browser — so they
 * are shown only to say who holds them.
 */
final class TerminalPicker
{
    /** Group order, and the heading each one gets. */
    public const GROUPS = [
        'computer' => 'Ordinateurs',
        'tablet'   => 'Tablettes',
        'other'    => 'Autres terminaux',
        'mobile'   => 'Terminaux mobiles',
    ];

    /**
     * @param  Collection<int,\App\Models\VirtualDevice>  $devices  with `is_in_use` set
     * @return list<array{type:string,label:string,devices:Collection,free:int,total:int,selectable:bool}>
     */
    public static function groups(Collection $devices, ?int $currentDeviceId = null): array
    {
        $byType = $devices->groupBy(fn ($d) => array_key_exists((string) $d->type, self::GROUPS) ? (string) $d->type : 'other');

        $groups = [];
        foreach (self::GROUPS as $type => $label) {
            $members = $byType->get($type);
            if (! $members || $members->isEmpty()) {
                continue;
            }

            $isFree = fn ($d) => ! $d->is_in_use || (int) $d->id === $currentDeviceId;

            $groups[] = [
                'type'       => $type,
                'label'      => $label,
                'devices'    => $members
                    ->sortBy(fn ($d) => [$isFree($d) ? 0 : 1, mb_strtolower((string) $d->name)])
                    ->values(),
                'free'       => $members->filter($isFree)->count(),
                'total'      => $members->count(),
                'selectable' => $type !== 'mobile',
            ];
        }

        return $groups;
    }
}
