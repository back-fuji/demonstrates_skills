<?php

use App\Services\Ingest\ChunkSplitter;
use App\Services\Ingest\TokenCounter;

// ChunkSplitter は純粋クラス。境界ケースを厚く検証する(HANDOFF 1-3)。

function makeSplitter(int $maxTokens = 500, int $overlap = 100): ChunkSplitter
{
    return new ChunkSplitter(new TokenCounter(), $maxTokens, $overlap);
}

it('空文書は空配列を返す', function () {
    expect(makeSplitter()->split(''))->toBe([]);
    expect(makeSplitter()->split("   \n  \n"))->toBe([]);
});

it('h2 見出し単位で分割し section_path に見出しを入れる', function () {
    $md = <<<'MD'
    # 予約・キャンセルポリシー

    ## 当日キャンセル
    当日キャンセルは施術料金の50%が発生します。

    ## 無断キャンセル
    無断キャンセルは施術料金の100%が発生します。
    MD;

    $chunks = makeSplitter()->split($md);

    expect($chunks)->toHaveCount(2);
    expect($chunks[0]['section_path'])->toBe('当日キャンセル');
    expect($chunks[0]['content'])->toContain('50%');
    expect($chunks[1]['section_path'])->toBe('無断キャンセル');
    expect($chunks[1]['content'])->toContain('100%');
});

it('h1 は section_path に含めず、h2 > h3 の階層を保持する', function () {
    $md = <<<'MD'
    # 施術マニュアル

    ## 予約ポリシー

    ### 当日キャンセル
    当日は50%。

    ### 無断キャンセル
    無断は100%。
    MD;

    $chunks = makeSplitter()->split($md);

    expect($chunks)->toHaveCount(2);
    expect($chunks[0]['section_path'])->toBe('予約ポリシー > 当日キャンセル');
    expect($chunks[1]['section_path'])->toBe('予約ポリシー > 無断キャンセル');
});

it('見出しの無い文書は本文セクションとして扱う', function () {
    $md = 'これは見出しの無いプレーンな本文です。検索対象にはなります。';

    $chunks = makeSplitter()->split($md);

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]['section_path'])->toBe('本文');
    expect($chunks[0]['content'])->toContain('プレーン');
});

it('h1 直下の前文は概要セクションになる', function () {
    $md = <<<'MD'
    # タイトル

    これは前文です。見出し配下ではありません。

    ## 本セクション
    本文です。
    MD;

    $chunks = makeSplitter()->split($md);

    expect($chunks[0]['section_path'])->toBe('概要');
    expect($chunks[0]['content'])->toContain('前文');
    expect($chunks[1]['section_path'])->toBe('本セクション');
});

it('maxTokens を超えるセクションは二次分割され、全チャンクが上限以内に収まる', function () {
    // 1文20文字前後 × 60行で maxTokens(50)を大きく超える巨大セクション
    $paras = [];
    for ($i = 1; $i <= 60; $i++) {
        $paras[] = "これは第{$i}段落の本文であり分割対象になります。";
    }
    $md = "# 文書\n\n## 巨大セクション\n\n".implode("\n\n", $paras);

    $splitter = makeSplitter(maxTokens: 50, overlap: 10);
    $chunks = $splitter->split($md);
    $counter = new TokenCounter();

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        // すべて同じ section_path
        expect($chunk['section_path'])->toBe('巨大セクション');
        // 上限を大きく超えない(オーバーラップ分の余裕を見て 1.5 倍まで許容)
        expect($chunk['token_count'])->toBeLessThanOrEqual((int) (50 * 1.5));
    }
});

it('二次分割時に直前チャンク末尾がオーバーラップとして次チャンク先頭へ引き継がれる', function () {
    $paras = [];
    for ($i = 1; $i <= 40; $i++) {
        $paras[] = "段落{$i}:ユニークな内容マーカー{$i}を含む本文テキストです。";
    }
    $md = "# 文書\n\n## セクション\n\n".implode("\n\n", $paras);

    $splitter = makeSplitter(maxTokens: 40, overlap: 15);
    $chunks = $splitter->split($md);

    // 各後続チャンクの先頭は、直前チャンク末尾のテキスト(オーバーラップ)で始まる
    expect(count($chunks))->toBeGreaterThan(1);
    for ($i = 1; $i < count($chunks); $i++) {
        $prev = $chunks[$i - 1]['content'];
        $curr = $chunks[$i]['content'];
        // curr 先頭の十数文字が prev 内に存在する = 末尾オーバーラップの引き継ぎ
        $head = mb_substr($curr, 0, 10);
        expect(str_contains($prev, $head))->toBeTrue();
    }
});

it('深いネスト(h4)も section_path に反映される', function () {
    $md = <<<'MD'
    # 文書

    ## A

    ### B

    #### C
    深い階層の本文。
    MD;

    $chunks = makeSplitter()->split($md);

    expect($chunks[0]['section_path'])->toBe('A > B > C');
});

it('コードフェンス内の # は見出しとして扱わない', function () {
    $md = <<<'MD'
    # 文書

    ## セクション
    説明文です。

    ```
    # これはコメントであって見出しではない
    echo hello
    ```
    MD;

    $chunks = makeSplitter()->split($md);

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]['section_path'])->toBe('セクション');
    expect($chunks[0]['content'])->toContain('コメント');
});
