<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $table = 'categories';
    protected $primaryKey = 'idCategory';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'type',
        'idUser',
        'createdAt',
    ];

    protected $casts = [
        'createdAt' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'idUser', 'idUser');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'idCategory', 'idCategory');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class, 'idCategory', 'idCategory');
    }
}
