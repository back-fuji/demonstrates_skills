<?php

use App\Models\Message;
use App\Models\User;

// 検索・回答フロー(HANDOFF 1-5)

beforeEach(function () {
    // Fake embedding の類似度でも確実にヒットさせるためしきい値を下げる
    config(['rag.score_threshold' => 0.1]);
});

it('質問に対し sources → token → done を SSE 配信し、引用元を含む', function () {
    seedDocument(
        '予約・キャンセルポリシー',
        'policy',
        "# 予約・キャンセルポリシー\n\n## 当日キャンセル\n当日キャンセルは施術料金の50%が発生します。"
    );

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/api/search', [
        'question' => '当日キャンセルのキャンセル料はいくらですか',
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');

    $events = parseSse($response->streamedContent());
    $names = array_column($events, 'event');

    expect($names)->toContain('sources');
    expect($names)->toContain('token');
    expect($names)->toContain('done');

    // sources に文書タイトルが含まれる
    $sources = collect($events)->firstWhere('event', 'sources')['data']['chunks'];
    expect($sources)->not->toBeEmpty();
    expect($sources[0]['document_title'])->toBe('予約・キャンセルポリシー');

    // assistant メッセージが保存されている
    expect(Message::query()->where('role', 'assistant')->count())->toBe(1);
});

it('根拠が無い質問には no_answer を返し、生成をスキップする', function () {
    // コーパスが空 → 検索ヒット0 → no_answer
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/api/search', [
        'question' => '存在しない他院の料金を教えてください',
    ]);

    $response->assertOk();
    $events = parseSse($response->streamedContent());
    $names = array_column($events, 'event');

    expect($names)->toContain('no_answer');
    // no_answer では引用元は空
    $sources = collect($events)->firstWhere('event', 'sources')['data']['chunks'];
    expect($sources)->toBe([]);
});

it('同一質問の2回目はキャッシュから即返却される(cached=true)', function () {
    seedDocument('文書', 'faq', "# 文書\n\n## Q\nボトックス後の飲酒は施術後24時間控えてください。");
    $user = User::factory()->create();

    $q = ['question' => 'ボトックス後の飲酒はいつから可能ですか'];

    $first = $this->actingAs($user)->post('/api/search', $q);
    $firstEvents = parseSse($first->streamedContent());
    expect(collect($firstEvents)->firstWhere('event', 'done')['data']['cached'])->toBeFalse();

    $second = $this->actingAs($user)->post('/api/search', $q);
    $secondEvents = parseSse($second->streamedContent());
    expect(collect($secondEvents)->firstWhere('event', 'done')['data']['cached'])->toBeTrue();
});
