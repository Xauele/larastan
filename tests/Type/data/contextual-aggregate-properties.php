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

function differentAggregateFunctions(): void
{
    $min = User::query()->withMin('posts', 'id')->firstOrFail();
    assertType('float|int|string|null', $min->posts_min_id);

    $max = User::query()->withMax('posts', 'id')->firstOrFail();
    assertType('float|int|string|null', $max->posts_max_id);

    $average = User::query()->withAvg('posts', 'id')->firstOrFail();
    assertType('float|int|string|null', $average->posts_avg_id);
}

function chainedAndAliasedAggregates(): void
{
    $user = User::query()
        ->withCount(['posts as published_posts_count' => static fn ($query) => $query->where('id', '>', 0)])
        ->withExists(['accounts AS has_accounts'])
        ->withSum('posts', 'id')
        ->where('id', '>', 0)
        ->firstOrFail();

    assertType('int<0, max>', $user->published_posts_count);
    assertType('bool', $user->has_accounts);
    assertType('float|int|string|null', $user->posts_sum_id);
}

function customBuilderAndCollection(): void
{
    $post = \App\Post::query()->withExists('comments')->firstOrFail();
    assertType('bool', $post->comments_exists);

    $users = User::query()->withExists('posts')->get();
    assertType('Illuminate\\Database\\Eloquent\\Collection<int, App\\User&object{posts_exists: bool}>', $users);
    assertType('App\\User&object{posts_exists: bool}', $users->firstOrFail());
    assertType('bool', $users->firstOrFail()->posts_exists);
}

/** @param non-empty-string $relation */
function unknownRelationNames(string $relation, string $column): void
{
    assertType('Illuminate\\Database\\Eloquent\\Builder<App\\User>', User::query()->withExists($relation));
    assertType('Illuminate\\Database\\Eloquent\\Builder<App\\User>', User::query()->withSum('posts', $column));
}

// Standard Eloquent model static forwarding should carry the same query annotation.
function forwardedModelStatic(): void
{
    $user = User::withExists('posts')->firstOrFail();

    assertType('bool', $user->posts_exists);
}

function diagnosticShapeSequence(): void
{
    $q1 = User::query()->withCount(['posts as published_posts_count' => static fn ($query) => $query->where('id', '>', 0)]);
    $q2 = $q1->withExists(['accounts AS has_accounts']);
    $q3 = $q2->withSum('posts', 'id');
    $q4 = $q3->where('id', '>', 0);

    assertType('Illuminate\\Database\\Eloquent\\Builder<App\\User>', $q1);
    assertType('Illuminate\\Database\\Eloquent\\Builder<App\\User>', $q2);
    assertType('Illuminate\\Database\\Eloquent\\Builder<App\\User>', $q3);
    assertType('Illuminate\\Database\\Eloquent\\Builder<App\\User>', $q4);
}

function latestAliasWins(): void
{
    $user = User::query()
        ->withCount(['posts as shared_stat'])
        ->withExists(['posts as shared_stat'])
        ->firstOrFail();

    assertType('bool', $user->shared_stat);
}

function projectionReset(): void
{
    // select() wipes the previously selected aggregate expression in SQL.
    $selected = User::query()->withExists('posts')->select('id')->firstOrFail();
    assertType('App\\User', $selected);

    // Selecting first and aggregating afterward retains the extra column.
    $selected = User::query()->select('id')->withExists('posts')->firstOrFail();
    assertType('bool', $selected->posts_exists);
}

function unselectedModelsAreNotAugmented(): void
{
    assertType('App\\User', User::query()->withExists('posts')->make());

    $query = User::query()->withExists('posts');
    // A separately stored builder has no statically visible call chain.
    assertType('App\\User', $query->firstOrFail());
}

function nullableRetrieval(): void
{
    assertType('(App\\User&object{posts_exists: bool})|null', User::query()->withExists('posts')->first());
}
