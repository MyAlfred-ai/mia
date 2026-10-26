<?php

declare(strict_types=1);

class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper(trim($method)) ?: 'GET';

        // parse_url() renvoie false sur une URI sévèrement malformée (bots,
        // scans). On retombe alors proprement sur "/" plutôt que de crasher.
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $uri = rtrim($path, '/') ?: '/';

        foreach ($this->routes[$method] ?? [] as $path => $handler) {
            $pattern = $this->toRegex($path);
            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $this->call($handler, $params);
                return;
            }
        }

        http_response_code(404);
        require dirname(__DIR__) . '/templates/404.php';
    }

    private function toRegex(string $path): string
    {
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    private function call(callable|array $handler, array $params): void
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $instance = new $class();
            $instance->$method(...array_values($params));
        } else {
            $handler(...array_values($params));
        }
    }
}
