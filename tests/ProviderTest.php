<?php declare(strict_types=1);
namespace InoceanTaxProviderUS\Tests;
use Doctrine\DBAL\Connection;
use InoceanTaxProviderUS\Config\Constants;
use InoceanTaxProviderUS\Core\Checkout\Cart\Tax\UsaTaxProvider;
use InoceanTaxProviderUS\Core\Checkout\Cart\Error\UnknownTaxZipError;
use InoceanTaxProviderUS\Service\RateLookup;
use InoceanTaxProviderUS\Service\CaliforniaAddressLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\{Delivery, DeliveryCollection, DeliveryDate, DeliveryPosition, DeliveryPositionCollection, ShippingLocation};
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Messenger\MessageBus;
final class ProviderTest extends TestCase
{
    private function provider(?RateLookup $lookup = null, bool $freight = true, bool $enabled = true): UsaTaxProvider
    {
        $config = $this->createStub(SystemConfigService::class);
        $config->method('getBool')->willReturn($enabled);
        $config->method('get')->willReturnCallback(static fn ($key, $channel) => match ($key) {
            'InoceanTaxProviderUS.config.NexusStates' => 'WA', 'InoceanTaxProviderUS.config.FreightTaxable' => $freight, default => null });
        $lookup ??= new class implements RateLookup { public function find(string $state, string $postalCode, string $date): array { return ['rate' => 10.5, 'components' => ['StateRate' => 6.5, 'LocalRate' => 4.], 'datasetId' => 'test', 'jurisdiction' => '1702']; } };
        return new UsaTaxProvider($config, $lookup, new CaliforniaAddressLookup($this->createStub(Connection::class), new MessageBus(), new MockHttpClient(), $config), new NullLogger());
    }
    private function context(string $tax = CartPrice::TAX_STATE_NET, string $country = 'US', string $stateCode = 'WA'): SalesChannelContext
    {
        $countryEntity = new CountryEntity(); $countryEntity->setIso($country);
        $state = new CountryStateEntity(); $state->setShortCode($stateCode); $state->setId('state');
        $address = new CustomerAddressEntity(); $address->setId(str_repeat('a',32)); $address->setCountry($countryEntity); $address->setCountryState($state); $address->setZipcode('98001-0000');
        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getShippingLocation')->willReturn(ShippingLocation::createFromAddress($address));
        $context->method('getTaxState')->willReturn($tax);
        $context->method('getSalesChannelId')->willReturn(str_repeat('b',32));
        $context->method('getTotalRounding')->willReturn(new CashRoundingConfig(2, .01, true));
        $context->method('getTaxCalculationType')->willReturn(\Shopware\Core\System\SalesChannel\SalesChannelDefinition::CALCULATION_TYPE_HORIZONTAL);
        $payment = new \Shopware\Core\Checkout\Payment\PaymentMethodEntity(); $payment->setId(str_repeat('c',32));
        $context->method('getPaymentMethod')->willReturn($payment);
        $context->method('getItemRounding')->willReturn(new CashRoundingConfig(2, .01, true));
        return $context;
    }
    private function price(float $value, float $rate = 10.): CalculatedPrice { return new CalculatedPrice($value, $value, new CalculatedTaxCollection([new CalculatedTax($value * $rate / 100, $rate, $value)]), new TaxRuleCollection()); }
    private function item(string $id, float $value, bool $exempt = false): LineItem
    {
        $line = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE); $line->setPrice($this->price($value));
        $line->setPayloadValue('taxId', Constants::TAXES[$exempt ? 1 : 0]['id']); return $line;
    }
    public function testNetAndOneLookupPerCart(): void
    {
        $lookup = $this->createMock(RateLookup::class); $lookup->expects(self::once())->method('find')->willReturn(['rate' => 10.5, 'datasetId' => 'test']);
        $cart = new Cart('test'); $cart->add($this->item('a',100)); $cart->add($this->item('b',100));
        $result = $this->provider($lookup)->provide($cart, $this->context());
        self::assertSame(21., $result->getCartPriceTaxes()->getAmount()); self::assertCount(1, $result->getCartPriceTaxes()); self::assertCount(0, $cart->getErrors());
    }
    public function testGrossAmountUsesCombinedRate(): void
    {
        $cart = new Cart('test'); $cart->add($this->item('a',110.5));
        self::assertSame(10.5, $this->provider()->provide($cart, $this->context(CartPrice::TAX_STATE_GROSS))->getCartPriceTaxes()->getAmount());
    }
    public function testMixedExemptPromotion(): void
    {
        $cart = new Cart('test'); $cart->add($this->item('a',100)); $cart->add($this->item('b',100,true));
        $promo = new LineItem('discount', LineItem::PROMOTION_LINE_ITEM_TYPE); $promo->setPrice($this->price(-20));
        $promo->setPayloadValue('composition', [['id'=>'a','discount'=>10],['id'=>'b','discount'=>10]]); $cart->add($promo);
        $result = $this->provider()->provide($cart, $this->context());
        self::assertSame(9.45, $result->getCartPriceTaxes()->getAmount()); self::assertCount(0,$cart->getErrors());
    }
    public function testUnknownRateBlocksOrderWithoutGuess(): void
    {
        $lookup = $this->createStub(RateLookup::class); $lookup->method('find')->willThrowException(new \DomainException('unknown'));
        $cart = new Cart('test'); $cart->add($this->item('a',100));
        self::assertFalse($this->provider($lookup)->provide($cart,$this->context())->declaresTaxes());
        self::assertTrue($cart->getErrors()->first()->blockOrder());
    }
    public function testErrorClearedAfterCorrectionAndNoSingletonRateCache(): void
    {
        $lookup = $this->createMock(RateLookup::class); $lookup->expects(self::exactly(2))->method('find')->willReturnOnConsecutiveCalls(['rate'=>10.5],['rate'=>8.5]);
        $cart = new Cart('test'); $cart->add($this->item('a',100)); $cart->addErrors(new UnknownTaxZipError('x','WA'));
        $p=$this->provider($lookup); self::assertSame(10.5,$p->provide($cart,$this->context())->getCartPriceTaxes()->getAmount());
        self::assertSame(8.5,$p->provide($cart,$this->context())->getCartPriceTaxes()->getAmount()); self::assertCount(0,$cart->getErrors());
    }
    public function testDisabledManagedCheckoutBlocks(): void
    {
        $cart=new Cart('test');$cart->add($this->item('a',100));$this->provider(enabled:false)->provide($cart,$this->context());self::assertTrue($cart->getErrors()->first()->blockOrder());
    }
    public function testOutsideNexusIsZero(): void
    {
        $cart=new Cart('test');$cart->add($this->item('a',100));$result=$this->provider()->provide($cart,$this->context(stateCode:'NY')); self::assertSame(0.,$result->getCartPriceTaxes()->getAmount());
    }
    public function testNonUsUnchangedAndStalePayloadRemoved(): void
    {
        $cart=new Cart('test');$line=$this->item('a',100);$line->setPayloadValue('inoceanShippingTaxInfo',[['tax'=>99]]);$cart->add($line);
        self::assertFalse($this->provider()->provide($cart,$this->context(country:'CA'))->declaresTaxes()); self::assertNull($line->getPayloadValue('inoceanShippingTaxInfo'));
    }
    public function testTaxFreeCustomerSkipsLookup(): void
    {
        $lookup=$this->createMock(RateLookup::class);$lookup->expects(self::never())->method('find');
        $cart=new Cart('test');$cart->add($this->item('a',100));self::assertFalse($this->provider($lookup)->provide($cart,$this->context(CartPrice::TAX_STATE_FREE))->declaresTaxes());
    }
    public function testEveryDeliveryAndFreightExemption(): void
    {
        foreach ([true,false] as $taxable) {
            $cart=new Cart('test');$cart->add($this->item('a',100));$context=$this->context();$deliveries=[];
            foreach ([1,2] as $id) {
                $date=new DeliveryDate(new \DateTimeImmutable(),new \DateTimeImmutable());$method=new ShippingMethodEntity();$method->setTaxId(Constants::TAXES[0]['id']);
                $positions=new DeliveryPositionCollection([new DeliveryPosition('p'.$id,$cart->getLineItems()->first(),1,$this->price(100),$date)]);
                $deliveries[]=new Delivery($positions,$date,$method,$context->getShippingLocation(),$this->price(10));
            }
            $cart->setDeliveries(new DeliveryCollection($deliveries));$result=$this->provider(freight:$taxable)->provide($cart,$context);
            self::assertCount(2,$result->getDeliveryTaxes());self::assertSame($taxable?12.6:10.5,$result->getCartPriceTaxes()->getAmount());
        }
    }
    public function testUnpricedItemBlocksInsteadOfFatal(): void
    {
        $cart=new Cart('test');$line=$this->item('a',100);$line->setPrice(null);$cart->add($line);$this->provider()->provide($cart,$this->context());self::assertTrue($cart->getErrors()->first()->blockOrder());
    }
    public function testPromotionWithoutCompositionBlocks(): void
    {
        $cart=new Cart('test');$cart->add($this->item('a',100));$promo=new LineItem('d',LineItem::PROMOTION_LINE_ITEM_TYPE);$promo->setPrice($this->price(-10));$cart->add($promo);
        $this->provider()->provide($cart,$this->context());self::assertTrue($cart->getErrors()->first()->blockOrder());
    }

    public function testExemptOnlyCartDoesNotNeedGeographicRate(): void
    {
        $lookup=$this->createMock(RateLookup::class);$lookup->expects(self::never())->method('find');
        $cart=new Cart('test');$cart->add($this->item('a',100,true));$result=$this->provider($lookup)->provide($cart,$this->context());
        self::assertSame(0.,$result->getCartPriceTaxes()->getAmount());self::assertCount(0,$cart->getErrors());
    }
    public function testSavedPriceReloadPreservesCombinedTax(): void
    {
        $cart=new Cart('test');$line=$this->item('a',100);$cart->add($line);$result=$this->provider()->provide($cart,$this->context());
        $price=$line->getPrice();$price->setCalculatedTaxes($result->getLineItemTaxes()[$line->getUniqueIdentifier()]);
        $serializer=new \Shopware\Core\Framework\DataAbstractionLayer\FieldSerializer\CalculatedPriceFieldSerializer($this->createStub(\Symfony\Component\Validator\Validator\ValidatorInterface::class),$this->createStub(\Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry::class));
        $loaded=$serializer->decode(new \Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField('price','price'),json_encode($price,JSON_THROW_ON_ERROR));
        self::assertSame(10.5,$loaded->getCalculatedTaxes()->getAmount());self::assertSame(10.5,$loaded->getCalculatedTaxes()->first()->getTaxRate());
    }

    public function testCoreAdjustmentAndPaymentAmountsForNetAndGrossCarts(): void
    {
        foreach ([CartPrice::TAX_STATE_NET=>100.,CartPrice::TAX_STATE_GROSS=>110.5] as $state=>$price) {
            $cart=new Cart('test');$cart->add($this->item('a',$price));$context=$this->context($state);
            $cart->setTransactions(new \Shopware\Core\Checkout\Cart\Transaction\Struct\TransactionCollection([
                new \Shopware\Core\Checkout\Cart\Transaction\Struct\Transaction($this->price(1.),str_repeat('c',32))]));
            $rounding=new \Shopware\Core\Checkout\Cart\Price\CashRounding();
            $amount=new \Shopware\Core\Checkout\Cart\Price\AmountCalculator($rounding,new \Shopware\Core\Checkout\Cart\Tax\PercentageTaxRuleBuilder(),new \Shopware\Core\Checkout\Cart\Tax\TaxCalculator());
            $adjustment=new \Shopware\Core\Checkout\Cart\TaxProvider\TaxAdjustment($amount,$rounding,new \Shopware\Core\Checkout\Cart\Transaction\TransactionProcessor());
            $adjustment->adjust($cart,$this->provider()->provide($cart,$context),$context);
            self::assertSame(100.,$cart->getPrice()->getNetPrice());self::assertSame(110.5,$cart->getPrice()->getTotalPrice());
            self::assertSame(10.5,$cart->getPrice()->getCalculatedTaxes()->getAmount());self::assertSame(110.5,$cart->getTransactions()->first()->getAmount()->getTotalPrice());
        }
    }
    public function testCollectorClearsPayloadEvenWhenProviderIsSkipped(): void
    {
        $cart=new Cart('test');$line=$this->item('a',100);$line->setPayloadValue('inoceanUsaTaxVersion',['datasetId'=>'old']);$cart->add($line);
        (new \InoceanTaxProviderUS\Core\Checkout\Cart\Tax\TaxPayloadCleaner())->collect(new \Shopware\Core\Checkout\Cart\LineItem\CartDataCollection(),$cart,$this->context(country:'CA'),new \Shopware\Core\Checkout\Cart\CartBehavior());
        self::assertNull($line->getPayloadValue('inoceanUsaTaxVersion'));self::assertSame(Constants::TAXES[0]['id'],$line->getPayloadValue('taxId'));
    }

    public function testHistoricalOrderCannotBeSilentlyRetaxedAtTodaysRate(): void
    {
        $cart=new Cart('test');$line=$this->item('a',100);$line->setPayloadValue('inoceanUsaTaxVersion',['datasetId'=>'historic']);$cart->add($line);
        $cart->addExtension(\Shopware\Core\Checkout\Cart\Order\OrderConverter::ORIGINAL_ORDER_NUMBER,new \Shopware\Core\Framework\Struct\ArrayStruct(['id'=>'old-order']));
        $this->expectException(\RuntimeException::class);
        try { $this->provider()->provide($cart,$this->context()); }
        finally { self::assertSame('historic',$line->getPayloadValue('inoceanUsaTaxVersion')['datasetId']); }
    }
}
