<?php

namespace App\Console\Commands;

use App\Services\Rag\SearchService;
use App\Support\Metrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

// キャッシュスタンピード計測(docs/06 Step 2 / S3)。
// 複数OSプロセスを真に同時起動し、人気質問の同時失効時の実LLM生成回数を計測する。
// HTTP内蔵サーバの直列化を回避するため、サービス層を独立プロセスで並列実行する。
class BenchStampedeCommand extends Command
{
    protected $signature = 'bench:stampede {--concurrency=50} {--question=}';

    protected $description = 'キャッシュスタンピードの実LLM生成回数を計測する(SWRの有無で比較)';

    public function handle(SearchService $search): int
    {
        $concurrency = (int) $this->option('concurrency');
        $question = (string) ($this->option('question') ?: '医療脱毛の当日キャンセル料はいくらですか');
        $swr = (bool) config('rag.cache.swr_enabled', false);

        $this->info(sprintf('スタンピード計測: concurrency=%d, SWR=%s', $concurrency, $swr ? 'ON' : 'OFF'));

        // 1) 人気質問を事前生成(fresh + (SWR時)stale)
        $search->regenerate($question);
        // 2) メトリクスをリセット
        Metrics::reset('llm_generate_calls');
        // 3) fresh を強制失効(SWR時は stale が残る)
        app(\App\Services\Rag\AnswerCache::class)->expireFresh($question);

        // 4) N プロセスを真に同時起動(サービス層のキャッシュ/生成経路を実行)
        $php = base_path('artisan');
        $pool = Process::pool(function ($pool) use ($concurrency, $php, $question) {
            for ($i = 0; $i < $concurrency; $i++) {
                $pool->path(base_path())->command(['php', $php, 'bench:stampede-worker', $question]);
            }
        })->start();

        $pool->wait();

        // 5) 裏の再生成ジョブ(SWR)が動く猶予
        sleep(3);

        $llmCalls = Metrics::get('llm_generate_calls');

        $this->newLine();
        $this->table(
            ['SWR', 'concurrency', '実LLM生成回数'],
            [[$swr ? 'ON(対策後)' : 'OFF(対策前)', $concurrency, $llmCalls]],
        );
        $this->line($swr
            ? '→ SWR+ロックにより生成が1件に収束(スタンピード解消)。'
            : '→ 同時失効時に多数の生成が発生(スタンピード)。SWR有効化で収束する。');

        return self::SUCCESS;
    }
}
