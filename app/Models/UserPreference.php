<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $table = 'user_preferences';
    protected $primaryKey = 'idPreference';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'idUser',
        'theme',
        'hideBalance',
        'dailyReminder',
        'budgetLimitAlert',
        'weeklySummary',
    ];

    protected $casts = [
        'hideBalance' => 'boolean',
        'dailyReminder' => 'boolean',
        'budgetLimitAlert' => 'boolean',
        'weeklySummary' => 'boolean',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'idUser', 'idUser');
    }
}
