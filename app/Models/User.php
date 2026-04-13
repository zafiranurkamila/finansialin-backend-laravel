<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'idUser';

    public $incrementing = true;

    protected $keyType = 'int';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'idUser',
        'name',
        'email',
        'phone',
        'password',
        'salary_date',
        'emailVerifiedAt',
        'phoneVerifiedAt',
        'twoFactorEnabled',
        'twoFactorConfirmedAt',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'salary_date' => 'date',
            'createdAt' => 'datetime',
            'updatedAt' => 'datetime',
            'emailVerifiedAt' => 'datetime',
            'phoneVerifiedAt' => 'datetime',
            'twoFactorEnabled' => 'boolean',
            'twoFactorConfirmedAt' => 'datetime',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'idUser', 'idUser');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'idUser', 'idUser');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class, 'idUser', 'idUser');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'idUser', 'idUser');
    }

    public function authTokens(): HasMany
    {
        return $this->hasMany(AuthToken::class, 'idUser', 'idUser');
    }

    public function securityOtps(): HasMany
    {
        return $this->hasMany(SecurityOtp::class, 'idUser', 'idUser');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class, 'idUser', 'idUser');
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(Salary::class, 'idUser', 'idUser');
    }
}
