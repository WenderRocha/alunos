<?php

declare(strict_types = 1);

namespace App\Services;

use App\Models\BillingNotification;
use App\Models\Invoice;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class BillingProcessor
{
    public function __construct(
        private readonly BillingSchedule $schedule,
        private readonly EvolutionApi $evolution,
    ) {
    }

    public function process(?CarbonImmutable $date = null): int
    {
        $today     = ($date ?? CarbonImmutable::now())->startOfDay();
        $processed = 0;

        Student::query()
            ->with('contacts')
            ->whereNotNull('billing_amount')
            ->whereNotNull('billing_recurrence')
            ->whereNotNull('billing_due_day')
            ->chunkById(100, function (Collection $students) use ($today, &$processed): void {
                foreach ($students as $student) {
                    $this->processStudent($student, $today);
                    $processed++;
                }
            });

        return $processed;
    }

    public function chargeNow(Student $student): bool
    {
        $today   = CarbonImmutable::now()->startOfDay();
        $invoice = $student->invoices()
            ->whereDate('due_date', $today->toDateString())
            ->first();

        if ($invoice?->isPaid()) {
            Log::warning('Cobrança manual não enviada porque a fatura já está paga.', [
                'student_id' => $student->id,
                'invoice_id' => $invoice->id,
                'user_id'    => $student->user_id,
            ]);

            return false;
        }

        if ($invoice === null) {
            $invoice = $student->invoices()->create([
                'user_id'  => $student->user_id,
                'amount'   => $student->billing_amount,
                'due_date' => $today->toDateString(),
                'status'   => Invoice::STATUS_PENDING,
            ]);

            Log::info('Fatura criada para cobrança manual.', [
                'student_id' => $student->id,
                'invoice_id' => $invoice->id,
                'user_id'    => $student->user_id,
                'amount'     => $invoice->amount,
                'due_date'   => $invoice->due_date,
            ]);
        }

        $message = sprintf(
            "Olá! Foi lançada agora uma cobrança de %s referente ao aluno %s, com vencimento hoje (%s).\n\n%s\n\nSe você já realizou o pagamento, desconsidere esta mensagem.",
            $this->formatAmount($invoice),
            $student->name,
            $today->format('d/m/Y'),
            $this->paymentInstructions($student),
        );
        $message = $this->customizeMessage($student, $invoice, $message, 'cobrança');

        Log::info('Iniciando cobrança manual.', [
            'student_id'      => $student->id,
            'invoice_id'      => $invoice->id,
            'user_id'         => $student->user_id,
            'recipient_count' => count($this->recipients($student)),
            'message_length'  => mb_strlen($message),
            'message_hash'    => hash('sha256', $message),
        ]);

        $sent = $this->sendToRecipients($invoice, $student, 'manual', 0, $message, $today, true);

        Log::info('Cobrança manual finalizada.', [
            'student_id' => $student->id,
            'invoice_id' => $invoice->id,
            'user_id'    => $student->user_id,
            'sent'       => $sent,
            'sent_count' => BillingNotification::query()
                ->where('invoice_id', $invoice->id)
                ->where('kind', 'manual')
                ->whereNotNull('sent_at')
                ->count(),
            'error_count' => BillingNotification::query()
                ->where('invoice_id', $invoice->id)
                ->where('kind', 'manual')
                ->whereNotNull('error')
                ->count(),
        ]);

        return $sent;
    }

    private function processStudent(Student $student, CarbonImmutable $today): void
    {
        $pendingPastDue = $student->invoices()
            ->where('status', Invoice::STATUS_PENDING)
            ->whereDate('due_date', '<=', $today)
            ->orderBy('due_date')
            ->first();

        if ($pendingPastDue !== null) {
            $this->sendDueReminder($pendingPastDue, $student, $today);

            return;
        }

        $dueDate = $this->schedule->nextDueDate($student, $today);
        $invoice = $student->invoices()
            ->whereDate('due_date', $dueDate->toDateString())
            ->first();

        if ($invoice === null) {
            $invoice = $student->invoices()->create([
                'user_id'  => $student->user_id,
                'amount'   => $student->billing_amount,
                'due_date' => $dueDate->toDateString(),
                'status'   => Invoice::STATUS_PENDING,
            ]);
        }

        if ($invoice->isPaid()) {
            return;
        }

        $daysUntilDue = (int) $today->diffInDays($dueDate, false);

        if ($daysUntilDue === 0) {
            $this->sendDueReminder($invoice, $student, $today);
        } elseif ($daysUntilDue > 0) {
            $this->sendPreDueReminder($invoice, $student, $daysUntilDue, $today);
        }
    }

    private function sendPreDueReminder(
        Invoice $invoice,
        Student $student,
        int $daysUntilDue,
        CarbonImmutable $today,
    ): void {
        $reminderDays = (array) config('billing.reminder_days', [7, 3, 1]);

        if (! in_array($daysUntilDue, $reminderDays, true)) {
            return;
        }

        $message = sprintf(
            "Olá! Lembrete da cobrança de %s referente ao aluno %s: vencimento em %d %s (%s).\n\n%s",
            $this->formatAmount($invoice),
            $student->name,
            $daysUntilDue,
            $daysUntilDue === 1 ? 'dia' : 'dias',
            CarbonImmutable::parse($invoice->due_date)->format('d/m/Y'),
            $this->paymentInstructions($student),
        );
        $message = $this->customizeMessage($student, $invoice, $message, 'lembrete', $daysUntilDue);

        $this->sendToRecipients($invoice, $student, 'pre_due', $daysUntilDue, $message, $today);
    }

    private function sendDueReminder(Invoice $invoice, Student $student, CarbonImmutable $today): void
    {
        if ($invoice->due_reminder_sent_at !== null) {
            return;
        }

        $dueDate = CarbonImmutable::parse($invoice->due_date);
        $dueText = $dueDate->lt($today)
            ? 'venceu em ' . $dueDate->format('d/m/Y')
            : 'vence hoje (' . $dueDate->format('d/m/Y') . ')';

        $message = sprintf(
            "Olá! A cobrança de %s referente ao aluno %s %s e ainda não foi marcada como paga.\n\n%s\n\nSe você já realizou o pagamento, desconsidere esta mensagem.",
            $this->formatAmount($invoice),
            $student->name,
            $dueText,
            $this->paymentInstructions($student),
        );
        $message = $this->customizeMessage($student, $invoice, $message, 'vencimento');

        if ($this->sendToRecipients($invoice, $student, 'due', 0, $message, $today)) {
            $invoice->forceFill(['due_reminder_sent_at' => now()])->save();
        }
    }

    private function sendToRecipients(
        Invoice $invoice,
        Student $student,
        string $kind,
        int $reminderDay,
        string $message,
        CarbonImmutable $today,
        bool $force = false,
    ): bool {
        $recipients = $this->recipients($student);
        $sent       = 0;

        if ($recipients === []) {
            Log::warning('Cobrança não enviada porque não há destinatários válidos.', [
                'student_id' => $student->id,
                'invoice_id' => $invoice->id,
                'user_id'    => $student->user_id,
                'kind'       => $kind,
            ]);

            return false;
        }

        foreach ($recipients as $recipient) {
            $notification = BillingNotification::query()->firstOrCreate([
                'invoice_id'   => $invoice->id,
                'kind'         => $kind,
                'reminder_day' => $reminderDay,
                'phone'        => $recipient['phone'],
            ], [
                'recipient_type' => $recipient['type'],
                'recipient_name' => $recipient['name'],
            ]);

            if ($notification->sent_at !== null && ! $force) {
                Log::info('Notificação de cobrança já enviada; envio ignorado.', [
                    'student_id'      => $student->id,
                    'invoice_id'      => $invoice->id,
                    'notification_id' => $notification->id,
                    'kind'            => $kind,
                    'recipient_type'  => $recipient['type'],
                ]);
                $sent++;

                continue;
            }

            if ($force && $notification->sent_at !== null) {
                Log::info('Reenvio manual solicitado para uma notificação já enviada.', [
                    'student_id'       => $student->id,
                    'invoice_id'       => $invoice->id,
                    'notification_id'  => $notification->id,
                    'kind'             => $kind,
                    'recipient_type'   => $recipient['type'],
                    'previous_sent_at' => $notification->sent_at,
                ]);

                $notification->forceFill([
                    'sent_at' => null,
                    'error'   => null,
                ])->save();
            }

            try {
                Log::info('Tentando enviar notificação de cobrança.', [
                    'student_id'      => $student->id,
                    'invoice_id'      => $invoice->id,
                    'notification_id' => $notification->id,
                    'kind'            => $kind,
                    'recipient_type'  => $recipient['type'],
                    'reminder_day'    => $reminderDay,
                ]);

                $this->evolution->sendText($recipient['phone'], $message);
                $notification->forceFill([
                    'sent_at' => now(),
                    'error'   => null,
                ])->save();
                Log::info('Notificação de cobrança aceita pela Evolution API.', [
                    'student_id'      => $student->id,
                    'invoice_id'      => $invoice->id,
                    'notification_id' => $notification->id,
                    'kind'            => $kind,
                    'recipient_type'  => $recipient['type'],
                ]);
                $sent++;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
                $notification->forceFill(['error' => $error])->save();
                $invoice->forceFill(['last_notification_error' => $error])->save();

                Log::error('Falha ao enviar cobrança via Evolution API.', [
                    'invoice_id'      => $invoice->id,
                    'student_id'      => $student->id,
                    'phone'           => $recipient['phone'],
                    'date'            => $today->toDateString(),
                    'kind'            => $kind,
                    'notification_id' => $notification->id,
                    'exception'       => $exception,
                ]);
            }
        }

        Log::info('Lote de notificações de cobrança finalizado.', [
            'student_id'      => $student->id,
            'invoice_id'      => $invoice->id,
            'kind'            => $kind,
            'recipient_count' => count($recipients),
            'sent_count'      => $sent,
            'all_sent'        => $sent === count($recipients),
        ]);

        return $sent === count($recipients);
    }

    /**
     * @return array<int, array{type: string, name: string, phone: string}>
     */
    private function recipients(Student $student): array
    {
        $recipients = [];

        if ($student->student_receives_billing && filled($student->phone)) {
            $recipients[] = [
                'type'  => 'student',
                'name'  => $student->name,
                'phone' => EvolutionApi::normalizePhone((string) $student->phone),
            ];
        }

        foreach ($student->contacts->where('is_responsible', true) as $contact) {
            $recipients[] = [
                'type'  => 'contact',
                'name'  => $contact->name,
                'phone' => EvolutionApi::normalizePhone($contact->phone),
            ];
        }

        /** @var array<string, array{type: string, name: string, phone: string}> $unique */
        $unique = [];

        foreach ($recipients as $recipient) {
            if ($recipient['phone'] !== '') {
                $unique[$recipient['phone']] = $recipient;
            }
        }

        return array_values($unique);
    }

    private function formatAmount(Invoice $invoice): string
    {
        return 'R$ ' . number_format((float) $invoice->amount, 2, ',', '.');
    }

    private function paymentInstructions(Student $student): string
    {
        $pixKey = trim((string) $student->user()->value('pix_key'));

        if ($pixKey === '') {
            return 'Pagamento via Pix. Solicite a chave Pix ao responsável pelo atendimento e, após pagar, envie o comprovante por este WhatsApp.';
        }

        return "Pagamento via Pix — chave: {$pixKey}. Após pagar, envie o comprovante por este WhatsApp.";
    }

    private function customizeMessage(
        Student $student,
        Invoice $invoice,
        string $defaultMessage,
        string $type,
        int $daysUntilDue = 0,
    ): string {
        $template = trim((string) $student->user()->value('billing_message_template'));

        if ($template === '') {
            return $defaultMessage;
        }

        $pixKey = trim((string) $student->user()->value('pix_key'));

        return strtr($template, [
            '{aluno}'       => $student->name,
            '{valor}'       => $this->formatAmount($invoice),
            '{vencimento}'  => CarbonImmutable::parse($invoice->due_date)->format('d/m/Y'),
            '{pix}'         => $pixKey !== '' ? $pixKey : 'não cadastrada',
            '{comprovante}' => 'Após pagar, envie o comprovante por este WhatsApp.',
            '{tipo}'        => $type,
            '{dias}'        => (string) $daysUntilDue,
        ]);
    }
}
