<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\PaymentProviderEnvironment;
use App\Exception\CommerceException;
use App\Service\PaymentWebhookDueProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Process a single due webhook batch. Exit SUCCESS even when failed_count > 0
 * (transient item failures). FAILURE only for invalid configuration/options.
 */
#[AsCommand(
    name: 'app:payment:webhook:process-due',
    description: 'Process due payment webhook inbox events (received / retry / stale lease).',
)]
final class PaymentWebhookProcessDueCommand extends Command
{
    public function __construct(
        private readonly PaymentWebhookDueProcessor $batchProcessor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max events to select', (string) PaymentWebhookDueProcessor::DEFAULT_LIMIT)
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Optional provider code filter')
            ->addOption('environment', null, InputOption::VALUE_REQUIRED, 'Optional environment filter (sandbox|production)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List due counts only; no claim/mutation')
            ->setHelp(
                <<<'HELP'
Exit codes:
  0  Batch completed (including when some items failed — see failed=N).
  1  Invalid options / configuration error.

Output is numeric summary only (no UUIDs, hashes, refs, or PII).
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limitRaw = $input->getOption('limit');
        if (!\is_string($limitRaw) && !\is_int($limitRaw)) {
            $output->writeln('selected=0 processed=0 rejected=0 retry_pending=0 dead_letter=0 failed=0 skipped=0');

            return Command::FAILURE;
        }
        $limit = (int) $limitRaw;
        if ($limit < 1 || $limit > PaymentWebhookDueProcessor::MAX_LIMIT) {
            $output->writeln('error=invalid_limit');

            return Command::FAILURE;
        }

        $provider = $input->getOption('provider');
        $providerCode = \is_string($provider) && '' !== trim($provider) ? trim($provider) : null;

        $envOption = $input->getOption('environment');
        $environment = null;
        if (\is_string($envOption) && '' !== trim($envOption)) {
            $environment = PaymentProviderEnvironment::tryFrom(strtolower(trim($envOption)));
            if (!$environment instanceof PaymentProviderEnvironment) {
                $output->writeln('error=invalid_environment');

                return Command::FAILURE;
            }
        }

        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $summary = $this->batchProcessor->process($limit, $providerCode, $environment, $dryRun);
        } catch (CommerceException $e) {
            $output->writeln('error=config');

            return Command::FAILURE;
        }

        $output->writeln(\sprintf(
            'selected=%d processed=%d rejected=%d retry_pending=%d dead_letter=%d failed=%d skipped=%d dry_run=%d',
            $summary->selected,
            $summary->processed,
            $summary->rejected,
            $summary->retryPending,
            $summary->deadLetter,
            $summary->failed,
            $summary->skipped,
            $summary->dryRun ? 1 : 0,
        ));

        return Command::SUCCESS;
    }
}
