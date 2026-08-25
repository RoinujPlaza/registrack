<?php

declare(strict_types=1);

namespace RegisTrack\Core;

/**
 * Minimal path router with {param} segment support.
 * Handlers receive the matched parameters: fn(array $params): void
 */
final class Router
{
    /** @var array<string, array<string, array{pattern: string, handler: callable}>> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $method = strtoupper($method);
        $this->routes[$method][$pattern] = ['pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $requestSegments = $this->segments($path);

        $pathExists = false;

        foreach ($this->routes as $routeMethod => $methodRoutes) {
            foreach ($methodRoutes as $route) {
                $match = $this->match($route['pattern'], $requestSegments);
                if ($match === null) {
                    continue;
                }
                $pathExists = true;
                if ($routeMethod === $method) {
                    ($route['handler'])($match);
                    return;
                }
            }
        }

        if ($pathExists) {
            Http::error('method_not_allowed', 'HTTP method not allowed for this endpoint.', 405);
            return;
        }

        Http::error('not_found', 'The requested endpoint does not exist.', 404);
    }

    /** @return array<string, string>|null */
    private function match(string $pattern, array $requestSegments): ?array
    {
        $patternSegments = $this->segments($pattern);
        if (count($patternSegments) !== count($requestSegments)) {
            return null;
        }

        $params = [];
        foreach ($patternSegments as $i => $patternSegment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $patternSegment, $m) === 1) {
                $params[$m[1]] = urldecode($requestSegments[$i]);
                continue;
            }
            if ($patternSegment !== $requestSegments[$i]) {
                return null;
            }
        }

        return $params;
    }

    /** @return string[] */
    private function segments(string $path): array
    {
        $trimmed = trim($path, '/');
        return $trimmed === '' ? [] : explode('/', $trimmed);
    }
}
