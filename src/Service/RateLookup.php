<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Service;

interface RateLookup
{
    /** Rates are percentages. Missing, ambiguous and expired data must throw. */
    public function find(string $state, string $postalCode, string $date): array;
}
