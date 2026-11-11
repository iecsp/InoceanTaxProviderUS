<?php declare(strict_types=1);
namespace InoceanTaxProviderUS\Tests;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use InoceanTaxProviderUS\Migration\Migration1793491200TaxStorage;
use InoceanTaxProviderUS\Service\{RateSyncService, WashingtonSource, CaliforniaSource, DatabaseRateLookup, CaliforniaAddressLookup};
use InoceanTaxProviderUS\Message\{SyncRates, VerifyCaliforniaAddress};
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
final class DatabaseTest extends TestCase
{
    private ?Connection $db = null;
    private ?Connection $admin = null;
    private ?string $database = null;
    protected function setUp(): void
    {
        if (getenv('INO_USA_DB_TESTS') !== '1') { self::markTestSkipped('Opt in with INO_USA_DB_TESTS=1; creates and drops only an isolated random database.'); }
        (new Dotenv())->bootEnv(__DIR__ . '/../../../../.env');
        $params=(new DsnParser(['mysql'=>'pdo_mysql']))->parse($_SERVER['DATABASE_URL']);
        $this->admin=DriverManager::getConnection($params);
        $this->database='ino_usa_test_'.bin2hex(random_bytes(6));
        $this->admin->executeStatement('CREATE DATABASE '.$this->database);
        $params['dbname']=$this->database;
        $this->db=DriverManager::getConnection($params);
        (new Migration1793491200TaxStorage())->update($this->db);
    }
    protected function tearDown(): void
    {
        $this->db?->close();
        if ($this->database && $this->admin) { $this->admin->executeStatement('DROP DATABASE '.$this->database); $this->admin->close(); }
    }
    private function dataset(string $state='WA'): string
    {
        $id=bin2hex(random_bytes(16));$this->db->insert('ino_usa_tax_dataset',['id'=>$id,'state'=>$state,'source_url'=>'https://example.invalid/test','checksum'=>str_repeat('a',64),'valid_from'=>'2026-10-01','valid_until'=>'2026-12-31','row_count'=>1,'created_at'=>'2026-11-01 00:00:00']);
        $this->db->update('ino_usa_tax_source',['active_dataset_id'=>$id],['state'=>$state]);return $id;
    }
    public function testMigrationIsIdempotent(): void
    {
        (new Migration1793491200TaxStorage())->update($this->db);self::assertSame(2,(int)$this->db->fetchOne('SELECT COUNT(*) FROM ino_usa_tax_source'));
    }
    public function testIndexedRangeLookupAndExpiry(): void
    {
        $id=$this->dataset();$this->db->insert('ino_usa_tax_rate',['dataset_id'=>$id,'zip'=>'98001','plus4_low'=>0,'plus4_high'=>1,'jurisdiction'=>'1702','state_rate'=>6.5,'local_rate'=>4,'combined_rate'=>10.5]);
        $lookup=new DatabaseRateLookup($this->db);self::assertSame(10.5,$lookup->find('WA','98001-0000','2026-11-01')['rate']);
        $this->expectException(\DomainException::class);$lookup->find('WA','98001-0000','2027-01-01');
    }
    public function testAmbiguousRangeFailsClosed(): void
    {
        $id=$this->dataset();foreach ([[0,10],[5,15]] as [$low,$high]) { $this->db->insert('ino_usa_tax_rate',['dataset_id'=>$id,'zip'=>'98001','plus4_low'=>$low,'plus4_high'=>$high,'jurisdiction'=>'1702','state_rate'=>6.5,'local_rate'=>4,'combined_rate'=>10.5]); }
        $this->expectException(\DomainException::class);(new DatabaseRateLookup($this->db))->find('WA','98001-0007','2026-11-01');
    }
    public function testFiveDigitZipIsRejected(): void
    {
        $this->expectException(\DomainException::class);(new DatabaseRateLookup($this->db))->find('WA','98001','2026-11-01');
    }
    private function sync(string $html): RateSyncService
    {
        return new RateSyncService($this->db,new WashingtonSource(new MockHttpClient()),new CaliforniaSource(new MockHttpClient(static fn()=>new MockResponse($html))),new MessageBus(),new NullLogger());
    }
    private function html(): string
    {
        $quarter=(int)ceil((int)gmdate('n')/3);$date=new \DateTimeImmutable(gmdate('Y').'-'.sprintf('%02d',($quarter-1)*3+1).'-01');
        $html='<h1>effective '.$date->format('F j, Y').'</h1><table><tbody>';
        for($i=0;$i<510;++$i) { $html.='<tr><td>City '.$i.'</td><td>8.750%</td><td>Sacramento</td><td>City</td><td></td></tr>'; }
        return $html.'</tbody></table>';
    }
    public function testSuccessfulPublishAndFailedReplacementRetainsOldVersion(): void
    {
        $this->sync($this->html())->run();$old=$this->db->fetchOne("SELECT active_dataset_id FROM ino_usa_tax_source WHERE state='CA'");
        self::assertSame(510,(int)$this->db->fetchOne('SELECT COUNT(*) FROM ino_usa_tax_jurisdiction WHERE dataset_id=?',[$old]));
        try { $this->sync('<html>broken</html>')->run();self::fail('Must reject malformed release'); } catch (\DomainException) {}
        self::assertSame($old,$this->db->fetchOne("SELECT active_dataset_id FROM ino_usa_tax_source WHERE state='CA'"));
        self::assertSame('failed',$this->db->fetchOne("SELECT status FROM ino_usa_tax_source WHERE state='CA'"));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM ino_usa_tax_dataset'));
    }
    public function testQueuedSyncIsDeduplicated(): void
    {
        $sync=$this->sync($this->html());$first=$sync->queue();self::assertSame($first,$sync->queue());
        $sync->run($first,'CA');self::assertSame('completed',$this->db->fetchOne("SELECT status FROM ino_usa_tax_source WHERE state='CA'"));
        $sync->run($first,'CA');self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM ino_usa_tax_dataset'));
    }
    public function testAddressCacheHitNeverUsesHttpAndNewDatasetInvalidates(): void
    {
        $id=$this->dataset('CA');$address=new CustomerAddressEntity();$address->setId(str_repeat('a',32));$address->setStreet('450 N St');$address->setCity('Sacramento');$address->setZipcode('95814');
        $hash=CaliforniaAddressLookup::fingerprint('450 N St','Sacramento','95814');
        $this->db->insert('ino_usa_tax_address',['address_hash'=>$hash,'dataset_id'=>$id,'status'=>'verified','rate'=>8.75,'jurisdiction'=>'340607050000','checked_at'=>'2026-11-01 12:00:00']);
        $http=new MockHttpClient(static function() { throw new \LogicException('Checkout must not call HTTP'); });
        $lookup=new CaliforniaAddressLookup($this->db,new MessageBus(),$http,$this->createStub(\Shopware\Core\System\SystemConfig\SystemConfigService::class));
        self::assertSame(8.75,$lookup->find($address,'2026-11-01')['rate']);
        $this->dataset('CA');$this->expectException(\DomainException::class);$lookup->find($address,'2026-11-01');
    }
    public function testColdAddressQueuesOnce(): void
    {
        $this->dataset('CA');$address=new CustomerAddressEntity();$address->setId(str_repeat('a',32));$address->setStreet('450 N St');$address->setCity('Sacramento');$address->setZipcode('95814');
        $transport=new InMemoryTransport();$locator=new SendersLocator([VerifyCaliforniaAddress::class=>['test']],new ServiceLocator(['test'=>fn()=>$transport]));
        $lookup=new CaliforniaAddressLookup($this->db,new MessageBus([new SendMessageMiddleware($locator)]),new MockHttpClient(),$this->createStub(\Shopware\Core\System\SystemConfig\SystemConfigService::class));
        for($i=0;$i<2;++$i) { try { $lookup->find($address,'2026-11-01');self::fail('Pending address must block'); } catch(\DomainException) {} }
        self::assertCount(1,$transport->getSent());
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM ino_usa_tax_address'));
    }

    public function testSameReleaseDoesNotInvalidateAddressCache(): void
    {
        $sync=$this->sync($this->html());$sync->run();$before=$this->db->fetchOne("SELECT active_dataset_id FROM ino_usa_tax_source WHERE state='CA'");
        $sync->run();self::assertSame($before,$this->db->fetchOne("SELECT active_dataset_id FROM ino_usa_tax_source WHERE state='CA'"));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM ino_usa_tax_dataset'));
    }
    public function testPruningKeepsActiveDatasetEvenWhenItIsOld(): void
    {
        $active=$this->dataset('CA');$this->db->update('ino_usa_tax_dataset',['created_at'=>'2020-01-01 00:00:00'],['id'=>$active]);
        for($i=0;$i<4;++$i) { $this->dataset('CA'); }
        $this->db->update('ino_usa_tax_source',['active_dataset_id'=>$active],['state'=>'CA']);
        $removed=$this->sync('')->prune(1);self::assertSame(3,$removed);
        self::assertSame($active,$this->db->fetchOne("SELECT active_dataset_id FROM ino_usa_tax_source WHERE state='CA'"));
    }
    public function testStaleQueuedJobCanBeRecoveredAndOldMessageIsIgnored(): void
    {
        $sync=$this->sync($this->html());$old=$sync->queue();$this->db->executeStatement("UPDATE ino_usa_tax_source SET requested_at='2020-01-01 00:00:00' WHERE state='CA'");
        $new=$sync->queue();self::assertNotSame($old,$new);$sync->run($old,'CA');
        self::assertNull($this->db->fetchOne("SELECT active_dataset_id FROM ino_usa_tax_source WHERE state='CA'"));
        $sync->run($new,'CA');self::assertSame('completed',$this->db->fetchOne("SELECT status FROM ino_usa_tax_source WHERE state='CA'"));
    }
    public function testAdvisoryLockExcludesSecondConnection(): void
    {
        $this->admin->fetchOne("SELECT GET_LOCK('ino_usa_tax_sync',0)");
        try { $this->expectException(\RuntimeException::class);$this->sync('')->queue(); }
        finally { $this->admin->fetchOne("SELECT RELEASE_LOCK('ino_usa_tax_sync')"); }
    }
    public function testConsentRevocationPreventsBackgroundDisclosure(): void
    {
        $id=$this->dataset('CA');$hash=str_repeat('a',64);$this->db->insert('ino_usa_tax_address',['address_hash'=>$hash,'dataset_id'=>$id,'status'=>'queued','checked_at'=>gmdate('Y-m-d H:i:s')]);
        $config=$this->createStub(\Shopware\Core\System\SystemConfig\SystemConfigService::class);$config->method('getBool')->willReturn(false);
        $http=new MockHttpClient(static function(){ throw new \LogicException('Must not disclose an address after consent is revoked.'); });
        (new CaliforniaAddressLookup($this->db,new MessageBus(),$http,$config))->verify(new VerifyCaliforniaAddress(str_repeat('b',32),$hash,$id,str_repeat('c',32)));
        self::assertSame('rejected',$this->db->fetchOne('SELECT status FROM ino_usa_tax_address'));
    }

    private function workerFixture(): array
    {
        $this->sync($this->html())->run();$id=$this->db->fetchOne("SELECT active_dataset_id FROM ino_usa_tax_source WHERE state='CA'");
        $this->db->executeStatement('CREATE TABLE country (id BINARY(16) PRIMARY KEY, iso CHAR(2))');
        $this->db->executeStatement('CREATE TABLE country_state (id BINARY(16) PRIMARY KEY, short_code VARCHAR(10))');
        $this->db->executeStatement('CREATE TABLE customer_address (id BINARY(16) PRIMARY KEY, country_id BINARY(16), country_state_id BINARY(16), street VARCHAR(255), city VARCHAR(100), zipcode VARCHAR(20))');
        $this->db->insert('country',['id'=>hex2bin(str_repeat('a',32)),'iso'=>'US']);
        $this->db->insert('country_state',['id'=>hex2bin(str_repeat('b',32)),'short_code'=>'US-CA']);
        $addressId=str_repeat('c',32);$this->db->insert('customer_address',['id'=>hex2bin($addressId),'country_id'=>hex2bin(str_repeat('a',32)),'country_state_id'=>hex2bin(str_repeat('b',32)),'street'=>'450 N St','city'=>'Sacramento','zipcode'=>'95814']);
        $hash=CaliforniaAddressLookup::fingerprint('450 N St','Sacramento','95814');
        $this->db->insert('ino_usa_tax_address',['address_hash'=>$hash,'dataset_id'=>$id,'status'=>'queued','checked_at'=>gmdate('Y-m-d H:i:s')]);
        $config=$this->createStub(\Shopware\Core\System\SystemConfig\SystemConfigService::class);$config->method('getBool')->willReturn(true);
        return [new VerifyCaliforniaAddress($addressId,$hash,$id,str_repeat('d',32)),$config];
    }
    public function testBackgroundAddressVerificationIsStoredAndDeliveryIsIdempotent(): void
    {
        [$job,$config]=$this->workerFixture();$requests=0;
        $http=new MockHttpClient(static function($method,$url,$options) use (&$requests) { ++$requests;return new MockResponse(json_encode(SourceTest::response())); });
        $lookup=new CaliforniaAddressLookup($this->db,new MessageBus(),$http,$config);$lookup->verify($job);$lookup->verify($job);
        self::assertSame(1,$requests);self::assertSame('verified',$this->db->fetchOne('SELECT status FROM ino_usa_tax_address'));
        self::assertSame(8.75,(float)$this->db->fetchOne('SELECT rate FROM ino_usa_tax_address'));
    }
    public function testEditedAddressNeverUsesTheQueuedFingerprint(): void
    {
        [$job,$config]=$this->workerFixture();$this->db->executeStatement("UPDATE customer_address SET street='451 N St'");
        $http=new MockHttpClient(static function(){ throw new \LogicException('Changed address must not be disclosed by the obsolete task.'); });
        (new CaliforniaAddressLookup($this->db,new MessageBus(),$http,$config))->verify($job);
        self::assertSame('rejected',$this->db->fetchOne('SELECT status FROM ino_usa_tax_address'));
    }
}
