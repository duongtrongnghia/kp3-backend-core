<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;

class ValidateCsrfTokenForSanctum extends Middleware
{
    /**
     * URIs excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [];
}
