<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Forces every new MariaDB/MySQL Doctrine connection session timezone to +00:00.
 *
 * Production deployments must keep this (or an equivalent INIT_COMMAND) enabled so
 * DATETIME values remain comparable to UTC_TIMESTAMP() in triggers.
 */
final class MariaDbUtcSessionTimezoneMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): Connection {
                $connection = parent::connect($params);
                $connection->exec("SET time_zone = '+00:00'");

                return $connection;
            }
        };
    }
}
