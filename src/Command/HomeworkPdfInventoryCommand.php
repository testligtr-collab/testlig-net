<?php

declare(strict_types=1);

namespace App\Command;

use App\Homework\Pdf\HomeworkPdfInventoryReport;
use App\Homework\Pdf\HomeworkPdfStorageInventory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:homework-pdf:inventory',
    description: 'Read-only preview of homework PDF storage use, retention classes and orphan candidates. Writes nothing.',
)]
final class HomeworkPdfInventoryCommand extends Command
{
    public function __construct(
        private readonly HomeworkPdfStorageInventory $inventory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'orphan-min-age',
                null,
                InputOption::VALUE_REQUIRED,
                'Test age threshold in seconds (>= 3600): older unreferenced files become orphan candidates. Not a retention policy.',
                (string) HomeworkPdfStorageInventory::DEFAULT_ORPHAN_MIN_AGE_SECONDS,
            )
            ->addOption('by-institution', null, InputOption::VALUE_NONE, 'Also print per-institution totals (institution id, counts and bytes only).')
            ->addOption('preview-limit-bytes', null, InputOption::VALUE_REQUIRED, 'Explicit test limit in bytes used only to print a usage percentage. It is not a quota and rejects nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawAge = $input->getOption('orphan-min-age');
        if (!\is_string($rawAge) || 1 !== preg_match('/^[0-9]+$/', $rawAge)
            || (int) $rawAge < HomeworkPdfStorageInventory::MIN_ORPHAN_MIN_AGE_SECONDS
        ) {
            return $this->invalidOption($output);
        }
        $minAge = (int) $rawAge;

        $limit = null;
        $rawLimit = $input->getOption('preview-limit-bytes');
        if (null !== $rawLimit) {
            if (!\is_string($rawLimit) || 1 !== preg_match('/^[1-9][0-9]{0,17}$/', $rawLimit)) {
                return $this->invalidOption($output);
            }
            $limit = (int) $rawLimit;
        }

        try {
            $report = $this->inventory->preview($minAge);
        } catch (\Throwable) {
            $output->writeln('homework_pdf_inventory_status=error');
            $output->writeln('homework_pdf_inventory_code=inventory_failed');

            return Command::FAILURE;
        }

        $output->writeln('homework_pdf_inventory_status='.$report->status);
        $output->writeln('preview_only=yes');
        $output->writeln('writes=none');
        $output->writeln('point_in_time=yes');
        $output->writeln('orphan_min_age_seconds='.$report->orphanMinAgeSeconds);
        if (!$report->isUsable()) {
            return Command::FAILURE;
        }

        $output->writeln('store_empty='.($report->storeEmpty ? 'yes' : 'no'));
        foreach (HomeworkPdfInventoryReport::COUNTERS as $name) {
            $output->writeln($name.'='.$report->count($name));
        }
        $output->writeln('db_declared_minus_physical_bytes='.($report->count('db_declared_bytes') - $report->count('db_physical_bytes')));

        if (null !== $limit) {
            $output->writeln('preview_limit_bytes='.$limit);
            $output->writeln('preview_limit_applied=no');
            $output->writeln('usage_percent_physical_total='.$this->percent($report->count('db_physical_bytes'), $limit));
        }

        if ((bool) $input->getOption('by-institution')) {
            $output->writeln('institution_count='.\count($report->institutions));
            foreach ($report->institutions as $id => $totals) {
                $line = 'institution='.$id
                    .' references='.$totals['references']
                    .' declared_bytes='.$totals['declared_bytes']
                    .' physical_bytes='.$totals['physical_bytes'];
                if (null !== $limit) {
                    $line .= ' usage_percent='.$this->percent($totals['physical_bytes'], $limit);
                }
                $output->writeln($line);
            }
            if ($report->unknownOwner['references'] > 0) {
                // Ambiguous ownership is shown on its own line and never added to an institution.
                $output->writeln('institution=ambiguous'
                    .' references='.$report->unknownOwner['references']
                    .' declared_bytes='.$report->unknownOwner['declared_bytes']
                    .' physical_bytes='.$report->unknownOwner['physical_bytes']);
            }
        }

        return Command::SUCCESS;
    }

    private function invalidOption(OutputInterface $output): int
    {
        $output->writeln('homework_pdf_inventory_status=invalid_option');

        return Command::INVALID;
    }

    private function percent(int $bytes, int $limit): string
    {
        return number_format($bytes * 100 / $limit, 2, '.', '');
    }
}
