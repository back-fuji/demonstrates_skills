<?php

use App\Models\EvalCase;
use App\Models\EvalResult;
use App\Models\EvalRun;

// 評価ハーネス(Phase 2 / docs/05)

it('eval:run --only=retrieval が Recall を計測し run/results を保存する', function () {
    config(['rag.score_threshold' => 0.1]);

    // 期待チャンクと一致する文書をシード
    seedDocument(
        '予約・キャンセルポリシー',
        'policy',
        "# 予約・キャンセルポリシー\n\n## 当日キャンセル\n当日キャンセルは施術料金の50%が発生します。"
    );

    // ゴールデンケース(expected_chunk_keys は「文書タイトル#section_path」)
    EvalCase::query()->create([
        'key' => 'policy_test',
        'question' => '当日キャンセルのキャンセル料は',
        'category' => 'policy',
        'expected_chunk_keys' => ['予約・キャンセルポリシー#当日キャンセル'],
        'expected_answer_points' => ['施術料金の50%'],
        'is_active' => true,
    ]);
    // no_answer ケース
    EvalCase::query()->create([
        'key' => 'no_answer_test',
        'question' => '他院の料金を教えてください',
        'category' => 'no_answer',
        'expected_chunk_keys' => [],
        'expected_answer_points' => ['該当文書が見つからない'],
        'is_active' => true,
    ]);

    $this->artisan('eval:run --only=retrieval')->assertExitCode(0);

    $run = EvalRun::query()->latest('id')->first();
    expect($run)->not->toBeNull();
    expect($run->mode)->toBe('retrieval');
    expect((float) $run->summary['recall_at_k'])->toBe(1.0); // policyケースの期待チャンクを検索できている
    expect($run->summary['no_answer_total'])->toBe(1);

    expect(EvalResult::query()->where('eval_run_id', $run->id)->count())->toBe(2);
});

it('フル評価では Judge スコアが記録される', function () {
    config(['rag.score_threshold' => 0.1]);
    seedDocument('FAQ', 'faq', "# FAQ\n\n## 飲酒\nボトックス後の飲酒は24時間控えてください。");

    EvalCase::query()->create([
        'key' => 'fact_test',
        'question' => 'ボトックス後の飲酒はいつから',
        'category' => 'fact',
        'expected_chunk_keys' => ['FAQ#飲酒'],
        'expected_answer_points' => ['24時間'],
        'is_active' => true,
    ]);

    $this->artisan('eval:run')->assertExitCode(0);

    $result = EvalResult::query()->latest('id')->first();
    expect($result->judge_score)->not->toBeNull();
    expect($result->answer)->not->toBeNull();

    $run = EvalRun::query()->latest('id')->first();
    expect($run->mode)->toBe('full');
    expect($run->summary['judge_avg'])->not->toBeNull();
});
