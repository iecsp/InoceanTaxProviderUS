<?php declare(strict_types=1);
namespace InoceanTaxProviderUS\Service;

use Doctrine\DBAL\Connection;

final class DatabaseRateLookup implements RateLookup
{
    public function __construct(private readonly Connection $connection) {}

    public function find(string $state, string $postalCode, string $date): array
    {
        if (!preg_match('/^[A-Z]{2}$/D', $state) || !preg_match('/^(\d{5})-?(\d{4})$/D', trim($postalCode), $m)) {
            throw new \DomainException('A state and full ZIP+4 are required.');
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.*, d.source_url, d.valid_from, d.valid_until FROM ino_usa_tax_source s
             INNER JOIN ino_usa_tax_dataset d ON d.id = s.active_dataset_id
             INNER JOIN ino_usa_tax_rate r ON r.dataset_id = d.id
             WHERE s.state = :state AND r.zip = :zip AND r.plus4_low <= :plus4
             AND r.plus4_high >= :plus4 AND d.valid_from <= :date AND d.valid_until >= :date LIMIT 2',
            ['state' => $state, 'zip' => $m[1], 'plus4' => (int) $m[2], 'date' => $date]
        );
        if (count($rows) !== 1) {
            throw new \DomainException('No unique current official rate covers this address.');
        }
        $row = $rows[0];
        return ['rate' => (float) $row['combined_rate'],
            'components' => ['StateRate' => (float) $row['state_rate'], 'LocalRate' => (float) $row['local_rate']],
            'datasetId' => $row['dataset_id'], 'jurisdiction' => $row['jurisdiction'],
            'validFrom' => $row['valid_from'], 'validUntil' => $row['valid_until']];
    }
}
