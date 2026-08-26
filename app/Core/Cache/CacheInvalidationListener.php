<?php

declare(strict_types=1);

namespace App\Core\Cache;

use App\Core\Hooks\HookConstants;

/**
 * Generic cache invalidation — no module-specific knowledge.
 *
 * Core hooks (CONTENT_SAVED, USER_PROFILE_UPDATED) registered in CoreServiceProvider.
 * Module-specific hooks (post.saved, comment.status_changed) registered
 * in each module's own ServiceProvider.
 */
class CacheInvalidationListener
{
    /**
     * Entity saved — flush entity cache + related tags + related entities.
     * Modules call this via their own hooks.
     *
     * @param  mixed  $entity  The saved model instance
     * @param  string  $type  Morph alias ('post', 'page', 'product', etc.)
     * @param  string[]  $relatedTags  Additional tags to invalidate (['posts', 'feed', ...])
     * @param  array<int, array{type: string, relation: string}>  $relatedEntities  Related entities
     */
    public static function onEntitySaved(
        mixed $entity,
        string $type,
        array $relatedTags = [],
        array $relatedEntities = [],
    ): void {
        $cache = app(CacheService::class);
        $cache->invalidateEntity($type, $entity->id);

        foreach ($relatedTags as $tag) {
            $cache->invalidateTag($tag);
        }

        // Flush related entities if relations are loaded (N+1 safe)
        foreach ($relatedEntities as $rel) {
            $relationName = $rel['relation'];
            $relType = $rel['type'];
            if ($entity->relationLoaded($relationName)) {
                foreach ($entity->$relationName as $related) {
                    $cache->invalidateEntity($relType, $related->id);
                }
            }
        }

        do_action(HookConstants::CACHE_INVALIDATED, $type, $entity->id);
    }

    /**
     * Related entity changed — flush the parent's cache via morphable relation.
     * E.g., comment status changed → flush parent post/product cache.
     */
    public static function onRelatedEntityChanged(mixed $entity, string $parentRelation = 'commentable'): void
    {
        $parentType = $entity->{$parentRelation.'_type'} ?? null;
        $parent = $entity->$parentRelation ?? null;

        if ($parentType && $parent) {
            $cache = app(CacheService::class);
            $cache->invalidateEntity($parentType, $parent->id);

            do_action(HookConstants::CACHE_INVALIDATED, $parentType, $parent->id);
        }
    }

    /**
     * Generic content saved — flush page cache, feed, sitemap.
     */
    public static function onContentSaved(mixed $content): void
    {
        $cache = app(CacheService::class);
        $cache->invalidateTag('pages');
        $cache->invalidateTag('feed');
        $cache->invalidateTag('sitemap');

        do_action(HookConstants::CACHE_INVALIDATED, 'content', null);
    }

    /**
     * Content deleted — same invalidation as content saved.
     */
    public static function onContentDeleted(mixed $content): void
    {
        self::onContentSaved($content);
    }

    /**
     * User profile updated — flush author bio fragment cache.
     */
    public static function onUserProfileUpdated(mixed $user): void
    {
        $cache = app(CacheService::class);
        $cache->forget('fragment_'.md5('author_bio_'.$user->id));

        do_action(HookConstants::CACHE_INVALIDATED, 'user', $user->id);
    }
}
