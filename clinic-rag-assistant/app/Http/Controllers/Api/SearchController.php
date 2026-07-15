<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Models\ChatSession;
use App\Services\Rag\SearchService;
use Symfony\Component\HttpFoundation\StreamedResponse;

// 検索・回答API。SSEで sources → token → done を配信する(docs/03)。
class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    public function search(SearchRequest $request): StreamedResponse
    {
        $user = $request->user();
        $question = (string) $request->string('question');

        // セッションの解決(フォローアップ時は既存、なければ新規作成)
        $session = $this->resolveSession($request->input('session_id'), $user->id);

        return response()->stream(function () use ($question, $session) {
            $this->sendEvent('session', ['session_id' => $session->id]);

            $result = $this->search->answer(
                $question,
                $session,
                onSources: function (array $sources) {
                    $this->sendEvent('sources', ['chunks' => $sources]);
                },
                onToken: function (string $text) {
                    $this->sendEvent('token', ['text' => $text]);
                },
            );

            $eventName = $result['type'] === 'no_answer' ? 'no_answer' : 'done';
            $this->sendEvent($eventName, [
                'answer_id' => $result['message_id'],
                'cached' => $result['cached'],
                'latency_ms' => $result['latency_ms'],
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    private function resolveSession(?string $sessionId, int $userId): ChatSession
    {
        if ($sessionId !== null) {
            $session = ChatSession::query()
                ->where('id', $sessionId)
                ->where('user_id', $userId)
                ->first();
            if ($session !== null) {
                return $session;
            }
        }

        return ChatSession::query()->create(['user_id' => $userId]);
    }

    private function sendEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

        // Web配信時のみ逐次フラッシュ(テスト等のコンソール実行では出力キャプチャを壊さない)
        if (! app()->runningInConsole()) {
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        }
    }
}
