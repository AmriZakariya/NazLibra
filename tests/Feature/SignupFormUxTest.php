<?php

namespace Tests\Feature;

use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * A refused signup used to reload castlitpos.com at the top, with
 * « validation.required » under the field. It now says what is wrong in
 * the visitor's language, and opens on the form.
 */
class SignupFormUxTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        Queue::fake();
        Bus::fake();
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Spa Paris', 'contact_name' => 'Majda', 'email' => 'majda@example.com',
            'desired_subdomain' => 'spaparis', 'currency' => 'EUR', 'country' => 'FR',
        ], $overrides);
    }

    public function test_a_refused_signup_reopens_on_the_form_in_french(): void
    {
        $response = $this->from('/')->post('/inscription', $this->form(['desired_subdomain' => '']));

        $response->assertRedirect(url('/').'#inscription');
        $page = $this->get('/?lang=fr')->assertOk()->getContent();
        $this->assertStringContainsString('Le champ adresse souhaitée est obligatoire.', $page);
        $this->assertStringNotContainsString('validation.required', $page);
    }

    public function test_the_language_of_the_page_is_kept(): void
    {
        $this->from('/?lang=ar')->post('/inscription', $this->form(['email' => 'pas-un-email']))
            ->assertRedirect(url('/').'?lang=ar#inscription');
    }

    public function test_a_page_from_elsewhere_is_never_where_it_sends_you(): void
    {
        $this->from('https://evil.example/phish')->post('/inscription', $this->form(['business_name' => '']))
            ->assertRedirect(route('castlit.landing').'#inscription');
    }

    public function test_an_address_already_asked_for_is_refused_on_the_form(): void
    {
        Subscription::create($this->form() + ['status' => Subscription::STATUS_PENDING]);

        $this->from('/')->post('/inscription', $this->form(['email' => 'autre@example.com']))
            ->assertRedirect(url('/').'#inscription')
            ->assertSessionHasErrors('desired_subdomain');
    }

    public function test_the_form_asks_whether_an_address_is_free_while_typing(): void
    {
        Subscription::create($this->form() + ['status' => Subscription::STATUS_PENDING]);
        $reserved = config('castlit.reserved_subdomains')[0];
        $url = route('castlit.subscribe.availability');

        $this->getJson($url.'?adresse=salonnadia')->assertExactJson(['subdomain' => 'salonnadia', 'available' => true, 'reason' => null]);
        $this->getJson($url.'?adresse=SpaParis')->assertJsonPath('available', false)->assertJsonPath('reason', 'taken');
        $this->getJson($url.'?adresse='.$reserved)->assertJsonPath('reason', 'reserved');
        $this->getJson($url.'?adresse=x')->assertJsonPath('reason', 'format');
    }

    public function test_the_page_checks_the_form_before_sending_it(): void
    {
        $page = $this->get('/?lang=fr')->assertOk()->getContent();

        $this->assertStringContainsString('data-signup-form', $page);
        $this->assertStringContainsString('Ce champ est obligatoire.', $page);
        $this->assertStringContainsString(route('castlit.subscribe.availability'), str_replace('\/', '/', $page));
    }

    public function test_arabic_messages_are_sentences_too(): void
    {
        app()->setLocale('ar');

        $this->assertSame('حقل العنوان المطلوب مطلوب.', __('validation.required', ['attribute' => __('validation.attributes.desired_subdomain')]));
    }
}
