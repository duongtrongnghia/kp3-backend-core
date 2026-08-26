<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Return null so Laravel throws AuthenticationException (→ JSON 401)
     * rather than trying to redirect to a 'login' named route that doesn't exist.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
