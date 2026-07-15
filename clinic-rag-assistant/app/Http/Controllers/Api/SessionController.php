<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// セッション内の会話履歴を取得する(フォローアップUI表示用、docs/03)。
class SessionController extends Controller
{
    public function messages(Request $request, string $id): JsonResponse
    {
        $session = ChatSession::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $messages = $session->messages()
            ->with('sources.chunk.document')
            ->get()
            ->map(function ($m) {
                return [
                    'id' => $m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'cached' => $m->cached,
                    'latency_ms' => $m->latency_ms,
                    'created_at' => $m->created_at,
                    'sources' => $m->sources->map(fn ($s) => [
                        'chunk_id' => $s->chunk_id,
                        'document_title' => $s->chunk?->document?->title,
                        'section' => $s->chunk?->section_path,
                        'score' => $s->score,
                        'rank' => $s->rank,
                    ]),
                ];
            });

        return response()->json(['session_id' => $session->id, 'messages' => $messages]);
    }
}
