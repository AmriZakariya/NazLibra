<?php

namespace Tests\Unit;

use App\Support\AmountInWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The total in letters is the line an auditor reads when the figures on a
 * paper invoice look altered, so it has to be right in the awkward places —
 * which in French is most of the range above sixty.
 */
class AmountInWordsTest extends TestCase
{
    public static function integers(): array
    {
        return [
            'zero' => [0, 'zéro'],
            'the last simple word' => [16, 'seize'],
            'teens are built on ten' => [17, 'dix-sept'],
            'nineteen' => [19, 'dix-neuf'],
            'twenty one keeps its et' => [21, 'vingt et un'],
            'sixty one keeps its et' => [61, 'soixante et un'],
            'seventy counts in twenties' => [70, 'soixante-dix'],
            'seventy one keeps the et of sixty one' => [71, 'soixante et onze'],
            'seventy seven' => [77, 'soixante-dix-sept'],
            'eighty takes an s alone' => [80, 'quatre-vingts'],
            'eighty one drops both the s and the et' => [81, 'quatre-vingt-un'],
            'ninety' => [90, 'quatre-vingt-dix'],
            'ninety one takes no et' => [91, 'quatre-vingt-onze'],
            'ninety seven' => [97, 'quatre-vingt-dix-sept'],
            'one hundred has no un' => [100, 'cent'],
            'a hundred and eighty keeps the s' => [180, 'cent quatre-vingts'],
            'two hundred takes an s alone' => [200, 'deux cents'],
            'two hundred and ten drops it' => [210, 'deux cent dix'],
            'the largest three digits' => [999, 'neuf cent quatre-vingt-dix-neuf'],
            'one thousand has no un' => [1000, 'mille'],
            'thousand never takes an s' => [2000, 'deux mille'],
            'million is a noun and does' => [2_000_000, 'deux millions'],
            'a realistic invoice total' => [1_234_567, 'un million deux cent trente-quatre mille cinq cent soixante-sept'],
        ];
    }

    #[DataProvider('integers')]
    public function test_it_writes_integers_in_french(int $number, string $expected): void
    {
        $this->assertSame($expected, AmountInWords::integer($number));
    }

    public function test_it_names_the_currency_and_its_hundredths(): void
    {
        $this->assertSame('Mille quatre cent cinquante dirhams et soixante-quinze centimes', AmountInWords::money(1450.75));
    }

    public function test_one_and_zero_stay_singular(): void
    {
        $this->assertSame('Un dirham', AmountInWords::money(1));
        $this->assertSame('Zéro dirham', AmountInWords::money(0));
    }

    public function test_a_whole_amount_says_nothing_about_centimes(): void
    {
        $this->assertSame('Deux cents dirhams', AmountInWords::money(200));
    }

    public function test_cents_are_rounded_through_integers_not_floats(): void
    {
        // 1.13 is stored as 1.129999…, so truncating the hundredths prints an
        // amount one centime below the figure beside it — on the one line of
        // the invoice whose whole job is to corroborate that figure.
        $this->assertSame('Un dirham et treize centimes', AmountInWords::money(1.13));
        $this->assertSame('Zéro dirham et vingt-neuf centimes', AmountInWords::money(0.29));
        $this->assertSame('Cent quarante-cinq dirhams et trente centimes', AmountInWords::money(145.30));
    }

    public function test_another_currency_is_named_rather_than_assumed(): void
    {
        $this->assertSame('Dix euros et cinquante centimes', AmountInWords::money(10.5, 'EUR'));
    }
}
