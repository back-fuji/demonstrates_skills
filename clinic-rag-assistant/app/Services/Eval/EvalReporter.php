<?php

namespace App\Services\Eval;

use App\Models\EvalResult;
use App\Models\EvalRun;

// eval_run の集計から Markdown レポートを生成する(docs/05 §4)。前回runとの差分を表示。
class EvalReporter
{
    public function render(EvalRun $run, ?EvalRun $previous): string
    {
        $cur = $run->summary ?? [];
        $prev = $previous?->summary ?? [];

        $lines = [];
        $git = $run->git_sha ?? 'unknown';
        $lines[] = "## Eval Report (run #{$run->id}, git: {$git}, prompt: {$run->prompt_version}, model: {$run->model}, mode: {$run->mode})";
        $lines[] = '';
        $lines[] = '| 指標 | 今回 | 前回 | 差分 |';
        $lines[] = '|---|---|---|---|';
        $lines[] = $this->metricRow('Recall@'.($cur['k'] ?? 8), $cur['recall_at_k'] ?? null, $prev['recall_at_k'] ?? null, higherBetter: true, decimals: 2);

        if ($run->mode === 'full') {
            $lines[] = $this->metricRow('Judge平均', $cur['judge_avg'] ?? null, $prev['judge_avg'] ?? null, higherBetter: true, decimals: 2);
            $lines[] = $this->metricRow('忠実性<3のケース', $cur['faithfulness_below_3'] ?? null, $prev['faithfulness_below_3'] ?? null, higherBetter: false, decimals: 0);
        }

        $curNa = ($cur['no_answer_correct'] ?? 0).'/'.($cur['no_answer_total'] ?? 0);
        $prevNa = isset($prev['no_answer_total']) ? ($prev['no_answer_correct'] ?? 0).'/'.$prev['no_answer_total'] : '-';
        $lines[] = "| no_answer正答率 | {$curNa} | {$prevNa} | - |";

        $lines[] = '';

        // デグレしたケース(前回比で judge_score が低下)
        if ($run->mode === 'full' && $previous !== null) {
            $regressions = $this->regressions($run, $previous);
            if ($regressions !== []) {
                $lines[] = '### デグレしたケース';
                foreach ($regressions as $r) {
                    $lines[] = "- {$r}";
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function metricRow(string $label, ?float $cur, ?float $prev, bool $higherBetter, int $decimals): string
    {
        $curStr = $cur === null ? '-' : number_format($cur, $decimals);
        $prevStr = $prev === null ? '-' : number_format($prev, $decimals);

        $diffStr = '-';
        if ($cur !== null && $prev !== null) {
            $diff = $cur - $prev;
            $sign = $diff >= 0 ? '+' : '';
            $mark = '';
            if (abs($diff) > 1e-9) {
                $improved = $higherBetter ? $diff > 0 : $diff < 0;
                $mark = $improved ? ' ✅' : ' ⚠️';
            }
            $diffStr = $sign.number_format($diff, $decimals).$mark;
        }

        return "| {$label} | {$curStr} | {$prevStr} | {$diffStr} |";
    }

    /**
     * @return list<string>
     */
    private function regressions(EvalRun $run, EvalRun $previous): array
    {
        $prevScores = EvalResult::query()
            ->where('eval_run_id', $previous->id)
            ->pluck('judge_score', 'eval_case_id');

        $out = [];
        $results = EvalResult::query()->where('eval_run_id', $run->id)->with('evalCase')->get();
        foreach ($results as $res) {
            $before = $prevScores[$res->eval_case_id] ?? null;
            if ($before !== null && $res->judge_score !== null && $res->judge_score < $before) {
                $key = $res->evalCase?->key ?? $res->eval_case_id;
                $missing = $res->missing_points ? ' missing: '.implode(', ', $res->missing_points) : '';
                $out[] = "{$key}: Judge {$before}→{$res->judge_score}.{$missing}";
            }
        }

        return $out;
    }
}
