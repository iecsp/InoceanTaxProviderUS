<?php declare(strict_types=1);
namespace InoceanTaxProviderUS\Message;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
final class VerifyCaliforniaAddress implements AsyncMessageInterface
{
    public function __construct(public readonly string $addressId, public readonly string $hash, public readonly string $datasetId, public readonly string $salesChannelId = '') {}
}
