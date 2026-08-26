<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED = ['en', 'vi'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = config('app.locale', 'vi');

        // 1. Authenticated user preference takes priority.
        if ($user = $request->user()) {
            if ($user->language && in_array($user->language, self::SUPPORTED, true)) {
                $locale = $user->language;
                App::setLocale($locale);

                return $next($request);
            }
        }

        // 2. Accept-Language header fallback (first 2 chars).
        $header = $request->header('Accept-Language');
        if ($header) {
            $parsed = substr((string) $header, 0, 2);
            if (in_array($parsed, self::SUPPORTED, true)) {
                $locale = $parsed;
            }
        }

        App::setLocale($locale);

        return $next($request);
    }
}
