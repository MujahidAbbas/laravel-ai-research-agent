<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchJudgement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'relevant' => 'float',
            'has_evidence' => 'float',
            'injection' => 'float',
            'label' => 'boolean',
        ];
    }
}
