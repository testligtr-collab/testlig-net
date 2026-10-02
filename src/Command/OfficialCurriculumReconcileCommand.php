<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\CurriculumImportException;
use App\Service\CurriculumImport\OfficialCurriculumReconcileService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:curriculum:reconcile-official-program',
    description: 'Reconcile the closed TYMM-2026 grade-1 Matematik outcome fixture (dry-run by default).',
)]
final class OfficialCurriculumReconcileCommand extends Command
{
    public function __construct(
        private readonly OfficialCurriculumReconcileService $reconcile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the official fixture YAML')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist a matching dry-run plan')
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Dry-run plan fingerprint required with --apply')
            ->setHelp(
                <<<'HELP'
Completes one published program from the verified TYMM-2026 grade-1 Matematik fixture.
It does not open published curriculum editing for any other program.

Default mode is dry-run. --apply requires the plan fingerprint printed by that dry-run.
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
        $apply = (bool) $input->getOption('apply');
        $plan = $input->getOption('plan');
        $plan = \is_string($plan) && '' !== $plan ? $plan : null;
        if ($apply && null === $plan) {
            $io->error('--apply requires --plan from the dry-run fingerprint.');

            return Command::FAILURE;
        }

        $path = $this->resolvePath(trim($file));
        $io->writeln($apply ? 'Mode: APPLY' : 'Mode: DRY-RUN (no writes)');

        try {
            $result = $this->reconcile->reconcile($path, $apply, $plan);
        } catch (CurriculumImportException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($result->lines() as $line) {
            $io->writeln($line);
        }
        $io->writeln('applied='.($result->applied ? '1' : '0'));
        $io->writeln('noop='.($result->noop ? '1' : '0'));

        return 1 === $result->applyReady ? Command::SUCCESS : Command::FAILURE;
    }

    private function resolvePath(string $file): string
    {
        if (str_starts_with($file, '/') || 1 === preg_match('/^[A-Za-z]:[\\\\\\/]/', $file)) {
            return $file;
        }
        $root = \dirname(__DIR__, 2);

        return $root.\DIRECTORY_SEPARATOR.str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $file);
    }
}
