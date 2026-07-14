<?php

use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;

// 取り込みパイプライン(HANDOFF 1-3)

it('管理者は文書を作成でき、同期取り込みで completed になる', function () {
    // QUEUE_CONNECTION=sync のため dispatch は即実行される
    $admin = User::factory()->admin()->create();

    $content = <<<'MD'
    # 予約・キャンセルポリシー

    <!-- category: policy -->

    ## 当日キャンセル
    当日キャンセルは施術料金の50%が発生します。

    ## 無断キャンセル
    無断キャンセルは施術料金の100%が発生します。
    MD;

    $response = $this->actingAs($admin)->postJson('/api/documents', [
        'title' => '予約・キャンセルポリシー',
        'category' => 'policy',
        'content' => $content,
    ]);

    $response->assertStatus(202)->assertJsonPath('status', 'processing');

    $document = Document::query()->latest('id')->first();
    expect($document->status)->toBe(Document::STATUS_COMPLETED);
    expect(Chunk::query()->where('document_id', $document->id)->where('is_active', true)->count())
        ->toBeGreaterThanOrEqual(2);
});

it('文書更新で旧チャンクが無効化され新チャンクに置き換わる', function () {
    $doc = seedDocument('文書A', 'manual', "# 文書A\n\n## S1\n初版の本文です。");
    $firstChunkIds = Chunk::query()->where('document_id', $doc->id)->pluck('id')->all();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->putJson("/api/documents/{$doc->id}", [
        'content' => "# 文書A\n\n## S1\n改訂版の本文です。\n\n## S2\n追加セクション。",
    ], ['If-Match' => (string) $doc->version])->assertStatus(202);

    // 旧チャンクは is_active=false、新チャンクが active
    foreach ($firstChunkIds as $id) {
        expect(Chunk::query()->find($id)->is_active)->toBeFalse();
    }
    expect(Chunk::query()->where('document_id', $doc->id)->where('is_active', true)->count())
        ->toBeGreaterThanOrEqual(2);
});

it('スタッフは文書管理APIにアクセスできない(403)', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->getJson('/api/documents')->assertStatus(403);
    $this->actingAs($staff)->postJson('/api/documents', [
        'title' => 'x', 'category' => 'manual', 'content' => '# x',
    ])->assertStatus(403);
});
