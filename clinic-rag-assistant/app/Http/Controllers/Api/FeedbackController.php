<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeedbackRequest;
use App\Models\Feedback;
use App\Models\Message;
use Illuminate\Http\JsonResponse;

// 回答(assistantメッセージ)への 👍/👎 フィードバック(docs/03)。
// 評価ハーネスのゴールデンセット候補収集にも使う。
class FeedbackController extends Controller
{
    public function store(FeedbackRequest $request, string $answerId): JsonResponse
    {
        // answer_id は assistant メッセージの ULID
        $message = Message::query()
            ->where('id', $answerId)
            ->where('role', Message::ROLE_ASSISTANT)
            ->firstOrFail();

        $feedback = Feedback::query()->updateOrCreate(
            ['message_id' => $message->id, 'user_id' => $request->user()->id],
            ['rating' => $request->string('rating'), 'comment' => $request->input('comment')],
        );

        return response()->json(['feedback_id' => $feedback->id], 201);
    }
}
