<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    private PDO $pdo;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        error_log(sprintf(
            '[database] source=%s host=%s database=%s',
            (string) ($config['source'] ?? 'unknown'),
            (string) ($config['host'] ?? 'unknown'),
            (string) ($config['database'] ?? 'unknown')
        ));

        $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function config(): array
    {
        return $this->config;
    }
}
