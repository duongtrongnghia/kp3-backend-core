<?php

declare(strict_types=1);

namespace App\Core\Hooks;

use Illuminate\Support\Facades\Log;
use Throwable;

class HookManager
{
    /**
     * Hook types:
     * - 'sync'  : Runs immediately (inside transaction). DB ops ONLY — no external I/O.
     * - 'async' : Deferred until after transaction commits. Safe for email, API calls, file I/O.
     */
    public const TYPE_SYNC = 'sync';

    public const TYPE_ASYNC = 'async';

    /** @var array<string, array<int, array{callback: callable, priority: int, type: string}>> */
    protected static array $actions = [];

    /** @var array<string, array<int, array{callback: callable, priority: int}>> */
    protected static array $filters = [];

    /** @var array<int, array{callback: callable, args: mixed[]}> */
    protected static array $deferredActions = [];

    /** @var array<int, callable> */
    protected static array $rollbackCallbacks = [];

    protected static bool $debug = false;

    /** @var array<string, bool> */
    protected static array $dirtyActions = [];

    /** @var array<string, bool> */
    protected static array $dirtyFilters = [];

    /**
     * Register an action callback for a given hook.
     *
     * @param  string  $hook  Hook name
     * @param  callable|string  $callback  Callback or 'Class@method' for lazy resolution
     * @param  int  $priority  Lower = earlier execution
     * @param  string  $type  'sync' (default, in-transaction DB ops) or 'async' (deferred, post-commit)
     */
    public static function addAction(string $hook, callable|string $callback, int $priority = 10, string $type = self::TYPE_SYNC): void
    {
        self::$actions[$hook][] = [
            'callback' => self::resolveCallback($callback),
            'priority' => $priority,
            'type' => $type,
        ];

        self::$dirtyActions[$hook] = true;
    }

    /**
     * Execute all callbacks registered for the given action hook.
     *
     * - 'sync' callbacks run immediately (caller wraps in transaction).
     * - 'async' callbacks are deferred and must be flushed via flushDeferred().
     */
    public static function doAction(string $hook, mixed ...$args): void
    {
        if (! isset(self::$actions[$hook])) {
            return;
        }

        self::sortActionsIfDirty($hook);

        foreach (self::$actions[$hook] as $entry) {
            if ($entry['type'] === self::TYPE_ASYNC) {
                self::$deferredActions[] = ['callback' => $entry['callback'], 'args' => $args];

                continue;
            }

            if (self::$debug) {
                $start = microtime(true);
                call_user_func_array($entry['callback'], $args);
                $elapsed = round((microtime(true) - $start) * 1000, 2);
                Log::debug("Hook action [{$hook}] executed in {$elapsed}ms");
            } else {
                call_user_func_array($entry['callback'], $args);
            }
        }
    }

    /**
     * Register a callback to run if transaction rolls back.
     * Use for cleanup: delete uploaded files, revert external state, etc.
     *
     * Usage (inside transactional code):
     *   HookManager::onRollback(fn() => Storage::delete($uploadedPath));
     */
    public static function onRollback(callable $callback): void
    {
        self::$rollbackCallbacks[] = $callback;
    }

    /**
     * Execute all deferred (async) callbacks and clear the queue.
     * Call this AFTER transaction commits successfully.
     */
    public static function flushDeferred(): void
    {
        // Clear rollback callbacks — transaction succeeded, no cleanup needed
        self::$rollbackCallbacks = [];

        $deferred = self::$deferredActions;
        self::$deferredActions = [];

        foreach ($deferred as $entry) {
            call_user_func_array($entry['callback'], $entry['args']);
        }
    }

    /**
     * Execute all rollback callbacks and clear all queues.
     * Call this AFTER transaction fails/rolls back.
     */
    public static function flushRollback(): void
    {
        // Discard deferred actions — transaction failed, don't execute post-commit work
        self::$deferredActions = [];

        $callbacks = self::$rollbackCallbacks;
        self::$rollbackCallbacks = [];

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                // Log but don't re-throw — rollback cleanup should not mask the original error
                Log::warning('Rollback callback failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Check if there are pending deferred actions.
     */
    public static function hasDeferredActions(): bool
    {
        return ! empty(self::$deferredActions);
    }

    /**
     * Register a filter callback for a given hook.
     *
     * @param  callable|string  $callback  Callback or 'Class@method' for lazy resolution
     */
    public static function addFilter(string $hook, callable|string $callback, int $priority = 10): void
    {
        self::$filters[$hook][] = [
            'callback' => self::resolveCallback($callback),
            'priority' => $priority,
        ];

        self::$dirtyFilters[$hook] = true;
    }

    /**
     * Apply all registered filter callbacks to a value.
     */
    public static function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (! isset(self::$filters[$hook])) {
            return $value;
        }

        self::sortFiltersIfDirty($hook);

        foreach (self::$filters[$hook] as $entry) {
            if (self::$debug) {
                $start = microtime(true);
                $value = call_user_func_array($entry['callback'], [$value, ...$args]);
                $elapsed = round((microtime(true) - $start) * 1000, 2);
                Log::debug("Hook filter [{$hook}] executed in {$elapsed}ms");
            } else {
                $value = call_user_func_array($entry['callback'], [$value, ...$args]);
            }
        }

        return $value;
    }

    /**
     * Check if any actions are registered for the given hook.
     */
    public static function hasAction(string $hook): bool
    {
        return ! empty(self::$actions[$hook]);
    }

    /**
     * Check if any filters are registered for the given hook.
     */
    public static function hasFilter(string $hook): bool
    {
        return ! empty(self::$filters[$hook]);
    }

    /**
     * Remove a specific action callback from a hook.
     */
    public static function removeAction(string $hook, callable $callback): void
    {
        if (! isset(self::$actions[$hook])) {
            return;
        }

        self::$actions[$hook] = array_values(
            array_filter(self::$actions[$hook], fn ($entry) => $entry['callback'] !== $callback)
        );
    }

    /**
     * Remove a specific filter callback from a hook.
     */
    public static function removeFilter(string $hook, callable $callback): void
    {
        if (! isset(self::$filters[$hook])) {
            return;
        }

        self::$filters[$hook] = array_values(
            array_filter(self::$filters[$hook], fn ($entry) => $entry['callback'] !== $callback)
        );
    }

    /**
     * Get all registered actions (for artisan hook:list).
     *
     * @return array<string, array<int, array{callback: callable, priority: int, type: string}>>
     */
    public static function getActions(): array
    {
        return self::$actions;
    }

    /**
     * Get all registered filters (for artisan hook:list).
     *
     * @return array<string, array<int, array{callback: callable, priority: int}>>
     */
    public static function getFilters(): array
    {
        return self::$filters;
    }

    /**
     * Enable debug mode — logs all hook calls with timing.
     */
    public static function enableDebug(): void
    {
        self::$debug = true;
    }

    /**
     * Disable debug mode.
     */
    public static function disableDebug(): void
    {
        self::$debug = false;
    }

    /**
     * Reset all hooks (useful for testing).
     */
    public static function reset(): void
    {
        self::$actions = [];
        self::$filters = [];
        self::$deferredActions = [];
        self::$rollbackCallbacks = [];
        self::$dirtyActions = [];
        self::$dirtyFilters = [];
        self::$debug = false;
    }

    protected static function sortActionsIfDirty(string $hook): void
    {
        if (! empty(self::$dirtyActions[$hook])) {
            usort(self::$actions[$hook], fn ($a, $b) => $a['priority'] <=> $b['priority']);
            unset(self::$dirtyActions[$hook]);
        }
    }

    protected static function sortFiltersIfDirty(string $hook): void
    {
        if (! empty(self::$dirtyFilters[$hook])) {
            usort(self::$filters[$hook], fn ($a, $b) => $a['priority'] <=> $b['priority']);
            unset(self::$dirtyFilters[$hook]);
        }
    }

    /**
     * Resolve callback — supports:
     *   callable (as-is)
     *   'Class@method' string (lazy — resolved only when hook fires)
     */
    protected static function resolveCallback(callable|string $callback): callable
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            return function () use ($callback): mixed {
                [$class, $method] = explode('@', $callback, 2);

                return app($class)->$method(...func_get_args());
            };
        }

        // At this point $callback is callable (not a 'Class@method' string)
        if (is_callable($callback)) {
            return $callback;
        }

        // Unreachable in practice — type system guarantees callable|string input,
        // and the string branch is handled above. Satisfy PHPStan's return analysis.
        return static fn () => null;
    }
}
