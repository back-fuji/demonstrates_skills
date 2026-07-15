<?php

namespace App\Services\Rag\Llm;

use App\Services\Contracts\LlmClient;

// テスト・負荷試験用の決定的な LLM 実装。
// コンテキスト(userPrompt)から短い擬似回答を組み立て、必要ならトークン分割してストリーミングする。
// 実APIを呼ばないため無料・高速・再現可能。
class FakeLlmClient implements LlmClient
{
    public function __construct(
        // 負荷試験で回答生成レイテンシを再現するための固定待機(ミリ秒)。
        private readonly int $latencyMs = 0,
        private readonly string $model = 'fake-llm',
    ) {}

    public function generate(string $system, string $userPrompt, ?callable $onToken = null): string
    {
        if ($this->latencyMs > 0) {
            usleep($this->latencyMs * 1000);
        }

        // JSON 応答が求められている(Judge等)場合は妥当な JSON を返す
        if (str_contains($system, 'JSON') || str_contains($userPrompt, 'JSON')) {
            return $this->fakeJson($onToken);
        }

        $answer = $this->fakeAnswer($userPrompt);

        if ($onToken !== null) {
            // 文字単位ではなく数文字ごとに分割してストリーミングを模す
            foreach ($this->splitForStream($answer) as $piece) {
                $onToken($piece);
            }
        }

        return $answer;
    }

    public function model(): string
    {
        return $this->model;
    }

    private function fakeAnswer(string $userPrompt): string
    {
        // コンテキストの最初の非空行を引用して「それらしい」回答を作る
        $firstLine = '';
        foreach (preg_split('/\n/', $userPrompt) as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                $firstLine = mb_substr($line, 0, 60);
                break;
            }
        }

        return '【フェイク回答】提供コンテキストに基づく回答です。'.$firstLine;
    }

    private function fakeJson(?callable $onToken): string
    {
        $json = json_encode([
            'score' => 4,
            'faithfulness' => 5,
            'missing_points' => [],
            'hallucinations' => [],
            'reason' => 'フェイクJudge: コンテキストに忠実で要点を概ね網羅。',
        ], JSON_UNESCAPED_UNICODE);

        if ($onToken !== null) {
            $onToken($json);
        }

        return $json;
    }

    /**
     * @return list<string>
     */
    private function splitForStream(string $text): array
    {
        $chars = mb_str_split($text);
        $pieces = [];
        $buf = '';
        foreach ($chars as $i => $c) {
            $buf .= $c;
            if (($i + 1) % 4 === 0) {
                $pieces[] = $buf;
                $buf = '';
            }
        }
        if ($buf !== '') {
            $pieces[] = $buf;
        }

        return $pieces;
    }
}
