<?php declare(strict_types=1);
namespace InoceanTaxProviderUS\Message;
use InoceanTaxProviderUS\Service\CaliforniaAddressLookup;
final class VerifyCaliforniaAddressHandler
{
    public function __construct(private readonly CaliforniaAddressLookup $lookup) {}
    public function __invoke(VerifyCaliforniaAddress $message): void { $this->lookup->verify($message); }
}
