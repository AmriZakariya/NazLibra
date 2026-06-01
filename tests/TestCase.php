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
}
