<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingRegistration extends Model
{
    protected $table = 'pending_registrations';
    protected $primaryKey = 'idPending';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'email',
        'passwordHash',
        'name',
        'phone',
        'salaryDate',
        'codeHash',
        'attempts',
        'expiresAt',
    ];

    protected $casts = [
        'expiresAt' => 'datetime',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
        'salaryDate' => 'date',
    ];
}
