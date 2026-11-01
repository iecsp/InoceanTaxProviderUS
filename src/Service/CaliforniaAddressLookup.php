<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Service;

use Doctrine\DBAL\Connection;
use InoceanSalesTaxesUsa\Message\VerifyCaliforniaAddress;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CaliforniaAddressLookup
{
    public const API = 'https://services.maps.cdtfa.ca.gov/api/taxrate/GetRateByAddress';
    public function __construct(private readonly Connection $db, private readonly MessageBusInterface $bus,
        private readonly HttpClientInterface $http, private readonly \Shopware\Core\System\SystemConfig\SystemConfigService $config) {}

    public static function fingerprint(string $street, string $city, string $zip): string
    {
        $normalize = static fn (string $s): string => mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $s)), 'UTF-8');
        return hash('sha256', json_encode(array_map($normalize, ['CA', $street, $city, $zip]), JSON_THROW_ON_ERROR));
    }

    /** Checkout is DB-only; an unknown address queues ONE background verification and blocks the order. */
    public function find(CustomerAddressEntity $address, string $date, string $salesChannelId = ''): array
    {
        if (!Uuid::isValid($address->getId()) || !preg_match('/^\d{5}(?:-?\d{4})?$/D', trim($address->getZipcode())) ||
            $address->getStreet() === '' || $address->getCity() === '') { throw new \DomainException('A saved complete California shipping address is required.'); }
        $release = $this->db->fetchAssociative("SELECT d.* FROM ino_usa_tax_source s JOIN ino_usa_tax_dataset d ON d.id = s.active_dataset_id
            WHERE s.state = 'CA' AND d.valid_from <= ? AND d.valid_until >= ?", [$date, $date]);
        if (!$release) { throw new \DomainException('Synchronize the current official California tax release.'); }
        $hash = self::fingerprint($address->getStreet(), $address->getCity(), $address->getZipcode());
        $row = $this->db->fetchAssociative('SELECT * FROM ino_usa_tax_address WHERE address_hash = ? AND dataset_id = ?', [$hash, $release['id']]);
        // API is a current-date lookup. Never reuse a previous calendar day's response, especially across rate changes.
        if ($row && $row['status'] === 'verified' && TaxDate::localDay($row['checked_at']) === $date) {
            return ['rate' => (float) $row['rate'], 'components' => [], 'datasetId' => $release['id'],
                'jurisdiction' => $row['jurisdiction'], 'verifiedAt' => $row['checked_at'], 'validUntil' => min($date, $release['valid_until'])];
        }
        if ($row && $row['status'] === 'rejected' && strtotime($row['checked_at'] . ' UTC') > time() - 300) {
            throw new \DomainException('CDTFA could not uniquely verify this address.');
        }
        $inserted = $this->db->executeStatement("INSERT IGNORE INTO ino_usa_tax_address (address_hash, dataset_id, status, checked_at) VALUES (?, ?, 'queued', UTC_TIMESTAMP())", [$hash, $release['id']]);
        if (!$inserted) {
            $inserted = $this->db->executeStatement("UPDATE ino_usa_tax_address SET status = 'queued', checked_at = UTC_TIMESTAMP()
                WHERE address_hash = ? AND dataset_id = ? AND checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)", [$hash, $release['id']]);
        }
        if ($inserted) {
            try { $this->bus->dispatch(new VerifyCaliforniaAddress($address->getId(), $hash, $release['id'], $salesChannelId)); }
            catch (\Throwable $e) {
                $this->db->executeStatement("UPDATE ino_usa_tax_address SET status = 'rejected', checked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 MINUTE) WHERE address_hash = ? AND dataset_id = ?", [$hash, $release['id']]);
                throw $e;
            }
        }
        throw new \DomainException('Official address verification is pending. Refresh checkout shortly.');
    }

    public function verify(VerifyCaliforniaAddress $job): void
    {
        if ($job->salesChannelId === '' || !$this->config->getBool('InoceanSalesTaxesUsa.config.Enabled', $job->salesChannelId) ||
            !$this->config->getBool('InoceanSalesTaxesUsa.config.OfficialAddressVerification', $job->salesChannelId)) {
            $this->reject($job); return;
        }
        $requestDay = TaxDate::today();
        $cached = $this->db->fetchAssociative('SELECT status, checked_at FROM ino_usa_tax_address WHERE address_hash = ? AND dataset_id = ?', [$job->hash, $job->datasetId]);
        if (!$cached || ($cached['status'] === 'verified' && TaxDate::localDay($cached['checked_at']) === $requestDay)) { return; }
        $address = $this->db->fetchAssociative("SELECT a.street, a.city, a.zipcode FROM customer_address a
            JOIN country c ON c.id = a.country_id JOIN country_state s ON s.id = a.country_state_id
            WHERE a.id = ? AND c.iso = 'US' AND s.short_code = 'CA'", [Uuid::fromHexToBytes($job->addressId)]);
        // Shopware installations can store either CA or US-CA as the state short code.
        if (!$address) {
            $address = $this->db->fetchAssociative("SELECT a.street, a.city, a.zipcode FROM customer_address a
                JOIN country c ON c.id = a.country_id JOIN country_state s ON s.id = a.country_state_id
                WHERE a.id = ? AND c.iso = 'US' AND s.short_code = 'US-CA'", [Uuid::fromHexToBytes($job->addressId)]);
        }
        if (!$address || self::fingerprint($address['street'], $address['city'], $address['zipcode']) !== $job->hash) {
            $this->reject($job); return; // Address changed or was deleted while queued.
        }
        $active = $this->db->fetchOne("SELECT s.active_dataset_id FROM ino_usa_tax_source s JOIN ino_usa_tax_dataset d ON d.id = s.active_dataset_id WHERE s.state = 'CA' AND d.valid_from <= ? AND d.valid_until >= ?", [$requestDay, $requestDay]);
        if ($active !== $job->datasetId) { return; } // A newer release superseded the job.
        try {
            $response = $this->http->request('GET', self::API, ['query' => ['address' => $address['street'], 'city' => $address['city'], 'zip' => substr($address['zipcode'], 0, 5)],
                'timeout' => 10, 'max_duration' => 20, 'max_redirects' => 0]);
            if ($response->getStatusCode() !== 200) { $response->cancel(); throw new \RuntimeException('CDTFA address verification unavailable.'); }
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > 100_000) { $response->cancel(); throw new \RuntimeException('Unexpected CDTFA address response size.'); }
            }
            if (TaxDate::today() !== $requestDay) { throw new \DomainException('California tax day changed during verification.'); }
            $result = self::validateResponse(json_decode($body, true, 32, JSON_THROW_ON_ERROR));
            // Detect an outdated bulk version; never invent a city from a mailing address.
            $known = $this->db->fetchOne('SELECT COUNT(*) FROM ino_usa_tax_jurisdiction WHERE dataset_id = ? AND county = ? AND rate = ?',
                [$job->datasetId, $result['county'], $result['rate']]);
            if (!$known) { throw new \DomainException('Address result is inconsistent with the synchronized California release.'); }
            $this->db->executeStatement("UPDATE ino_usa_tax_address SET status = 'verified', rate = ?, jurisdiction = ?, checked_at = UTC_TIMESTAMP()
                WHERE address_hash = ? AND dataset_id = ?", [$result['rate'], $result['jurisdiction'], $job->hash, $job->datasetId]);
        } catch (\DomainException) { $this->reject($job); }
        catch (\Throwable) {
            $this->reject($job);
            // Do not place an HTTP exception (which includes the address URL) in logs or failed message storage.
            throw new \RuntimeException('Official California address verification failed; retry later.');
        }
    }

    public static function validateResponse(array $data): array
    {
        $info = $data['taxRateInfo'] ?? null; $geo = $data['geocodeInfo'] ?? [];
        if (!is_array($info) || count($info) !== 1 || ($geo['confidence'] ?? '') !== 'High' ||
            !in_array('Good', $geo['matchCodes'] ?? [], true) || !in_array($geo['calcMethod'] ?? '', ['Rooftop', 'Parcel'], true)) {
            throw new \DomainException('CDTFA address match is ambiguous or imprecise.');
        }
        $r = $info[0];
        if (!is_numeric($r['rate'] ?? null) || !is_finite((float) $r['rate']) || (float) $r['rate'] < 0.0725 || (float) $r['rate'] > 0.2 ||
            !preg_match('/^\d{12}$/D', (string) ($r['tac'] ?? '')) || !is_string($r['county'] ?? null) || strlen($r['county']) > 100 || $r['county'] === '') {
            throw new \DomainException('Invalid CDTFA address tax result.');
        }
        return ['rate' => round((float) $r['rate'] * 100, 6), 'county' => $r['county'], 'jurisdiction' => $r['tac']];
    }

    private function reject(VerifyCaliforniaAddress $job): void
    {
        $this->db->executeStatement("UPDATE ino_usa_tax_address SET status = 'rejected', checked_at = UTC_TIMESTAMP() WHERE address_hash = ? AND dataset_id = ?", [$job->hash, $job->datasetId]);
    }
}
