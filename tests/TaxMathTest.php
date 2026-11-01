<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Tests;
use InoceanSalesTaxesUsa\Service\TaxMath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class TaxMathTest extends TestCase
{
    public static function amounts(): array { return [[100., 8.75, false, 2, 8.75], [108.75, 8.75, true, 2, 8.75], [-108.75, 8.75, true, 2, -8.75], [100., 0., true, 2, 0.], [1., 8.75, false, 2, .09], [100., 8.875, false, 3, 8.875]]; }
    #[DataProvider('amounts')]
    public function testAmounts(float $price, float $rate, bool $gross, int $decimals, float $expected): void { self::assertSame($expected, TaxMath::amount($price, $rate, $gross, $decimals)); }
    public function testMixedExemptDiscount(): void
    {
        $b = TaxMath::discount(-20, ['taxable' => 10., 'exempt' => 10.], ['taxable' => 8.75, 'exempt' => 0.], false, 2);
        self::assertSame(-.88, array_sum(array_column($b, 'tax')));
        self::assertSame(-20., array_sum(array_column($b, 'price')));
    }
    public function testGrossDiscount(): void { self::assertSame(-8.75, TaxMath::discount(-108.75, ['a' => 108.75], ['a' => 8.75], true, 2)[0]['tax']); }
    public function testUnknownDiscountTarget(): void { $this->expectException(\DomainException::class); TaxMath::discount(-10, ['a' => 10], [], false, 2); }
    public function testInvalidRate(): void { $this->expectException(\DomainException::class); TaxMath::amount(100, NAN, false, 2); }
}
