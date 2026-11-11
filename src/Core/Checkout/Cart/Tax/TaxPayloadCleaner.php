<?php declare(strict_types=1);
namespace InoceanTaxProviderUS\Core\Checkout\Cart\Tax;
use Shopware\Core\Checkout\Cart\{Cart, CartBehavior, CartDataCollectorInterface};
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
final class TaxPayloadCleaner implements CartDataCollectorInterface
{
    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        self::clear($original);
    }
    public static function clear(Cart $cart): void
    {
        if ($cart->hasExtension(\Shopware\Core\Checkout\Cart\Order\OrderConverter::ORIGINAL_ORDER_NUMBER)) { return; }
        // Also runs when a country or tax-exemption change makes the provider ineligible.
        foreach ($cart->getLineItems()->getFlat() as $line) {
            foreach (['inoceanUsaTaxInfo', 'inoceanShippingTaxInfo', 'inoceanUsaTaxVersion'] as $key) {
                if ($line->hasPayloadValue($key)) { $line->removePayloadValue($key); }
            }
        }
    }
}
