<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carries existing roles onto the split settings permissions.
 *
 * `settings.theme` used to be one checkbox that opened the company profile,
 * the POS settings, document templates, messaging, every reference list and
 * the stores. Splitting it without this would silently strip all of that from
 * every role a client has already configured — a permission change nobody
 * asked for, discovered by a manager who can no longer do their job.
 *
 * One shot, then only the new keys exist. Deliberately NOT a runtime alias:
 * two spellings of the same right is how a permission system rots.
 */
return new class extends Migration
{
    /** Old key => the keys that together mean the same thing. */
    private const SPLIT = [
        'settings.theme' => [
            'settings.company', 'settings.pos', 'settings.documents',
            'settings.messaging', 'settings.references',
        ],
    ];

    public function up(): void
    {
        $this->rewrite(function (array $permissions): array {
            foreach (self::SPLIT as $old => $new) {
                if (in_array($old, $permissions, true)) {
                    $permissions = array_merge(array_diff($permissions, [$old]), $new);
                }
            }

            // Roles that could manage users also managed stores, modules and
            // terminals through the same screen group.
            if (in_array('settings.users', $permissions, true)) {
                $permissions = array_merge($permissions, ['settings.stores', 'settings.modules', 'settings.devices']);
            }

            return $permissions;
        });
    }

    public function down(): void
    {
        $this->rewrite(function (array $permissions): array {
            foreach (self::SPLIT as $old => $new) {
                if (array_intersect($new, $permissions)) {
                    $permissions = array_merge(array_diff($permissions, $new), [$old]);
                }
            }

            return array_values(array_diff($permissions, ['settings.stores', 'settings.modules', 'settings.devices']));
        });
    }

    private function rewrite(callable $transform): void
    {
        DB::table('roles')->orderBy('id')->chunkById(200, function ($roles) use ($transform): void {
            foreach ($roles as $role) {
                $permissions = json_decode((string) $role->permissions, true);
                if (! is_array($permissions) || $permissions === []) {
                    continue;
                }
                // A wildcard already covers whatever the split produced.
                if (in_array('*', $permissions, true)) {
                    continue;
                }

                $updated = array_values(array_unique($transform($permissions)));
                sort($updated);

                if ($updated !== $permissions) {
                    DB::table('roles')->where('id', $role->id)
                        ->update(['permissions' => json_encode($updated), 'updated_at' => now()]);
                }
            }
        });
    }
};
