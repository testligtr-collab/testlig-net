<?php

declare(strict_types=1);

namespace App\Command;

use App\Homework\Pdf\HomeworkPdfQuarantineCleaner;
use App\Homework\Pdf\HomeworkPdfScanException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:homework-pdf:purge-quarantine',
    description: 'Purge stale unlocked homework PDF quarantine files.',
)]
final class PurgeHomeworkPdfQuarantineCommand extends Command
{
    public function __construct(
        private readonly HomeworkPdfQuarantineCleaner $cleaner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Minimum age in seconds (>= 60).', (string) HomeworkPdfQuarantineCleaner::DEFAULT_MAX_AGE_SECONDS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count eligible stale files without deleting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawAge = $input->getOption('max-age');
        if (!\is_string($rawAge) || 1 !== preg_match('/^[0-9]+$/', $rawAge)) {
            $output->writeln('quarantine_purge_status=invalid_max_age');

            return Command::INVALID;
        }
        $maxAge = (int) $rawAge;
        if ($maxAge < HomeworkPdfQuarantineCleaner::MIN_MAX_AGE_SECONDS) {
            $output->writeln('quarantine_purge_status=invalid_max_age');

            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $purged = $this->cleaner->purgeStale($maxAge, null, $dryRun);
        } catch (HomeworkPdfScanException $exception) {
            $output->writeln('quarantine_purge_status=error');
            $output->writeln('quarantine_purge_code='.$exception->getMessage());

            return Command::FAILURE;
        }

        $output->writeln('quarantine_purge_status=ok');
        $output->writeln('dry_run='.($dryRun ? 'yes' : 'no'));
        $output->writeln('max_age_seconds='.$maxAge);
        $output->writeln('purged_count='.$purged);

        return Command::SUCCESS;
    }
}
