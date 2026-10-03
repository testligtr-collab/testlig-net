<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Exception\LearningContentPackageException;
use App\Repository\UserRepository;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\EmailNormalizer;
use App\Service\LearningContent\LearningContentPackageImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:learning-content:import-package',
    description: 'Import one allowlisted lesson package into an existing placeholder draft.',
)]
final class ImportLearningContentPackageCommand extends Command
{
    public const ACTOR_EMAIL_ENV = 'TESTLIG_CONTENT_ACTOR_EMAIL';

    public function __construct(
        private readonly LearningContentPackageImportService $importer,
        private readonly EmailNormalizer $emailNormalizer,
        private readonly UserRepository $users,
        private readonly ActiveVerifiedUserPolicy $actors,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Allowlisted package directory, relative to the repository')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'verify, dry-run, or apply', 'verify')
            ->addOption('expected-plan-fingerprint', null, InputOption::VALUE_REQUIRED, 'Required only for apply')
            ->addOption(
                'actor-email-env',
                null,
                InputOption::VALUE_REQUIRED,
                'Allowlisted environment variable name that holds the actor email',
                self::ACTOR_EMAIL_ENV,
            )
            ->setHelp(
                <<<'HELP'
Default mode is verify. verify and dry-run do not write.
apply replaces one owned placeholder draft when the recomputed plan fingerprint matches.
The command does not review, seal, publish, or place the lesson, and it does not import questions.
The actor email is read only from the allowlisted environment variable TESTLIG_CONTENT_ACTOR_EMAIL.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $package = $input->getOption('package');
            $mode = $input->getOption('mode');
            $fingerprint = $input->getOption('expected-plan-fingerprint');
            $actorEnv = $input->getOption('actor-email-env');
            if (!\is_string($package) || '' === $package || !\is_string($mode) || !\is_string($actorEnv)) {
                throw LearningContentPackageException::rejected();
            }
            $actor = $this->actor($actorEnv);
            $report = $this->importer->execute(
                $package,
                $mode,
                \is_string($fingerprint) && '' !== $fingerprint ? $fingerprint : null,
                $actor,
            );
        } catch (LearningContentPackageException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }

        foreach ($report->lines() as $line) {
            $output->writeln($line);
        }

        return 0 === $report->conflicts ? Command::SUCCESS : Command::FAILURE;
    }

    private function actor(string $envName): User
    {
        if (self::ACTOR_EMAIL_ENV !== $envName) {
            throw LearningContentPackageException::actorUnavailable();
        }
        $email = getenv(self::ACTOR_EMAIL_ENV);
        if (!\is_string($email) || '' === trim($email)) {
            throw LearningContentPackageException::actorUnavailable();
        }

        try {
            $normalized = $this->emailNormalizer->normalize($email);
        } catch (\Throwable) {
            throw LearningContentPackageException::actorUnavailable();
        }

        $user = $this->users->findOneByNormalizedEmail($normalized);
        if (null === $user || !$this->actors->isActiveAndVerified($user)) {
            throw LearningContentPackageException::actorUnavailable();
        }

        return $user;
    }
}
