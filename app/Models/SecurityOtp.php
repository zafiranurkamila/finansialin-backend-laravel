<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityOtp extends Model
{
    protected $table = 'security_otps';
    protected $primaryKey = 'idOtp';
    public $timestamps = false;

    protected $fillable = [
        'idUser',
        'purpose',
        'codeHash',
        'attempts',
        'expiresAt',
        'consumedAt',
        'createdAt',
    ];

    protected $casts = [
        'expiresAt' => 'datetime',
        'consumedAt' => 'datetime',
        'createdAt' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'idUser', 'idUser');
    }

    public static function issue(User $user, string $purpose, int $ttlMinutes = 10): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        self::query()
            ->where('idUser', $user->idUser)
            ->where('purpose', $purpose)
            ->whereNull('consumedAt')
            ->update(['consumedAt' => now()]);

        $record = self::query()->create([
            'idUser' => $user->idUser,
            'purpose' => $purpose,
            'codeHash' => hash('sha256', $code),
            'attempts' => 0,
            'expiresAt' => now()->addMinutes($ttlMinutes),
        ]);

        return [
            'code' => $code,
            'expiresAt' => $record->expiresAt,
        ];
    }

    public static function consume(User $user, string $purpose, string $plainCode): bool
    {
        $record = self::query()
            ->where('idUser', $user->idUser)
            ->where('purpose', $purpose)
            ->whereNull('consumedAt')
            ->where('expiresAt', '>', now())
            ->orderByDesc('idOtp')
            ->first();

        if (!$record) {
            return false;
        }

        $isValid = hash_equals($record->codeHash, hash('sha256', trim($plainCode)));

        $record->attempts = (int) $record->attempts + 1;

        if (!$isValid) {
            if ($record->attempts >= 5) {
                $record->consumedAt = now();
            }
            $record->save();
            return false;
        }

        $record->consumedAt = now();
        $record->save();

        return true;
    }
}
