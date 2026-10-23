<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Book extends Model
{
    protected $table = 'book';

    public $timestamps = false;

    protected $fillable = ['title', 'price_cents', 'stock'];

    protected $casts = ['price_cents' => 'integer', 'stock' => 'integer', 'published' => 'boolean'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function scopeByAuthorName(Builder $query, string $name): void
    {
        $query->whereHas('author', static fn (Builder $author) => $author->where('name', $name));
    }

    public function scopeInStock(Builder $query, bool $inStock): void
    {
        $query->where($this->qualifyColumn('stock'), $inStock ? '>' : '=', 0);
    }

    public function scopePublished(Builder $query): void
    {
        $query->where($this->qualifyColumn('published'), true);
    }
}
