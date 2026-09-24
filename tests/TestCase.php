<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function seed($class = DatabaseSeeder::class)
    {
        parent::seed($class);

        if (class_basename($this) === 'AuthTest') {
            return $this;
        }

        $owner = User::where('email', 'amina@librairie-atlas.ma')->first();

        if ($owner) {
            $this->actingAs($owner);
        }

        return $this;
    }

    /**
     * The tenant's current store key.
     *
     * Emplacements are rows in `locations` — the same ones inventory and
     * transfers read — so a store key is a location id, not the slug the
     * settings JSON used to hold.
     */
    protected function storeKey(?\App\Models\Tenant $tenant = null): string
    {
        $tenant ??= \App\Models\Tenant::firstOrFail();

        return (string) (data_get($tenant->settings, 'current_store')
            ?: \App\Models\Location::where('tenant_id', $tenant->id)
                ->where('is_default', true)
                ->value('id'));
    }
}
