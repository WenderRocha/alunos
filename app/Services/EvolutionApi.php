<?php

declare(strict_types = 1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EvolutionApi
{
    public function sendText(string $phone, string $message): array
    {
        $baseUrl  = trim((string) config('services.evolution.base_url'));
        $apiKey   = trim((string) config('services.evolution.api_key'));
        $instance = trim((string) config('services.evolution.instance'));

        if ($baseUrl === '' || $apiKey === '' || $instance === '') {
            Log::error('Evolution API não está configurada para envio de mensagens.', [
                'base_url_configured' => $baseUrl !== '',
                'api_key_configured'  => $apiKey !== '',
                'instance_configured' => $instance !== '',
            ]);

            throw new RuntimeException('A Evolution API não está configurada.');
        }

        $payload = [
            'number' => $this->normalizePhone($phone),
        ];

        if (config('services.evolution.payload_format') === 'top-level') {
            $payload['text'] = $message;
        } else {
            $payload['textMessage'] = ['text' => $message];
        }

        $path = '/message/sendText/' . rawurlencode($instance);

        Log::info('Iniciando envio de mensagem pela Evolution API.', [
            'url'            => rtrim($baseUrl, '/') . $path,
            'instance'       => $instance,
            'phone'          => $payload['number'],
            'payload_keys'   => array_keys($payload),
            'message_length' => mb_strlen($message),
            'message_hash'   => hash('sha256', $message),
            'message'        => $message,
        ]);

        try {
            $response = $this->request()->post($path, $payload);
        } catch (Throwable $exception) {
            Log::error('Erro de conexão com a Evolution API.', [
                'url'       => rtrim($baseUrl, '/') . $path,
                'instance'  => $instance,
                'phone'     => $payload['number'],
                'message'   => $message,
                'exception' => $exception,
            ]);

            throw $exception;
        }

        if ($response->failed()) {
            Log::error('Evolution API rejeitou o envio da mensagem.', [
                'status'       => $response->status(),
                'url'          => rtrim($baseUrl, '/') . $path,
                'instance'     => $instance,
                'phone'        => $payload['number'],
                'payload_keys' => array_keys($payload),
                'message'      => $message,
                'response'     => $response->json() ?: $response->body(),
            ]);

            $response->throw();
        }

        $body = $response->json();

        Log::info('Evolution API aceitou o envio da mensagem.', [
            'status'   => $response->status(),
            'url'      => rtrim($baseUrl, '/') . $path,
            'instance' => $instance,
            'phone'    => $payload['number'],
            'message'  => $message,
            'response' => is_array($body) ? $body : mb_substr($response->body(), 0, 1000),
        ]);

        return is_array($body) ? $body : [];
    }

    public function configured(): bool
    {
        return trim((string) config('services.evolution.base_url')) !== ''
            && trim((string) config('services.evolution.api_key')) !== ''
            && trim((string) config('services.evolution.instance')) !== '';
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['apikey' => (string) config('services.evolution.api_key')])
            ->timeout((int) config('services.evolution.timeout', 15))
            ->baseUrl(rtrim((string) config('services.evolution.base_url'), '/'));
    }

    public static function normalizePhone(string $phone): string
    {
        $digits      = preg_replace('/\D+/', '', $phone) ?: '';
        $countryCode = preg_replace('/\D+/', '', (string) config('services.evolution.default_country_code', '55')) ?: '55';

        if (in_array(strlen($digits), [10, 11], true) && ! str_starts_with($digits, $countryCode)) {
            return $countryCode . $digits;
        }

        return $digits;
    }
}
