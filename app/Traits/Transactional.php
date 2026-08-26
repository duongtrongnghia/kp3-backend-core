<?php

declare(strict_types=1);

namespace App\Traits;

use Closure;
use Illuminate\Support\Facades\DB;

trait Transactional
{
    /**
     * Execute a callback within a database transaction.
     * On success: returns result. On failure: rolls back and re-throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function transactional(Closure $callback): mixed
    {
        return DB::transaction($callback);
    }
}
