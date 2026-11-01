<?php declare(strict_types=1);
// Loads only this plugin in an isolated cache. Does not install it or mutate business records.
$root=realpath(__DIR__.'/../../../..');$loader=require $root.'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($root.'/.env');
$_SERVER['APP_CACHE_DIR']=$_ENV['APP_CACHE_DIR']=sys_get_temp_dir().'/ino-usa-kernel-smoke';
$info=['baseClass'=>'InoceanSalesTaxesUsa\\InoceanSalesTaxesUsa','name'=>'InoceanSalesTaxesUsa','active'=>true,'path'=>'custom/plugins/InoceanSalesTaxesUsa','version'=>'2.0.0','autoload'=>['psr-4'=>['InoceanSalesTaxesUsa\\'=>'src/']],'managedByComposer'=>false,'composerName'=>'inocean/sales-taxes-usa'];
$plugins=new Shopware\Core\Framework\Plugin\KernelPluginLoader\StaticKernelPluginLoader($loader,null,[$info]);
$kernel=new Shopware\Core\Kernel('test',true,$plugins,'ino-usa-smoke','6.7.15.1',Shopware\Core\Kernel::getConnection(),$root);
try {
    $kernel->boot();$c=$kernel->getContainer()->get('test.service_container');
    echo get_class($c->get(InoceanSalesTaxesUsa\Service\RateSyncService::class)),PHP_EOL;
    $routes=$c->get('router')->getRouteCollection();
    foreach(['status'=>['GET','system_config:read'],'sync'=>['POST','system_config:update']] as $action=>[$method,$acl]) {
        $route=$routes->get('api.action.inocean_usa_tax.'.$action);
        if(!$route || $route->getMethods()!==[$method] || $route->getDefault('_acl')!==[$acl]) { throw new RuntimeException('Incorrect route security metadata.'); }
        $request=Symfony\Component\HttpFoundation\Request::create('http://localhost/api/_action/inocean-usa-tax/'.$action,$method);
        $response=$kernel->handle($request);
        if($response->getStatusCode()!==401) { throw new RuntimeException('Anonymous request must be rejected: '.$response->getStatusCode()); }
        echo $method.' '.$action.': anonymous rejected (401), required ACL '.$acl.PHP_EOL;
        $kernel->terminate($request,$response);
    }
    $bundle=$kernel->getBundle('InoceanSalesTaxesUsa');
    $cards=(new Shopware\Core\System\SystemConfig\Util\ConfigReader())->getConfigFromBundle($bundle);
    if(count($cards)!==2) { throw new RuntimeException('Invalid settings page.'); }
    echo 'Configuration XML: 2 valid settings cards.',PHP_EOL;
    $senders=$c->get('messenger.senders_locator')->getSenders(new Symfony\Component\Messenger\Envelope(new InoceanSalesTaxesUsa\Message\SyncRates(str_repeat('a',32))));
    if(!array_key_exists('async',iterator_to_array($senders))) { throw new RuntimeException('Sync must be dispatched asynchronously.'); }
    echo 'Tax synchronization routed to the async transport.',PHP_EOL;
} finally { $kernel->shutdown(); }
