<?php

namespace App\Services\Rag;

use App\Services\Contracts\LlmClient;
use App\Support\Metrics;

// 検索チャンクをコンテキストに Claude で回答を生成する(docs/02 §2.1 手順6)。
// システムプロンプトは resources/prompts/answer_{version}.md で外部管理する。
class AnswerGenerator
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @param  list<array{chunk_id:int, content:string, section_path:string, document_title:string, score:float}>  $chunks
     * @param  callable(string):void|null  $onToken  トークン逐次コールバック(SSE用)
     */
    public function generate(string $question, array $chunks, ?callable $onToken = null): string
    {
        $system = $this->prompts->load('answer', (string) config('rag.answer_prompt_version', 'v1'));
        $userPrompt = $this->buildUserPrompt($question, $chunks);

        // LLM 生成回数(キャッシュ効果・スタンピード対策の直接指標、docs/06 §4)
        Metrics::increment('llm_generate_calls');

        return $this->llm->generate($system, $userPrompt, $onToken);
    }

    public function model(): string
    {
        return $this->llm->model();
    }

    /**
     * @param  list<array{content:string, section_path:string, document_title:string, score:float}>  $chunks
     */
    private function buildUserPrompt(string $question, array $chunks): string
    {
        $lines = ['## コンテキスト', ''];
        foreach ($chunks as $i => $c) {
            $n = $i + 1;
            $score = number_format($c['score'], 2);
            $lines[] = "[{$n}] {$c['document_title']} > {$c['section_path']} (類似度 {$score})";
            $lines[] = $c['content'];
            $lines[] = '';
        }
        $lines[] = '## 質問';
        $lines[] = $question;

        return implode("\n", $lines);
    }
}
