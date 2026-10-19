<?php

declare(strict_types = 1);

use App\Models\BillingNotification;
use App\Models\Student;
use App\Models\User;
use App\Services\BillingProcessor;
use App\Services\EvolutionApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('adds the Brazilian country code to local phone numbers', function (): void {
    expect(EvolutionApi::normalizePhone('(19) 98182-8750'))->toBe('5519981828750')
        ->and(EvolutionApi::normalizePhone('5519981828750'))->toBe('5519981828750');
});

it('creates a student with up to three contacts and billing settings', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::student.create')
        ->assertSet('student_receives_billing', false)
        ->set('name', 'Maria Silva')
        ->set('phone', '5511999998888')
        ->set('billing_amount', '250.00')
        ->set('billing_recurrence', 'monthly')
        ->set('billing_due_day', 10)
        ->set('contacts', [
            [
                'name'           => 'João Silva',
                'phone'          => '5511988887777',
                'relationship'   => 'Pai',
                'is_responsible' => true,
            ],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $student = Student::query()->with('contacts')->firstOrFail();

    expect($student->billing_amount)->toBe('250.00')
        ->and($student->billing_recurrence)->toBe('monthly')
        ->and($student->billing_due_day)->toBe(10)
        ->and($student->contacts)->toHaveCount(1)
        ->and($student->contacts->first()->is_responsible)->toBeTrue();
});

it('requires a responsible contact for a minor student', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::student.create')
        ->set('name', 'Pedro Silva')
        ->set('birth_date', CarbonImmutable::now()->subYears(10)->toDateString())
        ->set('phone', '')
        ->set('student_receives_billing', false)
        ->set('billing_amount', '100.00')
        ->set('billing_recurrence', 'weekly')
        ->set('billing_due_day', 1)
        ->set('contacts', [
            [
                'name'           => 'Ana Silva',
                'phone'          => '5511977776666',
                'relationship'   => 'Mãe',
                'is_responsible' => false,
            ],
        ])
        ->call('save')
        ->assertHasErrors(['contacts']);

    expect(Student::query()->count())->toBe(0);
});

it('creates one invoice and sends idempotent reminders through Evolution API', function (): void {
    $user = User::factory()->create(['pix_key' => 'financeiro@example.com']);
    $this->actingAs($user);
    config([
        'services.evolution.base_url' => 'https://evolution.test',
        'services.evolution.api_key'  => 'test-key',
        'services.evolution.instance' => 'school',
    ]);
    Http::fake(['https://evolution.test/*' => Http::response(['status' => 'PENDING'])]);

    $today   = CarbonImmutable::today();
    $dueDate = $today->addDay();
    $student = Student::query()->create([
        'user_id'                  => $user->id,
        'name'                     => 'Maria Silva',
        'phone'                    => '5511999998888',
        'billing_amount'           => 250,
        'billing_recurrence'       => 'monthly',
        'billing_due_day'          => $dueDate->day,
        'student_receives_billing' => true,
        'billing_start_date'       => $today->toDateString(),
    ]);
    $student->contacts()->create([
        'name'           => 'João Silva',
        'phone'          => '5511988887777',
        'is_responsible' => true,
    ]);

    $processor = app(BillingProcessor::class);
    $processor->process($today);
    $processor->process($today);

    expect($student->invoices()->count())->toBe(1)
        ->and(BillingNotification::query()->where('kind', 'pre_due')->whereNotNull('sent_at')->count())->toBe(2);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => str_contains($request['text'], 'financeiro@example.com')
        && str_contains($request['text'], 'envie o comprovante'));

    $processor->process($dueDate);

    expect(BillingNotification::query()->where('kind', 'due')->whereNotNull('sent_at')->count())->toBe(2)
        ->and($student->invoices()->firstOrFail()->fresh()->due_reminder_sent_at)->not->toBeNull();

    Http::assertSentCount(4);
});

it('allows sending a manual charge immediately', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    config([
        'services.evolution.base_url' => 'https://evolution.test',
        'services.evolution.api_key'  => 'test-key',
        'services.evolution.instance' => 'school',
    ]);
    Http::fake(['https://evolution.test/*' => Http::response(['status' => 'PENDING'])]);

    $student = Student::query()->create([
        'user_id'                  => $user->id,
        'name'                     => 'Carlos Souza',
        'phone'                    => '5511999991111',
        'billing_amount'           => 180,
        'billing_recurrence'       => 'monthly',
        'billing_due_day'          => 15,
        'student_receives_billing' => true,
        'billing_start_date'       => CarbonImmutable::today()->toDateString(),
    ]);

    Livewire::test('pages::student.index')
        ->call('chargeNow', $student->id)
        ->call('chargeNow', $student->id);

    expect($student->invoices()->count())->toBe(1)
        ->and(BillingNotification::query()->where('kind', 'manual')->whereNotNull('sent_at')->count())->toBe(1);

    Http::assertSentCount(2);
});

it('allows undoing a payment and returns the invoice to pending', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $student = Student::query()->create([
        'user_id'                  => $user->id,
        'name'                     => 'Ana Souza',
        'phone'                    => '5511999992222',
        'billing_amount'           => 180,
        'billing_recurrence'       => 'monthly',
        'billing_due_day'          => 15,
        'student_receives_billing' => true,
        'billing_start_date'       => CarbonImmutable::today()->toDateString(),
    ]);

    $invoice = $student->invoices()->create([
        'user_id'              => $user->id,
        'amount'               => 180,
        'due_date'             => CarbonImmutable::today()->toDateString(),
        'status'               => 'paid',
        'paid_at'              => now(),
        'due_reminder_sent_at' => now(),
    ]);

    Livewire::test('pages::student.index')
        ->call('undoPayment', $invoice->id)
        ->assertHasNoErrors();

    $invoice = $invoice->fresh();

    expect($invoice->status)->toBe('pending')
        ->and($invoice->paid_at)->toBeNull()
        ->and($invoice->due_reminder_sent_at)->toBeNull();
});

it('deletes a student and its billing data', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $student = Student::query()->create([
        'user_id'                  => $user->id,
        'name'                     => 'João Souza',
        'phone'                    => '5511999993333',
        'billing_amount'           => 180,
        'billing_recurrence'       => 'monthly',
        'billing_due_day'          => 15,
        'student_receives_billing' => true,
        'billing_start_date'       => CarbonImmutable::today()->toDateString(),
    ]);

    $student->contacts()->create([
        'name'           => 'Responsável',
        'phone'          => '5511999994444',
        'is_responsible' => true,
    ]);

    $invoice = $student->invoices()->create([
        'user_id'  => $user->id,
        'amount'   => 180,
        'due_date' => CarbonImmutable::today()->toDateString(),
        'status'   => 'pending',
    ]);

    $invoice->notifications()->create([
        'kind'           => 'manual',
        'reminder_day'   => 0,
        'recipient_type' => 'student',
        'recipient_name' => $student->name,
        'phone'          => $student->phone,
    ]);

    Livewire::test('pages::student.index')
        ->call('deleteStudent', $student->id)
        ->assertHasNoErrors();

    expect(Student::query()->whereKey($student->id)->exists())->toBeFalse()
        ->and($student->contacts()->count())->toBe(0)
        ->and($student->invoices()->count())->toBe(0)
        ->and(BillingNotification::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

it('uses the billing message template and replaces its variables', function (): void {
    $user = User::factory()->create([
        'pix_key'                  => 'financeiro@example.com',
        'billing_message_template' => 'Olá {aluno}! O valor é {valor} e vence em {vencimento}. Pix: {pix}. {comprovante} Tipo: {tipo}.',
    ]);
    $this->actingAs($user);
    config([
        'services.evolution.base_url' => 'https://evolution.test',
        'services.evolution.api_key'  => 'test-key',
        'services.evolution.instance' => 'school',
    ]);
    Http::fake(['https://evolution.test/*' => Http::response(['status' => 'PENDING'])]);

    $today   = CarbonImmutable::today();
    $dueDate = $today->addDay();
    $student = Student::query()->create([
        'user_id'                  => $user->id,
        'name'                     => 'Maria Silva',
        'phone'                    => '5511999998888',
        'billing_amount'           => 250,
        'billing_recurrence'       => 'monthly',
        'billing_due_day'          => $dueDate->day,
        'student_receives_billing' => true,
        'billing_start_date'       => $today->toDateString(),
    ]);

    app(BillingProcessor::class)->process($today);

    Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Olá Maria Silva!')
        && str_contains($request['text'], 'R$ 250,00')
        && str_contains($request['text'], $dueDate->format('d/m/Y'))
        && str_contains($request['text'], 'financeiro@example.com')
        && str_contains($request['text'], 'envie o comprovante')
        && str_contains($request['text'], 'Tipo: lembrete.'));
});
