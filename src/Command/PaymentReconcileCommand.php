<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\PaymentProviderEnvironment;
use App\Exception\CommerceException;
use App\Service\PaymentReconciliationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Manual payment reconciliation. Aggregate output only.
 */
#[AsCommand(
    name: 'app:payment:reconcile',
    description: 'Reconcile local payment attempts against provider snapshots (SUPER_ADMIN).',
)]
final class PaymentReconcileCommand extends Command
{
    public function __construct(
        private readonly PaymentReconciliationService $reconciliationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('actor-id', null, InputOption::VALUE_REQUIRED, 'SUPER_ADMIN operator UUID')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider code')
            ->addOption('environment', null, InputOption::VALUE_REQUIRED, 'sandbox|production')
            ->addOption('attempt-id', null, InputOption::VALUE_REQUIRED, 'Optional single attempt UUID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Batch limit', (string) PaymentReconciliationService::DEFAULT_LIMIT)
            ->addOption('reason-code', null, InputOption::VALUE_REQUIRED, 'Normalized reason code', 'manual_reconciliation')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute outcomes without persistence/mutation')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Required when not dry-run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actorIdRaw = $input->getOption('actor-id');
        $provider = $input->getOption('provider');
        $envRaw = $input->getOption('environment');
        $reasonCode = $input->getOption('reason-code');
        $limitRaw = $input->getOption('limit');
        $attemptRaw = $input->getOption('attempt-id');
        $dryRun = (bool) $input->getOption('dry-run');
        $confirm = (bool) $input->getOption('confirm');

        if (!\is_string($actorIdRaw) || !Uuid::isValid($actorIdRaw)
            || !\is_string($provider) || '' === trim($provider)
            || !\is_string($envRaw)
            || !\is_string($reasonCode)
        ) {
            $output->writeln('status=error reason=invalid_options');

            return Command::FAILURE;
        }

        $environment = PaymentProviderEnvironment::tryFrom(strtolower(trim($envRaw)));
        if (!$environment instanceof PaymentProviderEnvironment) {
            $output->writeln('status=error reason=invalid_environment');

            return Command::FAILURE;
        }

        $limit = (int) $limitRaw;
        if ($limit < 1 || $limit > PaymentReconciliationService::MAX_LIMIT) {
            $output->writeln('status=error reason=invalid_limit');

            return Command::FAILURE;
        }

        $attemptId = null;
        if (\is_string($attemptRaw) && '' !== trim($attemptRaw)) {
            if (!Uuid::isValid($attemptRaw)) {
                $output->writeln('status=error reason=invalid_attempt_id');

                return Command::FAILURE;
            }
            $attemptId = Uuid::fromString($attemptRaw);
        }

        try {
            $summary = $this->reconciliationService->reconcile(
                actorId: Uuid::fromString($actorIdRaw),
                providerCode: trim($provider),
                environment: $environment,
                reasonCode: $reasonCode,
                confirm: $confirm,
                dryRun: $dryRun,
                attemptId: $attemptId,
                limit: $limit,
            );
        } catch (CommerceException $e) {
            $output->writeln('status=error reason='.$e->getReason()->value);

            return Command::FAILURE;
        }

        $output->writeln(\sprintf(
            'status=ok checked=%d matched=%d discrepancy=%d failed=%d run_status=%s dry_run=%d',
            $summary->checked,
            $summary->matched,
            $summary->discrepancy,
            $summary->failed,
            $summary->runStatus,
            $summary->dryRun ? 1 : 0,
        ));

        return Command::SUCCESS;
    }
}
