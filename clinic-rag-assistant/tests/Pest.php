<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// テスト用: 文書を作成し Fake embedding で取り込み(completed 状態)にする。
function seedDocument(string $title, string $category, string $content): App\Models\Document
{
    $document = App\Models\Document::query()->create([
        'title' => $title,
        'category' => $category,
        'content' => $content,
        'status' => App\Models\Document::STATUS_PROCESSING,
        'version' => 1,
    ]);
    app(App\Services\Ingest\DocumentIngestor::class)->ingest($document, 1);

    return $document->refresh();
}

// SSE ストリームを event => data(配列) の連想に展開する。
function parseSse(string $body): array
{
    $events = [];
    foreach (explode("\n\n", trim($body)) as $frame) {
        if (! preg_match('/event:\s*(\S+)\s*\ndata:\s*(.+)/s', $frame, $m)) {
            continue;
        }
        $events[] = ['event' => $m[1], 'data' => json_decode(trim($m[2]), true)];
    }

    return $events;
}
