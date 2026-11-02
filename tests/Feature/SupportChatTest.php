<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SupportConversation;
use App\Models\TenantInstall;
use App\Models\User;
use App\Support\SupportChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * "Contacter le support": a shop or a visitor writes from the app or the
 * website, the team answers from castlit-admin, and each side sees what the
 * other has read.
 */
class SupportChatTest extends TestCase
{
    use ActsAsMasterInstall;
    use RefreshDatabase;

    private const API = '/api/v1/support/conversations';

    protected function setUp(): void
    {
        $this->actsAsMaster();
        Storage::fake('local');
        SupportChat::$keyResolver = null;
    }

    private function actsAsMaster(): void
    {
        putenv('CASTLIT_MASTER=true');
        $_ENV['CASTLIT_MASTER'] = 'true';
        $_SERVER['CASTLIT_MASTER'] = 'true';
        \Tests\TestCase::setUp();
    }

    /** @return array{0: string, 1: string} conversation id and secret */
    private function start(array $data = []): array
    {
        // A bare national number is read in the visitor's country.
        $json = $this->postJson(self::API, $data + [
            'name' => 'Salma', 'phone' => '0612345678', 'channel' => 'web', 'platform' => 'web',
        ], ['Accept-Language' => 'fr-MA'])->assertCreated()->json();

        return [$json['conversation_id'], $json['token']];
    }

    private function install(string $sub = 'atlas'): TenantInstall
    {
        $s = Subscription::create([
            'business_name' => 'Atlas Coiffure', 'contact_name' => 'C', 'email' => $sub.'@example.com',
            'desired_subdomain' => $sub, 'currency' => 'MAD', 'status' => Subscription::STATUS_APPROVED,
        ]);

        return TenantInstall::create([
            'subscription_id' => $s->id, 'subdomain' => $sub, 'domain' => $sub.'.castlitpos.com',
            'status' => TenantInstall::STATUS_LIVE, 'access_code' => 'K7M2QX',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'name' => 'Zak Support']);
    }

    public function test_a_visitor_must_leave_a_number_to_be_called_back(): void
    {
        $this->postJson(self::API, ['name' => 'Salma', 'channel' => 'web'])->assertStatus(422);

        [$id, $token] = $this->start();
        $conversation = SupportConversation::where('public_id', $id)->sole();
        $this->assertSame('+212612345678', $conversation->phone);
        $this->assertNull($conversation->install);
        $this->assertNotSame($token, $conversation->token_hash, 'only the hash is kept');
    }

    public function test_only_the_device_holding_the_secret_reads_the_thread(): void
    {
        [$id, $token] = $this->start();

        $this->getJson(self::API."/{$id}")->assertNotFound();
        $this->getJson(self::API."/{$id}", ['X-Support-Token' => 'guess'])->assertNotFound();
        $this->getJson(self::API."/{$id}", ['X-Support-Token' => $token])->assertOk();
    }

    public function test_text_photos_files_and_voice_notes_go_through_and_the_rest_does_not(): void
    {
        [$id, $token] = $this->start();
        $h = ['X-Support-Token' => $token];

        $this->postJson(self::API."/{$id}/messages", ['body' => 'Bonjour, la caisse ne imprime pas'], $h)->assertCreated();
        $photo = $this->post(self::API."/{$id}/messages", ['attachment' => UploadedFile::fake()->image('ticket.jpg')], $h + ['Accept' => 'application/json'])
            ->assertCreated()->json('message');
        $this->assertSame('image', $photo['attachment']['kind']);
        $voice = $this->post(self::API."/{$id}/messages", [
            'attachment' => UploadedFile::fake()->create('vocal.m4a', 40, 'video/mp4'), 'voice' => '1', 'duration' => '12',
        ], $h + ['Accept' => 'application/json'])->assertCreated()->json('message');
        $this->assertSame('audio', $voice['attachment']['kind'], 'a recording sniffed as video/mp4 is still a voice note');
        $this->assertSame(12, $voice['attachment']['duration_seconds']);
        $pdf = $this->post(self::API."/{$id}/messages", ['attachment' => UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf')], $h + ['Accept' => 'application/json'])
            ->assertCreated()->json('message');
        $this->assertSame('file', $pdf['attachment']['kind']);

        $this->post(self::API."/{$id}/messages", ['attachment' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')], $h + ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->post(self::API."/{$id}/messages", [
            'attachment' => UploadedFile::fake()->create('long.m4a', 40, 'audio/mp4'), 'voice' => '1', 'duration' => '600',
        ], $h + ['Accept' => 'application/json'])->assertStatus(422);
        $this->postJson(self::API."/{$id}/messages", ['body' => '   '], $h)->assertStatus(422);

        // The photo is served to the secret's holder only, inline.
        $this->get($photo['attachment']['url'])->assertNotFound();
        $this->get($photo['attachment']['url'].'?token='.$token)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(4, SupportConversation::sole()->messages()->count());
    }

    public function test_the_team_answers_and_each_side_sees_what_the_other_read(): void
    {
        [$id, $token] = $this->start();
        $h = ['X-Support-Token' => $token];
        $this->postJson(self::API."/{$id}/messages", ['body' => 'Bonjour'], $h)->assertCreated();
        $conversation = SupportConversation::sole();
        $admin = $this->admin();

        // Listed unread; opening it does not mark it read.
        $this->actingAs($admin)->get(route('castlit.admin.support'))->assertOk()->assertSee('Salma')->assertSee('data-support-unread', false);
        $this->actingAs($admin)->get(route('castlit.admin.support', ['c' => $conversation->id]))->assertOk();
        $this->assertSame(1, SupportChat::unreadConversations());

        $this->actingAs($admin)->postJson(route('castlit.admin.support.reply', $conversation), ['body' => 'Bonjour Salma, on regarde.'])
            ->assertOk()->assertJsonPath('message.author_name', 'Zak Support');
        $this->assertSame(0, SupportChat::unreadConversations(), 'answering is reading');

        $thread = $this->getJson(self::API."/{$id}", $h)->assertOk();
        $thread->assertJsonPath('unread', 1)->assertJsonCount(2, 'messages');
        $this->assertGreaterThan(0, $thread->json('read_up_to'), 'the client sees its message was read');

        $this->postJson(self::API."/{$id}/read", [], $h)->assertOk();
        $this->getJson(self::API."/{$id}", $h)->assertJsonPath('unread', 0);
        $this->actingAs($admin)->getJson(route('castlit.admin.support.poll', $conversation))
            ->assertOk()->assertJsonPath('read_up_to', $conversation->messages()->where('sender', 'support')->max('id'));
    }

    public function test_the_team_marks_read_and_unread_by_hand_and_closes(): void
    {
        [$id, $token] = $this->start();
        $this->postJson(self::API."/{$id}/messages", ['body' => 'Allô ?'], ['X-Support-Token' => $token]);
        $conversation = SupportConversation::sole();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('castlit.admin.support.read', $conversation), ['read' => '1'])->assertRedirect();
        $this->assertSame(0, SupportChat::unreadConversations());
        $this->actingAs($admin)->post(route('castlit.admin.support.read', $conversation), ['read' => '0'])->assertRedirect();
        $this->assertSame(1, SupportChat::unreadConversations());

        $this->actingAs($admin)->post(route('castlit.admin.support.status', $conversation), ['status' => 'closed'])->assertRedirect();
        $this->assertSame('closed', $conversation->fresh()->status);
        $this->actingAs($admin)->get(route('castlit.admin.support', ['filtre' => 'open']))->assertDontSee('Allô ?');

        // The client writing again reopens it.
        $this->postJson(self::API."/{$id}/messages", ['body' => 'Toujours là ?'], ['X-Support-Token' => $token]);
        $this->assertSame('open', $conversation->fresh()->status);
    }

    public function test_a_logged_in_app_is_known_by_its_signed_identity(): void
    {
        $install = $this->install();
        SupportChat::$keyResolver = fn (TenantInstall $i) => $i->subdomain === 'atlas' ? 'base64:client-key' : null;
        config(['app.url' => 'https://atlas.castlitpos.com', 'app.key' => 'base64:client-key']);
        $identity = SupportChat::signIdentity('Atlas Coiffure', 'Karim', 'karim@example.com');

        // No phone needed: the shop is known.
        $json = $this->postJson(self::API, ['name' => 'Karim', 'channel' => 'app', 'identity' => $identity])
            ->assertCreated()->json();
        $this->assertSame('atlas', $json['client']);
        $conversation = SupportConversation::sole();
        $this->assertSame($install->id, $conversation->tenant_install_id);
        $this->assertSame('Atlas Coiffure', $conversation->name);
        $this->assertSame('Karim', $conversation->user_name);

        // A forged one is just a visitor, and a visitor needs a number.
        $forged = ['signature' => str_repeat('0', 64)] + $identity;
        $this->postJson(self::API, ['name' => 'Karim', 'channel' => 'app', 'identity' => $forged])->assertStatus(422);

        // A visitor who logs in later is attached to the shop.
        [$id, $token] = $this->start();
        $this->postJson(self::API."/{$id}/identify", ['identity' => $identity], ['X-Support-Token' => $token])->assertOk();
        $this->assertSame($install->id, SupportConversation::where('public_id', $id)->value('tenant_install_id'));
    }

    public function test_the_landing_page_carries_the_chat_bubble(): void
    {
        $this->get('/')->assertOk()->assertSee('data-support-bubble', false);
    }
}
