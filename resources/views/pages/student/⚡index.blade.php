<?php

use App\Models\Invoice;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

new class () extends Component
{
    use WithPagination;

    public bool $listOnly = false;

    public string $search = '';

    public string $billingFilter = 'all';

    /** @var array<string, string> */
    public array $recurrenceOptions = [
        'weekly'    => 'Semanal',
        'monthly'   => 'Mensal',
        'quarterly' => 'Trimestral',
        'yearly'    => 'Anual',
    ];

    public function mount(): void
    {
        $this->listOnly = request()->routeIs('alunos.index');
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'billingFilter']);
        $this->billingFilter = 'all';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBillingFilter(): void
    {
        $this->resetPage();
    }

    public function markAsPaid(int $invoiceId): void
    {
        Invoice::query()
            ->where('user_id', Auth::id())
            ->whereKey($invoiceId)
            ->where('status', Invoice::STATUS_PENDING)
            ->update([
                'status'  => Invoice::STATUS_PAID,
                'paid_at' => now(),
            ]);

        \Flux\Flux::toast(variant: 'success', text: 'Cobrança marcada como paga.');
    }

    public function undoPayment(int $invoiceId): void
    {
        Invoice::query()
            ->where('user_id', Auth::id())
            ->whereKey($invoiceId)
            ->where('status', Invoice::STATUS_PAID)
            ->update([
                'status'               => Invoice::STATUS_PENDING,
                'paid_at'              => null,
                'due_reminder_sent_at' => null,
            ]);

        \Flux\Flux::toast(variant: 'success', text: 'Pagamento desfeito. A cobrança voltou a ficar em aberto.');
    }

    public function deleteStudent(int $studentId): void
    {
        Student::query()
            ->where('user_id', Auth::id())
            ->findOrFail($studentId)
            ->delete();

        $this->resetPage();

        \Flux\Flux::toast(variant: 'success', text: 'Aluno excluído com sucesso.');
    }

    public function chargeNow(int $studentId): void
    {
        $student = Student::query()
            ->where('user_id', Auth::id())
            ->with('contacts')
            ->findOrFail($studentId);

        $sent = app(\App\Services\BillingProcessor::class)->chargeNow($student);

        \Flux\Flux::toast(
            variant: $sent ? 'success' : 'danger',
            text: $sent
                ? 'Cobrança enviada pelo WhatsApp.'
                : 'Não foi possível enviar a cobrança. Verifique a configuração da Evolution e os contatos.',
        );
    }

    /** @return array{total: int, pending: int, paid: int, received_amount: float, pending_amount: float, total_amount: float, received_percentage: int} */
    public function getStatsProperty(): array
    {
        $query          = Student::query()->where('user_id', Auth::id());
        $invoiceQuery   = Invoice::query()->where('user_id', Auth::id());
        $receivedAmount = (float) (clone $invoiceQuery)
            ->where('status', Invoice::STATUS_PAID)
            ->sum('amount');
        $pendingAmount = (float) (clone $invoiceQuery)
            ->where('status', Invoice::STATUS_PENDING)
            ->sum('amount');
        $totalAmount = $receivedAmount + $pendingAmount;

        return [
            'total'               => (clone $query)->count(),
            'pending'             => (clone $query)->whereHas('invoices', fn ($invoice): mixed => $invoice->where('status', Invoice::STATUS_PENDING))->count(),
            'paid'                => (clone $query)->whereHas('invoices', fn ($invoice): mixed => $invoice->where('status', Invoice::STATUS_PAID))->count(),
            'received_amount'     => $receivedAmount,
            'pending_amount'      => $pendingAmount,
            'total_amount'        => $totalAmount,
            'received_percentage' => $totalAmount > 0 ? (int) round(($receivedAmount / $totalAmount) * 100) : 0,
        ];
    }

    /** @return LengthAwarePaginator<int, Student> */
    public function getStudentsProperty(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Student::query()
            ->where('user_id', Auth::id())
            ->with(['contacts', 'invoices' => fn ($query) => $query->latest('due_date')])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($this->billingFilter === 'pending', fn ($query) => $query->whereHas('invoices', fn ($invoice): mixed => $invoice->where('status', Invoice::STATUS_PENDING)))
            ->when($this->billingFilter === 'paid', fn ($query) => $query->whereHas('invoices', fn ($invoice): mixed => $invoice->where('status', Invoice::STATUS_PAID)))
            ->when($this->billingFilter === 'without_invoice', fn ($query) => $query->whereDoesntHave('invoices'))
            ->latest()
            ->paginate(10);
    }

    public function formatAmount(mixed $amount): string
    {
        return 'R$ ' . number_format((float) $amount, 2, ',', '.');
    }

    public function formatDueDate(mixed $date): string
    {
        return CarbonImmutable::parse($date)->format('d/m/Y');
    }
};
?>

<div class="mx-auto w-full max-w-7xl space-y-4 sm:space-y-6">
    <header class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end sm:gap-4">
        <div>
            <flux:text class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Gestão financeira</flux:text>
            <flux:heading size="xl" level="1">{{ $listOnly ? 'Alunos' : 'Visão geral' }}</flux:heading>
            <flux:text class="mt-2 max-w-2xl">
                {{ $listOnly ? 'Consulte alunos, cobranças e próximos vencimentos.' : 'Acompanhe alunos, cobranças e recebimentos em um só lugar.' }}
            </flux:text>
        </div>

        <flux:button class="w-full sm:w-auto" variant="primary" icon="plus" :href="route('alunos.create')" wire:navigate>
            Novo aluno
        </flux:button>
    </header>

    @if (! $listOnly)
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3">
        <flux:card class="col-span-2 p-3 sm:col-span-1 sm:p-4">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:text class="text-sm">Total de alunos</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $this->stats['total'] }}</flux:heading>
                </div>
                <div class="flex size-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 sm:size-11 dark:bg-indigo-400/10 dark:text-indigo-300">
                    <flux:icon.users class="size-4 sm:size-5" />
                </div>
            </div>
        </flux:card>

        <flux:card class="p-3 sm:p-4">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:text class="text-sm">Cobranças em aberto</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $this->stats['pending'] }}</flux:heading>
                </div>
                <div class="flex size-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600 sm:size-11 dark:bg-amber-400/10 dark:text-amber-300">
                    <flux:icon.clock class="size-4 sm:size-5" />
                </div>
            </div>
        </flux:card>

        <flux:card class="p-3 sm:p-4">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:text class="text-sm">Alunos com pagamento</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $this->stats['paid'] }}</flux:heading>
                </div>
                <div class="flex size-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 sm:size-11 dark:bg-emerald-400/10 dark:text-emerald-300">
                    <flux:icon.check-circle class="size-4 sm:size-5" />
                </div>
            </div>
        </flux:card>
        </div>

        <details class="group overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4 select-none sm:p-5 [&::-webkit-details-marker]:hidden">
            <div class="min-w-0">
                <flux:heading size="lg">Resumo financeiro</flux:heading>
                <flux:text class="mt-1 truncate">Valores recebidos, pendentes e progresso.</flux:text>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <flux:badge color="emerald">{{ $this->stats['received_percentage'] }}%</flux:badge>
                <flux:icon.chevron-down class="size-4 transition-transform group-open:rotate-180" />
            </div>
        </summary>

        <div class="space-y-4 border-t border-zinc-200 p-4 sm:space-y-5 sm:p-5 dark:border-white/10">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3">
                <div class="rounded-xl bg-emerald-50 p-3 sm:p-4 dark:bg-emerald-400/10">
                    <flux:text class="text-sm text-emerald-700 dark:text-emerald-300">Já recebido</flux:text>
                    <flux:heading size="lg" class="mt-1 text-base text-emerald-800 sm:text-lg dark:text-emerald-200">
                        {{ $this->formatAmount($this->stats['received_amount']) }}
                    </flux:heading>
                </div>
                <div class="rounded-xl bg-amber-50 p-3 sm:p-4 dark:bg-amber-400/10">
                    <flux:text class="text-sm text-amber-700 dark:text-amber-300">Falta receber</flux:text>
                    <flux:heading size="lg" class="mt-1 text-base text-amber-800 sm:text-lg dark:text-amber-200">
                        {{ $this->formatAmount($this->stats['pending_amount']) }}
                    </flux:heading>
                </div>
                <div class="col-span-2 rounded-xl bg-zinc-100 p-3 sm:col-span-1 sm:p-4 dark:bg-white/10">
                    <flux:text class="text-sm">Total das cobranças</flux:text>
                    <flux:heading size="lg" class="mt-1 text-base sm:text-lg">
                        {{ $this->formatAmount($this->stats['total_amount']) }}
                    </flux:heading>
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex justify-between gap-4 text-sm">
                    <flux:text>Progresso de recebimento</flux:text>
                    <flux:text variant="strong">{{ $this->stats['received_percentage'] }}%</flux:text>
                </div>
                <flux:progress
                    color="emerald"
                    style="--flux-progress-percentage: {{ $this->stats['received_percentage'] }}%;"
                />
            </div>
        </div>
        </details>
    @endif

    @if ($listOnly)
        <details class="group overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4 select-none sm:p-5 [&::-webkit-details-marker]:hidden">
            <div class="flex min-w-0 items-center gap-3">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300">
                    <flux:icon.funnel class="size-4" />
                </div>
                <div class="min-w-0">
                    <flux:heading size="lg">Busca e filtros</flux:heading>
                    <flux:text class="mt-1 truncate">
                        {{ $search !== '' || $billingFilter !== 'all' ? 'Filtros ativos' : 'Pesquisar por nome ou WhatsApp' }}
                    </flux:text>
                </div>
            </div>
            <flux:icon.chevron-down class="size-4 shrink-0 transition-transform group-open:rotate-180" />
        </summary>

        <div class="border-t border-zinc-200 p-3 sm:p-4 dark:border-white/10">
            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px_auto] md:items-end">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    label="Buscar aluno"
                    placeholder="Nome ou WhatsApp"
                    icon="magnifying-glass"
                />

                <flux:select wire:model.live="billingFilter" label="Filtrar por cobrança">
                    <flux:select.option value="all">Todos</flux:select.option>
                    <flux:select.option value="pending">Com cobrança em aberto</flux:select.option>
                    <flux:select.option value="paid">Com pagamento registrado</flux:select.option>
                    <flux:select.option value="without_invoice">Sem fatura</flux:select.option>
                </flux:select>

                <flux:button class="w-full md:w-auto" variant="ghost" icon="arrow-path" wire:click="clearFilters">
                    Limpar filtros
                </flux:button>
            </div>
        </div>
        </details>

        <flux:card class="overflow-hidden">


        @if ($this->students->isNotEmpty())
            <div class="hidden overflow-x-auto md:block">
                <flux:table class="w-full" :paginate="$this->students">
                <flux:table.columns>
                    <flux:table.column>Aluno</flux:table.column>
                    <flux:table.column>Contato</flux:table.column>
                    <flux:table.column>Cobrança</flux:table.column>
                    <flux:table.column>Próximo vencimento</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column align="end">Ações</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->students as $student)
                        @php($invoice = $student->invoices->first())

                        <flux:table.row :key="$student->id">
                            <flux:table.cell variant="strong">
                                <div class="flex min-w-52 items-center gap-3">
                                    <flux:avatar :name="$student->name" size="sm" />
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="truncate">{{ $student->name }}</span>
                                            @if ($student->isMinor())
                                                <flux:badge size="sm" color="amber">Menor</flux:badge>
                                            @endif
                                        </div>
                                        <flux:text class="truncate text-xs">
                                            {{ $student->student_receives_billing ? 'Recebe cobranças no WhatsApp' : 'Cobrança via contato' }}
                                        </flux:text>
                                    </div>
                                </div>
                            </flux:table.cell>

                            <flux:table.cell>
                                <div>
                                    <flux:text>{{ $student->phone ?: 'Sem WhatsApp' }}</flux:text>
                                    <flux:text class="text-xs">{{ $student->contacts->count() }} contato(s)</flux:text>
                                </div>
                            </flux:table.cell>

                            <flux:table.cell>
                                <div>
                                    <flux:text variant="strong">{{ $this->formatAmount($student->billing_amount) }}</flux:text>
                                    <flux:text class="text-xs">{{ $recurrenceOptions[$student->billing_recurrence] ?? $student->billing_recurrence }}</flux:text>
                                </div>
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($invoice)
                                    <flux:text>{{ $this->formatDueDate($invoice->due_date) }}</flux:text>
                                    <flux:text class="text-xs">Dia {{ $student->billing_due_day }}</flux:text>
                                @else
                                    <flux:text>Sem fatura</flux:text>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                @if (! $invoice)
                                    <flux:badge color="zinc">Sem fatura</flux:badge>
                                @elseif ($invoice->isPaid())
                                    <flux:badge color="green">Pago</flux:badge>
                                @elseif (CarbonImmutable::parse($invoice->due_date)->lt(CarbonImmutable::today()))
                                    <flux:badge color="red">Em atraso</flux:badge>
                                @else
                                    <flux:badge color="amber">Em aberto</flux:badge>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-2">
                                    @if ($invoice && $invoice->isPaid())
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="arrow-path"
                                            wire:click="undoPayment({{ $invoice->id }})"
                                            wire:confirm="Deseja desfazer o pagamento desta cobrança?"
                                            wire:loading.attr="disabled"
                                            wire:target="undoPayment({{ $invoice->id }})"
                                        >
                                            Desfazer pagamento
                                        </flux:button>
                                    @else
                                        @if ($invoice)
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="markAsPaid({{ $invoice->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="markAsPaid({{ $invoice->id }})"
                                        >
                                            Marcar pago
                                        </flux:button>
                                        @endif

                                        <flux:button
                                            size="sm"
                                            variant="primary"
                                            icon="paper-airplane"
                                            wire:click="chargeNow({{ $student->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="chargeNow({{ $student->id }})"
                                        >
                                            Cobrar
                                        </flux:button>
                                    @endif

                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                        aria-label="Excluir aluno"
                                        wire:click="deleteStudent({{ $student->id }})"
                                        wire:confirm="Tem certeza que deseja excluir este aluno? Esta ação não pode ser desfeita."
                                        wire:loading.attr="disabled"
                                        wire:target="deleteStudent({{ $student->id }})"
                                    >
                                        Excluir
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
                </flux:table>
            </div>

            <div class="divide-y divide-zinc-200 md:hidden dark:divide-white/10">
                @foreach ($this->students as $student)
                    @php($invoice = $student->invoices->first())

                    <article class="space-y-3 p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-3">
                                <flux:avatar :name="$student->name" size="sm" />
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:text class="truncate font-medium">{{ $student->name }}</flux:text>
                                        @if ($student->isMinor())
                                            <flux:badge size="sm" color="amber">Menor</flux:badge>
                                        @endif
                                    </div>
                                    <flux:text class="truncate text-xs">{{ $student->phone ?: 'Sem WhatsApp' }}</flux:text>
                                </div>
                            </div>

                            @if (! $invoice)
                                <flux:badge class="shrink-0" color="zinc">Sem fatura</flux:badge>
                            @elseif ($invoice->isPaid())
                                <flux:badge class="shrink-0" color="green">Pago</flux:badge>
                            @elseif (CarbonImmutable::parse($invoice->due_date)->lt(CarbonImmutable::today()))
                                <flux:badge class="shrink-0" color="red">Em atraso</flux:badge>
                            @else
                                <flux:badge class="shrink-0" color="amber">Em aberto</flux:badge>
                            @endif
                        </div>

                        <div class="grid grid-cols-2 gap-2 rounded-xl bg-zinc-50 p-3 dark:bg-white/[4%]">
                            <div class="min-w-0">
                                <flux:text class="text-xs">Cobrança</flux:text>
                                <flux:text variant="strong" class="mt-1 block truncate">{{ $this->formatAmount($student->billing_amount) }}</flux:text>
                                <flux:text class="truncate text-xs">{{ $recurrenceOptions[$student->billing_recurrence] ?? $student->billing_recurrence }}</flux:text>
                            </div>
                            <div class="min-w-0">
                                <flux:text class="text-xs">Vencimento</flux:text>
                                @if ($invoice)
                                    <flux:text class="mt-1 block">{{ $this->formatDueDate($invoice->due_date) }}</flux:text>
                                    <flux:text class="text-xs">Dia {{ $student->billing_due_day }}</flux:text>
                                @else
                                    <flux:text class="mt-1 block">Sem fatura</flux:text>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            @if ($invoice && $invoice->isPaid())
                                <flux:button
                                    class="col-span-2 w-full"
                                    size="sm"
                                    variant="ghost"
                                    icon="arrow-path"
                                    wire:click="undoPayment({{ $invoice->id }})"
                                    wire:confirm="Deseja desfazer o pagamento desta cobrança?"
                                    wire:loading.attr="disabled"
                                    wire:target="undoPayment({{ $invoice->id }})"
                                >
                                    Desfazer pagamento
                                </flux:button>
                            @else
                                @if ($invoice)
                                    <flux:button
                                        class="w-full"
                                        size="sm"
                                        variant="ghost"
                                        icon="check"
                                        wire:click="markAsPaid({{ $invoice->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="markAsPaid({{ $invoice->id }})"
                                    >
                                        Marcar pago
                                    </flux:button>
                                @endif

                                <flux:button
                                    class="w-full {{ $invoice ? '' : 'col-span-2' }}"
                                    size="sm"
                                    variant="primary"
                                    icon="paper-airplane"
                                    wire:click="chargeNow({{ $student->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="chargeNow({{ $student->id }})"
                                >
                                    Cobrar
                                </flux:button>
                            @endif

                            <flux:button
                                class="col-span-2 w-full text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                wire:click="deleteStudent({{ $student->id }})"
                                wire:confirm="Tem certeza que deseja excluir este aluno? Esta ação não pode ser desfeita."
                                wire:loading.attr="disabled"
                                wire:target="deleteStudent({{ $student->id }})"
                            >
                                Excluir aluno
                            </flux:button>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="px-3 pb-3 md:hidden">
                <flux:pagination :paginator="$this->students" />
            </div>
        @else
            <div class="px-6 py-16 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-500 dark:bg-white/10 dark:text-zinc-300">
                    <flux:icon.users class="size-7" />
                </div>
                <flux:heading size="lg" class="mt-4">
                    {{ $search !== '' || $billingFilter !== 'all' ? 'Nenhum aluno encontrado' : 'Nenhum aluno cadastrado' }}
                </flux:heading>
                <flux:text class="mx-auto mt-2 max-w-md">
                    {{ $search !== '' || $billingFilter !== 'all' ? 'Tente mudar os filtros para encontrar outro aluno.' : 'Cadastre seu primeiro aluno para começar a acompanhar as cobranças.' }}
                </flux:text>
                @if ($search === '' && $billingFilter === 'all')
                    <flux:button class="mt-6" variant="primary" icon="plus" :href="route('alunos.create')" wire:navigate>
                        Cadastrar primeiro aluno
                    </flux:button>
                @else
                    <flux:button class="mt-6" variant="ghost" wire:click="clearFilters">
                        Limpar filtros
                    </flux:button>
                @endif
            </div>
        @endif
        </flux:card>
    @endif
</div>
