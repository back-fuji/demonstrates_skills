<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Ingest\DocumentIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

// 文書取り込みの非同期ジョブ(docs/02 §2.2)。指数バックオフでリトライする。
class IngestDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $documentId,
        // ジョブ投入時点の version。実行時に version が進んでいたら破棄(ADR-005)。
        public int $expectedVersion,
    ) {}

    // 指数バックオフ: 10秒 → 30秒 → 60秒
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(DocumentIngestor $ingestor): void
    {
        $document = Document::query()->find($this->documentId);
        if ($document === null) {
            return; // 削除済み
        }

        $ingestor->ingest($document, $this->expectedVersion);
    }

    // 最終的に失敗した場合の後処理
    public function failed(\Throwable $e): void
    {
        Document::query()->whereKey($this->documentId)->update([
            'status' => Document::STATUS_FAILED,
            'error_message' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
