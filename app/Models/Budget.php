<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    protected $table = 'budgets';
    protected $primaryKey = 'idBudget';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'idUser',
        'idCategory',
        'period',
        'periodStart',
        'periodEnd',
        'amount',
    ];

    protected $casts = [
        'periodStart' => 'datetime',
        'periodEnd' => 'datetime',
        'amount' => 'decimal:2',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'idUser', 'idUser');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'idCategory', 'idCategory');
    }
}
