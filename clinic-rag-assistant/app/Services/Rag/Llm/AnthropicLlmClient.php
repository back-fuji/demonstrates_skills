<?php

namespace App\Services\Rag\Llm;

use App\Services\Contracts\LlmClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Anthropic Claude Messages API 実装。SSE ストリーミングでトークンを逐次コールバックする。
// 参考: POST /v1/messages, header x-api-key / anthropic-version: 2023-06-01,
//       stream:true で content_block_delta(delta.type=text_delta)を拾う。
class AnthropicLlmClient implements LlmClient
{
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $maxTokens,
    ) {}

    public function generate(string $system, string $userPrompt, ?callable $onToken = null): string
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'stream' => true,
        ];

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ])
            ->withOptions(['stream' => true])
            ->timeout(120)
            ->baseUrl($this->baseUrl)
            ->post('/v1/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'Anthropic API failed: '.$response->status().' '.$response->body()
            );
        }

        return $this->consumeStream($response->toPsrResponse()->getBody(), $onToken);
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * SSE ストリームを読み、text_delta を連結して全文を返す。
     */
    private function consumeStream($body, ?callable $onToken): string
    {
        $full = '';
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            // 完全な行を処理し、末尾の未完了分は buffer に残す
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);
                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $json = trim(substr($line, strlen('data:')));
                if ($json === '' || $json === '[DONE]') {
                    continue;
                }

                $event = json_decode($json, true);
                if (! is_array($event)) {
                    continue;
                }

                if (
                    ($event['type'] ?? null) === 'content_block_delta'
                    && (($event['delta']['type'] ?? null) === 'text_delta')
                ) {
                    $text = $event['delta']['text'] ?? '';
                    if ($text !== '') {
                        $full .= $text;
                        if ($onToken !== null) {
                            $onToken($text);
                        }
                    }
                }
            }
        }

        return $full;
    }
}
