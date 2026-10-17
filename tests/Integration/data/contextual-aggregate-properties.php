<?php

namespace ContextualAggregatePropertiesIntegration;

use App\User;

function noPhantomAggregateProperties(): void
{
    (new User())->posts_exists;

    $reset = User::query()->withExists('posts')->select('id')->firstOrFail();
    $reset->posts_exists;

    $storedQuery = User::query()->withExists('posts');
    $storedQuery->firstOrFail()->posts_exists;

    $unselected = User::query()->withExists('posts')->make();
    $unselected->posts_exists;

    // The corresponding inlined, selected expression is properly known.
    $selected = User::query()->withExists('posts')->firstOrFail();
    $selected->posts_exists;
}
