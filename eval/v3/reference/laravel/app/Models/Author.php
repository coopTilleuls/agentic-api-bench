<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Author extends Model
{
    protected $table = 'author';

    public $timestamps = false;

    protected $withCount = ['books'];

    public function books(): HasMany
    {
        return $this->hasMany(Book::class)->where('published', true)->orderBy('id');
    }
}
