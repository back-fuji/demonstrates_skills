<?php

namespace App\Console\Commands;

use App\Services\Rag\SearchService;
use Illuminate\Console\Command;

// bench:stampede から並列起動される単一ワーカー(内部用)。
// キャッシュ/生成経路(answerHeadless)を1回実行し、type を出力する。
class BenchStampedeWorkerCommand extends Command
{
    protected $signature = 'bench:stampede-worker {question}';

    protected $description = '(内部)スタンピード計測用ワーカー';

    protected $hidden = true;

    public function handle(SearchService $search): int
    {
        $type = $search->answerHeadless((string) $this->argument('question'));
        $this->line($type);

        return self::SUCCESS;
    }
}
