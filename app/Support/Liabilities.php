<?php

namespace App\Support;

/**
 * Sale lines that are not revenue: a gift card or a package sold is money the
 * shop owes, not money it earned. One place says which lines they are, so a
 * report that sums sale lines subtracts the same ones as every other.
 */
final class Liabilities
{
    public const GIFT_CARD = 'gift_card';

    public const PACKAGE = 'package';

    /** A line paid for by a package session: priced 0, consumes a session. */
    public const PACKAGE_USE = 'package_use';

    /** Line kinds that are a liability rather than revenue. */
    public const KINDS = [self::GIFT_CARD, self::PACKAGE];

    /** The payment method of a gift card spent at checkout. */
    public const GIFT_CARD_PAYMENT = 'gift_card';

    /** SQL: the line's revenue, 0 for a gift card or package line. Both SQLite and MySQL. */
    public static function revenueSql(string $kindColumn = 'sale_items.kind', string $amountColumn = 'sale_items.total_price'): string
    {
        return "CASE WHEN COALESCE($kindColumn, '') IN ('gift_card', 'package') THEN 0 ELSE $amountColumn END";
    }

    /** SQL: the amount of a line of one kind only. */
    public static function kindSql(string $kind, string $kindColumn = 'sale_items.kind', string $amountColumn = 'sale_items.total_price'): string
    {
        return "CASE WHEN $kindColumn = '$kind' THEN $amountColumn ELSE 0 END";
    }
}
