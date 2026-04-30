<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $query,
        public readonly array $request,
        public readonly array $server,
        public readonly array $headers,
        public readonly string $body,
        private array $routeParams = []
    ) {
    }

    public static function capture(): self
    {
        $uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $request = $_POST;

        if ($method === 'POST' && isset($request['_method'])) {
            $method = strtoupper((string) $request['_method']);
        }

        return new self(
            $method,
            rtrim($uri, '/') ?: '/',
            $_GET,
            $request,
            $_SERVER,
            function_exists('getallheaders') ? getallheaders() : [],
            file_get_contents('php://input') ?: ''
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $default;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
