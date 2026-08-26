<?php

declare(strict_types=1);

namespace App\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Typo-safe hooks: forbid passing a raw string literal as the hook name to
 * do_action()/add_action()/apply_filters()/add_filter().
 *
 * A mistyped string hook fails silently (the listener never fires). Requiring a
 * class constant (e.g. ExampleHooks::CREATED) makes typos a compile-time error
 * and gives a single source of truth for hook names.
 *
 * Dynamic names (interpolated "{$alias}.{$event}", variables, constant()) are
 * allowed — only plain string literals are flagged.
 *
 * @implements Rule<FuncCall>
 */
class HookConstantRule implements Rule
{
    private const HOOK_FUNCTIONS = ['do_action', 'add_action', 'apply_filters', 'add_filter'];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Name) {
            return [];
        }

        $function = $node->name->toString();
        if (! in_array($function, self::HOOK_FUNCTIONS, true)) {
            return [];
        }

        $args = $node->getArgs();
        if ($args === []) {
            return [];
        }

        if ($args[0]->value instanceof String_) {
            return [
                RuleErrorBuilder::message(sprintf(
                    'Hook name passed to %s() must be a HookConstants class constant, not the string literal "%s". '
                    .'String hooks fail silently on typos — declare it in a HookConstants class.',
                    $function,
                    $args[0]->value->value,
                ))
                    ->identifier('hooks.literalName')
                    ->build(),
            ];
        }

        return [];
    }
}
