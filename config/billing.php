<?php

declare(strict_types = 1);

return [
    'enabled'       => env('BILLING_ENABLED', false),
    'reminder_days' => array_values(array_filter(array_map(
        static fn (string $day): int => (int) trim($day),
        explode(',', (string) env('BILLING_REMINDER_DAYS', '7,3,1')),
    ), static fn (int $day): bool => $day > 0)),
    'process_time'     => env('BILLING_PROCESS_TIME', '08:00'),
    'message_template' => "Olá! Este é um {tipo} referente ao aluno {aluno}, no valor de {valor}, com vencimento em {vencimento}.\n\nPagamento via Pix — chave: {pix}.\n{comprovante}\n\nSe você já realizou o pagamento, desconsidere esta mensagem.",
];
