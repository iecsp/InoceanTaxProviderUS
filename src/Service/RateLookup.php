<?php declare(strict_types=1);
namespace InoceanTaxProviderUS\Service;

interface RateLookup
{
    /** Rates are percentages. Missing, ambiguous and expired data must throw. */
    public function find(string $state, string $postalCode, string $date): array;
}
