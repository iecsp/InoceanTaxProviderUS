<?php declare(strict_types=1);
// Explicit live-source smoke test. Creates and drops a random database; never writes business tables.
require __DIR__.'/bootstrap.php';
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use InoceanSalesTaxesUsa\Migration\Migration1793491200TaxStorage;
use InoceanSalesTaxesUsa\Service\{WashingtonSource,CaliforniaSource,RateSyncService,DatabaseRateLookup,TaxDate};
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\MessageBus;
use Psr\Log\NullLogger;
(new Dotenv())->bootEnv(__DIR__.'/../../../../.env');
$params=(new DsnParser(['mysql'=>'pdo_mysql']))->parse($_SERVER['DATABASE_URL']);
$admin=DriverManager::getConnection($params);$name='ino_usa_live_'.bin2hex(random_bytes(6));$admin->executeStatement('CREATE DATABASE '.$name);$db=null;
try {
    $params['dbname']=$name;$db=DriverManager::getConnection($params);(new Migration1793491200TaxStorage())->update($db);
    $http=HttpClient::create();$sync=new RateSyncService($db,new WashingtonSource($http),new CaliforniaSource($http),new MessageBus(),new NullLogger());
    foreach(['CA','WA'] as $state) { $start=microtime(true);$sync->run(null,$state);echo $state.' official release staged and published in '.round(microtime(true)-$start,3)." seconds\n"; }
    $lookup=new DatabaseRateLookup($db);$start=microtime(true);
    for($i=0;$i<1000;++$i) { $rate=$lookup->find('WA','98001-0000',TaxDate::today()); }
    echo '1000 indexed lookups: '.round((microtime(true)-$start)*1000,2).' ms total; result '.$rate['rate']."%\n";
    echo json_encode($sync->status(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    echo 'Peak PHP memory: '.round(memory_get_peak_usage(true)/1048576,2)." MiB\n";
} finally {
    $db?->close();$admin->executeStatement('DROP DATABASE '.$name);$admin->close();echo "Disposable smoke database removed.\n";
}
