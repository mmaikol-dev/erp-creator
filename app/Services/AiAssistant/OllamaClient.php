<?php

namespace App\Services\AiAssistant;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

class OllamaClient
{
    public function __construct(private HttpFactory $http)
    {
        //
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, model: string}
     */
    public function chat(string $model, array $messages): array
    {
        $response = $this->http
            ->baseUrl((string) config('ai-assistant.ollama.base_url'))
            ->connectTimeout(3)
            ->timeout($this->chatTimeout())
            ->post('/api/chat', [
                'model' => $model,
                'stream' => false,
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->httpError('chat', $response->body()));
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();
        $content = data_get($payload, 'message.content');

        if (! is_string($content) || $content === '') {
            throw new RuntimeException('Ollama chat response did not include message content.');
        }

        $responseModel = data_get($payload, 'model');

        return [
            'content' => $content,
            'model' => is_string($responseModel) ? $responseModel : $model,
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return \Generator<int, string>
     */
    public function streamChat(string $model, array $messages): \Generator
    {
        $response = $this->http
            ->baseUrl((string) config('ai-assistant.ollama.base_url'))
            ->connectTimeout(3)
            ->timeout($this->chatTimeout())
            ->withOptions(['stream' => true])
            ->post('/api/chat', [
                'model' => $model,
                'stream' => true,
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->httpError('stream chat', $response->body()));
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $lastReadAt = microtime(true);
        $maxIdleSeconds = min(30, max(5, (int) floor($this->chatTimeout() / 3)));

        while (! $body->eof()) {
            $chunk = $body->read(1024);

            if ($chunk === '') {
                if ((microtime(true) - $lastReadAt) >= $maxIdleSeconds) {
                    throw new RuntimeException("Ollama stream stalled for {$maxIdleSeconds} seconds.");
                }

                usleep(10_000);
                continue;
            }

            $lastReadAt = microtime(true);
            $buffer .= $chunk;

            while (($newlinePosition = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePosition));
                $buffer = substr($buffer, $newlinePosition + 1);

                if ($line === '') {
                    continue;
                }

                /** @var array<string, mixed>|null $payload */
                $payload = json_decode($line, true);

                if (! is_array($payload)) {
                    continue;
                }

                $content = data_get($payload, 'message.content');

                if (is_string($content) && $content !== '') {
                    yield $content;
                }

                if ((bool) data_get($payload, 'done', false) === true) {
                    return;
                }
            }
        }

        $trailing = trim($buffer);

        if ($trailing !== '') {
            /** @var array<string, mixed>|null $payload */
            $payload = json_decode($trailing, true);

            if (is_array($payload)) {
                $content = data_get($payload, 'message.content');

                if (is_string($content) && $content !== '') {
                    yield $content;
                }
            }
        }
    }

    /**
     * @return list<float>
     */
    public function embedding(string $model, string $input): array
    {
        try {
            $embedResponse = $this->http
                ->baseUrl((string) config('ai-assistant.ollama.base_url'))
                ->connectTimeout(3)
                ->timeout($this->embeddingTimeout())
                ->post('/api/embed', [
                    'model' => $model,
                    'input' => $input,
                ]);

            if ($embedResponse->successful()) {
                /** @var array<string, mixed> $payload */
                $payload = $embedResponse->json();
                $embeddings = data_get($payload, 'embeddings.0');

                if (is_array($embeddings) && $embeddings !== []) {
                    /** @var list<float> $vector */
                    $vector = array_map('floatval', $embeddings);

                    return $vector;
                }
            }
        } catch (RequestException) {
            // Fall through to /api/embeddings endpoint.
        }

        $legacyResponse = $this->http
            ->baseUrl((string) config('ai-assistant.ollama.base_url'))
            ->connectTimeout(3)
            ->timeout($this->embeddingTimeout())
            ->post('/api/embeddings', [
                'model' => $model,
                'prompt' => $input,
            ]);

        if ($legacyResponse->failed()) {
            throw new RuntimeException($this->httpError('embedding', $legacyResponse->body()));
        }

        /** @var array<string, mixed> $legacyPayload */
        $legacyPayload = $legacyResponse->json();
        $embedding = data_get($legacyPayload, 'embedding');

        if (! is_array($embedding) || $embedding === []) {
            throw new RuntimeException('Ollama embedding response did not include a vector.');
        }

        /** @var list<float> $vector */
        $vector = array_map('floatval', $embedding);

        return $vector;
    }

    private function httpError(string $operation, string $body): string
    {
        $trimmedBody = trim($body);

        if ($trimmedBody === '') {
            return "Ollama {$operation} request failed without response body.";
        }

        return "Ollama {$operation} request failed: {$trimmedBody}";
    }

    private function chatTimeout(): int
    {
        $configured = (int) config('ai-assistant.ollama.timeout', 90);

        return min(120, max(15, $configured));
    }

    private function embeddingTimeout(): int
    {
        $configured = (int) config('ai-assistant.ollama.timeout', 90);

        return min(45, max(8, $configured));
    }
}
