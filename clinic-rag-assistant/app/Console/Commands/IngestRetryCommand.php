<?php

namespace App\Console\Commands;

use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use Illuminate\Console\Command;

// 失敗した取り込みの再実行(docs/03)。
class IngestRetryCommand extends Command
{
    protected $signature = 'ingest:retry {document_id}';

    protected $description = '失敗した文書取り込みを再実行する';

    public function handle(): int
    {
        $id = (int) $this->argument('document_id');
        $document = Document::query()->find($id);
        if ($document === null) {
            $this->error("文書が見つかりません: {$id}");

            return self::FAILURE;
        }

        $document->update(['status' => Document::STATUS_PROCESSING, 'error_message' => null]);
        IngestDocumentJob::dispatch($document->id, $document->version);
        $this->info("再取り込みをキュー投入しました: document_id={$id}, version={$document->version}");

        return self::SUCCESS;
    }
}
