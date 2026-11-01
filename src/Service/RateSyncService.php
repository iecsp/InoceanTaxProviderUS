<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Service;

use Doctrine\DBAL\Connection;
use InoceanSalesTaxesUsa\Message\SyncRates;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;

final class RateSyncService
{
    public function __construct(private readonly Connection $db, private readonly WashingtonSource $source,
        private readonly CaliforniaSource $california, private readonly MessageBusInterface $bus, private readonly LoggerInterface $logger) {}

    public function status(): array
    {
        return $this->db->fetchAllAssociative('SELECT s.*, d.source_url, d.checksum, d.valid_from, d.valid_until, d.row_count
            FROM ino_usa_tax_source s LEFT JOIN ino_usa_tax_dataset d ON d.id = s.active_dataset_id ORDER BY s.state');
    }

    private function lock(): void
    {
        if ((int) $this->db->fetchOne("SELECT GET_LOCK('ino_usa_tax_sync', 0)") !== 1) {
            throw new \RuntimeException('Tax synchronization is already running.');
        }
    }
    private function unlock(): void { $this->db->fetchOne("SELECT RELEASE_LOCK('ino_usa_tax_sync')"); }

    public function queue(string $state = 'CA'): string
    {
        if (!in_array($state, ['CA', 'WA'], true)) { throw new \DomainException('Unsupported source state.'); }
        $this->lock();
        try {
            $row = $this->db->fetchAssociative("SELECT job_id, status, requested_at FROM ino_usa_tax_source WHERE state = ?", [$state]);
            if (!$row) { throw new \RuntimeException('Run the plugin database migration first.'); }
            if (in_array($row['status'], ['queued', 'running'], true) && $row['requested_at'] !== null && strtotime($row['requested_at'] . ' UTC') > time() - 900) { return (string) $row['job_id']; }
            $id = Uuid::randomHex();
            $this->db->executeStatement("UPDATE ino_usa_tax_source SET job_id = ?, status = 'queued', last_error = NULL,
                requested_at = UTC_TIMESTAMP(3) WHERE state = ?", [$id, $state]);
            try { $this->bus->dispatch(new SyncRates($id, $state)); }
            catch (\Throwable $e) {
                $this->db->executeStatement("UPDATE ino_usa_tax_source SET status = 'failed', last_error = 'Queue unavailable; retry sync.' WHERE state = ? AND job_id = ?", [$state, $id]);
                throw $e;
            }
            return $id;
        } finally { $this->unlock(); }
    }

    /** Worker and CLI share this path. Failed downloads never change the active pointer. */
    public function run(?string $jobId = null, string $state = 'CA'): void
    {
        if (!in_array($state, ['CA', 'WA'], true)) { throw new \DomainException('Unsupported source state.'); }
        $this->lock();
        $dataset = null; $published = false;
        try {
            $row = $this->db->fetchAssociative("SELECT job_id, status, requested_at FROM ino_usa_tax_source WHERE state = ?", [$state]);
            if (!$row) { throw new \RuntimeException('Run the plugin database migration first.'); }
            if ($jobId !== null && ($jobId !== $row['job_id'] || $row['status'] === 'completed')) { return; }
            $jobId ??= Uuid::randomHex();
            $this->db->executeStatement("UPDATE ino_usa_tax_source SET job_id = ?, status = 'running', last_error = NULL WHERE state = ?", [$jobId, $state]);
            $release = $state === 'CA' ? $this->california->fetch(TaxDate::today()) : $this->source->fetch(TaxDate::today());
            $current = $this->db->fetchAssociative('SELECT d.checksum FROM ino_usa_tax_source s JOIN ino_usa_tax_dataset d ON d.id = s.active_dataset_id WHERE s.state = ?', [$state]);
            if ($current && hash_equals($current['checksum'], $release['checksum'])) {
                $this->db->executeStatement("UPDATE ino_usa_tax_source SET status = 'completed', completed_at = UTC_TIMESTAMP(3), last_error = NULL WHERE state = ?", [$state]);
                return;
            }
            $dataset = Uuid::randomHex();
            // Staging is invisible to checkout. Batches avoid large SQL packets and long publication locks.
            $this->db->insert('ino_usa_tax_dataset', ['id' => $dataset, 'state' => $state, 'source_url' => $release['url'],
                'checksum' => $release['checksum'], 'valid_from' => $release['from'], 'valid_until' => $release['until'],
                'row_count' => 0, 'created_at' => gmdate('Y-m-d H:i:s')]);
            $count = 0; $batch = [];
            if ($state === 'CA') {
                foreach ($release['rows'] as [$name, $county, $rate]) {
                    $this->db->insert('ino_usa_tax_jurisdiction', ['dataset_id' => $dataset, 'name' => $name, 'county' => $county, 'rate' => $rate]);
                    ++$count;
                }
            } else {
                foreach ($this->source->rows($release['csv'], $release['from'], $release['until']) as $rate) {
                    $batch[] = array_merge([$dataset], $rate); ++$count;
                    if (count($batch) === 500) { $this->insertBatch($batch); $batch = []; }
                }
                if ($batch !== []) { $this->insertBatch($batch); }
                if ($count < 10_000 || $count > 500_000) { throw new \DomainException('Incomplete or unexpectedly large official WA release.'); }
            }
            $previousCount = (int) $this->db->fetchOne("SELECT d.row_count FROM ino_usa_tax_source s JOIN ino_usa_tax_dataset d ON d.id = s.active_dataset_id WHERE s.state = ?", [$state]);
            if ($previousCount > 0 && $count < $previousCount * 0.8) { throw new \DomainException('Official data shrank unexpectedly; manual source review required.'); }
            $this->db->transactional(function () use ($dataset, $count, $state): void {
                $this->db->update('ino_usa_tax_dataset', ['row_count' => $count], ['id' => $dataset]);
                $this->db->executeStatement("UPDATE ino_usa_tax_source SET active_dataset_id = ?, status = 'completed', last_error = NULL,
                    completed_at = UTC_TIMESTAMP(3) WHERE state = ?", [$dataset, $state]);
            });
            $published = true;
            $this->logger->info('Official sales tax dataset published.', ['datasetId' => $dataset, 'rows' => $count]);
        } catch (\Throwable $e) {
            if ($dataset !== null && !$published) { $this->db->delete('ino_usa_tax_dataset', ['id' => $dataset]); }
            if (!$published) {
                $this->db->executeStatement("UPDATE ino_usa_tax_source SET status = 'failed', last_error = 'Synchronization failed; previous version retained. Check server logs.' WHERE state = ?", [$state]);
            }
            $this->logger->error('Official sales tax synchronization failed.', ['exception' => $e]);
            throw $e;
        } finally { $this->unlock(); }
    }

    /** Keep a bounded history; active versions and saved order snapshots remain intact. */
    public function prune(int $keep = 3): int
    {
        if ($keep < 1 || $keep > 20) { throw new \DomainException('Retention must be between 1 and 20 versions per state.'); }
        $this->lock();
        try {
            $removed = 0;
            foreach (['CA', 'WA'] as $state) {
                $active = $this->db->fetchOne('SELECT active_dataset_id FROM ino_usa_tax_source WHERE state = ?', [$state]);
                $ids = $this->db->fetchFirstColumn('SELECT id FROM ino_usa_tax_dataset WHERE state = ? ORDER BY created_at DESC, id DESC', [$state]);
                foreach (array_slice($ids, $keep) as $id) {
                    if ($id !== $active) { $removed += $this->db->delete('ino_usa_tax_dataset', ['id' => $id]); }
                }
            }
            $this->db->executeStatement('DELETE FROM ino_usa_tax_address WHERE checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)');
            return $removed;
        } finally { $this->unlock(); }
    }

    private function insertBatch(array $batch): void
    {
        $this->db->executeStatement('INSERT INTO ino_usa_tax_rate (dataset_id, zip, plus4_low, plus4_high, jurisdiction, state_rate, local_rate, combined_rate) VALUES ' .
            implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,?)')), array_merge(...$batch));
    }
}
