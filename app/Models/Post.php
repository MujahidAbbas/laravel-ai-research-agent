<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'slug', 'description', 'tags', 'published_at'];

    protected $casts = ['published_at' => 'date'];
}
