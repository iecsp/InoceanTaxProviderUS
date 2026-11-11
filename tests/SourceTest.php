<?php declare(strict_types=1);
namespace InoceanTaxProviderUS\Tests;
use InoceanTaxProviderUS\Service\CaliforniaSource;
use InoceanTaxProviderUS\Service\CaliforniaAddressLookup;
use InoceanTaxProviderUS\Service\WashingtonSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
final class SourceTest extends TestCase
{
    public function testWashingtonFractionToPercentageAndLeadingZeros(): void
    {
        $rows = iterator_to_array((new WashingtonSource(new MockHttpClient()))->rows("98001,0000,0001,1702,0.06500,0.04000,0.10500,20261001,20261231\n", '2026-10-01', '2026-12-31'));
        self::assertSame(['98001', 0, 1, '1702', '6.500000', '4.000000', '10.500000'], $rows[0]);
    }
    public function testWashingtonOverlapRejected(): void
    {
        $this->expectException(\DomainException::class);
        iterator_to_array((new WashingtonSource(new MockHttpClient()))->rows(str_repeat("98001,0000,0001,1702,0.06500,0.04000,0.10500,20261001,20261231\n", 2), '2026-10-01', '2026-12-31'));
    }
    public function testWashingtonInconsistentSumRejected(): void
    {
        $this->expectException(\DomainException::class);
        iterator_to_array((new WashingtonSource(new MockHttpClient()))->rows("98001,0000,0001,1702,0.06500,0.04000,0.10000,20261001,20261231\n", '2026-10-01', '2026-12-31'));
    }
    public function testWashingtonExpiredReleaseNotDownloaded(): void
    {
        $http = new MockHttpClient(new MockResponse('<a href="/sites/default/files/2026-05/Zip4Q326C.zip">old</a>'));
        $this->expectException(\RuntimeException::class);
        (new WashingtonSource($http))->fetch('2026-11-01');
    }
    public function testRedirectFailsClosed(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: http://127.0.0.1/']]));
        $this->expectException(\RuntimeException::class); (new WashingtonSource($http))->fetch('2026-11-01');
    }
    public static function response(): array { return ['taxRateInfo' => [['rate' => .0875, 'county' => 'SACRAMENTO', 'tac' => '340607050000']], 'geocodeInfo' => ['confidence' => 'High', 'matchCodes' => ['Good'], 'calcMethod' => 'Rooftop']]; }
    public function testCaliforniaVerifiedAddress(): void { self::assertSame(8.75, CaliforniaAddressLookup::validateResponse(self::response())['rate']); }
    public function testCaliforniaAmbiguityRejectedEvenForSameRate(): void
    {
        $r = self::response(); $r['taxRateInfo'][] = $r['taxRateInfo'][0];
        $this->expectException(\DomainException::class); CaliforniaAddressLookup::validateResponse($r);
    }
    public function testCaliforniaLowPrecisionRejected(): void
    {
        $r = self::response(); $r['geocodeInfo']['calcMethod'] = 'ZipCode';
        $this->expectException(\DomainException::class); CaliforniaAddressLookup::validateResponse($r);
    }
    public function testCaliforniaFingerprintStableAndAddressChangeInvalidates(): void
    {
        self::assertSame(CaliforniaAddressLookup::fingerprint('450  N St', 'Sacramento', '95814'), CaliforniaAddressLookup::fingerprint('450 n st ', 'SACRAMENTO', '95814'));
        self::assertNotSame(CaliforniaAddressLookup::fingerprint('450 N St', 'Sacramento', '95814'), CaliforniaAddressLookup::fingerprint('451 N St', 'Sacramento', '95814'));
    }
    public function testCaliforniaIncompleteTableRejected(): void
    {
        $this->expectException(\DomainException::class); (new CaliforniaSource(new MockHttpClient()))->parse('<h1>effective October 1, 2026</h1><table></table>', '2026-11-01');
    }
    public function testCaliforniaExpiredTableRejected(): void
    {
        $this->expectException(\DomainException::class); (new CaliforniaSource(new MockHttpClient()))->parse('<h1>effective July 1, 2026</h1>', '2026-11-01');
    }

    public function testTaxDateUsesPacificCalendarAroundUtcMidnight(): void
    {
        self::assertSame('2026-09-30',\InoceanTaxProviderUS\Service\TaxDate::localDay('2026-10-01 00:30:00'));
        self::assertSame('2026-10-01',\InoceanTaxProviderUS\Service\TaxDate::localDay('2026-10-01 08:30:00'));
    }
}
