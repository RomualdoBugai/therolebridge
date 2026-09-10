<?php

declare(strict_types=1);

class Router
{
    private array $routes = [];
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function get(string $path, array $handler, ?string $ability = null, bool $auth = true): void
    {
        $this->addRoute('GET', $path, $handler, $ability, $auth);
    }

    public function post(string $path, array $handler, ?string $ability = null, bool $auth = true): void
    {
        $this->addRoute('POST', $path, $handler, $ability, $auth);
    }

    public function put(string $path, array $handler, ?string $ability = null, bool $auth = true): void
    {
        $this->addRoute('PUT', $path, $handler, $ability, $auth);
    }

    public function delete(string $path, array $handler, ?string $ability = null, bool $auth = true): void
    {
        $this->addRoute('DELETE', $path, $handler, $ability, $auth);
    }

    private function addRoute(string $method, string $path, array $handler, ?string $ability, bool $auth): void
    {
        $this->routes[] = [
            'method'  => $method,
            'path'    => $path,
            'handler' => $handler,
            'ability' => $ability,
            'auth'    => $auth,
        ];
    }

    public function dispatch(): void
    {
        $method = $this->request->method();
        $path   = $this->request->path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $route['path']) . '$#';

            if (!preg_match($pattern, $path, $matches)) {
                continue;
            }

            if ($route['auth']) {
                $token = $this->authenticate();

                if ($route['ability'] !== null
                    && !TokenGuard::hasAbility($token, $route['ability'])
                ) {
                    Response::forbidden('Token lacks required ability: ' . $route['ability']);
                }
            }

            // Only named capture groups as route params
            $routeParams = array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);

            [$class, $action] = $route['handler'];
            (new $class())->$action($this->request, $routeParams);
            exit;
        }

        // Check if path exists with different method
        foreach ($this->routes as $route) {
            $pattern = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $route['path']) . '$#';
            if (preg_match($pattern, $path)) {
                Response::methodNotAllowed();
            }
        }

        Response::notFound();
    }

    private function authenticate(): array
    {
        $plainToken = $this->request->bearerToken();

        if ($plainToken === null) {
            Response::unauthorized('Bearer token required');
        }

        $token = TokenGuard::resolve($plainToken);

        if ($token === null) {
            Response::unauthorized('Invalid or expired token');
        }

        return $token;
    }
}
