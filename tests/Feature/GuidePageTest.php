<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The client manual, published at /guide on the marketing site.
 *
 * It is the page a shopkeeper opens from the till, from their phone, and
 * before they have bought anything — so it is public, and it has to survive
 * the things that quietly break a long document.
 */
class GuidePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Routes are registered at boot from this flag, so config() set inside
        // a test comes too late.
        putenv('CASTLIT_MASTER=true');
        $_ENV['CASTLIT_MASTER'] = 'true';
        parent::setUp();
    }

    private function page(): string
    {
        return $this->get('/guide')->assertOk()->getContent();
    }

    /**
     * One chapter's markup.
     *
     * Str::between() runs to the LAST needle, which here would swallow every
     * chapter after this one and let an assertion pass on text that lives
     * somewhere else entirely.
     */
    private function chapter(string $anchor): string
    {
        return Str::before(Str::after($this->page(), 'id="'.$anchor.'"'), '</article>');
    }

    public function test_the_guide_is_public(): void
    {
        // No login: a prospect reads it before there is an account to log into.
        $this->get('/guide')->assertOk();
        $this->get('/manuel')->assertOk();
    }

    public function test_every_chapter_in_the_sommaire_exists_on_the_page(): void
    {
        $page = $this->page();
        $toc = Str::between($page, 'aria-label="Sommaire"', '</nav>');

        // Digits allowed: without them the regex silently skips `#stock2`
        // instead of reporting it, and the check passes on a broken link.
        preg_match_all('/href="#([a-z0-9-]+)"/', $toc, $anchors);
        $this->assertGreaterThanOrEqual(15, count($anchors[1]));
        $this->assertSame(
            substr_count($toc, 'href="#'),
            count($anchors[1]),
            'une entrée du sommaire a une ancre que ce test ne sait pas lire',
        );

        // A sommaire entry pointing at nothing is the classic way a long
        // document rots: the chapter gets renamed, the link does not.
        foreach ($anchors[1] as $anchor) {
            $this->assertStringContainsString('id="'.$anchor.'"', $page, "le chapitre #$anchor est absent");
        }
    }

    public function test_the_chapters_are_numbered_in_order(): void
    {
        preg_match_all('/<span class="num">(\d+)<\/span>/', $this->page(), $numbers);

        $this->assertSame(
            range(1, count($numbers[1])),
            array_map('intval', $numbers[1]),
            'la numérotation des chapitres saute ou se répète',
        );
    }

    public function test_it_explains_the_choice_that_costs_money_to_get_wrong(): void
    {
        $chapter = $this->chapter('declinaisons');

        $this->assertStringContainsString('Ai-je besoin de compter ce stock séparément', $chapter);
        $this->assertStringContainsString('Déclinaison', $chapter);
        $this->assertStringContainsString('Option de ligne', $chapter);
    }

    public function test_it_describes_the_transfer_stages_the_app_actually_has(): void
    {
        $chapter = $this->chapter('transferts');

        // The stages as the DIAGRAM shows them, in order. Asserting on the
        // chapter text passes while the diagram is wrong: "En transit" also
        // appears in the table of buttons below it.
        $flow = Str::before(Str::after($chapter, '<div class="flow">'), '</div>');
        preg_match_all('/<span class="step[^"]*">([^<]+)<\/span>/', $flow, $steps);

        $this->assertSame(['Brouillon', 'En transit', 'Reçu'], $steps[1]);
        // And the three ways to conclude a transfer, which the app offers.
        foreach (['Enregistrer le brouillon', 'Créer et envoyer', 'Transférer maintenant'] as $button) {
            $this->assertStringContainsString($button, $chapter);
        }
    }

    public function test_the_contact_address_is_the_real_one(): void
    {
        $this->assertStringContainsString(config('castlit.brand.email'), $this->page());
    }

    public function test_callouts_do_not_paint_light_boxes_on_a_dark_page(): void
    {
        // The marketing layout is dark. A hardcoded near-white panel put the
        // body text at rgb(243,245,250) on rgb(242,245,254) — present in the
        // HTML, invisible on screen.
        $page = $this->page();
        $css = Str::between($page, '.note {', '.cards {');

        $this->assertStringNotContainsString('#FFF9EC', $css);
        $this->assertStringNotContainsString('#F2F5FE', $css);
        $this->assertStringContainsString('var(--surface)', $css);
    }
}
