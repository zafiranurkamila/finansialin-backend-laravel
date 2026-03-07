<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailVerificationToken extends Model
{
    protected $table = 'email_verification_tokens';
    protected $primaryKey = 'idVerificationToken';
    public $timestamps = false;

    protected $fillable = [
        'idUser',
        'tokenHash',
        'expiresAt',
        'verifiedAt',
        'createdAt',
    ];

    protected $casts = [
        'expiresAt' => 'datetime',
        'verifiedAt' => 'datetime',
        'createdAt' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'idUser', 'idUser');
    }
}
