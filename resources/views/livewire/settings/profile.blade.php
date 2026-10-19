<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name, email address and Pix key')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link
                                class="cursor-pointer text-sm"
                                wire:click.prevent="resendVerificationNotification"
                            >
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>
                    </div>
                @endif
            </div>

            <div>
                <flux:input wire:model="pix_key" label="Chave Pix" type="text" placeholder="CPF, e-mail, telefone ou chave aleatória" autocomplete="off" />
                <flux:text class="mt-1 text-xs">Essa chave será incluída nas mensagens de cobrança enviadas aos alunos e responsáveis.</flux:text>
                @error('pix_key') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:textarea
                    wire:model="billing_message_template"
                    label="Mensagem de cobrança"
                    rows="7"
                />
                <flux:text class="mt-1 text-xs">
                    O modelo já vem preenchido e pode ser alterado. Variáveis disponíveis: {aluno}, {valor}, {vencimento}, {pix}, {comprovante}, {tipo} e {dias}.
                </flux:text>
                @error('billing_message_template') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-4">
                <flux:button class="w-full sm:w-auto" variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
