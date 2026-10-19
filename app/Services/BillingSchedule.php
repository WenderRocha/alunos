<?php

declare(strict_types = 1);

namespace App\Services;

use App\Models\Student;
use Carbon\CarbonImmutable;

class BillingSchedule
{
    public function nextDueDate(Student $student, CarbonImmutable $from): CarbonImmutable
    {
        $from   = $from->startOfDay();
        $dueDay = max(1, (int) $student->billing_due_day);

        if ($student->billing_recurrence === 'weekly') {
            $daysUntilDue = ($dueDay - $from->dayOfWeekIso + 7) % 7;

            return $from->addDays($daysUntilDue);
        }

        $intervalMonths = match ($student->billing_recurrence) {
            'quarterly' => 3,
            'yearly'    => 12,
            default     => 1,
        };

        $anchor = $student->billing_start_date
            ? CarbonImmutable::parse($student->billing_start_date)->startOfDay()
            : CarbonImmutable::parse($student->created_at)->startOfDay();

        $monthsSinceAnchor = max(
            0,
            (($from->year - $anchor->year) * 12) + ($from->month - $anchor->month),
        );
        $cycle          = intdiv($monthsSinceAnchor, $intervalMonths);
        $candidateMonth = $anchor->addMonthsNoOverflow($cycle * $intervalMonths);
        $candidate      = $this->dateWithDueDay($candidateMonth, $dueDay);

        if ($candidate->lt($from)) {
            $candidate = $this->dateWithDueDay(
                $candidateMonth->addMonthsNoOverflow($intervalMonths),
                $dueDay,
            );
        }

        return $candidate;
    }

    private function dateWithDueDay(CarbonImmutable $month, int $dueDay): CarbonImmutable
    {
        return $month->startOfMonth()->addDays(min($dueDay, $month->daysInMonth) - 1);
    }
}
