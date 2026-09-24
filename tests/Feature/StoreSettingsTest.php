<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The emplacements screen: magasins, dépôts and rayons.
 *
 * They live in the tenant's settings JSON rather than in a table, and every
 * user's access to them is stored by NAME — which is what makes a rename and
 * a delete more than a list edit.
 */
class StoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::firstOrFail();
        $this->user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $this->actingAs($this->user);
    }

    /** @param array<int, array<string, mixed>> $stores */
    private function withStores(array $stores, ?string $current = null): void
    {
        $settings = $this->tenant->settings ?? [];
        $settings['stores'] = $stores;
        $settings['current_store'] = $current ?? $stores[0]['key'];
        $this->tenant->update(['settings' => $settings]);
    }

    private function store(string $key, string $name, bool $active = true, string $type = 'store'): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'type' => $type,
            'address' => null,
            'phone' => null,
            'manager' => null,
            'is_active' => $active,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function storedStores(): array
    {
        return data_get($this->tenant->fresh()->settings, 'stores', []);
    }

    private function currentKey(): ?string
    {
        return data_get($this->tenant->fresh()->settings, 'current_store');
    }

    private function grantAccess(User $user, array $names): void
    {
        $this->tenant->users()->updateExistingPivot($user->id, [
            'store_access' => json_encode($names),
        ]);
    }

    private function accessOf(User $user): array
    {
        $pivot = $this->tenant->users()->whereKey($user->id)->first()?->pivot;

        return json_decode($pivot->store_access ?? '[]', true) ?: [];
    }

    private function update(string $key, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->put('/parametres/magasins/'.$key, $payload + [
            'name' => 'Magasin', 'type' => 'store',
        ]);
    }

    // ── Keeping a shop able to sell ───────────────────────────────────────────

    public function test_the_last_active_emplacement_cannot_be_deactivated(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Dépôt B', active: false, type: 'warehouse'),
        ]);

        // is_active omitted is how an unchecked box arrives.
        $this->update('a', ['name' => 'Magasin A'])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($this->storedStores()[0]['is_active']);
    }

    public function test_deactivating_one_of_several_is_allowed(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Magasin B'),
        ]);

        $this->update('b', ['name' => 'Magasin B'])->assertSessionHasNoErrors();

        $this->assertFalse($this->storedStores()[1]['is_active']);
    }

    public function test_deactivating_the_current_emplacement_moves_the_till(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Magasin B'),
        ], current: 'a');

        $this->update('a', ['name' => 'Magasin A'])->assertSessionHasNoErrors();

        // Otherwise the top bar sits on a store the list no longer offers, and
        // nothing can switch away from it.
        $this->assertSame('b', $this->currentKey());
    }

    public function test_the_last_active_emplacement_cannot_be_deleted(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Dépôt B', active: false, type: 'warehouse'),
        ]);

        // A refusal, not an error page: this is one click away from a list.
        $this->delete('/parametres/magasins/a')->assertSessionHasErrors('store');

        $this->assertCount(2, $this->storedStores());
    }

    public function test_deleting_the_current_emplacement_promotes_an_active_one(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Dépôt B', active: false, type: 'warehouse'),
            $this->store('c', 'Magasin C'),
        ], current: 'a');

        $this->delete('/parametres/magasins/a')->assertSessionHasNoErrors();

        // Not simply the first survivor: that one is disabled.
        $this->assertSame('c', $this->currentKey());
    }

    public function test_a_disabled_emplacement_cannot_be_made_current(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Dépôt B', active: false, type: 'warehouse'),
        ], current: 'a');

        $this->post('/parametres/magasin-courant', ['current_store' => 'b'])
            ->assertSessionHasErrors('current_store');

        $this->assertSame('a', $this->currentKey());
    }

    public function test_an_active_emplacement_can_be_made_current(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Magasin B'),
        ], current: 'a');

        $this->post('/parametres/magasin-courant', ['current_store' => 'b'])
            ->assertSessionHasNoErrors();

        $this->assertSame('b', $this->currentKey());
    }

    // ── Access follows the name ───────────────────────────────────────────────

    public function test_renaming_an_emplacement_keeps_the_team_s_access(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Magasin B'),
        ]);
        $this->grantAccess($this->user, ['Magasin A', 'Magasin B']);

        $this->put('/parametres/magasins/a', [
            'name' => 'Magasin Centre', 'type' => 'store', 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        // Access is stored by name, so without carrying it across the rename
        // silently revokes everyone who had the store.
        $this->assertSame(['Magasin Centre', 'Magasin B'], $this->accessOf($this->user));
    }

    public function test_deleting_an_emplacement_drops_it_from_the_team_s_access(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Magasin B'),
        ], current: 'b');
        $this->grantAccess($this->user, ['Magasin A', 'Magasin B']);

        $this->delete('/parametres/magasins/a')->assertSessionHasNoErrors();

        $this->assertSame(['Magasin B'], $this->accessOf($this->user));
    }

    public function test_a_user_who_never_had_access_is_not_written_to(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Magasin B'),
        ]);
        $other = $this->tenant->users()->where('users.id', '!=', $this->user->id)->firstOrFail();
        $this->tenant->users()->updateExistingPivot($other->id, ['store_access' => null]);

        $this->put('/parametres/magasins/a', [
            'name' => 'Magasin Centre', 'type' => 'store', 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        // Still null, not rewritten to an empty list: a rename is not a reason
        // to touch the access of everyone who never had the store.
        $pivot = $this->tenant->users()->whereKey($other->id)->first()?->pivot;
        $this->assertNull($pivot->store_access);
    }

    public function test_a_rename_leaves_other_users_alone(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Magasin B'),
        ]);
        $other = $this->tenant->users()->where('users.id', '!=', $this->user->id)->firstOrFail();
        $this->grantAccess($other, ['Magasin B']);

        $this->put('/parametres/magasins/a', [
            'name' => 'Magasin Centre', 'type' => 'store', 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Magasin B'], $this->accessOf($other));
    }

    // ── The screen ────────────────────────────────────────────────────────────

    private function screen(): \Illuminate\Testing\TestResponse
    {
        return $this->get('/modules/settings?section=warehouses');
    }

    public function test_the_card_shows_what_the_emplacement_actually_is(): void
    {
        $this->withStores([
            array_merge($this->store('a', 'Magasin A'), [
                'phone' => '+212 522 00 00 00',
                'manager' => 'Amina El Fassi',
                'address' => '12 rue des Lilas, Casablanca',
            ]),
            $this->store('b', 'Dépôt B', type: 'warehouse'),
        ]);

        $content = $this->screen()->assertOk()->getContent();

        // In the card's own facts list, not merely somewhere on the page: the
        // edit form carries the same strings as input values, so an unscoped
        // assertion passes on a card that shows nothing at all.
        $facts = [
            'Téléphone' => '\+212 522 00 00 00',
            'Responsable' => 'Amina El Fassi',
            'Adresse' => '12 rue des Lilas, Casablanca',
        ];
        foreach ($facts as $label => $value) {
            // Label AND value, adjacent: a bare string is not a fact, and a
            // column of unlabelled lines reads as guesswork.
            $this->assertMatchesRegularExpression(
                '#<dt[^>]*>\s*'.$label.'\s*</dt>\s*<dd[^>]*>\s*'.$value.'#',
                $content,
                'the card should print '.$label.' without being opened',
            );
        }
    }

    public function test_an_emplacement_with_nothing_filled_in_says_so(): void
    {
        $this->withStores([$this->store('a', 'Magasin A'), $this->store('b', 'Magasin B')]);

        $this->screen()->assertOk()->assertSee('Aucun contact ni adresse renseignés.');
    }

    public function test_the_current_emplacement_is_marked_and_not_offered_again(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Magasin B'),
        ], current: 'a');

        $screen = $this->screen()->assertOk();

        $screen->assertSee('Courant');
        // One button, for the other emplacement: promoting the current one is
        // a no-op that only adds noise.
        $this->assertSame(1, substr_count($screen->getContent(), 'Définir comme courant'));
    }

    public function test_a_disabled_emplacement_is_not_offered_as_current(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Dépôt B', active: false, type: 'warehouse'),
        ], current: 'a');

        $screen = $this->screen()->assertOk();

        $this->assertStringNotContainsString('Définir comme courant', $screen->getContent());
        $screen->assertSee('Désactivé');
    }

    public function test_the_last_active_emplacement_cannot_be_deleted_from_the_screen_either(): void
    {
        $this->withStores([
            $this->store('a', 'Magasin A'),
            $this->store('b', 'Dépôt B', active: false, type: 'warehouse'),
        ]);

        // The server refuses it; the button must say so before the click, not
        // after.
        $this->screen()->assertOk()->assertSee('Gardez au moins un emplacement actif.');
    }

    public function test_the_form_fields_are_labelled(): void
    {
        $this->withStores([$this->store('a', 'Magasin A'), $this->store('b', 'Magasin B')]);

        $content = $this->screen()->assertOk()->getContent();

        // Placeholders vanish the moment something is typed; a screen of
        // unlabelled boxes is unreadable once filled. Counted, because the
        // create dialog carries the same labels — one of each would pass on a
        // card whose own form lost them.
        foreach (['Nom *', 'Type *', 'Téléphone', 'Responsable', 'Adresse'] as $label) {
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($content, '>'.$label.'</span>'),
                $label.' should label a field on the card and in the dialog',
            );
        }
    }

    public function test_the_cards_can_be_searched(): void
    {
        $this->withStores([$this->store('a', 'Magasin A'), $this->store('b', 'Magasin B')]);

        $screen = $this->screen()->assertOk();

        $screen->assertSee('data-card-filter="settings-stores-grid"', false);
        // The card carries its own haystack: an opened card holds its values
        // in inputs, where textContent cannot see them.
        $screen->assertSee('data-filter-text', false);
    }
}
