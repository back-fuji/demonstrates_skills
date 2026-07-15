<?php

namespace App\Services\Ingest;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Contracts\EmbeddingClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// 文書取り込みのオーケストレーション(docs/02 §2.2)。
// チャンク分割 → embedding バッチ生成 → 旧チャンク無効化 + 新チャンク一括INSERT を
// トランザクション内で原子的に行う。楽観ロック(ADR-005)で古いジョブの上書きを防ぐ。
class DocumentIngestor
{
    public function __construct(
        private readonly ChunkSplitter $splitter,
        private readonly EmbeddingClient $embedder,
    ) {}

    /**
     * @param  int  $expectedVersion  ジョブ投入時点の document.version。実行時に version が
     *                                進んでいたら、より新しい編集が存在するため自身を破棄する。
     */
    public function ingest(Document $document, int $expectedVersion): void
    {
        // 楽観ロック(ADR-005): version が進んでいたら新しいジョブに任せて破棄
        $lockEnabled = (bool) config('rag.ingest.optimistic_lock_enabled', true);
        $fresh = Document::query()->find($document->id);
        if ($fresh === null) {
            return; // 削除済み
        }
        if ($lockEnabled && $fresh->version !== $expectedVersion) {
            Log::info('取り込みジョブを破棄: version不一致', [
                'document_id' => $document->id,
                'expected' => $expectedVersion,
                'current' => $fresh->version,
            ]);

            return;
        }

        try {
            $chunks = $this->splitter->split($fresh->content);

            // 各チャンクに「文書タイトル + セクションパス」を文脈付与してから埋め込む(ADR-002)
            $inputs = array_map(
                fn (array $c) => $this->buildEmbeddingInput($fresh->title, $c['section_path'], $c['content']),
                $chunks
            );
            $embeddings = $inputs === [] ? [] : $this->embedder->embedBatch($inputs);

            DB::transaction(function () use ($fresh, $expectedVersion, $lockEnabled, $chunks, $embeddings) {
                // トランザクション内で version を再確認(FOR UPDATE で行ロック)
                $locked = Document::query()->whereKey($fresh->id)->lockForUpdate()->first();
                if ($locked === null) {
                    return;
                }
                if ($lockEnabled && $locked->version !== $expectedVersion) {
                    return; // 競合。破棄
                }

                // 旧チャンクを無効化(文書更新時。新規時は0件)
                Chunk::query()->where('document_id', $fresh->id)->update(['is_active' => false]);

                // 新チャンクを一括INSERT
                foreach ($chunks as $i => $c) {
                    Chunk::query()->create([
                        'document_id' => $fresh->id,
                        'section_path' => $c['section_path'],
                        'content' => $c['content'],
                        'token_count' => $c['token_count'],
                        'is_active' => true,
                        'embedding' => Chunk::toVectorLiteral($embeddings[$i]),
                    ]);
                }

                $locked->update([
                    'status' => Document::STATUS_COMPLETED,
                    'error_message' => null,
                ]);
            });
        } catch (Throwable $e) {
            // 失敗時は status=failed + エラー内容を記録(リトライ可能に)
            Document::query()->whereKey($document->id)->update([
                'status' => Document::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            throw $e; // ジョブのリトライ機構に委ねる
        }
    }

    private function buildEmbeddingInput(string $title, string $sectionPath, string $content): string
    {
        return "文書タイトル: {$title}\nセクション: {$sectionPath}\n\n{$content}";
    }
}
