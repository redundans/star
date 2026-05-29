<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (!$schema->hasColumn('posts', 'is_starred')) {
            $schema->table('posts', function (Blueprint $table) {
                $table->boolean('is_starred')->default(false);
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->table('posts', function (Blueprint $table) {
            $table->dropColumn('is_starred');
        });
    },
];
