<?php

/*
 * This file is part of redundans/star.
 *
 * Copyright (c) 2026 redundans.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace redundans\Star;

use Flarum\Extend;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Schema\Boolean;
use Flarum\Post\Event\Saving;
use Flarum\Api\Schema\Attribute;
use Flarum\Discussion\Discussion;
use Flarum\Api\Context;
use Illuminate\Database\ConnectionInterface;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Search\Database\DatabaseSearchDriver;
use redundans\Star\Filter\StarredFilter;
use redundans\Star\Listener\PublishStarredPost;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    (new Extend\ApiResource(DiscussionResource::class))
    ->fields(fn () => [
        Attribute::make('canStar')
            ->get(function (Discussion $discussion, Context $context) {
                $db = resolve(ConnectionInterface::class);
                $hasRestrictedTag = $db->table('discussion_tag')
                    ->join('tags', 'discussion_tag.tag_id', '=', 'tags.id')
                    ->where('discussion_tag.discussion_id', $discussion->id)
                    ->where('tags.is_restricted', true)
                    ->exists();

                if ($hasRestrictedTag) {
                    return false;
                }

                $actor = $context->getActor();
                return $actor ? $actor->can('redundans-star.star_posts', $discussion) : false;
            })
    ]),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\ApiResource(PostResource::class))
        ->fields(function () {
            return [
                Boolean::make('isStarred')
                    ->get(function ($post) {
                        return (bool) $post->is_starred;
                    })
                    ->writable(),
            ];
        }),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addFilter(PostSearcher::class, StarredFilter::class),

    (new Extend\Event())
        ->listen(Saving::class, function (Saving $event) {
            $post = $event->post;
            $data = $event->data;

            $discussion = $post->discussion;

            if ($discussion) {
                $tags = $discussion->tags()->get();
                foreach ($tags as $tag) {
                    if ((bool) $tag->is_restricted) {
                        return;
                    }
                }
            }

            if (isset($data['attributes']['isStarred'])) {
                $event->actor->assertCan('redundans-star.star_posts');
                $post->is_starred = (bool) $data['attributes']['isStarred'];
            }

            if ($post->is_starred && !$post->is_synced_to_social) {
                resolve(PublishStarredPost::class)->handle($event);
            }
        }),
];
