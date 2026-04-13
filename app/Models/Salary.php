<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Salary extends Model
{
    protected $table = 'salaries';
    protected $primaryKey = 'idSalary';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'idUser',
        'amount',
        'salaryDate',
        'nextSalaryDate',
        'status',
        'description',
        'source',
        'autoCreateTransaction',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'salaryDate' => 'date',
        'nextSalaryDate' => 'date',
        'autoCreateTransaction' => 'boolean',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'idUser', 'idUser');
    }

    /**
     * Scope untuk filter berdasarkan status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope untuk filter gajian bulan ini
     */
    public function scopeThisMonth($query)
    {
        return $query->whereYear('salaryDate', now()->year)
            ->whereMonth('salaryDate', now()->month);
    }

    /**
     * Scope untuk filter gajian yang pending
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Ambil gajian terakhir
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('salaryDate', 'desc');
    }
}
