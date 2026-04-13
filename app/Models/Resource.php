<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    protected $table = 'resources';
    protected $primaryKey = 'idResource';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'idUser',
        'source',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    /**
     * Resource belongs to User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'idUser', 'idUser');
    }

    /**
     * Resource has many transactions
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'idResource', 'idResource');
    }

    /**
     * Scope to filter by source (wallet type)
     */
    public function scopeBySource($query, $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Scope to filter resources by user
     */
    public function scopeForUser($query, $idUser)
    {
        return $query->where('idUser', $idUser);
    }

    /**
     * Generate source name for wallet
     * Example: mbanking, emoney, cash
     */
    public static function generateSource($walletType)
    {
        return strtolower($walletType);
    }
}
