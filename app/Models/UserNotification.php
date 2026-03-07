<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    protected $table = 'notifications';
    protected $primaryKey = 'idNotification';
    public $timestamps = false;

    protected $fillable = [
        'idUser',
        'type',
        'read',
        'message',
        'createdAt',
    ];

    protected $casts = [
        'read' => 'boolean',
        'createdAt' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'idUser', 'idUser');
    }
}
