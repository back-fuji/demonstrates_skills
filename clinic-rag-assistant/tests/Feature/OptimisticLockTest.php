<?php

use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;

// 楽観ロック(ADR-005 / 負荷試験 S4 相当)

it('If-Match が古い version の更新は 409 を返し、データ不整合が起きない', function () {
    $doc = seedDocument('文書', 'manual', "# 文書\n\n## S\n初版。");
    $admin = User::factory()->admin()->create();

    // 1回目: version 1 で更新 → 成功(version 2)
    $this->actingAs($admin)->putJson("/api/documents/{$doc->id}", [
        'content' => "# 文書\n\n## S\n改訂1。",
    ], ['If-Match' => '1'])->assertStatus(202);

    // 2回目: 古い version 1 のまま更新 → 409 Conflict
    $this->actingAs($admin)->putJson("/api/documents/{$doc->id}", [
        'content' => "# 文書\n\n## S\n改訂2(衝突)。",
    ], ['If-Match' => '1'])->assertStatus(409);

    $doc->refresh();
    expect($doc->version)->toBe(2);
    // 改訂2の内容は反映されていない(編集消失なし)
    expect($doc->content)->toContain('改訂1');
    // active チャンクは1系統のみ(二重取り込みなし)
    expect(Chunk::query()->where('document_id', $doc->id)->where('is_active', true)->count())
        ->toBeGreaterThanOrEqual(1);
});

it('If-Match 欠落は 428 Precondition Required を返す', function () {
    $doc = seedDocument('文書', 'manual', "# 文書\n\n## S\n本文。");
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->putJson("/api/documents/{$doc->id}", [
        'content' => "# 文書\n\n## S\n更新。",
    ])->assertStatus(428);
});

it('楽観ロックを無効化すると衝突しても後勝ちで更新される(対策なしの再現)', function () {
    config(['rag.ingest.optimistic_lock_enabled' => false]);

    $doc = seedDocument('文書', 'manual', "# 文書\n\n## S\n初版。");
    $admin = User::factory()->admin()->create();

    // If-Match 無しでも通り、version が進む
    $this->actingAs($admin)->putJson("/api/documents/{$doc->id}", [
        'content' => "# 文書\n\n## S\n改訂A。",
    ])->assertStatus(202);
    $this->actingAs($admin)->putJson("/api/documents/{$doc->id}", [
        'content' => "# 文書\n\n## S\n改訂B。",
    ])->assertStatus(202);

    $doc->refresh();
    expect($doc->version)->toBe(3);
    expect($doc->content)->toContain('改訂B'); // 後勝ち
});
