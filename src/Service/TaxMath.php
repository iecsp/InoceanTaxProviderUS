<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Service;

final class TaxMath
{
    /** Gross prices include the COMBINED tax, not each component independently. */
    public static function amount(float $price, float $rate, bool $gross, int $decimals): float
    {
        if (!is_finite($price) || !is_finite($rate) || $rate < 0 || $rate > 100 || $decimals < 0 || $decimals > 6) {
            throw new \DomainException('Invalid price, tax rate or rounding precision.');
        }
        return round($price * $rate / ($gross ? 100 + $rate : 100), $decimals);
    }

    /** Include tax-free weights. Never allocate their share to taxable items. */
    public static function discount(float $discount, array $weights, array $rates, bool $gross, int $decimals): array
    {
        if ($discount > 0 || !is_finite($discount)) { throw new \DomainException('Unsupported promotion price.'); }
        $total = array_sum($weights);
        if ($discount === 0.0) { return []; }
        if ($total <= 0) { throw new \DomainException('Cannot determine promotion allocation.'); }
        $bases = [];
        foreach ($weights as $id => $weight) {
            if ($weight < 0 || !is_finite($weight) || !array_key_exists($id, $rates)) {
                throw new \DomainException('Unknown promotion composition.');
            }
            $rate = $rates[$id];
            if ($rate === null) { throw new \DomainException('Mixed-rate custom promotion target requires explicit support.'); }
            $key = (string) $rate;
            $bases[$key] = ($bases[$key] ?? 0.0) + $discount * $weight / $total;
        }
        $bands = [];
        foreach ($bases as $rate => $base) {
            $bands[] = ['rate' => (float) $rate, 'price' => $base, 'tax' => self::amount($base, (float) $rate, $gross, $decimals)];
        }
        return $bands;
    }
}
