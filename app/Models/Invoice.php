<?php

declare(strict_types = 1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'user_id',
        'student_id',
        'amount',
        'due_date',
        'status',
        'paid_at',
        'due_reminder_sent_at',
        'last_notification_error',
    ];

    protected function casts(): array
    {
        return [
            'amount'               => 'decimal:2',
            'due_date'             => 'date',
            'paid_at'              => 'datetime',
            'due_reminder_sent_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(BillingNotification::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
