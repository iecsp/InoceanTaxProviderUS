<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Core\Checkout\Cart\Tax;

use InoceanSalesTaxesUsa\Config\Constants;
use InoceanSalesTaxesUsa\Core\Checkout\Cart\Error\UnknownTaxZipError;
use InoceanSalesTaxesUsa\Service\RateLookup;
use InoceanSalesTaxesUsa\Service\CaliforniaAddressLookup;
use InoceanSalesTaxesUsa\Service\TaxMath;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\TaxProvider\AbstractTaxProvider;
use Shopware\Core\Checkout\Cart\TaxProvider\Struct\TaxProviderResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class UsaTaxProvider extends AbstractTaxProvider
{
    public function __construct(private readonly SystemConfigService $config, private readonly RateLookup $lookup,
        private readonly CaliforniaAddressLookup $california, private readonly LoggerInterface $logger) {}

    public function provide(Cart $cart, SalesChannelContext $context): TaxProviderResult
    {
        // The official address API supplies today's rate, not historical rates. Abort an admin
        // recalculation before it can silently rewrite an existing US order with current tax.
        if ($cart->hasExtension(\Shopware\Core\Checkout\Cart\Order\OrderConverter::ORIGINAL_ORDER_NUMBER)) {
            foreach ($cart->getLineItems()->getFlat() as $line) {
                if ($this->managed($line->getPayloadValue('taxId')) || $line->hasPayloadValue('inoceanUsaTaxVersion') || $line->hasPayloadValue('inoceanUsaTaxInfo')) {
                    throw new \RuntimeException('Historical USA order tax recalculation requires a reviewed historical tax adjustment. Existing saved amounts are preserved.');
                }
            }
        }
        $cart->getErrors()->remove(UnknownTaxZipError::KEY);
        // No request state or full-table cache lives in this singleton service.
        TaxPayloadCleaner::clear($cart);
        if ($context->getTaxState() === CartPrice::TAX_STATE_FREE || $context->getShippingLocation()->getCountry()->getIso() !== 'US') {
            return new TaxProviderResult();
        }
        $hasManaged = false;
        foreach ($cart->getLineItems()->getFlat() as $line) {
            $hasManaged = $hasManaged || $this->managed($line->getPayloadValue('taxId'));
        }
        foreach ($cart->getDeliveries() as $delivery) { $hasManaged = $hasManaged || $this->managed($delivery->getShippingMethod()->getTaxId()); }
        if (!$hasManaged) { return new TaxProviderResult(); }
        $channel = $context->getSalesChannelId();
        $address = $context->getShippingLocation()->getAddress();
        $state = strtoupper((string) $context->getShippingLocation()->getState()?->getShortCode());
        $state = str_starts_with($state, 'US-') ? substr($state, 3) : $state;
        $zip = (string) $address?->getZipcode();
        try {
            if (!$this->config->getBool('InoceanSalesTaxesUsa.config.Enabled', $channel)) {
                throw new \DomainException('USA tax provider must be configured and enabled.');
            }
            $nexus = array_values(array_filter(array_map('trim', explode(',', strtoupper((string) $this->config->get('InoceanSalesTaxesUsa.config.NexusStates', $channel))))));
            if ($nexus === [] || !preg_match('/^[A-Z]{2}$/D', $state)) {
                throw new \DomainException('Configure nexus states and a valid shipping state.');
            }
            foreach ($nexus as $code) {
                if (!in_array($code, Constants::STATE_CODES, true)) { throw new \DomainException('Invalid nexus configuration.'); }
            }
            // No geographic rate is needed for an entirely tax-free cart.
            $needsRate = false;
            foreach ($cart->getLineItems()->getFlat() as $candidate) {
                $needsRate = $needsRate || $candidate->getPayloadValue('taxId') === Constants::TAXES[0]['id'];
            }
            foreach ($cart->getDeliveries() as $candidate) {
                $needsRate = $needsRate || (($this->config->get('InoceanSalesTaxesUsa.config.FreightTaxable', $channel) ?? true) && $candidate->getShippingMethod()->getTaxId() === Constants::TAXES[0]['id']);
            }
            $release = $needsRate && in_array($state, $nexus, true)
                ? ($state === 'CA' ? $this->californiaRate($address, $channel) : $this->lookup->find($state, $zip, \InoceanSalesTaxesUsa\Service\TaxDate::today()))
                : ['rate' => 0.0, 'components' => [], 'datasetId' => $needsRate ? 'no-nexus' : 'tax-exempt', 'jurisdiction' => $state];
            $rate = (float) $release['rate'];
            $gross = $context->getTaxState() === CartPrice::TAX_STATE_GROSS;
            $decimals = $context->getItemRounding()->getDecimals();
            $lineTaxes = []; $deliveryTaxes = []; $aggregate = new CalculatedTaxCollection();
            $rates = []; $prices = []; $promotions = [];
            foreach ($cart->getLineItems() as $line) {
                if (!$line->getPrice() || $line->hasChildren()) { throw new \DomainException('Unpriced or nested items require explicit tax support.'); }
                if ($line->getType() === LineItem::PROMOTION_LINE_ITEM_TYPE) { $promotions[] = $line; continue; }
                $price = $line->getPrice()->getTotalPrice();
                if ($this->managed($line->getPayloadValue('taxId'))) {
                    $itemRate = $line->getPayloadValue('taxId') === Constants::TAXES[1]['id'] ? 0.0 : $rate;
                    $taxes = $this->taxes([['rate' => $itemRate, 'price' => $price, 'tax' => TaxMath::amount($price, $itemRate, $gross, $decimals)]]);
                    $lineTaxes[$line->getUniqueIdentifier()] = $taxes;
                    $line->setPayloadValue('inoceanUsaTaxVersion', $release);
                    $line->setPayloadValue('inoceanUsaTaxInfo', [['name' => 'CombinedRate', 'rate' => $itemRate, 'tax' => $taxes->getAmount()]]);
                    $rates[$line->getId()] = $itemRate;
                } else {
                    $taxes = $line->getPrice()->getCalculatedTaxes();
                    $rates[$line->getId()] = $taxes->count() <= 1 ? ($taxes->first()?->getTaxRate() ?? 0.0) : null;
                }
                $prices[$line->getId()] = max(0.0, $price);
                $aggregate->merge($taxes);
            }
            foreach ($promotions as $line) {
                $composition = $line->getPayloadValue('composition');
                $weights = [];
                // Falling back to all items can tax a shipping-only or restricted discount incorrectly.
                if (!is_array($composition) || $composition === []) { throw new \DomainException('Promotion requires an explicit item composition.'); }
                foreach ($composition as $entry) {
                    if (!is_array($entry) || !is_string($entry['id'] ?? null) || !is_numeric($entry['discount'] ?? null)) {
                        throw new \DomainException('Invalid promotion composition.');
                    }
                    $id = $entry['id'];
                    if (!array_key_exists($id, $rates)) { throw new \DomainException('Unknown promotion target.'); }
                    $weights[$id] = ($weights[$id] ?? 0.0) + abs((float) $entry['discount']);
                }
                foreach ($weights as $id => $weight) {
                    if ($weight > $prices[$id] + pow(10, -$decimals)) { throw new \DomainException('Promotion exceeds its item base.'); }
                    $prices[$id] -= $weight;
                }
                $discount = $line->getPrice()->getTotalPrice();
                if (abs(array_sum($weights) - abs($discount)) > pow(10, -$decimals) + 0.000001) {
                    throw new \DomainException('Promotion composition does not match its total.');
                }
                $bands = TaxMath::discount($discount, $weights, $rates, $gross, $decimals);
                $taxes = $this->taxes($bands);
                $lineTaxes[$line->getUniqueIdentifier()] = $taxes;
                $aggregate->merge($taxes);
                $line->setPayloadValue('inoceanUsaTaxInfo', array_map(static fn (array $b): array => ['name' => 'CombinedRate', 'rate' => $b['rate'], 'tax' => $b['tax']], $bands));
                $line->setPayloadValue('inoceanUsaTaxVersion', $release);
            }
            $freightTaxable = $this->config->get('InoceanSalesTaxesUsa.config.FreightTaxable', $channel) ?? true;
            $shippingInfo = [];
            foreach ($cart->getDeliveries() as $delivery) {
                // Different delivery addresses must not silently use the first delivery's jurisdiction.
                $location = $delivery->getLocation();
                if ($location->getCountry()->getIso() !== 'US' || $location->getAddress()?->getZipcode() !== $address?->getZipcode() || $location->getState()?->getId() !== $context->getShippingLocation()->getState()?->getId()) {
                    throw new \DomainException('Multiple shipping jurisdictions require separate orders.');
                }
                $taxId = $delivery->getShippingMethod()->getTaxId();
                if ($this->managed($taxId)) {
                    $shippingRate = $freightTaxable && $taxId === Constants::TAXES[0]['id'] ? $rate : 0.0;
                    $shippingPrice = $delivery->getShippingCosts()->getTotalPrice();
                    $taxes = $this->taxes([['rate' => $shippingRate, 'price' => $shippingPrice,
                        'tax' => TaxMath::amount($shippingPrice, $shippingRate, $gross, $decimals)]]);
                    $position = $delivery->getPositions()->first();
                    if (!$position) { throw new \DomainException('Delivery has no position.'); }
                    $deliveryTaxes[$position->getIdentifier()] = $taxes;
                    $shippingInfo[] = ['name' => 'CombinedRate', 'rate' => $shippingRate, 'tax' => $taxes->getAmount()];
                } else { $taxes = $delivery->getShippingCosts()->getCalculatedTaxes(); }
                $aggregate->merge($taxes);
            }
            if ($cart->getLineItems()->first()) { $cart->getLineItems()->first()->setPayloadValue('inoceanShippingTaxInfo', $shippingInfo); }
            return new TaxProviderResult($lineTaxes, $deliveryTaxes, $aggregate);
        } catch (\DomainException $e) {
            $cart->addErrors(new UnknownTaxZipError($zip, $state));
            $this->logger->warning('USA sales tax order blocked.', ['reason' => $e->getMessage(), 'salesChannelId' => $channel]);
            return new TaxProviderResult();
        } catch (\Throwable $e) {
            $cart->addErrors(new UnknownTaxZipError($zip, $state));
            $this->logger->error('USA sales tax local lookup unavailable.', ['exception' => $e]);
            return new TaxProviderResult();
        }
    }

    private function californiaRate(?\Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity $address, string $channel): array
    {
        if (!$address || !$this->config->getBool('InoceanSalesTaxesUsa.config.OfficialAddressVerification', $channel)) {
            throw new \DomainException('Enable official California address verification after reviewing address disclosure.');
        }
        return $this->california->find($address, \InoceanSalesTaxesUsa\Service\TaxDate::today(), $channel);
    }

    private function managed(mixed $taxId): bool { return in_array($taxId, array_column(Constants::TAXES, 'id'), true); }
    private function taxes(array $bands): CalculatedTaxCollection
    {
        $taxes = new CalculatedTaxCollection();
        foreach ($bands as $band) { $taxes->merge(new CalculatedTaxCollection([new CalculatedTax($band['tax'], $band['rate'], $band['price'])])); }
        return $taxes;
    }
}
