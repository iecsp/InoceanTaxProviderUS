<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1793491200TaxStorage extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1793491200; }

    public function update(Connection $connection): void
    {
        $connection->executeStatement("CREATE TABLE IF NOT EXISTS ino_usa_tax_dataset (
            id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            state CHAR(2) CHARACTER SET ascii NOT NULL,
            source_url VARCHAR(512) NOT NULL,
            checksum CHAR(64) CHARACTER SET ascii NOT NULL,
            valid_from DATE NOT NULL, valid_until DATE NOT NULL,
            row_count INT UNSIGNED NOT NULL, created_at DATETIME(3) NOT NULL,
            INDEX idx_state_created (state, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $connection->executeStatement("CREATE TABLE IF NOT EXISTS ino_usa_tax_rate (
            dataset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            zip CHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            plus4_low SMALLINT UNSIGNED NOT NULL, plus4_high SMALLINT UNSIGNED NOT NULL,
            jurisdiction VARCHAR(16) NOT NULL,
            state_rate DECIMAL(9,6) NOT NULL, local_rate DECIMAL(9,6) NOT NULL,
            combined_rate DECIMAL(9,6) NOT NULL,
            PRIMARY KEY (dataset_id, zip, plus4_low, plus4_high),
            CONSTRAINT fk_ino_usa_rate_dataset FOREIGN KEY (dataset_id) REFERENCES ino_usa_tax_dataset (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $connection->executeStatement("CREATE TABLE IF NOT EXISTS ino_usa_tax_source (
            state CHAR(2) CHARACTER SET ascii NOT NULL PRIMARY KEY,
            active_dataset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            job_id CHAR(32) CHARACTER SET ascii NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'idle',
            last_error VARCHAR(255) NULL, requested_at DATETIME(3) NULL, completed_at DATETIME(3) NULL,
            CONSTRAINT fk_ino_usa_source_dataset FOREIGN KEY (active_dataset_id) REFERENCES ino_usa_tax_dataset (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $connection->executeStatement("CREATE TABLE IF NOT EXISTS ino_usa_tax_jurisdiction (
            dataset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            name VARCHAR(160) NOT NULL, county VARCHAR(100) NOT NULL, rate DECIMAL(9,6) NOT NULL,
            PRIMARY KEY (dataset_id, name, county),
            CONSTRAINT fk_ino_usa_jurisdiction_dataset FOREIGN KEY (dataset_id) REFERENCES ino_usa_tax_dataset (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $connection->executeStatement("CREATE TABLE IF NOT EXISTS ino_usa_tax_address (
            address_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            dataset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status VARCHAR(16) NOT NULL, rate DECIMAL(9,6) NULL, jurisdiction VARCHAR(160) NULL,
            checked_at DATETIME NOT NULL,
            PRIMARY KEY (address_hash, dataset_id),
            INDEX idx_checked (checked_at),
            CONSTRAINT fk_ino_usa_address_dataset FOREIGN KEY (dataset_id) REFERENCES ino_usa_tax_dataset (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $connection->executeStatement("INSERT IGNORE INTO ino_usa_tax_source (state) VALUES ('CA')");
        $connection->executeStatement("INSERT IGNORE INTO ino_usa_tax_source (state) VALUES ('WA')");
    }

    public function updateDestructive(Connection $connection): void {}
}
