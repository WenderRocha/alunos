<?php

declare(strict_types = 1);

namespace App\Models;

use App\Models\Scopes\UserScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[ScopedBy([UserScope::class])]
class Student extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'birth_date',
        'billing_amount',
        'billing_recurrence',
        'billing_due_day',
        'student_receives_billing',
        'billing_start_date',
    ];

    protected function casts(): array
    {
        return [
            'birth_date'               => 'date',
            'billing_amount'           => 'decimal:2',
            'billing_due_day'          => 'integer',
            'student_receives_billing' => 'boolean',
            'billing_start_date'       => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<StudentContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(StudentContact::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isMinor(?Carbon $asOf = null): bool
    {
        return $this->birth_date !== null
            && Carbon::parse($this->birth_date)->diffInYears($asOf ?? now()) < 18;
    }
}
