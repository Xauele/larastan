<?php

declare(strict_types=1);

namespace Tests\Type;

use Illuminate\Database\Eloquent\Model;

final class DynamicCastPrecedenceModel extends Model
{
    /** @var array<string, string> */
    protected $casts = ['integer' => 'bool'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['integer' => $this->selectCast()];
    }

    private function selectCast(): string
    {
        return 'string';
    }
}
