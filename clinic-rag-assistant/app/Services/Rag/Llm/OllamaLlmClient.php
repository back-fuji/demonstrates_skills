<?php

namespace App\Services\Rag\Llm;

use App\Services\Contracts\LlmClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// ローカルLLM(Ollama)実装。外部課金ゼロ・オフライン・データ非送出で本物の回答を生成する。
// Ollama /api/chat は NDJSON(1行1JSON)でストリーミングする: {"message":{"content":"..."},"done":false}
class OllamaLlmClient implements LlmClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {}

    public function generate(string $system, string $userPrompt, ?callable $onToken = null): string
    {
        $response = Http::baseUrl($this->baseUrl)
            ->timeout(300)
            ->withOptions(['stream' => true])
            ->post('/api/chat', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'stream' => true,
                'options' => ['num_predict' => $this->maxTokens],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Ollama API failed: '.$response->status().' '.$response->body());
        }

        return $this->consumeStream($response->toPsrResponse()->getBody(), $onToken);
    }

    public function model(): string
    {
        return 'ollama:'.$this->model;
    }

    private function consumeStream($body, ?callable $onToken): string
    {
        $full = '';
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '') {
                    continue;
                }

                $event = json_decode($line, true);
                if (! is_array($event)) {
                    continue;
                }

                $text = $event['message']['content'] ?? '';
                if ($text !== '') {
                    $full .= $text;
                    if ($onToken !== null) {
                        $onToken($text);
                    }
                }
            }
        }

        return $full;
    }
}
