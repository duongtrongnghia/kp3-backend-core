<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * DEV-ONLY: Simulate API errors for frontend testing.
 * Automatically disabled outside local environment.
 *
 * Usage — edit the private properties below:
 *
 *   $enabled = true   + empty $onlyRoutes/$onlyMethods  → block ALL api routes
 *   $onlyRoutes = ['pages/*']                            → partial path match, any method
 *   $onlyRoutes = ['/api/v1/pages/*']                    → full path match
 *   $onlyRoutes = ['DELETE /api/v1/pages/*']             → specific method + full path
 *   $onlyMethods = ['DELETE']                            → specific method, any route
 *
 * Pattern rules:
 *   Leading '/' → full anchored path match
 *   No '/'      → partial match anywhere in path
 *   *           → single path segment
 *   **          → multiple segments
 */
class SimulateApiError
{
    // ── Configure here ──────────────────────────────────────────────────────
    private bool $enabled = true;

    private int $status = 500;

    private string $message = 'Simulated API error for testing.';

    /** @var list<string> */
    private array $onlyRoutes = ['pages/*'];

    /** @var list<string> */
    private array $onlyMethods = ['DELETE'];
    // ────────────────────────────────────────────────────────────────────────

    public function handle(Request $request, Closure $next): mixed
    {
        if (! app()->isLocal() || ! $this->enabled) {
            return $next($request);
        }

        if (! $this->matchesFilters($request)) {
            return $next($request);
        }

        sleep(1);
        abort($this->status, $this->message);
    }

    private function matchesFilters(Request $request): bool
    {
        $method = strtoupper($request->method());
        $path = '/'.ltrim($request->path(), '/');

        if (! empty($this->onlyMethods)) {
            if (! in_array($method, array_map('strtoupper', $this->onlyMethods), true)) {
                return false;
            }
        }

        if (! empty($this->onlyRoutes)) {
            return $this->matchesRoute($method, $path);
        }

        return true;
    }

    private function matchesRoute(string $method, string $path): bool
    {
        foreach ($this->onlyRoutes as $route) {
            $parts = explode(' ', trim((string) $route), 2);

            if (count($parts) === 1) {
                if ($this->pathMatches($parts[0], $path)) {
                    return true;
                }

                continue;
            }

            [$routeMethod, $routePath] = $parts;
            if (strtoupper($routeMethod) === $method && $this->pathMatches($routePath, $path)) {
                return true;
            }
        }

        return false;
    }

    private function pathMatches(string $pattern, string $path): bool
    {
        $escaped = preg_quote($pattern, '#');
        $escaped = str_replace('\*\*', '.+', $escaped);
        $escaped = str_replace('\*', '[^/]+', $escaped);

        $regex = str_starts_with($pattern, '/')
            ? "#^{$escaped}$#"
            : "#{$escaped}#";

        return (bool) preg_match($regex, $path);
    }
}
