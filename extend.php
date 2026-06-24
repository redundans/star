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

return [
    // Frontend files.
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    // Load language.
    new Extend\Locales(__DIR__.'/resources/locale'),

    // Register PostResource.
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
                        // Hämta den inloggade användaren direkt från Flarums API Context
                        $actor = $context->getActor(); //

                        // Returnerar true eller false baserat på inställningen i Adminpanelen
                        return $actor && $actor->hasPermission('redundans-star.star_posts');
                    }),
            ];
        }),


    // Registrera filtret med klassnamnet som en sträng för Flarum 2.0
    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addFilter(PostSearcher::class, StarredFilter::class),

    // Add listener.
    (new Extend\Event())
        ->listen(Saving::class, function (Saving $event) {
            $post = $event->post;
            $data = $event->data;

            if (isset($data['attributes']['isStarred'])) {
                $event->actor->assertCan('redundans-star.star_posts');
                $post->is_starred = (bool) $data['attributes']['isStarred'];
            }
        }),
];
