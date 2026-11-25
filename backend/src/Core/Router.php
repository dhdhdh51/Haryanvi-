<?php

declare(strict_types=1);

namespace Haryanvi\Core;

use Haryanvi\Http\Middleware\Middleware;

/**
 * Pattern router with per-route middleware.
 *
 * Patterns use `{name}` placeholders, matched against a single path segment:
 *     /api/v1/history/{id}
 */
final class Router
{
    /**
     * @var list<array{
     *   method: string,
     *   regex: string,
     *   keys: list<string>,
     *   pattern: string,
     *   handler: callable|array{0:class-string,1:string},
     *   middleware: list<class-string<Middleware>>
     * }>
     */
    private array $routes = [];

    /** @var list<class-string<Middleware>> */
    private array $globalMiddleware = [];

    /** @param class-string<Middleware> $middleware */
    public function addGlobalMiddleware(string $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    /**
     * @param callable|array{0:class-string,1:string} $handler
     * @param list<class-string<Middleware>>          $middleware
     */
    public function get(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{0:class-string,1:string} $handler
     * @param list<class-string<Middleware>>          $middleware
     */
    public function post(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{0:class-string,1:string} $handler
     * @param list<class-string<Middleware>>          $middleware
     */
    public function patch(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{0:class-string,1:string} $handler
     * @param list<class-string<Middleware>>          $middleware
     */
    public function put(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{0:class-string,1:string} $handler
     * @param list<class-string<Middleware>>          $middleware
     */
    public function delete(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{0:class-string,1:string} $handler
     * @param list<class-string<Middleware>>          $middleware
     */
    public function add(string $method, string $pattern, callable|array $handler, array $middleware = []): void
    {
        $keys  = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$keys): string {
                $keys[] = $m[1];

                return '([^/]+)';
            },
            $pattern
        ) ?? $pattern;

        $this->routes[] = [
            'method'     => strtoupper($method),
            'regex'      => '#^' . $regex . '$#',
            'keys'       => $keys,
            'pattern'    => $pattern,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * Match and execute. Controllers terminate the request by calling
     * Response::success()/error(), so this never returns normally.
     */
    public function dispatch(Request $request): never
    {
        $path   = $request->path();
        $method = $request->method();

        // HEAD is served by the GET handler; the SAPI discards the body.
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;

        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;

            if ($route['method'] !== $lookupMethod) {
                continue;
            }

            array_shift($matches);

            /** @var array<string,string> $params */
            $params = [];
            foreach ($route['keys'] as $index => $key) {
                $params[$key] = rawurldecode($matches[$index] ?? '');
            }

            $context = new RouteContext($params, $route['method'] . ' ' . $route['pattern']);

            foreach ([...$this->globalMiddleware, ...$route['middleware']] as $middlewareClass) {
                $this->runMiddleware($middlewareClass, $request, $context);
            }

            $this->invoke($route['handler'], $request, $context);
        }

        if ($pathMatched) {
            // Run global middleware so CORS/OPTIONS still behaves correctly.
            if ($method === 'OPTIONS') {
                foreach ($this->globalMiddleware as $middlewareClass) {
                    $this->runMiddleware($middlewareClass, $request, new RouteContext());
                }
            }

            throw ApiException::methodNotAllowed(
                sprintf('%s is not supported on %s.', $method, $path)
            );
        }

        throw ApiException::notFound(sprintf('No API endpoint matches %s %s.', $method, $path));
    }

    /** @param class-string<Middleware> $middlewareClass */
    private function runMiddleware(string $middlewareClass, Request $request, RouteContext $context): void
    {
        if (!class_exists($middlewareClass)) {
            Logger::error('Middleware class missing', ['class' => $middlewareClass]);

            throw ApiException::server();
        }

        /** @var Middleware $middleware */
        $middleware = new $middlewareClass();
        $middleware->handle($request, $context);
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    private function invoke(callable|array $handler, Request $request, RouteContext $context): never
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;

            if (!class_exists($class)) {
                Logger::error('Controller class missing', ['class' => $class]);

                throw ApiException::server();
            }

            $controller = new $class();

            if (!method_exists($controller, $method)) {
                Logger::error('Controller action missing', ['class' => $class, 'action' => $method]);

                throw ApiException::server();
            }

            $controller->{$method}($request, $context);
        } else {
            $handler($request, $context);
        }

        // A controller that forgets to emit a response would otherwise hang
        // the client with an empty 200.
        Logger::error('Controller returned without emitting a response', [
            'route' => $context->routeName(),
        ]);

        throw ApiException::server();
    }
}
