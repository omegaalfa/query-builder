## Purpose

This file gives concise, actionable guidance for AI coding agents (Copilot-style) to be immediately productive in the Wrouter codebase. Only include patterns and behaviours discoverable in the repository.

## Quick facts

- Language: PHP (strict_types=1). Composer project in `composer.json` (requires PHP >=8.4).
- Key dependencies: `laminas/laminas-diactoros`, `psr/http-message`, PSR-15 middleware interfaces.
- Tests: `composer test` runs `phpunit` (see `phpunit.xml` and `tests/RouterTest.php`).
- Static analysis: `composer phpstan` runs phpstan against `src` and `tests`.

## Big picture architecture (what to know fast)

- Router core: routing is a trie-based implementation located in `src/Router/` (notably `TreeRouter.php`, `TreeNode.php`, `Router.php`, `Wrouter.php`, `Dispatcher.php`).
- Request lifecycle: request is created/normalized in `Router::__construct()` via `ParsedBody` (see `src/Support/ParsedBody.php`) which parses JSON, form-urlencoded, XML and multipart before routes are matched.
- Dispatching: `Router::findRouteNoCached()` wires middlewares (PSR-15) through `MiddlewareDispatcher` and then invokes `Dispatcher` to call the handler. `Wrouter::dispatcher()` formats non-200 responses into JSON.
- Emission: HTTP emission uses `src/Http/Emitter.php` (uses PHP SAPI header() + echo) — handlers must return `Psr\Http\Message\ResponseInterface` to be emitted.

## Important conventions & gotchas (project-specific)

- Route registration is evaluated against the current request: `Router::map()` only adds a route if the router's current request method matches the method being registered. Tests set the request before calling `$router->get(...)` (see `tests/RouterTest.php`). When writing tests or registering routes programmatically, ensure `Router::setRequest()` or pass the ServerRequest into `new Wrouter($response, $request)` first.
- Handlers must be PHP Closures that accept the request and response and return a `ResponseInterface`. If a handler doesn't return a `ResponseInterface` a `RuntimeException` is thrown by `Dispatcher`.
- Middlewares must implement PSR-15 `MiddlewareInterface`. Middleware are applied by `Router::handlerMiddleware()` which wraps the route execution using `MiddlewareDispatcher`.
- Parsed body behavior: `ParsedBody::process()` runs in the Router constructor and only for non-GET methods. Supported types: `application/json`, `application/x-www-form-urlencoded`, `application/xml`, `text/xml`, `multipart/form-data`. See `src/Support/ParsedBody.php` for exact behavior and error handling.
- Route cache: there is a cache helper `src/Router/RouterCache.php` with a default path `src/Router/cache/cache_routes.php`—but cache generation details are minimal in the repo; treat it as optional.

- Route cache (implemented): `src/Router/RouterCache.php` now supports:
	- Persisting handler references as metadata (file + start line + end line) so another process can reconstruct the Closure by reading the original source.
	- Persisting middleware references as class-strings or callable strings (middleware objects are not serialized); on load the cache attempts to instantiate middleware classes with a zero-argument constructor or call a callable string to obtain an instance.
	- In-memory runtime routes for the current process (`RouterCache::getRuntimeRoutes()`) so `generateRoutes()` + immediate `Wrouter` usage (common in tests) works even if the cache file is not yet readable by another process.

Notes and gotchas for agents:
- When adding or modifying route cache behavior, prefer storing handler references (`file,start,end`) rather than raw closure source; the repo follows this approach already (see `RouterCache::generateRoutes`).
- Rehydration uses `eval()` to reconstruct closures from source slices; only accept caches from trusted sources. If you change this behavior, update tests and the README examples.
- Middlewares are persisted as class-strings where possible. If the middleware requires constructor arguments a resolver/factory approach is needed; document or implement a DI resolver when adding such middleware.

Example usage (tested in repository):

```php
// generate cache (one-time or as part of a build step)
$cache = new \Omegaalfa\Wrouter\Router\RouterCache();
$cache->generateRoutes('/cached', 'GET', function($req, $res) {
		$res->getBody()->write('cached');
		return $res->withStatus(200);
});

// at runtime Wrouter will load runtime routes and persisted cache automatically
$router = new \Omegaalfa\Wrouter\Router\Wrouter();
```

If you want me to extend the cache to support factories or integrate a PSR-11 container for middleware rehydration, say which option you prefer and I will implement it.

## Where to look for examples

- Minimal app example: `public/index.php` — shows creating `Wrouter`, registering routes and calling `$router->dispatcher($path)` then `$router->emitResponse($response)`.
- Unit tests: `tests/RouterTest.php` — good canonical examples for route registration, middleware chaining, groups and assertions you should preserve when changing behavior.

## Guidance for code changes (short checklist)

1. When adding or modifying route registration or dispatching, update `tests/RouterTest.php` to set a `ServerRequest` with the expected method before registering routes.
2. Ensure handlers return `ResponseInterface`. Update or add tests that assert response body and status codes (see existing assertions).
3. If you add new content-type parsing, update `ParsedBody::supportedContentTypes()` and include tests for request parsing.
4. Keep changes small and add phpstan level-appropriate annotations; run `composer phpstan` and `composer test` locally.

## Useful grep targets for automation

- Route handling: `src/Router/*.php`
- Middleware dispatch: `src/Middleware/*`
- Request parsing: `src/Support/ParsedBody.php`
- Emission: `src/Http/Emitter.php`

## If you modify public API

- Update README.md examples and `composer.json` if minimum PHP version or dependencies change.
- Keep PSR-7/PSR-15 compatibility intact.

---

If anything here is unclear or you'd like more examples (for instance, explicit snippets for adding parameterized routes or creating route cache files), tell me which area to expand and I will iterate.
