<?php

namespace App\Support;

/**
 * Admin Sale Report money summary. Total Sales is the existing line total and
 * is never recalculated here.
 */
class AdminSaleReportSummary
{
    /**
     * @param  array{
     *     gross?: float|int|string,
     *     item_discount?: float|int|string,
     *     extra_discount?: float|int|string,
     *     coupon_discount?: float|int|string,
     *     referral_discount?: float|int|string,
     *     tax?: float|int|string,
     *     delivery_fees?: float|int|string,
     *     total_sales?: float|int|string
     * }  $parts
     * @return array{
     *     gross_sales: float,
     *     total_discounts: float,
     *     net_sales: float,
     *     tax: float,
     *     delivery_fees: float,
     *     total_sales: float
     * }
     */
    public static function fromParts(array $parts): array
    {
        $gross = self::money($parts['gross'] ?? 0);
        $discounts = self::money($parts['item_discount'] ?? 0)
            + self::money($parts['extra_discount'] ?? 0)
            + self::money($parts['coupon_discount'] ?? 0)
            + self::money($parts['referral_discount'] ?? 0);

        return [
            'gross_sales' => $gross,
            'total_discounts' => $discounts,
            'net_sales' => $gross - $discounts,
            'tax' => self::money($parts['tax'] ?? 0),
            'delivery_fees' => self::money($parts['delivery_fees'] ?? 0),
            'total_sales' => self::money($parts['total_sales'] ?? 0),
        ];
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
