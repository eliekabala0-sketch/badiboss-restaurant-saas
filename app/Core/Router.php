<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, array $action, array $middleware = []): void
    {
        $this->register('GET', $path, $action, $middleware);
    }

    public function post(string $path, array $action, array $middleware = []): void
    {
        $this->register('POST', $path, $action, $middleware);
    }

    private function register(string $method, string $path, array $action, array $middleware): void
    {
        $this->routes[$method][] = [
            'path' => $path,
            'action' => $action,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): void
    {
        $route = null;
        $params = [];

        foreach ($this->routes[$request->method] ?? [] as $candidate) {
            $pattern = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $candidate['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $request->uri, $matches) === 1) {
                $route = $candidate;
                foreach ($matches as $key => $value) {
                    if (!is_int($key)) {
                        $params[$key] = $value;
                    }
                }
                break;
            }
        }

        if ($route === null) {
            http_response_code(404);
            echo '404 Not Found';
            return;
        }

        $request->setRouteParams($params);
        Container::getInstance()->set('request', $request);

        foreach ($route['middleware'] as $middlewareClass) {
            (new $middlewareClass())->handle($request);
        }

        [$class, $method] = $route['action'];
        $controller = new $class();
        $controller->{$method}($request);
    }
}
