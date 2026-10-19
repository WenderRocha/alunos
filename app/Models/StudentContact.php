<?php

declare(strict_types = 1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentContact extends Model
{
    protected $fillable = [
        'student_id',
        'name',
        'phone',
        'email',
        'relationship',
        'is_responsible',
    ];

    protected function casts(): array
    {
        return [
            'is_responsible' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
