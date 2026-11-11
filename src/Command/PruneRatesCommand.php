<?php declare(strict_types=1);
namespace InoceanTaxProviderUS\Command;
use InoceanTaxProviderUS\Service\RateSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
final class PruneRatesCommand extends Command
{
    public function __construct(private readonly RateSyncService $sync) { parent::__construct('ino:usa-tax:prune'); }
    protected function configure(): void
    {
        $this->setDescription('Prune inactive tax datasets and address verification caches older than seven days.')
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Recent versions per state (1-20); active version is always retained', '3');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = (string) $input->getOption('keep');
        if (!ctype_digit($raw)) { throw new \InvalidArgumentException('Retention must be a positive integer.'); }
        $output->writeln(sprintf('Removed %d inactive tax versions.', $this->sync->prune((int) $raw)));
        return self::SUCCESS;
    }
}
