<?php

declare(strict_types = 1);

namespace App\Console\Commands;

use App\Services\BillingProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ProcessBilling extends Command
{
    protected $signature = 'billing:process {--date= : Data de processamento no formato Y-m-d}';

    protected $description = 'Gera faturas e envia os lembretes de cobrança via WhatsApp';

    public function handle(BillingProcessor $processor): int
    {
        $date      = $this->option('date');
        $processed = $processor->process($date ? CarbonImmutable::parse((string) $date) : null);

        $this->info(sprintf('%d aluno(s) processado(s).', $processed));

        return self::SUCCESS;
    }
}
