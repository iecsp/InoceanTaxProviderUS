<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Service;
final class TaxDate
{
    public static function today(): string { return (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d'); }
    public static function localDay(string $utc): string { return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('Y-m-d'); }
}
