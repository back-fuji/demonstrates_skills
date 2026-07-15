<?php

namespace App\Jobs;

use App\Services\Rag\AnswerCache;
use App\Services\Rag\SearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

// SWR の裏再生成ジョブ(ADR-004 / 負荷試験 Step 2)。
// stale を即返した後、このジョブが1件だけ動いてキャッシュを最新化し、再生成ロックを解放する。
class RegenerateAnswerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $question,
        public string $lockOwner,
    ) {}

    public function handle(SearchService $search, AnswerCache $cache): void
    {
        try {
            $search->regenerate($this->question);
        } catch (Throwable $e) {
            // 再生成失敗時も stale が残っているため致命ではない
            report($e);
        } finally {
            $cache->releaseRegenLock($this->question, $this->lockOwner);
        }
    }
}
