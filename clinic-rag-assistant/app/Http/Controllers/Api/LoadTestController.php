<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Contracts\EmbeddingClient;
use App\Services\Rag\AnswerCache;
use App\Services\Rag\ChunkRepository;
use App\Services\Rag\SearchService;
use App\Support\Metrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 負荷試験用の補助エンドポイント(docs/06)。**非本番環境でのみ**ルート登録される。
// k6 から認証なしで実処理を叩き、キャッシュ効果・スタンピード・LLM呼び出し数を計測する。
class LoadTestController extends Controller
{
    public function __construct(
        private readonly SearchService $search,
        private readonly EmbeddingClient $embedder,
        private readonly ChunkRepository $chunks,
        private readonly AnswerCache $cache,
    ) {}

    // 全体パス(embedding→検索→(キャッシュ/生成))。全体レイテンシ計測用。
    public function search(Request $request): JsonResponse
    {
        $question = (string) $request->input('question', '');
        $session = $this->loadTestSession();

        $result = $this->search->answer(
            $question,
            $session,
            onSources: fn () => null,
            onToken: fn () => null,
        );

        return response()->json([
            'type' => $result['type'],
            'cached' => $result['cached'],
            'latency_ms' => $result['latency_ms'],
        ]);
    }

    // 検索のみ(embedding + ベクトル検索)。検索単体の p95 計測用。
    public function retrieve(Request $request): JsonResponse
    {
        $question = (string) $request->input('question', '');
        $start = microtime(true);
        $embedding = $this->embedder->embed($question);
        $hits = $this->chunks->search(
            $embedding,
            (int) config('rag.top_k', 8),
            (float) config('rag.score_threshold', 0.5),
        );

        return response()->json([
            'hits' => count($hits),
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);
    }

    // 質問のキャッシュを事前生成(S3 準備: fresh+stale を作る)
    public function warm(Request $request): JsonResponse
    {
        $question = (string) $request->input('question', '');
        $this->search->regenerate($question);

        return response()->json(['warmed' => true]);
    }

    // fresh キャッシュを強制失効(S3: 人気質問の期限切れを再現。SWR時は stale が残る)
    public function expireCache(Request $request): JsonResponse
    {
        $question = (string) $request->input('question', '');
        $this->cache->expireFresh($question);

        return response()->json(['expired' => true]);
    }

    // S4: 同一文書への並列更新。楽観ロック経路(DocumentController.update と同じSQL)を
    // 認証なしで叩き、202/409 の分布とデータ不整合の有無を計測する。
    public function updateDoc(Request $request): JsonResponse
    {
        $id = (int) $request->input('document_id');
        $expected = (int) $request->input('expected_version');
        $lockEnabled = (bool) config('rag.ingest.optimistic_lock_enabled', true);

        if ($lockEnabled) {
            $affected = \Illuminate\Support\Facades\DB::table('documents')
                ->where('id', $id)->where('version', $expected)->whereNull('deleted_at')
                ->update(['version' => $expected + 1, 'status' => 'processing', 'updated_at' => now()]);

            return $affected === 0
                ? response()->json(['status' => 409], 409)
                : response()->json(['status' => 202], 202);
        }

        // 対策なし(後勝ち)
        \Illuminate\Support\Facades\DB::table('documents')->where('id', $id)
            ->update(['version' => \Illuminate\Support\Facades\DB::raw('version + 1'), 'status' => 'processing', 'updated_at' => now()]);

        return response()->json(['status' => 202], 202);
    }

    public function metrics(): JsonResponse
    {
        return response()->json([
            'llm_generate_calls' => Metrics::get('llm_generate_calls'),
        ]);
    }

    public function resetMetrics(): JsonResponse
    {
        Metrics::reset('llm_generate_calls');

        return response()->json(['reset' => true]);
    }

    private function loadTestSession(): ChatSession
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'loadtest@example.com'],
            ['name' => '負荷試験', 'password' => bcrypt('password'), 'role' => User::ROLE_STAFF],
        );

        return ChatSession::query()->create(['user_id' => $user->id]);
    }
}
