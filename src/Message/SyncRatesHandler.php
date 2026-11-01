<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Message;
use InoceanSalesTaxesUsa\Service\RateSyncService;
final class SyncRatesHandler
{
    public function __construct(private readonly RateSyncService $sync) {}
    public function __invoke(SyncRates $message): void { $this->sync->run($message->jobId, $message->state); }
}
