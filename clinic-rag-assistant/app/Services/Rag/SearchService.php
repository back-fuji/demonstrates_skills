<?php

namespace App\Services\Rag;

use App\Models\ChatSession;
use App\Models\Message;
use App\Models\MessageSource;
use App\Services\Contracts\EmbeddingClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// 検索・回答フローのオーケストレーション(docs/02 §2.1)。
// キャッシュ確認 → クエリembedding → ベクトル検索 → no_answer分岐 → 回答生成 → 保存。
class SearchService
{
    public function __construct(
        private readonly EmbeddingClient $embedder,
        private readonly ChunkRepository $chunks,
        private readonly AnswerGenerator $generator,
        private readonly AnswerCache $cache,
    ) {}

    /**
     * 質問に回答する。SSE配信のためのコールバックを受け取る。
     *
     * @param  callable(array):void  $onSources  引用元(sourcesイベント)を先行送信する
     * @param  callable(string):void  $onToken   生成トークンを逐次送信する
     * @return array{type:string, message_id:?string, cached:bool, latency_ms:int, sources:array, answer:string}
     */
    public function answer(string $question, ChatSession $session, callable $onSources, callable $onToken): array
    {
        $start = microtime(true);

        // ユーザー発話を保存
        Message::query()->create([
            'session_id' => $session->id,
            'role' => Message::ROLE_USER,
            'content' => $question,
        ]);

        // 1. キャッシュ確認(ADR-004)。SWR有効時は stale ヒットも扱う。
        $entry = $this->cache->getEntry($question);
        if ($entry['payload'] !== null) {
            $cached = $entry['payload'];

            // stale(期限切れだが stale 値あり)なら裏で再生成をトリガ(ロックで1件に収束)
            if ($entry['stale']) {
                $this->triggerRevalidate($question);
            }

            $onSources($cached['sources']);
            $latency = (int) round((microtime(true) - $start) * 1000);
            $message = $this->persistAssistant($session, $cached['answer'], $cached['sources'], $latency, cached: true);
            $onToken($cached['answer']); // キャッシュ時は一括返却

            return $this->result('cached', $message->id, true, $latency, $cached['sources'], $cached['answer']);
        }

        // 2. クエリembedding
        $queryEmbedding = $this->embedder->embed($question);

        // 3. ベクトル検索(しきい値未満は除外)
        $topK = (int) config('rag.top_k', 8);
        $threshold = (float) config('rag.score_threshold', 0.5);
        $hits = $this->chunks->search($queryEmbedding, $topK, $threshold);

        // 4. no_answer 分岐(根拠なし → 生成をスキップし誤答を防ぐ)
        if ($hits === []) {
            $noAnswer = (string) config('rag.no_answer_message');
            $onSources([]);
            $onToken($noAnswer);
            $latency = (int) round((microtime(true) - $start) * 1000);
            $message = $this->persistAssistant($session, $noAnswer, [], $latency, cached: false);

            return $this->result('no_answer', $message->id, false, $latency, [], $noAnswer);
        }

        // 引用元(sources)を回答生成前に先行送信(体感速度、docs/03)
        $sources = $this->toSources($hits);
        $onSources($sources);

        // 5. 回答生成(ストリーミング)。上流障害時は劣化運転(docs/02 §5)。
        try {
            $answer = $this->generator->generate($question, $hits, $onToken);
        } catch (Throwable $e) {
            Log::warning('回答生成に失敗、劣化運転で検索結果のみ返却', ['error' => $e->getMessage()]);
            $degraded = 'AI回答は一時的に利用できません。検索された関連文書のみ表示します。';
            $onToken($degraded);
            $latency = (int) round((microtime(true) - $start) * 1000);
            $message = $this->persistAssistant($session, $degraded, $sources, $latency, cached: false);

            return $this->result('degraded', $message->id, false, $latency, $sources, $degraded);
        }

        // 6. 保存 + キャッシュ
        $latency = (int) round((microtime(true) - $start) * 1000);
        $message = $this->persistAssistant($session, $answer, $sources, $latency, cached: false);
        $this->cache->put($question, [
            'answer' => $answer,
            'sources' => $sources,
            'model' => $this->generator->model(),
        ]);

        return $this->result('answer', $message->id, false, $latency, $sources, $answer);
    }

    // セッション永続化を伴わない、キャッシュ/生成のみの経路(負荷試験 bench:stampede 用)。
    // HTTP内蔵サーバの直列化を避け、複数プロセスで真の同時実行スタンピードを再現するために使う。
    public function answerHeadless(string $question): string
    {
        $entry = $this->cache->getEntry($question);
        if ($entry['payload'] !== null) {
            if ($entry['stale']) {
                $this->triggerRevalidate($question);
            }

            return 'cached';
        }

        $embedding = $this->embedder->embed($question);
        $topK = (int) config('rag.top_k', 8);
        $threshold = (float) config('rag.score_threshold', 0.5);
        $hits = $this->chunks->search($embedding, $topK, $threshold);

        if ($hits === []) {
            $this->cache->put($question, ['answer' => (string) config('rag.no_answer_message'), 'sources' => [], 'model' => $this->generator->model()]);

            return 'no_answer';
        }

        $answer = $this->generator->generate($question, $hits);
        $this->cache->put($question, [
            'answer' => $answer,
            'sources' => $this->toSources($hits),
            'model' => $this->generator->model(),
        ]);

        return 'answer';
    }

    // SWR: stale を返した後、裏でキャッシュを再生成する(ADR-004)。
    // 再生成ロックを1件だけ取得できた場合のみジョブを投入し、生成処理を1件へ収束させる。
    private function triggerRevalidate(string $question): void
    {
        $lock = $this->cache->acquireRegenLock($question);
        if ($lock === null) {
            return; // 別リクエストが既に再生成中
        }
        // ロックは owner トークンをジョブへ渡し、ジョブ完了時に解放する
        \App\Jobs\RegenerateAnswerJob::dispatch($question, $lock->owner());
    }

    // キャッシュ再生成(ジョブから呼ばれる)。ストリーミング無しで生成しキャッシュへ保存。
    public function regenerate(string $question): void
    {
        $queryEmbedding = $this->embedder->embed($question);
        $topK = (int) config('rag.top_k', 8);
        $threshold = (float) config('rag.score_threshold', 0.5);
        $hits = $this->chunks->search($queryEmbedding, $topK, $threshold);

        if ($hits === []) {
            $noAnswer = (string) config('rag.no_answer_message');
            $this->cache->put($question, ['answer' => $noAnswer, 'sources' => [], 'model' => $this->generator->model()]);

            return;
        }

        $sources = $this->toSources($hits);
        $answer = $this->generator->generate($question, $hits);
        $this->cache->put($question, [
            'answer' => $answer,
            'sources' => $sources,
            'model' => $this->generator->model(),
        ]);
    }

    /**
     * @param  list<array{chunk_id:int, content:string, section_path:string, document_title:string, score:float}>  $hits
     * @return list<array{chunk_id:int, document_title:string, section:string, score:float, rank:int}>
     */
    private function toSources(array $hits): array
    {
        $sources = [];
        foreach ($hits as $i => $h) {
            $sources[] = [
                'chunk_id' => $h['chunk_id'],
                'document_title' => $h['document_title'],
                'section' => $h['section_path'],
                'score' => round($h['score'], 4),
                'rank' => $i + 1,
            ];
        }

        return $sources;
    }

    private function persistAssistant(ChatSession $session, string $answer, array $sources, int $latency, bool $cached): Message
    {
        return DB::transaction(function () use ($session, $answer, $sources, $latency, $cached) {
            $message = Message::query()->create([
                'session_id' => $session->id,
                'role' => Message::ROLE_ASSISTANT,
                'content' => $answer,
                'latency_ms' => $latency,
                'cached' => $cached,
            ]);

            foreach ($sources as $s) {
                MessageSource::query()->create([
                    'message_id' => $message->id,
                    'chunk_id' => $s['chunk_id'] ?? null,
                    'score' => $s['score'],
                    'rank' => $s['rank'],
                ]);
            }

            return $message;
        });
    }

    private function result(string $type, ?string $messageId, bool $cached, int $latency, array $sources, string $answer): array
    {
        return [
            'type' => $type,
            'message_id' => $messageId,
            'cached' => $cached,
            'latency_ms' => $latency,
            'sources' => $sources,
            'answer' => $answer,
        ];
    }
}
