<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private PDO $pdo;
    private array $config;
    private array $lastAttemptedConfig = [];

    public function __construct(array $config)
    {
        $candidates = $config['candidates'] ?? [$config];
        $lastException = null;

        foreach ($candidates as $candidate) {
            $this->lastAttemptedConfig = $candidate;

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $candidate['host'],
                $candidate['port'],
                $candidate['database'],
                $candidate['charset']
            );

            error_log(sprintf(
                '[database] trying source=%s host=%s port=%s database=%s',
                (string) ($candidate['source'] ?? 'unknown'),
                (string) ($candidate['host'] ?? 'unknown'),
                (string) ($candidate['port'] ?? 'unknown'),
                (string) ($candidate['database'] ?? 'unknown')
            ));

            try {
                $this->pdo = new PDO($dsn, $candidate['username'], $candidate['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $this->config = $candidate;

                error_log(sprintf(
                    '[database] connected source=%s host=%s port=%s database=%s',
                    (string) ($candidate['source'] ?? 'unknown'),
                    (string) ($candidate['host'] ?? 'unknown'),
                    (string) ($candidate['port'] ?? 'unknown'),
                    (string) ($candidate['database'] ?? 'unknown')
                ));

                return;
            } catch (PDOException $exception) {
                $lastException = $exception;

                error_log(sprintf(
                    '[database] failed source=%s host=%s port=%s database=%s message=%s',
                    (string) ($candidate['source'] ?? 'unknown'),
                    (string) ($candidate['host'] ?? 'unknown'),
                    (string) ($candidate['port'] ?? 'unknown'),
                    (string) ($candidate['database'] ?? 'unknown'),
                    $this->shortError($exception->getMessage())
                ));
            }
        }

        $this->config = $this->lastAttemptedConfig !== [] ? $this->lastAttemptedConfig : $config;

        if ($lastException instanceof PDOException) {
            throw $lastException;
        }

        throw new PDOException('Connexion impossible');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function config(): array
    {
        return $this->config;
    }

    public function lastAttemptedConfig(): array
    {
        return $this->lastAttemptedConfig;
    }

    private function shortError(string $message): string
    {
        $message = preg_replace('/password\s*=\s*[^;\s]+/i', 'password=***', $message) ?? $message;
        $message = preg_replace('/\/\/([^:@\/]+):([^@\/]+)@/', '//***:***@', $message) ?? $message;

        return trim($message) !== '' ? $message : 'Connexion impossible';
    }
}
