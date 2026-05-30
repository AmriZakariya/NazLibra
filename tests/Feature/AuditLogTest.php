<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutating_actions_are_recorded_in_audit_log(): void
    {
        $this->seed();

        $this->post(route('catalog.categories.store'), [
            'name' => 'Audit Test',
            'description' => 'Created from audit test',
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'catalog.categories.store',
        ]);

        $log = \DB::table('audit_logs')->where('action', 'catalog.categories.store')->first();
        $properties = json_decode($log->properties, true);

        $this->assertSame('POST', $properties['method']);
        $this->assertSame('catalogue/categories', $properties['path']);
        $this->assertSame('Audit Test', $properties['payload']['name']);
    }

    public function test_read_only_pages_are_not_recorded_in_audit_log(): void
    {
        $this->seed();
        $before = \DB::table('audit_logs')->count();

        $this->get(route('dashboard'))->assertOk();

        $this->assertSame($before, \DB::table('audit_logs')->count());
    }
}
