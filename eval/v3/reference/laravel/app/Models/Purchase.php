<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'purchase';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class)->orderBy('id');
    }
}
