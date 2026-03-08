<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundingSource extends Model
{
    protected $table = 'funding_sources';
    protected $primaryKey = 'idFundingSource';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'idUser',
        'name',
        'initialBalance',
    ];

    protected $casts = [
        'initialBalance' => 'decimal:2',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'idUser', 'idUser');
    }
}
