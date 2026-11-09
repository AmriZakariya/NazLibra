<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SupportConversation;
use App\Models\TenantInstall;
use App\Models\User;
use App\Support\SupportChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * The team writes to a client first. The message goes into the shop's
 * conversation, which any of the shop's logged-in devices joins — so it is
 * seen wherever someone opens the back office or the app next.
 */
class SupportShopThreadTest extends TestCase
{
    use ActsAsMasterInstall {
        tearDown as actsAsMasterTearDown;
    }
    use RefreshDatabase;

    private const API = '/api/v1/support/conversations';

    protected function tearDown(): void
    {
        SupportChat::$keyResolver = null;
        $this->actsAsMasterTearDown();
    }

    private function install(string $sub = 'atlas'): TenantInstall
    {
        $s = Subscription::create([
            'business_name' => 'Atlas Coiffure', 'contact_name' => 'Karim', 'email' => $sub.'@example.com',
            'phone' => '+212612345678', 'desired_subdomain' => $sub, 'currency' => 'MAD',
            'status' => Subscription::STATUS_APPROVED,
        ]);

        return TenantInstall::create([
            'subscription_id' => $s->id, 'subdomain' => $sub, 'domain' => $sub.'.castlitpos.com',
            'status' => TenantInstall::STATUS_LIVE, 'access_code' => 'K7M2QX', 'owner_email' => 'owner@'.$sub.'.ma',
        ]);
    }

    /** A signed identity, as the shop's own server would give its logged-in device. */
    private function identity(string $sub = 'atlas'): array
    {
        SupportChat::$keyResolver = fn (TenantInstall $i) => 'base64:key-of-'.$i->subdomain;
        $master = config('app.key');
        config(['app.url' => "https://{$sub}.castlitpos.com", 'app.key' => 'base64:key-of-'.$sub]);
        $identity = SupportChat::signIdentity('Atlas Coiffure', 'Karim', 'karim@example.com');
        config(['app.key' => $master]);

        return $identity;
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'name' => 'Zak Support']);
    }

    public function test_the_team_writes_first_and_the_shop_finds_it_on_any_device(): void
    {
        $install = $this->install();

        $this->actingAs($this->admin())
            ->post(route('castlit.admin.support.write', $install->subscription_id), ['body' => 'Bonjour, tout va bien avec la caisse ?'])
            ->assertSessionHas('success');

        $conversation = SupportConversation::sole();
        $this->assertSame($install->id, $conversation->tenant_install_id);
        $this->assertSame('support', $conversation->channel);
        $this->assertSame('Atlas Coiffure', $conversation->name);

        // A logged-in device of the shop joins it, with a badge waiting.
        $joined = $this->postJson(self::API.'/join', ['identity' => $this->identity()])->assertOk()->json();
        $this->assertSame($conversation->public_id, $joined['conversation_id']);
        $this->assertSame(1, $joined['unread']);

        $thread = $this->getJson(self::API.'/'.$joined['conversation_id'], ['X-Support-Token' => $joined['token']])->assertOk()->json();
        $this->assertSame('Bonjour, tout va bien avec la caisse ?', $thread['messages'][0]['body']);
        $this->assertSame('support', $thread['messages'][0]['sender']);

        // And answers in it.
        $this->postJson(self::API.'/'.$joined['conversation_id'].'/messages', ['body' => 'Oui merci !'], ['X-Support-Token' => $joined['token']])
            ->assertCreated();
        $this->assertSame(1, SupportConversation::count());
    }

    public function test_the_team_writes_into_the_conversation_the_shop_already_has(): void
    {
        $install = $this->install();
        [$older] = SupportChat::start(['name' => 'Atlas Coiffure', 'channel' => 'web'], $install);
        SupportChat::post($older, 'client', 'Ancienne question');
        [$latest] = SupportChat::start(['name' => 'Atlas Coiffure', 'channel' => 'app'], $install);
        SupportChat::post($latest, 'client', 'Le ticket ne sort pas');
        $latest->forceFill(['last_message_at' => now()->addMinute()])->save();

        $this->actingAs($this->admin())
            ->post(route('castlit.admin.support.write', $install->subscription_id), ['body' => 'Nous regardons.']);

        $this->assertSame(2, SupportConversation::count());
        $this->assertSame('Nous regardons.', $latest->messages()->latest('id')->value('body'));
        $this->assertSame(1, $older->messages()->count());
    }

    public function test_the_shop_key_opens_only_that_shops_conversation(): void
    {
        $atlas = $this->install('atlas');
        $other = $this->install('nour');
        [$theirs] = SupportChat::start(['name' => 'Nour', 'channel' => 'web'], $other);
        SupportChat::writeToShop($atlas, 'Bonjour', 'Zak');

        $joined = $this->postJson(self::API.'/join', ['identity' => $this->identity('atlas')])->json();
        $this->getJson(self::API.'/'.$theirs->public_id, ['X-Support-Token' => $joined['token']])->assertNotFound();

        // A forged identity joins nothing.
        $forged = ['signature' => str_repeat('0', 64)] + $this->identity('atlas');
        $this->postJson(self::API.'/join', ['identity' => $forged])->assertForbidden();
    }

    public function test_a_shop_with_no_conversation_joins_nothing_yet(): void
    {
        $this->install();

        $this->postJson(self::API.'/join', ['identity' => $this->identity()])
            ->assertOk()->assertJson(['ok' => true, 'conversation_id' => null]);
    }

    public function test_the_clients_page_has_the_support_card(): void
    {
        $install = $this->install();
        [$conversation] = SupportChat::start(['name' => 'Atlas Coiffure', 'channel' => 'app'], $install);
        SupportChat::post($conversation, 'client', 'Le ticket ne sort pas');

        $this->actingAs($this->admin())
            ->get('/castlit-admin/'.$install->subscription_id)
            ->assertOk()
            ->assertSee('data-support-card', false)
            ->assertSee('Écrire à ce client')
            ->assertSee('https://wa.me/212612345678', false)
            ->assertSee('mailto:owner@atlas.ma', false)
            ->assertSee('Le ticket ne sort pas')
            ->assertSee('1 non lu');
    }
}
