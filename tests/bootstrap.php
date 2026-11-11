<?php declare(strict_types=1);
$loader = null;
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) { $loader = require $autoload; break; }
}
if ($loader === null) { throw new RuntimeException('Install Composer dependencies before running tests.'); }
$loader->addPsr4('InoceanTaxProviderUS\\', __DIR__ . '/../src');
$loader->addPsr4('InoceanTaxProviderUS\\Tests\\', __DIR__);
