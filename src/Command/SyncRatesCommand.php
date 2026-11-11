<?php declare(strict_types=1);
namespace InoceanTaxProviderUS\Command;
use InoceanTaxProviderUS\Service\RateSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
final class SyncRatesCommand extends Command
{
    public function __construct(private readonly RateSyncService $sync) { parent::__construct('ino:usa-tax:sync'); }
    protected function configure(): void { $this->setDescription('Synchronize official CA or WA rates; recover interrupted jobs.')->addOption('state', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'CA or WA', 'CA'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->sync->run(null, (string) $input->getOption('state'));
        $output->writeln('Official tax release validated and published.');
        return self::SUCCESS;
    }
}
