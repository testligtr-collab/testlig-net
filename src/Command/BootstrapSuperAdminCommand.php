<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\SuperAdminBootstrapException;
use App\Service\SuperAdminBootstrapService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:bootstrap-super-admin',
    description: 'One-shot creation of the first ROLE_SUPER_ADMIN account (env-gated).',
)]
final class BootstrapSuperAdminCommand extends Command
{
    public function __construct(
        private readonly SuperAdminBootstrapService $bootstrapService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Account e-mail')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'First name', 'Super')
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Last name', 'Admin')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Required confirmation flag')
            ->setHelp(
                <<<'HELP'
Creates the first SUPER_ADMIN when ALLOW_SUPER_ADMIN_BOOTSTRAP=1.

Password is read via hidden interactive input (or PHPUnit input stream).
Never pass the password as a CLI argument.

After success, set ALLOW_SUPER_ADMIN_BOOTSTRAP=0 again.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getOption('email');
        if (!\is_string($email) || '' === trim($email)) {
            $io->error('The --email option is required.');

            return Command::FAILURE;
        }

        $firstName = $input->getOption('first-name');
        $lastName = $input->getOption('last-name');
        if (!\is_string($firstName) || !\is_string($lastName)) {
            $io->error('Invalid name options.');

            return Command::FAILURE;
        }

        $confirmed = (bool) $input->getOption('confirm');
        if (!$confirmed) {
            $io->error('Refusing to run without --confirm.');

            return Command::FAILURE;
        }

        // Reject accidental password-style argv usage.
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (!\is_string($arg)) {
                continue;
            }
            if (str_starts_with($arg, '--password') || str_starts_with($arg, '-p=')) {
                $io->error('Password must not be passed as a CLI argument.');

                return Command::FAILURE;
            }
        }

        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            $io->error('Question helper is unavailable.');

            return Command::FAILURE;
        }
        $question = new Question('Password (hidden): ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $password = $helper->ask($input, $output, $question);
        if (!\is_string($password) || '' === $password) {
            $io->error('Password is required.');

            return Command::FAILURE;
        }

        try {
            $user = $this->bootstrapService->bootstrap(
                email: $email,
                plainPassword: $password,
                firstName: $firstName,
                lastName: $lastName,
                confirmed: true,
            );
        } catch (SuperAdminBootstrapException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            // Best-effort wipe of local variable content length awareness only.
            unset($password);
        }

        $io->success(\sprintf(
            'SUPER_ADMIN created (id=%s). Set ALLOW_SUPER_ADMIN_BOOTSTRAP=0 now.',
            $user->getId()->toRfc4122(),
        ));
        $io->writeln('E-mail is not printed. Password was not logged.');

        return Command::SUCCESS;
    }
}
