<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Application health probes. Responses never include credentials, hostnames, or paths.
 */
final class HealthChecker
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(REDIS_URL)%')]
        private readonly string $redisUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     status: string,
     *     application: string,
     *     environment: string,
     *     checks: array{database: string, redis: string}
     * }
     */
    public function check(): array
    {
        $database = $this->checkDatabase();
        $redis = $this->checkRedis();

        $status = ('ok' === $database && 'ok' === $redis) ? 'ok' : 'degraded';
        if ('fail' === $database) {
            $status = 'unavailable';
        }

        return [
            'status' => $status,
            'application' => 'testlig',
            'environment' => $this->environment,
            'checks' => [
                'database' => $database,
                'redis' => $redis,
            ],
        ];
    }

    private function checkDatabase(): string
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return 'ok';
        } catch (\Throwable $exception) {
            $this->logger->warning('Health check: database unavailable.', [
                'exception_class' => $exception::class,
            ]);

            return 'fail';
        }
    }

    private function checkRedis(): string
    {
        try {
            $client = new RedisClient($this->redisUrl);
            $response = $client->ping();

            if ('PONG' === (string) $response || true === $response) {
                return 'ok';
            }

            return 'fail';
        } catch (\Throwable $exception) {
            $this->logger->warning('Health check: redis unavailable.', [
                'exception_class' => $exception::class,
            ]);

            return 'fail';
        }
    }
}
