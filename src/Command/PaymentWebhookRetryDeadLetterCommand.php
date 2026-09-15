<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\CommerceException;
use App\Service\PaymentWebhookDeadLetterRequeueService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Controlled SUPER_ADMIN dead-letter requeue. Sensitive identifiers are not printed.
 */
#[AsCommand(
    name: 'app:payment:webhook:retry-dead-letter',
    description: 'Requeue a dead-letter webhook inbox event to retry_pending (SUPER_ADMIN).',
)]
final class PaymentWebhookRetryDeadLetterCommand extends Command
{
    public function __construct(
        private readonly PaymentWebhookDeadLetterRequeueService $requeueService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('event-id', null, InputOption::VALUE_REQUIRED, 'Inbox event UUID')
            ->addOption('actor-id', null, InputOption::VALUE_REQUIRED, 'SUPER_ADMIN operator UUID')
            ->addOption('reason-code', null, InputOption::VALUE_REQUIRED, 'Normalized reason code')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Required confirmation flag');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $eventIdRaw = $input->getOption('event-id');
        $actorIdRaw = $input->getOption('actor-id');
        $reasonCode = $input->getOption('reason-code');
        $confirm = (bool) $input->getOption('confirm');

        if (!\is_string($eventIdRaw) || !Uuid::isValid($eventIdRaw)
            || !\is_string($actorIdRaw) || !Uuid::isValid($actorIdRaw)
            || !\is_string($reasonCode) || '' === trim($reasonCode)
        ) {
            $output->writeln('status=error reason=invalid_options');

            return Command::FAILURE;
        }

        try {
            $result = $this->requeueService->requeue(
                Uuid::fromString($eventIdRaw),
                Uuid::fromString($actorIdRaw),
                $reasonCode,
                $confirm,
            );
        } catch (CommerceException $e) {
            $output->writeln('status=error reason='.$e->getReason()->value);

            return Command::FAILURE;
        }

        $output->writeln(\sprintf(
            'status=ok processing_status=%s attempt_count=%d provider_code=%s environment=%s',
            $result->status->value,
            $result->attemptCount,
            $result->providerCode,
            $result->environment->value,
        ));

        return Command::SUCCESS;
    }
}
