<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Message;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
final class SyncRates implements AsyncMessageInterface
{
    public function __construct(public readonly string $jobId, public readonly string $state = 'CA') {}
}
