<?php

namespace ContextualAggregateProperties;

use App\User;

use function PHPStan\Testing\assertType;

function fromQueryBuilder(): void
{
    $counts = User::query()->withCount(['posts as published_posts_count'])->firstOrFail();

    assertType('int<0, max>', $counts->published_posts_count);

    $exists = User::query()->withExists('posts')->firstOrFail();

    assertType('bool', $exists->posts_exists);

    $sum = User::query()->withSum('posts', 'id')->firstOrFail();

    assertType('float|int|string|null', $sum->posts_sum_id);
}
