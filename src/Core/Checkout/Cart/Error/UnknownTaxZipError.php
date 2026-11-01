<?php declare(strict_types=1);

namespace InoceanSalesTaxesUsa\Core\Checkout\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

class UnknownTaxZipError extends Error
{
    public const KEY = 'inocean-usa-unknown-tax-zip';

    public function __construct(
        private readonly string $zipCode,
        private readonly string $state,
    ) {
        $this->message = \sprintf(
            'Sales tax cannot be verified for ZIP code "%s" (%s). Check the full shipping address and state. If verification is pending, refresh shortly; otherwise contact the merchant to verify tax configuration and current data.',
            $zipCode,
            $state
        );

        parent::__construct($this->message);
    }

    public function isPersistent(): bool { return false; }

    public function getId(): string
    {
        return self::KEY;
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        return ['zipcode' => $this->zipCode, 'state' => $this->state];
    }
}
