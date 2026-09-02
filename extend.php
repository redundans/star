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
use Flarum\Post\Filter\PostSearcher;
use Flarum\Search\Database\DatabaseSearchDriver;
use redundans\Star\Filter\StarredFilter;
use redundans\Star\Listener\PublishStarredPost;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\ApiResource(PostResource::class))
        ->fields(function () {
            return [
                Boolean::make('isStarred')
                    ->get(function ($post) {
                        return (bool) $post->is_starred;
                    })
                    ->writable(),
                Boolean::make('canStar')
                    ->get(function ($post, \Flarum\Api\Context $context) {
                        $actor = $context->getActor();
                        return $actor && $actor->hasPermission('redundans-star.star_posts');
                    }),
            ];
        }),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addFilter(PostSearcher::class, StarredFilter::class),

    (new Extend\Event())
        ->listen(Saving::class, function (Saving $event) {
            $post = $event->post;
            $data = $event->data;

            if (isset($data['attributes']['isStarred'])) {
                $event->actor->assertCan('redundans-star.star_posts');
                $post->is_starred = (bool) $data['attributes']['isStarred'];
            }

            if ($post->is_starred && !$post->is_synced_to_social) {
                resolve(PublishStarredPost::class)->handle($event);
            }
        }),
];
