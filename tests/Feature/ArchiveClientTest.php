<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * « Retirer ce client »: the space leaves every fleet task — they all select
 * live installs — and nothing is deleted; « Réactiver » brings it back.
 */
class ArchiveClientTest extends TestCase
{
    use ActsAsMasterInstall;
    use RefreshDatabase;

    private function liveClient(string $sub): Subscription
    {
        $subscription = Subscription::create([
            'business_name' => 'Salon '.$sub, 'contact_name' => 'Contact', 'email' => "{$sub}@example.com",
            'desired_subdomain' => $sub, 'currency' => 'MAD', 'status' => Subscription::STATUS_APPROVED,
        ]);
        TenantInstall::create([
            'subscription_id' => $subscription->id, 'subdomain' => $sub, 'domain' => "{$sub}.castlitpos.com",
            'status' => TenantInstall::STATUS_LIVE, 'owner_email' => "{$sub}@example.com",
        ]);

        return $subscription->fresh();
    }

    public function test_a_retired_client_is_left_out_of_updates_and_can_be_reactivated(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $kept = $this->liveClient('gardé');
        $gone = $this->liveClient('retire');

        $this->actingAs($admin)->post(route('castlit.admin.archive', $gone))->assertSessionHas('success');

        $install = $gone->install->fresh();
        $this->assertSame(TenantInstall::STATUS_ARCHIVED, $install->status);
        $this->assertNotNull($install->archived_at);
        $this->assertSame(
            [$kept->install->id],
            TenantInstall::where('status', TenantInstall::STATUS_LIVE)->pluck('id')->all(),
            'the fleet commands (update, backup, schedules, rotation) select live installs only',
        );

        $this->actingAs($admin)->get(route('castlit.admin.show', $gone))
            ->assertOk()->assertSee('Client retiré')->assertSee('data-restore', false);

        $this->actingAs($admin)->post(route('castlit.admin.restore', $gone))->assertSessionHas('success');
        $this->assertSame(TenantInstall::STATUS_LIVE, $install->fresh()->status);
        $this->assertNull($install->fresh()->archived_at);
    }

    public function test_only_a_live_space_can_be_retired_and_only_by_the_team(): void
    {
        $client = $this->liveClient('salon');
        $client->install->update(['status' => TenantInstall::STATUS_FAILED]);
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)->post(route('castlit.admin.archive', $client))->assertSessionHas('error');
        $this->assertSame(TenantInstall::STATUS_FAILED, $client->install->fresh()->status);

        $client->install->update(['status' => TenantInstall::STATUS_LIVE]);
        $this->actingAs(User::factory()->create())->post(route('castlit.admin.archive', $client));
        $this->assertSame(TenantInstall::STATUS_LIVE, $client->install->fresh()->status, 'not a platform admin');
    }

    public function test_a_retired_client_leaves_approved_for_its_own_tab(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $kept = $this->liveClient('garde');
        $gone = $this->liveClient('retire');
        $gone->install->update(['status' => TenantInstall::STATUS_ARCHIVED]);

        $approved = $this->actingAs($admin)->get(route('castlit.admin.index', ['status' => 'approved']))->assertOk();
        $this->assertSame([$kept->id], $approved->viewData('subscriptions')->pluck('id')->all());
        $this->assertSame(1, $approved->viewData('counts')['approved']);
        $this->assertSame(1, $approved->viewData('counts')['archived']);
        $approved->assertSee('Retirés')->assertSee('data-stats-fold', false);
        $approved->assertSee('href="'.$kept->install->url().'"', false)->assertSee('data-space-link', false);

        $archived = $this->actingAs($admin)->get(route('castlit.admin.index', ['status' => 'archived']))->assertOk();
        $this->assertSame([$gone->id], $archived->viewData('subscriptions')->pluck('id')->all());
    }
}
