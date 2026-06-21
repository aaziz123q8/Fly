<?php

declare(strict_types=1);

namespace App\Core;

class Router
{
    /** @var array<string, array{pattern: string, params: list<string>, handler: callable, middleware: list<callable>}> */
    private array $routes = [];

    private string $groupPrefix = '';

    /** @var list<callable> */
    private array $groupMiddleware = [];

    /** @var callable|null */
    private $notFoundHandler = null;

    /** @var callable|null */
    private $methodNotAllowedHandler = null;

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    public function get(string $path, callable $handler, array $middleware = []): static
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): static
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable $handler, array $middleware = []): static
    {
        return $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, callable $handler, array $middleware = []): static
    {
        return $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    public function patch(string $path, callable $handler, array $middleware = []): static
    {
        return $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    /**
     * Group routes under a shared prefix and optional middleware.
     *
     * @param callable $callback fn(Router $router): void
     */
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousPrefix     = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix     = $previousPrefix . '/' . trim($prefix, '/');
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix     = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    // -------------------------------------------------------------------------
    // Error handlers
    // -------------------------------------------------------------------------

    public function setNotFound(callable $handler): void
    {
        $this->notFoundHandler = $handler;
    }

    public function setMethodNotAllowed(callable $handler): void
    {
        $this->methodNotAllowedHandler = $handler;
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $uri    = $request->uri();

        // Collect all methods that match the path (for 405 detection).
        $allowedMethods = [];

        foreach ($this->routes as $key => $route) {
            if (!preg_match($route['pattern'], $uri, $matches)) {
                continue;
            }

            // Path matches — extract named parameters.
            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = $matches[$name] ?? '';
            }

            $routeMethod = explode(' ', $key, 2)[0];

            if ($routeMethod !== $method) {
                $allowedMethods[] = $routeMethod;
                continue;
            }

            // Inject named params into request.
            $request->setRouteParams($params);

            // Run middleware stack, then handler.
            $handler    = $route['handler'];
            $middleware = $route['middleware'];

            $this->runMiddlewareStack($middleware, $request, $handler);
            return;
        }

        if (!empty($allowedMethods)) {
            if ($this->methodNotAllowedHandler !== null) {
                ($this->methodNotAllowedHandler)($request, $allowedMethods);
            } else {
                http_response_code(405);
                header('Allow: ' . implode(', ', array_unique($allowedMethods)));
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['error' => 'method_not_allowed', 'allowed' => array_unique($allowedMethods)]);
            }
            return;
        }

        if ($this->notFoundHandler !== null) {
            ($this->notFoundHandler)($request);
        } else {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'not_found', 'path' => $uri]);
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function addRoute(string $method, string $path, callable $handler, array $middleware): static
    {
        $fullPath = $this->groupPrefix . '/' . ltrim($path, '/');
        $fullPath = '/' . trim($fullPath, '/') ?: '/';

        // Normalise double slashes that can arise from grouping.
        $fullPath = preg_replace('#/+#', '/', $fullPath);

        [$pattern, $params] = $this->compilePath($fullPath);

        $key = $method . ' ' . $fullPath;

        $this->routes[$key] = [
            'pattern'    => $pattern,
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];

        return $this;
    }

    /**
     * Convert a path like /users/:id/posts/:slug into a regex and param list.
     *
     * @return array{string, list<string>}
     */
    private function compilePath(string $path): array
    {
        $params  = [];
        $pattern = preg_replace_callback('/:([a-zA-Z_][a-zA-Z0-9_]*)/', function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $path);

        $pattern = '#^' . $pattern . '$#u';

        return [$pattern, $params];
    }

    /**
     * Run middleware array then final handler.
     * Each middleware receives ($request, $next) where $next is a callable.
     */
    private function runMiddlewareStack(array $middleware, Request $request, callable $handler): void
    {
        $stack = array_reverse($middleware);

        $next = function (Request $req) use ($handler): void {
            $handler($req);
        };

        foreach ($stack as $mw) {
            $currentNext = $next;
            $next = function (Request $req) use ($mw, $currentNext): void {
                $mw($req, $currentNext);
            };
        }

        $next($request);
    }
}
