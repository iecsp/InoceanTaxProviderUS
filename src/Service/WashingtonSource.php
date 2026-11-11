<?php declare(strict_types=1);
namespace InoceanTaxProviderUS\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Official WA DOR short ZIP+4 format; no customer data is transmitted. */
final class WashingtonSource
{
    public const PAGE = 'https://dor.wa.gov/taxes-rates/sales-use-tax-rates/downloadable-database';
    public function __construct(private readonly HttpClientInterface $http) {}

    private function download(string $url, int $limit): string
    {
        // URLs originate only in the fixed official page, and must match the exact allowlist.
        if ($url !== self::PAGE && !preg_match('~^https://dor\.wa\.gov/sites/default/files/\d{4}-\d{2}/Zip4Q[1-4]\d{2}C\.zip$~iD', $url)) {
            throw new \DomainException('Unapproved tax source URL.');
        }
        $response = $this->http->request('GET', $url, ['max_redirects' => 0, 'timeout' => 15, 'max_duration' => 60,
            'headers' => ['User-Agent' => 'InoceanTaxProviderUS/2.0 (+local tax database sync)']]);
        if ($response->getStatusCode() !== 200) {
            $response->cancel();
            throw new \RuntimeException('Official tax source is unavailable.');
        }
        $body = '';
        foreach ($this->http->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (strlen($body) > $limit) {
                $response->cancel();
                throw new \RuntimeException('Tax source exceeds the download size limit.');
            }
        }
        return $body;
    }

    public function fetch(string $today): array
    {
        $page = $this->download(self::PAGE, 2_000_000);
        preg_match_all('~href=["\'](?:https://dor\.wa\.gov)?(/sites/default/files/\d{4}-\d{2}/Zip4Q([1-4])(\d{2})C\.zip)["\']~i', $page, $matches, PREG_SET_ORDER);
        $candidates = [];
        foreach ($matches as $match) {
            $year = 2000 + (int) $match[3];
            $month = ((int) $match[2] - 1) * 3 + 1;
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end = (new \DateTimeImmutable($start))->modify('+3 months -1 day')->format('Y-m-d');
            if ($start <= $today && $end >= $today) {
                $candidates['https://dor.wa.gov' . $match[1]] = [$start, $end];
            }
        }
        if (count($candidates) !== 1) {
            throw new \RuntimeException('Cannot identify a unique current official WA ZIP+4 release.');
        }
        $url = array_key_first($candidates);
        [$start, $end] = $candidates[$url];
        $archive = $this->download($url, 8_000_000);
        $path = tempnam(sys_get_temp_dir(), 'ino-tax-');
        if ($path === false) { throw new \RuntimeException('Cannot create tax staging file.'); }
        $zip = new \ZipArchive();
        $opened = false;
        try {
            if (file_put_contents($path, $archive) !== strlen($archive) || $zip->open($path) !== true) {
                throw new \RuntimeException('Invalid tax archive.');
            }
            $opened = true;
            if ($zip->numFiles !== 1 || ($zip->statIndex(0)['size'] ?? PHP_INT_MAX) > 32_000_000) {
                throw new \RuntimeException('Unexpected tax archive layout or size.');
            }
            // Read one bounded member; never extract archive paths to disk.
            $csv = $zip->getFromIndex(0, 32_000_001);
            if (!is_string($csv) || strlen($csv) > 32_000_000) { throw new \RuntimeException('Cannot read tax archive.'); }
            return ['url' => $url, 'checksum' => hash('sha256', $archive), 'from' => $start, 'until' => $end, 'csv' => $csv];
        } finally {
            if ($opened) { $zip->close(); }
            unlink($path);
        }
    }

    /** Validate every record, including dates, component sums, ordering and overlap. */
    public function rows(string $csv, string $from, string $until): \Generator
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $lastZip = ''; $lastHigh = -1;
        try {
            while (($r = fgetcsv($stream, 2048, ',', '"', '')) !== false) {
                if ($r === [null]) { continue; }
                if (count($r) !== 9 || !preg_match('/^\d{5}$/D', $r[0]) ||
                    !preg_match('/^\d{4}$/D', $r[1]) || !preg_match('/^\d{4}$/D', $r[2]) ||
                    !preg_match('/^\d{4}$/D', $r[3]) || $r[1] > $r[2] ||
                    $r[7] !== str_replace('-', '', $from) || $r[8] !== str_replace('-', '', $until)) {
                    throw new \DomainException('Invalid official WA boundary record.');
                }
                foreach ([4, 5, 6] as $i) {
                    if (!preg_match('/^0\.\d{1,6}$/D', $r[$i]) || (float) $r[$i] > 0.25) {
                        throw new \DomainException('Invalid official WA tax rate.');
                    }
                }
                if (abs((float) $r[4] + (float) $r[5] - (float) $r[6]) > 0.000001 ||
                    $r[0] < $lastZip || ($r[0] === $lastZip && (int) $r[1] <= $lastHigh)) {
                    throw new \DomainException('Inconsistent or overlapping official WA tax records.');
                }
                $lastZip = $r[0]; $lastHigh = (int) $r[2];
                yield [$r[0], (int) $r[1], (int) $r[2], $r[3],
                    number_format((float) $r[4] * 100, 6, '.', ''),
                    number_format((float) $r[5] * 100, 6, '.', ''),
                    number_format((float) $r[6] * 100, 6, '.', '')];
            }
        } finally { fclose($stream); }
    }
}
