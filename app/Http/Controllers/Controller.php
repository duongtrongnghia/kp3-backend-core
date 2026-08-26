<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Return the currently authenticated User (non-null).
     * All routes using this are behind auth:sanctum, so null never occurs at runtime.
     * The abort(401) acts as a runtime guard and satisfies PHPStan's null-safety check.
     */
    protected function currentUser(Request $request): User
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return $user;
    }
}
