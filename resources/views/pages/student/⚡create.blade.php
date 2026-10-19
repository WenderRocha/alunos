<?php

use App\Models\Student;
use App\Services\EvolutionApi;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class () extends Component
{
    public string $name = '';

    public string $phone = '';

    public ?string $birth_date = null;

    public string $billing_amount = '';

    public string $billing_recurrence = 'monthly';

    public ?int $billing_due_day = null;

    public bool $student_receives_billing = false;

    /** @var array<int, array{name: string, phone: string, relationship: string, is_responsible: bool}> */
    public array $contacts = [];

    /** @var array<string, string> */
    public array $recurrenceOptions = [
        'weekly'    => 'Semanal',
        'monthly'   => 'Mensal',
        'quarterly' => 'Trimestral',
        'yearly'    => 'Anual',
    ];

    public function mount(): void
    {
        $this->contacts = [$this->emptyContact()];
    }

    public function addContact(): void
    {
        if (count($this->contacts) >= 3) {
            return;
        }

        $this->contacts[] = $this->emptyContact();
    }

    public function removeContact(int $index): void
    {
        if (count($this->contacts) === 1) {
            $this->contacts = [$this->emptyContact()];

            return;
        }

        unset($this->contacts[$index]);
        $this->contacts = array_values($this->contacts);
    }

    public function updatedBirthDate(?string $value): void
    {
        if ($value !== null && $value !== '' && CarbonImmutable::parse($value)->age < 18) {
            $this->student_receives_billing = false;
        }
    }

    public function save(): void
    {
        $this->contacts = array_values(array_filter(
            $this->contacts,
            static fn (array $contact): bool => filled($contact['name'] ?? null)
                || filled($contact['phone'] ?? null)
                || filled($contact['relationship'] ?? null)
                || (bool) ($contact['is_responsible'] ?? false),
        ));

        $this->validate([
            'name'                      => ['required', 'string', 'max:255'],
            'phone'                     => ['nullable', 'string', 'max:30', 'unique:students,phone'],
            'birth_date'                => ['nullable', 'date', 'before_or_equal:today'],
            'billing_amount'            => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'billing_recurrence'        => ['required', Rule::in(array_keys($this->recurrenceOptions))],
            'billing_due_day'           => ['required', 'integer', 'min:1', 'max:31'],
            'student_receives_billing'  => ['boolean'],
            'contacts'                  => ['array', 'max:3'],
            'contacts.*.name'           => ['required', 'string', 'max:255'],
            'contacts.*.phone'          => ['required', 'string', 'min:8', 'max:30'],
            'contacts.*.relationship'   => ['nullable', 'string', 'max:100'],
            'contacts.*.is_responsible' => ['boolean'],
        ], [
            'contacts.*.name.required'  => 'Informe o nome do contato.',
            'contacts.*.phone.required' => 'Informe o telefone do contato.',
        ]);

        $isMinor          = $this->isMinor;
        $responsibleCount = count(array_filter(
            $this->contacts,
            static fn (array $contact): bool => (bool) ($contact['is_responsible'] ?? false),
        ));

        if ($responsibleCount > 1) {
            $this->addError('contacts', 'Escolha apenas um contato responsável.');
        }

        if ($isMinor && $responsibleCount !== 1) {
            $this->addError('contacts', 'Para menores de idade, informe um contato responsável pelas cobranças.');
        }

        if (! $this->student_receives_billing && $responsibleCount !== 1) {
            $this->addError('contacts', 'Informe um contato responsável ou habilite o envio para o aluno.');
        }

        if ($this->student_receives_billing && strlen(EvolutionApi::normalizePhone($this->phone)) < 8) {
            $this->addError('phone', 'Informe um telefone válido para o aluno receber as cobranças.');
        }

        foreach ($this->contacts as $index => $contact) {
            if (strlen(EvolutionApi::normalizePhone((string) $contact['phone'])) < 8) {
                $this->addError("contacts.{$index}.phone", 'Informe um telefone válido.');
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        DB::transaction(function (): void {
            $student = Student::query()->create([
                'user_id'                  => Auth::id(),
                'name'                     => $this->name,
                'phone'                    => $this->phone !== '' ? EvolutionApi::normalizePhone($this->phone) : null,
                'birth_date'               => $this->birth_date,
                'billing_amount'           => $this->billing_amount,
                'billing_recurrence'       => $this->billing_recurrence,
                'billing_due_day'          => $this->billing_due_day,
                'student_receives_billing' => $this->student_receives_billing,
                'billing_start_date'       => today()->toDateString(),
            ]);

            $student->contacts()->createMany(array_map(
                static fn (array $contact): array => [
                    'name'           => $contact['name'],
                    'phone'          => EvolutionApi::normalizePhone($contact['phone']),
                    'relationship'   => filled($contact['relationship'] ?? null) ? $contact['relationship'] : null,
                    'is_responsible' => (bool) ($contact['is_responsible'] ?? false),
                ],
                $this->contacts,
            ));
        });

        $this->resetForm();
        Flux::toast(variant: 'success', text: 'Aluno cadastrado com sucesso!');
    }

    public function getIsMinorProperty(): bool
    {
        return $this->birth_date !== null
            && $this->birth_date !== ''
            && CarbonImmutable::parse($this->birth_date)->age < 18;
    }

    private function resetForm(): void
    {
        $this->reset([
            'name',
            'phone',
            'birth_date',
            'billing_amount',
            'billing_due_day',
        ]);
        $this->billing_recurrence       = 'monthly';
        $this->student_receives_billing = false;
        $this->contacts                 = [$this->emptyContact()];
        $this->resetValidation();
    }

    /** @return array{name: string, phone: string, relationship: string, is_responsible: bool} */
    private function emptyContact(): array
    {
        return [
            'name'           => '',
            'phone'          => '',
            'relationship'   => '',
            'is_responsible' => false,
        ];
    }
};
?>

<div class="mx-auto w-full max-w-5xl space-y-4 sm:space-y-6">
    <header class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end sm:gap-4">
        <div>
            <flux:text class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Gestão financeira</flux:text>
            <flux:heading size="xl" level="1">Cadastrar aluno</flux:heading>
            <flux:text class="mt-2 max-w-2xl">Cadastre os dados, contatos e a cobrança recorrente.</flux:text>
        </div>

        <flux:button class="w-full sm:w-auto" variant="ghost" icon="arrow-left" :href="route('alunos.index')" wire:navigate>
            Voltar para alunos
        </flux:button>
    </header>

    <form wire:submit="save" class="space-y-4 sm:space-y-5">
        <div class="grid gap-3 sm:gap-4 lg:grid-cols-2">
            <flux:card class="space-y-4 p-4 sm:space-y-5 sm:p-5">
                <div>
                    <flux:heading size="lg">Dados do aluno</flux:heading>
                    <flux:text class="mt-1">Informações básicas e WhatsApp.</flux:text>
                </div>

                <flux:input wire:model="name" label="Nome completo" placeholder="Maria Silva" required />
                <flux:input wire:model="phone" label="WhatsApp do aluno" type="tel" placeholder="(11) 99999-9999" />
                <flux:input wire:model.live="birth_date" type="date" label="Data de nascimento" />

                @if ($this->isMinor)
                    <flux:callout class="text-sm" variant="warning" icon="exclamation-triangle" heading="Aluno menor de idade">
                        Selecione um contato responsável abaixo. Ele receberá as cobranças automaticamente.
                    </flux:callout>
                @endif

                <flux:checkbox
                    wire:model="student_receives_billing"
                    label="Enviar cobranças para o aluno também"
                    description="Desativado por padrão. Marque um contato responsável se deixar desativado."
                />

                @error('name') <flux:error>{{ $message }}</flux:error> @enderror
                @error('phone') <flux:error>{{ $message }}</flux:error> @enderror
                @error('birth_date') <flux:error>{{ $message }}</flux:error> @enderror
            </flux:card>

            <flux:card class="space-y-4 p-4 sm:space-y-5 sm:p-5">
                <div>
                    <flux:heading size="lg">Cobrança recorrente</flux:heading>
                    <flux:text class="mt-1">Valor, recorrência e vencimento.</flux:text>
                </div>

                <flux:input wire:model="billing_amount" label="Valor da cobrança" type="number" min="0.01" step="0.01" placeholder="250,00" required />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="billing_recurrence" label="Recorrência">
                        @foreach ($recurrenceOptions as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="billing_due_day" label="Dia do vencimento" type="number" min="1" max="31" placeholder="10" required />
                </div>

                <flux:text class="text-xs">
                    {{ $billing_recurrence === 'weekly' ? 'Use 1 para segunda-feira e 7 para domingo.' : 'Para meses menores, o vencimento cai no último dia do mês.' }}
                </flux:text>

                <flux:callout class="text-sm" icon="bell" heading="Lembretes automáticos">
                    Enviaremos lembretes 7, 3 e 1 dia antes e novamente no vencimento se a fatura estiver pendente.
                </flux:callout>

                @error('billing_amount') <flux:error>{{ $message }}</flux:error> @enderror
                @error('billing_recurrence') <flux:error>{{ $message }}</flux:error> @enderror
                @error('billing_due_day') <flux:error>{{ $message }}</flux:error> @enderror
            </flux:card>
        </div>

        <flux:card class="space-y-4 p-4 sm:space-y-5 sm:p-5">
            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-start sm:gap-3">
                <div>
                    <flux:heading size="lg">Contatos</flux:heading>
                    <flux:text class="mt-1">Até três contatos para receber cobranças.</flux:text>
                </div>
                <flux:button class="w-full sm:w-auto" type="button" variant="outline" icon="plus" wire:click="addContact" :disabled="count($contacts) >= 3">
                    Adicionar contato
                </flux:button>
            </div>

            @error('contacts') <flux:callout variant="danger" icon="exclamation-circle">{{ $message }}</flux:callout> @enderror

            <div class="grid gap-4">
                @foreach ($contacts as $index => $contact)
                    <div wire:key="contact-{{ $index }}" class="rounded-xl border border-zinc-200 bg-zinc-50/70 p-3 dark:border-white/10 dark:bg-white/[3%]">
                        <div class="mb-3 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex size-8 items-center justify-center rounded-lg bg-white text-zinc-500 shadow-sm dark:bg-white/10 dark:text-zinc-300">
                                    <flux:icon.user class="size-4" />
                                </div>
                                <flux:text class="font-medium">Contato {{ $index + 1 }}</flux:text>
                            </div>
                            <flux:button type="button" variant="ghost" size="sm" icon="trash" wire:click="removeContact({{ $index }})" aria-label="Remover contato" />
                        </div>

                        <div class="grid gap-3 sm:gap-4 md:grid-cols-2">
                            <flux:input wire:model="contacts.{{ $index }}.name" label="Nome" placeholder="João Silva" />
                            <flux:input wire:model="contacts.{{ $index }}.phone" label="WhatsApp" type="tel" placeholder="(11) 99999-9999" />
                            <flux:input wire:model="contacts.{{ $index }}.relationship" label="Parentesco ou relação" placeholder="Pai, mãe, responsável financeiro..." />
                        </div>

                        <flux:checkbox
                            class="mt-3"
                            wire:model="contacts.{{ $index }}.is_responsible"
                            label="Responsável por receber as cobranças"
                            description="Será o contato principal para os lembretes financeiros."
                        />

                        @error("contacts.{$index}.name") <flux:error>{{ $message }}</flux:error> @enderror
                        @error("contacts.{$index}.phone") <flux:error>{{ $message }}</flux:error> @enderror
                    </div>
                @endforeach
            </div>
        </flux:card>

        <div class="flex flex-col-reverse justify-end gap-2 sm:flex-row">
            <flux:button class="w-full sm:w-auto" type="button" variant="ghost" :href="route('alunos.index')" wire:navigate>
                Cancelar
            </flux:button>
            <flux:button class="w-full sm:w-auto" type="submit" variant="primary" icon="check-circle" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Cadastrar aluno</span>
                <span wire:loading wire:target="save">Salvando...</span>
            </flux:button>
        </div>
    </form>
</div>
