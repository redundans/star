<?php

use Illuminate\Database\Schema\Blueprint;
use Flarum\Database\Migration;

return Migration::addColumns('posts', [
    'is_synced_to_social' => [
        'boolean',
        'default' => false,
        'comment' => 'Markerar om det stjärnmärkta inlägget redan har skickats till Mastodon/Bluesky'
    ]
]);
