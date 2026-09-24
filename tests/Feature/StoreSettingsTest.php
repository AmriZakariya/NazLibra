<?php

namespace Tests\Feature;

use App\Models\ItemLocationStock;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Support\Str;
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

    /**
     * Replaces the tenant's emplacements, and hands back a map from the short
     * label a test uses to the real key.
     *
     * The keys are location ids now: emplacements live in the `locations`
     * table, the same rows inventory and transfers read, rather than in a
     * second list in the settings JSON.
     *
     * @param  array<string, array<string, mixed>>  $stores
     * @return array<string, string>
     */
    private function withStores(array $stores, ?string $current = null): array
    {
        Location::where('tenant_id', $this->tenant->id)->delete();

        $keys = [];
        $first = true;
        foreach ($stores as $label => $attributes) {
            $location = Location::create(array_merge([
                'tenant_id' => $this->tenant->id,
                'type' => 'store',
                'is_active' => true,
                'is_default' => $first,
            ], $attributes));
            $keys[$label] = (string) $location->id;
            $first = false;
        }

        $settings = $this->tenant->settings ?? [];
        $settings['current_store'] = $keys[$current] ?? reset($keys);
        $this->tenant->update(['settings' => $settings]);

        $this->keys = $keys;

        return $keys;
    }

    /** @var array<string, string> */
    private array $keys = [];

    private function store(string $name, bool $active = true, string $type = 'store'): array
    {
        return ['name' => $name, 'type' => $type, 'is_active' => $active];
    }

    private function locations(): \Illuminate\Support\Collection
    {
        return Location::where('tenant_id', $this->tenant->id)->orderBy('id')->get();
    }

    private function isActive(string $label): bool
    {
        return (bool) Location::findOrFail($this->keys[$label])->is_active;
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

    private function update(string $label, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->put('/parametres/magasins/'.$this->keys[$label], $payload + [
            'name' => 'Magasin', 'type' => 'store',
        ]);
    }

    private function destroy(string $label): \Illuminate\Testing\TestResponse
    {
        return $this->delete('/parametres/magasins/'.$this->keys[$label]);
    }

    // ── Keeping a shop able to sell ───────────────────────────────────────────

    public function test_the_last_active_emplacement_cannot_be_deactivated(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Dépôt B', active: false, type: 'warehouse'),
        ]);

        // is_active omitted is how an unchecked box arrives.
        $this->update('a', ['name' => 'Magasin A'])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($this->isActive('a'));
    }

    public function test_deactivating_one_of_several_is_allowed(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Magasin B'),
        ]);

        $this->update('b', ['name' => 'Magasin B'])->assertSessionHasNoErrors();

        $this->assertFalse($this->isActive('b'));
    }

    public function test_deactivating_the_current_emplacement_moves_the_till(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Magasin B'),
        ], current: 'a');

        $this->update('a', ['name' => 'Magasin A'])->assertSessionHasNoErrors();

        // Otherwise the top bar sits on a store the list no longer offers, and
        // nothing can switch away from it.
        $this->assertSame($this->keys['b'], $this->currentKey());
    }

    public function test_the_last_active_emplacement_cannot_be_deleted(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Dépôt B', active: false, type: 'warehouse'),
        ]);

        // A refusal, not an error page: this is one click away from a list.
        $this->destroy('a')->assertSessionHasErrors('store');

        $this->assertCount(2, $this->locations());
    }

    public function test_deleting_the_current_emplacement_promotes_an_active_one(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Dépôt B', active: false, type: 'warehouse'),
            'c' => $this->store('Magasin C'),
        ], current: 'a');

        $this->destroy('a')->assertSessionHasNoErrors();

        // Not simply the first survivor: that one is disabled.
        $this->assertSame($this->keys['c'], $this->currentKey());
    }

    public function test_a_disabled_emplacement_cannot_be_made_current(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Dépôt B', active: false, type: 'warehouse'),
        ], current: 'a');

        $this->post('/parametres/magasin-courant', ['current_store' => $this->keys['b']])
            ->assertSessionHasErrors('current_store');

        $this->assertSame($this->keys['a'], $this->currentKey());
    }

    public function test_an_active_emplacement_can_be_made_current(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Magasin B'),
        ], current: 'a');

        $this->post('/parametres/magasin-courant', ['current_store' => $this->keys['b']])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->keys['b'], $this->currentKey());
    }

    // ── Access follows the name ───────────────────────────────────────────────

    public function test_renaming_an_emplacement_keeps_the_team_s_access(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Magasin B'),
        ]);
        $this->grantAccess($this->user, ['Magasin A', 'Magasin B']);

        $this->put('/parametres/magasins/'.$this->keys['a'], [
            'name' => 'Magasin Centre', 'type' => 'store', 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        // Access is stored by name, so without carrying it across the rename
        // silently revokes everyone who had the store.
        $this->assertSame(['Magasin Centre', 'Magasin B'], $this->accessOf($this->user));
    }

    public function test_deleting_an_emplacement_drops_it_from_the_team_s_access(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Magasin B'),
        ], current: 'b');
        $this->grantAccess($this->user, ['Magasin A', 'Magasin B']);

        $this->destroy('a')->assertSessionHasNoErrors();

        $this->assertSame(['Magasin B'], $this->accessOf($this->user));
    }

    public function test_a_user_who_never_had_access_is_not_written_to(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Magasin B'),
        ]);
        $other = $this->tenant->users()->where('users.id', '!=', $this->user->id)->firstOrFail();
        $this->tenant->users()->updateExistingPivot($other->id, ['store_access' => null]);

        $this->put('/parametres/magasins/'.$this->keys['a'], [
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
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Magasin B'),
        ]);
        $other = $this->tenant->users()->where('users.id', '!=', $this->user->id)->firstOrFail();
        $this->grantAccess($other, ['Magasin B']);

        $this->put('/parametres/magasins/'.$this->keys['a'], [
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
            'a' => array_merge($this->store('Magasin A'), [
                'phone' => '+212 522 00 00 00',
                'manager_name' => 'Amina El Fassi',
                'address' => '12 rue des Lilas, Casablanca',
            ]),
            'b' => $this->store('Dépôt B', type: 'warehouse'),
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
        $this->withStores(['a' => $this->store('Magasin A'), 'b' => $this->store('Magasin B')]);

        $this->screen()->assertOk()->assertSee('Aucun contact ni adresse renseignés.');
    }

    public function test_the_current_emplacement_is_marked_and_not_offered_again(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Magasin B'),
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
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Dépôt B', active: false, type: 'warehouse'),
        ], current: 'a');

        $screen = $this->screen()->assertOk();

        $this->assertStringNotContainsString('Définir comme courant', $screen->getContent());
        $screen->assertSee('Désactivé');
    }

    public function test_the_last_active_emplacement_cannot_be_deleted_from_the_screen_either(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Dépôt B', active: false, type: 'warehouse'),
        ]);

        // The server refuses it; the button must say so before the click, not
        // after.
        $this->screen()->assertOk()->assertSee('Gardez au moins un emplacement actif.');
    }

    public function test_the_form_fields_are_labelled(): void
    {
        $this->withStores(['a' => $this->store('Magasin A'), 'b' => $this->store('Magasin B')]);

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
        $this->withStores(['a' => $this->store('Magasin A'), 'b' => $this->store('Magasin B')]);

        $screen = $this->screen()->assertOk();

        $screen->assertSee('data-card-filter="settings-stores-grid"', false);
        // The card carries its own haystack: an opened card holds its values
        // in inputs, where textContent cannot see them.
        $screen->assertSee('data-filter-text', false);
    }

    // ── One emplacement, one meaning ──────────────────────────────────────────

    public function test_an_emplacement_created_here_can_receive_a_transfer(): void
    {
        $this->withStores(['a' => $this->store('Magasin A')]);

        $this->post('/parametres/magasins', [
            'name' => 'Garage', 'type' => 'warehouse', 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        // The whole point. Emplacements used to live in the settings JSON while
        // transfers read the `locations` table, so adding one here created
        // nothing stock could be moved to — and the transfer screen's own
        // warning sent people back to this page, which could not help.
        $content = $this->get('/stock?panel=stock-transfer-add')->assertOk()->getContent();

        // In the SOURCE dropdown, not merely somewhere on the page: the
        // redirect flashes "Emplacement Garage ajouté", and the toast carrying
        // it renders on this very request.
        $sources = \Illuminate\Support\Str::between($content, 'data-transfer-source', '</select>');
        $this->assertStringContainsString('>Garage</option>', $sources);
        // Raw, not escaped: the warning is literal text in the template, so an
        // escaped needle never matches it and the assertion passes on nothing.
        $this->assertStringNotContainsString(
            'Un transfert a besoin d\'au moins deux emplacements.',
            $content,
        );
    }

    public function test_one_emplacement_still_warns_on_the_transfer_screen(): void
    {
        $this->withStores(['a' => $this->store('Magasin A')]);

        $this->assertStringContainsString(
            'Un transfert a besoin d\'au moins deux emplacements.',
            $this->get('/stock?panel=stock-transfer-add')->assertOk()->getContent(),
        );
    }

    public function test_a_disabled_emplacement_is_not_offered_for_a_transfer(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Entrepôt Nord', active: false, type: 'warehouse'),
        ]);

        $content = $this->get('/stock?panel=stock-transfer-add')->assertOk()->getContent();
        $sources = \Illuminate\Support\Str::between($content, 'data-transfer-source', '</select>');

        $this->assertStringNotContainsString('Entrepôt Nord', $sources);
    }

    public function test_two_emplacements_cannot_share_a_name(): void
    {
        $this->withStores(['a' => $this->store('Magasin A'), 'b' => $this->store('Magasin B')]);

        // The table carries a unique (tenant, name). Without catching it the
        // screen answers a duplicate with a database error page.
        $this->post('/parametres/magasins', [
            'name' => 'Magasin A', 'type' => 'store', 'is_active' => 1,
        ])->assertSessionHasErrors('name');

        $this->assertCount(2, $this->locations());
    }

    public function test_a_rename_onto_another_name_is_refused(): void
    {
        $this->withStores(['a' => $this->store('Magasin A'), 'b' => $this->store('Magasin B')]);

        $this->update('b', ['name' => 'Magasin A'])->assertSessionHasErrors('name');
    }

    public function test_renaming_an_emplacement_to_its_own_name_is_fine(): void
    {
        $this->withStores(['a' => $this->store('Magasin A'), 'b' => $this->store('Magasin B')]);

        $this->update('a', ['name' => 'Magasin A', 'is_active' => 1])
            ->assertSessionHasNoErrors();
    }

    public function test_an_emplacement_holding_stock_cannot_be_deleted(): void
    {
        $this->withStores(['a' => $this->store('Magasin A'), 'b' => $this->store('Dépôt B', type: 'warehouse')]);
        $item = \App\Models\Item::where('tenant_id', $this->tenant->id)->firstOrFail();
        ItemLocationStock::create([
            'tenant_id' => $this->tenant->id,
            'item_id' => $item->id,
            'location_id' => $this->keys['b'],
            'quantity' => 4,
        ]);

        // An emplacement is an inventory identity, not a label: deleting one
        // that holds stock strands it where no screen can reach it.
        $this->destroy('b')->assertSessionHasErrors('store');

        $this->assertCount(2, $this->locations());
    }

    public function test_an_empty_emplacement_can_still_be_deleted(): void
    {
        $this->withStores(['a' => $this->store('Magasin A'), 'b' => $this->store('Dépôt B', type: 'warehouse')]);
        $item = \App\Models\Item::where('tenant_id', $this->tenant->id)->firstOrFail();
        // A row at zero is not stock: an emplacement that was counted down to
        // nothing must not be undeletable forever.
        ItemLocationStock::create([
            'tenant_id' => $this->tenant->id,
            'item_id' => $item->id,
            'location_id' => $this->keys['b'],
            'quantity' => 0,
        ]);

        $this->destroy('b')->assertSessionHasNoErrors();

        $this->assertCount(1, $this->locations());
    }

    public function test_the_default_emplacement_survives_a_deletion(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Magasin B'),
        ], current: 'b');

        // 'a' was created first, so it holds is_default — what inventory falls
        // back on when a write names no emplacement. Deleting it must hand
        // that job over, not leave the tenant without one.
        $this->destroy('a')->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            Location::where('tenant_id', $this->tenant->id)->where('is_default', true)->count(),
        );
    }

    public function test_setting_the_current_emplacement_moves_the_inventory_fallback(): void
    {
        $keys = $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Magasin B'),
        ], current: 'a');

        $this->post('/parametres/magasin-courant', ['current_store' => $keys['b']])
            ->assertSessionHasNoErrors();

        // Otherwise a write that names no emplacement lands somewhere other
        // than where the till is actually selling.
        $this->assertTrue((bool) Location::findOrFail($keys['b'])->is_default);
        $this->assertFalse((bool) Location::findOrFail($keys['a'])->is_default);
    }

    // ── The transfer screen's own footer ──────────────────────────────────────

    private function transferScreen(): \Illuminate\Testing\TestResponse
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Dépôt B', type: 'warehouse'),
        ]);

        return $this->get('/stock?panel=stock-transfer-add')->assertOk();
    }

    public function test_the_transfer_footer_talks_about_a_transfer(): void
    {
        $screen = $this->transferScreen();

        // It carried the stock ADJUSTMENT's wording and counters, on a form
        // that never updates them: "0 article(s) sélectionné(s)" whatever was
        // on screen, under a button offering to validate an adjustment.
        $screen->assertSee('Créer et envoyer');
        $screen->assertSee('unité(s) à déplacer.');
        $screen->assertDontSee('unité(s) saisie(s).');
        $screen->assertDontSee("Valider l'ajustement");
        $screen->assertSee('data-transfer-count', false);
        $screen->assertDontSee('data-stock-adjustment-count', false);
    }

    public function test_the_transfer_submits_live_in_the_footer(): void
    {
        $content = $this->transferScreen()->getContent();

        // Two by design — brouillon and créer-et-envoyer — but both in the
        // sticky bar. The header carried a third, worded differently again.
        $this->assertSame(2, substr_count($content, 'data-transfer-submit'));
        $this->assertStringNotContainsString('>Créer transfert<', $content);
        $footer = Str::between($content, 'data-transfer-count', '</form>');
        $this->assertSame(2, substr_count($footer, 'data-transfer-submit'));
    }

    public function test_the_transfer_submit_starts_disabled(): void
    {
        // Nothing is chosen yet, so there is nothing to send. Saying so on the
        // button beats a round trip that returns a validation error.
        $this->transferScreen()->assertSee('data-transfer-submit disabled', false);
    }

    public function test_the_transfer_panel_is_not_declared_twice(): void
    {
        // A second @elseif for the same panel sat below the live one, dead and
        // unreachable, and every edit risked landing in the wrong copy.
        $blade = file_get_contents(resource_path('views/librairepro/catalog.blade.php'));

        $this->assertSame(1, substr_count($blade, "\$panel === 'stock-transfer-add'"));
    }

    public function test_the_transfer_footer_stays_inside_its_card(): void
    {
        $content = $this->transferScreen()->getContent();

        // The bar closed a tag opened in another panel, so it escaped the card
        // and covered the summary beside it. Balance is what keeps it in.
        $form = \Illuminate\Support\Str::between(
            $content,
            'data-transfer-form',
            '</form>',
        );
        $this->assertSame(
            substr_count($form, '<div'),
            substr_count($form, '</div>'),
            'the transfer form opens and closes the same number of divs',
        );
    }

    public function test_an_unknown_current_key_falls_back_on_the_default(): void
    {
        $keys = $this->withStores([
            'a' => $this->store('Atelier'),
            'b' => $this->store('Zone B'),
        ]);
        // 'Atelier' was created first, so it holds is_default.
        $settings = $this->tenant->settings ?? [];
        $settings['current_store'] = 'un-vieux-slug';
        $this->tenant->update(['settings' => $settings]);
        Location::whereKey($keys['a'])->update(['is_default' => false]);
        Location::whereKey($keys['b'])->update(['is_default' => true]);

        // Where inventory writes land when nothing names an emplacement —
        // not whichever name happens to sort first.
        $this->screen()->assertOk()->assertSee('Courant · Zone B');
    }

    public function test_an_emplacement_with_a_movement_history_cannot_be_deleted(): void
    {
        $this->withStores(['a' => $this->store('Magasin A'), 'b' => $this->store('Dépôt B', type: 'warehouse')]);
        $item = \App\Models\Item::where('tenant_id', $this->tenant->id)->firstOrFail();
        \App\Models\InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'item_id' => $item->id,
            'location_id' => $this->keys['b'],
            'type' => 'sale',
            'quantity_before' => 1,
            'quantity_delta' => -1,
            'quantity_after' => 0,
            'occurred_at' => now(),
        ]);

        // Its stock is back at zero but the ledger still points at it. Deleting
        // it would leave movements referring to an emplacement no screen can
        // name, so the shop is told to disable it instead.
        $this->destroy('b')->assertSessionHasErrors('store');

        $this->assertCount(2, $this->locations());
    }

    // ── The built assets ──────────────────────────────────────────────────────

    private function builtAsset(string $extension): string
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        // The app's own bundle, not the first file that happens to match: the
        // manifest also lists the font stylesheet.
        $entry = collect($manifest)->first(fn (array $row): bool => str_ends_with($row['file'], $extension)
            && str_contains($row['file'], 'assets/app-'));

        $this->assertNotNull($entry, 'no built app'.$extension.' in the manifest');

        return file_get_contents(public_path('build/'.$entry['file']));
    }

    public function test_dark_styles_follow_the_app_theme_not_the_system(): void
    {
        // The app toggles a `dark` class on <html>, but Tailwind v4's default
        // `dark:` variant keys off prefers-color-scheme. The two contradicted
        // each other: on a Mac set to dark, a light screen still picked up the
        // dark styles — the transfer's sticky bar rendered black — and the
        // app's own theme button did nothing at all on a light system.
        $css = $this->builtAsset('.css');

        $this->assertStringContainsString('.dark', $css);
        $this->assertStringNotContainsString('prefers-color-scheme:dark', $css);
    }

    public function test_the_transfer_footer_counters_are_wired_in_the_bundle(): void
    {
        $js = $this->builtAsset('.js');

        // The footer reads its numbers from these; without them it shows zero
        // whatever is on screen, which is what the adjustment copy did.
        $this->assertStringContainsString('data-transfer-count', $js);
        $this->assertStringContainsString('data-transfer-total', $js);
        $this->assertStringContainsString('data-transfer-submit', $js);
    }

    // ── The route the warning gives is a route that exists ────────────────────

    /**
     * The labels the settings screen actually shows for a section, as the
     * group tab and the item button inside it.
     *
     * @return array{group: string, item: string}
     */
    private function settingsPathFor(string $section): array
    {
        $content = $this->get('/modules/settings?section='.$section)->assertOk()->getContent();

        // The ACTIVE group's own heading, which the page prints just above the
        // buttons for the sections inside it — not the first tab in the strip.
        $items = \Illuminate\Support\Str::between($content, 'aria-label="Options du groupe actif"', '</nav>');
        $heading = \Illuminate\Support\Str::beforeLast(
            \Illuminate\Support\Str::before($content, 'aria-label="Options du groupe actif"'),
            '</p>',
        );
        $group = trim(\Illuminate\Support\Str::afterLast(
            \Illuminate\Support\Str::beforeLast($heading, '</p>'),
            '>',
        ));

        preg_match('#section='.$section.'[^>]*>\s*([^<]+?)\s*</a>#', $items, $match);

        // Decoded, because both sides of the comparison are page text:
        // the heading holds `&amp;` and so does the warning.
        return [
            'group' => html_entity_decode($group),
            'item' => html_entity_decode($match[1] ?? ''),
        ];
    }

    public function test_the_transfer_warning_names_a_settings_entry_that_exists(): void
    {
        $this->withStores(['a' => $this->store('Magasin A')]);
        $labels = $this->settingsPathFor('warehouses');

        $this->assertNotSame('', $labels['item'], 'the warehouses section should have a nav label');

        $warning = \Illuminate\Support\Str::between(
            $this->get('/stock?panel=stock-transfer-add')->assertOk()->getContent(),
            'Un transfert a besoin',
            '</p>',
        );

        // It read "Paramètres → Emplacements", and no such entry existed: the
        // page sat under Store & activité and was called Magasins. Someone
        // following that path finds nothing and gives up.
        $this->assertStringContainsString($labels['item'], $warning);
        $this->assertStringContainsString($labels['group'], html_entity_decode($warning));
    }

    public function test_the_transfer_warning_links_to_that_page(): void
    {
        $this->withStores(['a' => $this->store('Magasin A')]);

        $warning = \Illuminate\Support\Str::between(
            $this->get('/stock?panel=stock-transfer-add')->assertOk()->getContent(),
            'Un transfert a besoin',
            '</p>',
        );

        // Prose describing a route is a route someone has to walk. A link is
        // one click, and it cannot go stale the way the wording did.
        $this->assertStringContainsString('section=warehouses', $warning);
    }

    public function test_the_settings_entry_is_named_for_everything_it_holds(): void
    {
        $this->withStores([
            'a' => $this->store('Magasin A'),
            'b' => $this->store('Dépôt B', type: 'warehouse'),
            'c' => $this->store('Rayon C', type: 'area'),
        ]);

        // "Magasins" named the list after one of the four things in it, while
        // the transfer screen, the stock screens and the page's own copy all
        // say emplacement.
        $this->assertSame('Emplacements', $this->settingsPathFor('warehouses')['item']);
    }
}
