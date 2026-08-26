<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Skip HSTS on local to avoid browser caching issues during development.
        if (config('app.env') !== 'local') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $csp = "default-src 'self'; ";
        $csp .= "script-src 'self' 'unsafe-inline' 'unsafe-eval'; ";
        $csp .= "style-src 'self' 'unsafe-inline'; ";
        $csp .= "img-src 'self' data: https:; ";
        $csp .= "font-src 'self' data: https:; ";
        $csp .= config('app.env') === 'local'
            ? "connect-src 'self' http://localhost:8000 ws://localhost:5173 http://localhost:5173;"
            : "connect-src 'self' ".config('app.url').';';

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
