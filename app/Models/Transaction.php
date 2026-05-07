<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $table = 'transactions';
    protected $primaryKey = 'idTransaction';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'idUser',
        'idCategory',
        'idResource',
        'type',
        'amount',
        'description',
        'date',
        'source',
        'receiptImagePath',
    ];

    protected $hidden = [
        'idTransaction',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'datetime',
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

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'idResource', 'idResource');
    }
}
