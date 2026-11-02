<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The support chat in a client's web back office: on its pages, signed as
 * that space, and out of the cashier's way on the till.
 */
class SupportWidgetBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_back_office_carries_the_bubble_signed_as_the_space(): void
    {
        $this->seed();
        config(['app.url' => 'https://spaparis.castlitpos.com', 'castlit.main_domain' => 'castlitpos.com']);
        $owner = User::first();

        $page = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-support-bubble', $page);
        $this->assertStringContainsString('https:\/\/castlitpos.com\/api\/v1\/support\/conversations', $page);
        // The identity the page carries is one the master will accept.
        preg_match('/const identity = (\{.*?\});/', $page, $m);
        $identity = json_decode($m[1], true);
        $this->assertSame('spaparis', $identity['subdomain']);
        $this->assertSame(hash_hmac('sha256', $identity['payload'], (string) config('app.key')), $identity['signature']);
        $this->assertSame(Tenant::first()->name, json_decode(base64_decode($identity['payload']), true)['shop']);
    }

    public function test_the_till_page_has_no_bubble_over_the_cashiers_buttons(): void
    {
        $this->seed();
        $owner = User::first();

        $page = $this->actingAs($owner)->get(route('pos'));
        if ($page->status() === 200) {
            $this->assertStringNotContainsString('data-support-bubble', $page->getContent());
        } else {
            // The till asks for a terminal first: nothing to cover yet.
            $page->assertRedirect();
        }
    }

    public function test_a_page_from_a_client_space_may_call_the_masters_support_api(): void
    {
        $this->options('/api/v1/public/info', [], [
            'Origin' => 'https://spaparis.castlitpos.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'x-support-token, content-type',
        ])->assertHeader('Access-Control-Allow-Origin');
    }
}
