<?php declare(strict_types=1);
namespace InoceanSalesTaxesUsa\Service;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CaliforniaSource
{
    public const PAGE = 'https://cdtfa.ca.gov/taxes-and-fees/rates.aspx';
    public function __construct(private readonly HttpClientInterface $http) {}

    public function fetch(string $today): array
    {
        $response = $this->http->request('GET', self::PAGE, ['max_redirects' => 0, 'timeout' => 15, 'max_duration' => 45]);
        if ($response->getStatusCode() !== 200) { $response->cancel(); throw new \RuntimeException('CDTFA rates unavailable.'); }
        $html = '';
        foreach ($this->http->stream($response) as $chunk) {
            $html .= $chunk->getContent();
            if (strlen($html) > 2_000_000) { $response->cancel(); throw new \RuntimeException('CDTFA rate file too large.'); }
        }
        return $this->parse($html, $today);
    }

    public function parse(string $html, string $today): array
    {
        $dom = new \DOMDocument();
        $old = libxml_use_internal_errors(true);
        try { $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
        $xpath = new \DOMXPath($dom);
        $heading = $xpath->evaluate('string(//h1)');
        if (!preg_match('/effective\s+(January|April|July|October)\s+1,\s+(20\d{2})/i', $heading, $m)) {
            throw new \DomainException('Unknown CDTFA release date.');
        }
        $from = (new \DateTimeImmutable($m[1] . ' 1, ' . $m[2]))->format('Y-m-d');
        $until = (new \DateTimeImmutable($from))->modify('+3 months -1 day')->format('Y-m-d');
        if ($from > $today || $until < $today) { throw new \DomainException('CDTFA release is not current.'); }
        $rows = []; $seen = [];
        foreach ($xpath->query('//tbody/tr') as $tr) {
            $cells = $xpath->query('./td', $tr);
            if ($cells->length !== 5) { continue; }
            $values = [];
            foreach ($cells as $cell) { $values[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent)); }
            [$name, $rate, $county, $type] = $values;
            // Some umbrella county entries deliberately have '*' instead of a rate.
            if ($rate === '*') { continue; }
            if (!preg_match('/^(\d{1,2}\.\d{3})%$/D', $rate, $r) || !in_array($type, ['City', 'County'], true) ||
                $name === '' || strlen($name) > 160 || $county === '' || strlen($county) > 100 || (float) $r[1] < 7.25 || (float) $r[1] > 20) {
                throw new \DomainException('Invalid CDTFA jurisdiction record.');
            }
            $key = strtoupper($name . '|' . $county);
            if (isset($seen[$key])) { throw new \DomainException('Duplicate CDTFA jurisdiction.'); }
            $seen[$key] = true;
            $rows[] = [$name, $county, $r[1]];
        }
        if (count($rows) < 500 || count($rows) > 1000) { throw new \DomainException('Incomplete CDTFA jurisdiction table.'); }
        return ['url' => self::PAGE, 'checksum' => hash('sha256', json_encode([$from, $until, $rows], JSON_THROW_ON_ERROR)), 'from' => $from, 'until' => $until, 'rows' => $rows];
    }
}
