<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_messaging_sections_render(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'settings', 'section' => 'messaging']))
            ->assertOk()
            ->assertSeeText('Messages clients, modèles & canaux')
            ->assertSeeText('Envoyer un message');

        $this->get(route('module', ['module' => 'settings', 'section' => 'message-templates']))
            ->assertOk()
            ->assertSeeText('Modèles de messagerie');

        $this->get(route('module', ['module' => 'settings', 'section' => 'sms-api']))
            ->assertOk()
            ->assertSeeText('API & canaux');
    }

    public function test_can_update_messaging_settings(): void
    {
        $this->seed();

        $this->post(route('settings.messaging.update'), [
            'default_channel' => 'sms',
            'sender_name' => 'Oubra',
            'reply_to' => 'reply@example.test',
            'sms_provider' => 'twilio',
            'sms_sender_id' => 'OUBRA',
            'sms_api_key' => 'secret',
            'whatsapp_provider' => 'meta',
            'whatsapp_number' => '+212600000000',
            'whatsapp_token' => 'wa-token',
            'email_provider' => 'smtp',
            'email_from' => 'no-reply@example.test',
            'test_mode' => '1',
            'log_messages' => '1',
        ])->assertRedirect();

        $tenant = Tenant::firstOrFail()->fresh();

        $this->assertSame('sms', data_get($tenant->settings, 'messaging.default_channel'));
        $this->assertSame('twilio', data_get($tenant->settings, 'messaging.sms_provider'));
        $this->assertTrue(data_get($tenant->settings, 'messaging.test_mode'));
    }

    public function test_can_create_template_and_log_manual_message(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $contact = Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->firstOrFail();

        $this->post(route('settings.message-templates.store'), [
            'name' => 'Arrivage test',
            'channel' => 'whatsapp',
            'subject' => 'Arrivage',
            'body' => 'Bonjour {{client_name}}, arrivage disponible chez {{store_name}}.',
            'is_active' => '1',
        ])->assertRedirect();

        $this->post(route('settings.messaging.send'), [
            'channel' => 'whatsapp',
            'recipient_mode' => 'contact',
            'contact_id' => $contact->id,
            'subject' => 'Info',
            'body' => 'Bonjour {{client_name}}, test {{store_name}}.',
        ])->assertRedirect();

        $tenant = $tenant->fresh();

        $this->assertTrue(collect(data_get($tenant->settings, 'message_templates', []))->contains('name', 'Arrivage test'));
        $this->assertSame('simulated', data_get($tenant->settings, 'messaging_outbox.0.status'));
        $this->assertStringContainsString($contact->name, data_get($tenant->settings, 'messaging_outbox.0.body'));
    }
}
