<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\CurriculumImportException;
use App\Service\CurriculumImport\CurriculumPilotImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:curriculum:import-pilot-outcome',
    description: 'Import TYMM pilot curriculum learning outcome slice (dry-run by default).',
)]
final class CurriculumPilotImportCommand extends Command
{
    public function __construct(
        private readonly CurriculumPilotImportService $importService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Absolute or project-relative path to fixture YAML')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist changes (default is dry-run)')
            ->setHelp(
                <<<'HELP'
Imports a minimal TYMM curriculum program → unit → topic → learning outcome chain
for the LearningContent primary-outcome form.

Uses Curriculum* domain managers (SuperAdmin actor). Default mode is dry-run.
Pass --apply to persist. Idempotent on natural keys; second apply skips existing rows.

Example:
  php bin/console app:curriculum:import-pilot-outcome --file=data/curriculum/meb/tymm-2026/grade-1-matematik-uzamsal-iliskiler.yaml
  php bin/console app:curriculum:import-pilot-outcome --file=data/curriculum/meb/tymm-2026/grade-1-matematik-uzamsal-iliskiler.yaml --apply
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getOption('file');
        if (!\is_string($file) || '' === trim($file)) {
            $io->error('--file is required.');

            return Command::FAILURE;
        }
        $path = $this->resolvePath(trim($file));
        $apply = (bool) $input->getOption('apply');

        $io->writeln($apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (no writes)');

        try {
            $result = $this->importService->import($path, $apply);
        } catch (CurriculumImportException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($result->lines() as $line) {
            $io->writeln($line);
        }
        $io->writeln(\sprintf(
            'Summary: created=%d skipped=%d conflicts=%d errors=%d published=%s applied=%s',
            $result->created,
            $result->skipped,
            $result->conflicts,
            $result->errors,
            $result->published ? '1' : '0',
            $result->applied ? '1' : '0',
        ));

        return $result->errors > 0 || $result->conflicts > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolvePath(string $file): string
    {
        if (str_starts_with($file, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $file)) {
            return $file;
        }

        return \dirname(__DIR__, 2).\DIRECTORY_SEPARATOR.str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $file);
    }
}
