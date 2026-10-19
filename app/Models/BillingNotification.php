<?php

declare(strict_types = 1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingNotification extends Model
{
    protected $fillable = [
        'invoice_id',
        'kind',
        'reminder_day',
        'recipient_type',
        'recipient_name',
        'phone',
        'sent_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'reminder_day' => 'integer',
            'sent_at'      => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
