<?php declare(strict_types=1);
// Opt-in live public-source verification. No customer data, orders or business tables are changed.
require __DIR__.'/bootstrap.php';
use InoceanSalesTaxesUsa\Service\{WashingtonSource,CaliforniaSource,CaliforniaAddressLookup};
use Symfony\Component\HttpClient\HttpClient;
$http=HttpClient::create();$today=(new DateTimeImmutable('now',new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
$ca=(new CaliforniaSource($http))->fetch($today);
echo 'CA: '.count($ca['rows']).' official jurisdictions; '.$ca['from'].' through '.$ca['until'].PHP_EOL;
$waSource=new WashingtonSource($http);$wa=$waSource->fetch($today);$count=0;
foreach($waSource->rows($wa['csv'],$wa['from'],$wa['until']) as $row) { ++$count; }
echo 'WA: '.$count.' official ZIP+4 ranges; '.$wa['from'].' through '.$wa['until'].PHP_EOL;
// CDTFA's own published public example address, never a customer's address.
$response=$http->request('GET',CaliforniaAddressLookup::API,['query'=>['address'=>'450 N St','city'=>'Sacramento','zip'=>'95814'],'timeout'=>15,'max_duration'=>30,'max_redirects'=>0]);
$r=CaliforniaAddressLookup::validateResponse($response->toArray());
echo 'CA public example: '.$r['rate'].'%; TAC '.$r['jurisdiction'].PHP_EOL;
