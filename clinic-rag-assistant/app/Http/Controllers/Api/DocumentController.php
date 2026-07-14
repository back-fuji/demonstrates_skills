<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use App\Services\Rag\AnswerCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 文書管理API(管理者専用)。更新は楽観ロック(ADR-005)で同時実行を制御する。
class DocumentController extends Controller
{
    public function __construct(private readonly AnswerCache $cache) {}

    public function index(): JsonResponse
    {
        $documents = Document::query()
            ->select(['id', 'title', 'category', 'status', 'version', 'updated_at'])
            ->orderByDesc('updated_at')
            ->paginate(20);

        return response()->json($documents);
    }

    public function show(int $id): JsonResponse
    {
        $document = Document::query()->findOrFail($id);

        return response()->json([
            'id' => $document->id,
            'title' => $document->title,
            'category' => $document->category,
            'content' => $document->content,
            'status' => $document->status,
            'version' => $document->version,
            'error_message' => $document->error_message,
            'chunk_count' => $document->activeChunks()->count(),
            'updated_at' => $document->updated_at,
        ]);
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $document = Document::query()->create([
            'title' => $request->string('title'),
            'category' => $request->string('category'),
            'content' => $request->string('content'),
            'status' => Document::STATUS_PROCESSING,
            'version' => 1,
        ]);

        // 非同期で取り込み。APIは即応答(202)。
        IngestDocumentJob::dispatch($document->id, $document->version);
        $this->cache->flush();

        return response()->json([
            'document_id' => $document->id,
            'status' => $document->status,
        ], 202);
    }

    public function update(UpdateDocumentRequest $request, int $id): JsonResponse
    {
        $document = Document::query()->findOrFail($id);
        $lockEnabled = (bool) config('rag.ingest.optimistic_lock_enabled', true);

        if ($lockEnabled) {
            // If-Match 必須(楽観ロック)。欠落は 428 Precondition Required。
            $ifMatch = $this->parseIfMatch($request);
            if ($ifMatch === null) {
                return $this->problem(428, 'Precondition Required', 'If-Match ヘッダで version を指定してください。');
            }

            $newVersion = $ifMatch + 1;
            // version 一致時のみ更新。affected rows = 0 なら 409(ADR-005)。
            $affected = DB::table('documents')
                ->where('id', $id)
                ->where('version', $ifMatch)
                ->whereNull('deleted_at')
                ->update([
                    'content' => (string) $request->string('content'),
                    'title' => $request->has('title') ? (string) $request->string('title') : $document->title,
                    'category' => $request->has('category') ? (string) $request->string('category') : $document->category,
                    'version' => $newVersion,
                    'status' => Document::STATUS_PROCESSING,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                return $this->problem(
                    409,
                    'Conflict',
                    '他の管理者が先に更新しました。最新版を確認してください。'
                );
            }
        } else {
            // 楽観ロック無効(負荷試験S4の「対策なし」再現)。後勝ちで更新。
            $newVersion = $document->version + 1;
            DB::table('documents')->where('id', $id)->update([
                'content' => (string) $request->string('content'),
                'version' => $newVersion,
                'status' => Document::STATUS_PROCESSING,
                'updated_at' => now(),
            ]);
        }

        IngestDocumentJob::dispatch($id, $newVersion);
        $this->cache->flush();

        return response()->json([
            'document_id' => $id,
            'status' => Document::STATUS_PROCESSING,
            'version' => $newVersion,
        ], 202);
    }

    public function destroy(int $id): JsonResponse
    {
        $document = Document::query()->findOrFail($id);
        // 論理削除。チャンクは検索対象から即時除外(検索は deleted_at IS NULL を条件に持つ)。
        $document->delete();
        $this->cache->flush();

        return response()->json(null, 204);
    }

    private function parseIfMatch(Request $request): ?int
    {
        $header = $request->header('If-Match');
        if ($header === null || $header === '') {
            return null;
        }
        // W/"3" や "3" のような ETag 表現から数値を抽出
        if (preg_match('/(\d+)/', $header, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function problem(int $status, string $title, string $detail): JsonResponse
    {
        return response()->json([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json']);
    }
}
