<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\GradeLevel;
use App\Exception\CatalogException;
use App\Service\CatalogPublish\CatalogPublishTreeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:catalog:publish-tree',
    description: 'Atomically publish one MEB/TYMM catalog tree (dry-run / preflight by default).',
)]
final class CatalogPublishTreeCommand extends Command
{
    public function __construct(
        private readonly CatalogPublishTreeService $publishTreeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source-version', null, InputOption::VALUE_REQUIRED, 'Catalog source_version (e.g. TYMM-2026)')
            ->addOption('subject-code', null, InputOption::VALUE_REQUIRED, 'Subject source_code (e.g. MAT)')
            ->addOption('expected-subjects', null, InputOption::VALUE_REQUIRED, 'Expected subject count (safety guard)')
            ->addOption('expected-units', null, InputOption::VALUE_REQUIRED, 'Expected unit count (safety guard)')
            ->addOption('expected-topics', null, InputOption::VALUE_REQUIRED, 'Expected topic count (safety guard)')
            ->addOption('grade', null, InputOption::VALUE_REQUIRED, 'Required grade_level', '1')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist publish (default is preflight/dry-run)')
            ->setHelp(
                <<<'HELP'
Publishes one catalog tree bottom-up (topics → units → subject).

Default is dry-run (no DB writes). Pass --apply to publish draft rows only.
Never archives, deletes, or mutates names. Fully published trees are a safe no-op.

Example:
  php bin/console app:catalog:publish-tree --source-version=TYMM-2026 --subject-code=MAT --expected-subjects=1 --expected-units=7 --expected-topics=19
  php bin/console app:catalog:publish-tree --source-version=TYMM-2026 --subject-code=MAT --expected-subjects=1 --expected-units=7 --expected-topics=19 --apply
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sourceVersion = $input->getOption('source-version');
        $subjectCode = $input->getOption('subject-code');
        $expectedSubjects = $input->getOption('expected-subjects');
        $expectedUnits = $input->getOption('expected-units');
        $expectedTopics = $input->getOption('expected-topics');
        $gradeRaw = $input->getOption('grade');
        $apply = (bool) $input->getOption('apply');

        if (!\is_string($sourceVersion) || '' === trim($sourceVersion)) {
            $io->error('--source-version is required.');

            return Command::FAILURE;
        }
        if (!\is_string($subjectCode) || '' === trim($subjectCode)) {
            $io->error('--subject-code is required.');

            return Command::FAILURE;
        }
        if (!$this->isPositiveIntOption($expectedSubjects) || !$this->isPositiveIntOption($expectedUnits) || !$this->isPositiveIntOption($expectedTopics)) {
            $io->error('--expected-subjects, --expected-units and --expected-topics are required positive integers.');

            return Command::FAILURE;
        }
        if (!\is_string($gradeRaw) && !\is_int($gradeRaw)) {
            $io->error('--grade is invalid.');

            return Command::FAILURE;
        }
        $gradeValue = (int) $gradeRaw;
        try {
            $grade = GradeLevel::from($gradeValue);
        } catch (\ValueError) {
            $io->error('--grade is not a valid GradeLevel.');

            return Command::FAILURE;
        }

        $io->writeln($apply ? 'Mode: APPLY' : 'Mode: PREFLIGHT / DRY-RUN (no writes)');

        try {
            $result = $this->publishTreeService->publish(
                trim($sourceVersion),
                trim($subjectCode),
                (int) $expectedSubjects,
                (int) $expectedUnits,
                (int) $expectedTopics,
                $apply,
                $grade,
            );
        } catch (CatalogException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($result->lines() as $line) {
            $io->writeln($line);
        }
        $io->writeln(\sprintf(
            'Summary: found=%d/%d/%d to_publish=%d/%d/%d already=%d/%d/%d published=%d skipped=%d noop=%s applied=%s',
            $result->subjectsFound,
            $result->unitsFound,
            $result->topicsFound,
            $result->subjectsToPublish,
            $result->unitsToPublish,
            $result->topicsToPublish,
            $result->subjectsAlreadyPublished,
            $result->unitsAlreadyPublished,
            $result->topicsAlreadyPublished,
            $result->published,
            $result->skipped,
            $result->noop ? '1' : '0',
            $result->applied ? '1' : '0',
        ));

        return Command::SUCCESS;
    }

    private function isPositiveIntOption(mixed $value): bool
    {
        if (\is_int($value)) {
            return $value > 0;
        }
        if (!\is_string($value) || !preg_match('/^[1-9][0-9]*$/', $value)) {
            return false;
        }

        return (int) $value > 0;
    }
}
