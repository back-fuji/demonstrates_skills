<?php

namespace App\Services\Eval;

use App\Models\EvalCase;
use App\Services\Contracts\LlmClient;
use App\Services\Rag\PromptRepository;

// LLM-as-a-Judge(docs/05 §2 層2)。生成回答を別のLLM呼び出しで採点する。
// JSON出力を強制し、パース失敗時は1回だけリトライする。
class Judge
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @param  list<array{content:string, section_path:string, document_title:string, score:float}>  $chunks
     * @return array{score:int, faithfulness:int, coverage:int, citation:int, missing_points:array, hallucinations:array, reason:string}
     */
    public function judge(EvalCase $case, string $answer, array $chunks): array
    {
        $system = $this->prompts->load('judge', (string) config('rag.judge_prompt_version', 'v1'));
        $userPrompt = $this->buildPrompt($case, $answer, $chunks);

        // 最大2回試行(初回 + リトライ1回)
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $raw = $this->llm->generate($system, $userPrompt);
            $parsed = $this->parse($raw);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // パース不能: 最低スコア扱いにして理由を残す(サイレント成功を避ける)
        return [
            'score' => 1,
            'faithfulness' => 1,
            'coverage' => 1,
            'citation' => 1,
            'missing_points' => [],
            'hallucinations' => [],
            'reason' => 'Judge出力のJSONパースに失敗しました。',
        ];
    }

    private function buildPrompt(EvalCase $case, string $answer, array $chunks): string
    {
        $context = '';
        foreach ($chunks as $i => $c) {
            $n = $i + 1;
            $context .= "[{$n}] {$c['document_title']} > {$c['section_path']}\n{$c['content']}\n\n";
        }
        $points = implode("\n", array_map(fn ($p) => "- {$p}", $case->expected_answer_points ?? []));

        return <<<TXT
        ## 質問
        {$case->question}

        ## 引用チャンク(コンテキスト)
        {$context}

        ## 期待される要点
        {$points}

        ## 生成された回答
        {$answer}
        TXT;
    }

    private function parse(string $raw): ?array
    {
        // コードフェンスや前後テキストを剥がして最初のJSONオブジェクトを抽出
        $raw = trim($raw);
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['score'], $data['faithfulness'])) {
            return null;
        }

        return [
            'score' => (int) $data['score'],
            'faithfulness' => (int) $data['faithfulness'],
            'coverage' => (int) ($data['coverage'] ?? $data['score']),
            'citation' => (int) ($data['citation'] ?? $data['score']),
            'missing_points' => (array) ($data['missing_points'] ?? []),
            'hallucinations' => (array) ($data['hallucinations'] ?? []),
            'reason' => (string) ($data['reason'] ?? ''),
        ];
    }
}
