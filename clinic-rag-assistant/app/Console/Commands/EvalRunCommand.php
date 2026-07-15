<?php

namespace App\Console\Commands;

use App\Models\EvalCase;
use App\Models\EvalResult;
use App\Models\EvalRun;
use App\Services\Contracts\EmbeddingClient;
use App\Services\Eval\EvalReporter;
use App\Services\Eval\Judge;
use App\Services\Rag\AnswerGenerator;
use App\Services\Rag\ChunkRepository;
use Illuminate\Console\Command;

// 評価ハーネス本体(docs/05 §4)。
// 層1: Recall@k(決定的・高速・安価)。層2: LLM-as-a-Judge(--only=retrieval でスキップ)。
class EvalRunCommand extends Command
{
    protected $signature = 'eval:run {--only= : retrieval を指定すると層1のみ(LLM呼び出しなし)}';

    protected $description = 'ゴールデンセットで回帰テストを実行しレポートを出力する';

    // CI しきい値(docs/05 §4)
    private const RECALL_THRESHOLD = 0.85;

    public function handle(
        EmbeddingClient $embedder,
        ChunkRepository $chunks,
        AnswerGenerator $generator,
        Judge $judge,
        EvalReporter $reporter,
    ): int {
        $retrievalOnly = $this->option('only') === 'retrieval';
        $mode = $retrievalOnly ? 'retrieval' : 'full';

        $cases = EvalCase::query()->where('is_active', true)->orderBy('key')->get();
        if ($cases->isEmpty()) {
            $this->error('eval_cases が空です。先に `php artisan eval:seed` を実行してください。');

            return self::FAILURE;
        }

        $k = (int) config('rag.top_k', 8);
        $threshold = (float) config('rag.score_threshold', 0.5);

        $run = EvalRun::query()->create([
            'git_sha' => $this->gitSha(),
            'prompt_version' => (string) config('rag.answer_prompt_version', 'v1'),
            'model' => $retrievalOnly ? $embedder->dimensions().'d-embedding' : $generator->model(),
            'mode' => $mode,
            'started_at' => now(),
        ]);

        $recalls = [];
        $judgeScores = [];
        $faithBelow3 = 0;
        $noAnswerCorrect = 0;
        $noAnswerTotal = 0;

        $bar = $this->output->createProgressBar($cases->count());
        $bar->start();

        foreach ($cases as $case) {
            $start = microtime(true);
            $embedding = $embedder->embed($case->question);

            // 層1: recall 用に閾値無視で上位k件を取得
            $topHits = $chunks->search($embedding, $k, -1.0);
            $retrievedKeys = array_map(fn ($h) => $h['document_title'].'#'.$h['section_path'], $topHits);

            // 実システム挙動(閾値適用)。no_answer 判定に使う。
            $thresholdedHits = array_values(array_filter($topHits, fn ($h) => $h['score'] >= $threshold));

            $isNoAnswer = $case->category === EvalCase::CATEGORY_NO_ANSWER;
            $recallHit = null;
            $recall = null;

            if (! $isNoAnswer) {
                $expected = $case->expected_chunk_keys ?? [];
                if ($expected !== []) {
                    $matched = count(array_intersect($expected, $retrievedKeys));
                    $recall = $matched / count($expected);
                    $recallHit = abs($recall - 1.0) < 1e-9;
                    $recalls[] = $recall;
                }
            } else {
                $noAnswerTotal++;
                if ($thresholdedHits === []) {
                    $noAnswerCorrect++;
                }
            }

            // 層2: 回答生成 + Judge
            $answer = null;
            $judgeScore = null;
            $faith = null;
            $reason = null;
            $missing = null;
            $halluc = null;

            if (! $retrievalOnly) {
                if ($thresholdedHits === []) {
                    $answer = (string) config('rag.no_answer_message');
                } else {
                    $answer = $generator->generate($case->question, $thresholdedHits);
                }

                if ($isNoAnswer) {
                    // no_answer は決定的に採点(断り文言を返せていれば満点)
                    $correct = $thresholdedHits === [];
                    $judgeScore = $correct ? 5 : 1;
                    $faith = $correct ? 5 : 1;
                    $reason = $correct ? '正しく回答を辞退' : '根拠なしに回答した可能性';
                } else {
                    $j = $judge->judge($case, $answer, $thresholdedHits);
                    $judgeScore = $j['score'];
                    $faith = $j['faithfulness'];
                    $reason = $j['reason'];
                    $missing = $j['missing_points'];
                    $halluc = $j['hallucinations'];
                    $judgeScores[] = $judgeScore;
                    if ($faith < 3) {
                        $faithBelow3++;
                    }
                }
            }

            EvalResult::query()->create([
                'eval_run_id' => $run->id,
                'eval_case_id' => $case->id,
                'retrieved_chunk_keys' => $retrievedKeys,
                'recall_hit' => $recallHit,
                'judge_score' => $judgeScore,
                'faithfulness_score' => $faith,
                'judge_reason' => $reason,
                'missing_points' => $missing,
                'hallucinations' => $halluc,
                'answer' => $answer,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);

            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $recallAtK = $recalls === [] ? null : array_sum($recalls) / count($recalls);
        $judgeAvg = $judgeScores === [] ? null : array_sum($judgeScores) / count($judgeScores);

        $run->update([
            'finished_at' => now(),
            'summary' => [
                'k' => $k,
                'recall_at_k' => $recallAtK,
                'judge_avg' => $judgeAvg,
                'faithfulness_below_3' => $faithBelow3,
                'no_answer_correct' => $noAnswerCorrect,
                'no_answer_total' => $noAnswerTotal,
                'case_count' => $cases->count(),
            ],
        ]);

        // レポート出力(前回runとの差分)
        $previous = EvalRun::query()
            ->where('id', '<', $run->id)
            ->where('mode', $mode)
            ->orderByDesc('id')
            ->first();

        $report = $reporter->render($run, $previous);
        $this->line($report);

        $dir = storage_path('app/eval-reports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents("{$dir}/run-{$run->id}.md", $report);
        file_put_contents("{$dir}/latest.md", $report);

        // CI しきい値ゲート
        $failed = false;
        if ($recallAtK !== null && $recallAtK < self::RECALL_THRESHOLD) {
            $this->error(sprintf('Recall@%d が閾値割れ: %.2f < %.2f', $k, $recallAtK, self::RECALL_THRESHOLD));
            $failed = true;
        }
        if (! $retrievalOnly && $faithBelow3 > 0) {
            $this->error("忠実性<3のケースが {$faithBelow3} 件あります。");
            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function gitSha(): ?string
    {
        $sha = @shell_exec('git rev-parse --short HEAD 2>/dev/null');

        return $sha ? trim($sha) : null;
    }
}
