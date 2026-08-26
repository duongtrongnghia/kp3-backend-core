<?php

declare(strict_types=1);

namespace App\Core\Hooks;

/**
 * Core lifecycle hook constants (infrastructure-level).
 *
 * Module-specific hooks live in each module's own HookConstants class:
 *   Modules\Post\HookConstants::POST_SAVED
 *   Modules\Comment\HookConstants::COMMENT_STATUS_CHANGED
 */
class HookConstants
{
    // ─── Core Lifecycle Actions ───
    public const CORE_BOOTED = 'core_booted';

    /**
     * @deprecated Use module-specific hooks via getContentHooks() binding pattern (AD-93).
     * e.g., 'post.saved', 'page.saved'. Kept for theme code / third-party use.
     */
    public const CONTENT_SAVED = 'content_saved';

    /**
     * @deprecated Use module-specific hooks via getContentHooks() binding pattern (AD-93).
     * e.g., 'post.before_delete'. Kept for theme code / third-party use.
     */
    public const BEFORE_DELETE_CONTENT = 'before_delete_content';

    /**
     * @deprecated Use module-specific hooks via getContentHooks() binding pattern (AD-93).
     * e.g., 'post.before_bulk_delete'. Kept for theme code / third-party use.
     */
    public const BEFORE_BULK_DELETE_CONTENT = 'before_bulk_delete_content';

    public const USER_PROFILE_UPDATED = 'user.profile_updated';

    // ─── Theme Actions ───
    public const THEME_HEAD = 'theme_head';

    public const THEME_FOOTER = 'theme_footer';

    // ─── Cache ───
    public const CACHE_FLUSHED = 'cache_flushed';

    public const CACHE_INVALIDATED = 'cache_invalidated';

    // ─── Core Filters ───
    public const FILTER_POST_CONTENT = 'filter_post_content';

    // ─── Menu ───
    public const MENU_RESOLVE_SLUG = 'menu.resolve_slug';

    public const MENU_SEARCHABLE_ENTITIES = 'menu.searchable_entities';

    // ─── Builder ───
    public const BUILDER_CAN_ACCESS = 'builder.can_access';
}
