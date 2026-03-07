<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AuthToken extends Model
{
    protected $table = 'auth_tokens';
    protected $primaryKey = 'idToken';
    public $timestamps = false;

    protected $fillable = [
        'idUser',
        'tokenHash',
        'type',
        'expiresAt',
        'revokedAt',
        'createdAt',
    ];

    protected $casts = [
        'expiresAt' => 'datetime',
        'revokedAt' => 'datetime',
        'createdAt' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'idUser', 'idUser');
    }

    public static function issue(User $user, string $type, \DateTimeInterface $expiresAt): array
    {
        $plain = Str::random(80);

        self::create([
            'idUser' => $user->idUser,
            'tokenHash' => hash('sha256', $plain),
            'type' => $type,
            'expiresAt' => $expiresAt,
        ]);

        return [
            'plain' => $plain,
            'expiresAt' => $expiresAt,
        ];
    }
}
