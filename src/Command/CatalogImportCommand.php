<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\CatalogException;
use App\Service\CatalogImport\CatalogImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:catalog:import',
    description: 'Import MEB/TYMM catalog fixture as draft rows (dry-run by default).',
)]
final class CatalogImportCommand extends Command
{
    public function __construct(
        private readonly CatalogImportService $importService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Absolute or project-relative path to fixture YAML')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist changes (default is dry-run)')
            ->addOption('update-existing', null, InputOption::VALUE_NONE, 'Update name/position/source_url when source identity already exists')
            ->setHelp(
                <<<'HELP'
Imports a versioned MEB catalog fixture into CatalogSubject → CatalogUnit → CatalogTopic.

Default mode is dry-run (no DB writes). Pass --apply to persist.
All imported rows remain draft. This command never publishes, archives, or deletes.

Idempotency key: source_version + source_code + source_occurrence.
Without --update-existing, existing identities are skipped even if names differ.

Example:
  php bin/console app:catalog:import --file=data/catalog/meb/tymm-2026/grade-1-matematik.yaml
  php bin/console app:catalog:import --file=data/catalog/meb/tymm-2026/grade-1-matematik.yaml --apply
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
        $updateExisting = (bool) $input->getOption('update-existing');

        $io->writeln($apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (no writes)');
        if ($updateExisting) {
            $io->writeln('Flag: --update-existing');
        }

        try {
            $result = $this->importService->import($path, $apply, $updateExisting);
        } catch (CatalogException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($result->lines() as $line) {
            $io->writeln($line);
        }
        $io->writeln(\sprintf(
            'Summary: created=%d updated=%d skipped=%d conflicts=%d errors=%d applied=%s',
            $result->created,
            $result->updated,
            $result->skipped,
            $result->conflicts,
            $result->errors,
            $result->applied ? '1' : '0',
        ));

        if ($result->conflicts > 0 || $result->errors > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function resolvePath(string $file): string
    {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $file) || str_starts_with($file, '\\\\')) {
            return $file;
        }
        $projectRoot = \dirname(__DIR__, 2);

        return $projectRoot.\DIRECTORY_SEPARATOR.str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $file);
    }
}
