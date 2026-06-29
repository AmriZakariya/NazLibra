<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Run this command after every deployment to guarantee that every tenant
 * has the protected owner role with full access.
 *
 * Usage:  php artisan roles:ensure-system
 */
class EnsureSystemRoles extends Command
{
    protected $signature   = 'roles:ensure-system {--tenant= : Only repair a specific tenant slug}';
    protected $description = 'Ensure every tenant has the owner system role with wildcard permissions.';

    public function handle(): int
    {
        $query = Tenant::query();

        if ($slug = $this->option('tenant')) {
            $query->where('slug', $slug);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;

        foreach ($tenants as $tenant) {
            $role = Role::where('tenant_id', $tenant->id)->where('key', 'owner')->first();

            if (! $role) {
                Role::create([
                    'tenant_id'   => $tenant->id,
                    'name'        => 'Owner',
                    'key'         => 'owner',
                    'permissions' => ['*'],
                    'is_system'   => true,
                ]);
                $this->line("  <fg=green>✓</> [{$tenant->slug}] owner role created.");
                $created++;
            } elseif (! $role->is_system || ! in_array('*', $role->permissions ?? [], true)) {
                $role->update(['permissions' => ['*'], 'is_system' => true]);
                $this->line("  <fg=yellow>↻</> [{$tenant->slug}] owner role repaired.");
                $updated++;
            } else {
                $this->line("  <fg=gray>–</> [{$tenant->slug}] owner role OK.");
            }
        }

        $this->newLine();
        $this->info("Done. Created: {$created}, repaired: {$updated}, unchanged: " . ($tenants->count() - $created - $updated) . '.');

        return self::SUCCESS;
    }
}
