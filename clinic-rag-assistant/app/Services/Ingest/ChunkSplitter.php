<?php

namespace App\Services\Ingest;

// Markdown を検索単位のチャンクへ分割する純粋クラス(ADR-002)。
// フレームワーク非依存。単体テストで境界ケースを厚く担保する。
//
// 方針:
//  - h2/h3(以降)の見出し階層で分割し、section_path に階層を保持する
//    (h1 は文書タイトル相当とみなし section_path には含めない)
//  - 1セクションが maxTokens を超える場合のみ、段落境界で二次分割し
//    直前チャンク末尾を overlapTokens ぶんオーバーラップさせる
//  - 見出しが1つも無い文書は固定長分割へフォールバック(section_path='本文')
class ChunkSplitter
{
    public function __construct(
        private readonly TokenCounter $tokenCounter,
        private readonly int $maxTokens = 500,
        private readonly int $overlapTokens = 100,
    ) {}

    /**
     * @return list<array{section_path: string, content: string, token_count: int}>
     */
    public function split(string $markdown): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        if (trim($markdown) === '') {
            return [];
        }

        $sections = $this->parseSections($markdown);

        // 見出しが1つも無い(=単一の「本文」セクションのみ)場合のフォールバック判定
        if (count($sections) === 1 && $sections[0]['section_path'] === '本文') {
            // それでも maxTokens 以内なら1チャンクで返る(下の共通処理で処理)
        }

        $chunks = [];
        foreach ($sections as $section) {
            foreach ($this->splitSection($section['content']) as $piece) {
                $trimmed = trim($piece);
                if ($trimmed === '') {
                    continue;
                }
                $chunks[] = [
                    'section_path' => $section['section_path'],
                    'content' => $trimmed,
                    'token_count' => $this->tokenCounter->count($trimmed),
                ];
            }
        }

        return $chunks;
    }

    /**
     * Markdown を見出し単位のセクションへ分解する。
     *
     * @return list<array{section_path: string, content: string}>
     */
    private function parseSections(string $markdown): array
    {
        $lines = explode("\n", $markdown);

        $sections = [];
        // 見出しスタック(レベル2以降のみ保持。index 0 が h2, 1 が h3 ...)
        $stack = [];
        $currentPath = null; // 未だ見出しに入っていない=前文
        $buffer = [];
        $hasAnyHeading = false;
        $inCodeBlock = false;

        $flush = function () use (&$sections, &$buffer, &$currentPath) {
            $body = implode("\n", $buffer);
            if (trim($body) !== '') {
                $sections[] = [
                    'section_path' => $currentPath ?? '概要',
                    'content' => $body,
                ];
            }
            $buffer = [];
        };

        foreach ($lines as $line) {
            // コードフェンス内は見出し記号を無視する
            if (preg_match('/^\s*```/', $line)) {
                $inCodeBlock = ! $inCodeBlock;
                $buffer[] = $line;

                continue;
            }

            // /u 必須(日本語見出しがバイト境界で切断されないように)
            if (! $inCodeBlock && preg_match('/^(#{1,6})\s+(.*\S)\s*$/u', $line, $m)) {
                $level = strlen($m[1]);
                $title = trim($m[2]);
                $hasAnyHeading = true;

                // 見出しに到達したら直前のバッファを確定
                $flush();

                if ($level === 1) {
                    // h1 は文書タイトル相当。section_path には積まず、下位スタックをリセット
                    $stack = [];
                    $currentPath = null;

                    continue;
                }

                // レベル2以降: スタックを (level-2) の深さに切り詰めてから push
                $depth = $level - 2;
                $stack = array_slice($stack, 0, $depth);
                $stack[$depth] = $title;
                $stack = array_values($stack);
                $currentPath = implode(' > ', $stack);

                continue;
            }

            $buffer[] = $line;
        }
        $flush();

        // 見出しが1つも無い場合は「本文」セクション1つとして扱う(固定長フォールバック対象)
        if (! $hasAnyHeading) {
            $body = trim($markdown);

            return $body === '' ? [] : [['section_path' => '本文', 'content' => $body]];
        }

        return $sections;
    }

    /**
     * 1セクションの本文を maxTokens 以内のチャンク群へ二次分割する。
     *
     * @return list<string>
     */
    private function splitSection(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        if ($this->tokenCounter->count($body) <= $this->maxTokens) {
            return [$body];
        }

        // 段落(空行区切り)単位で詰めていく
        $paragraphs = preg_split('/\n{2,}/', $body) ?: [$body];

        $chunks = [];
        $current = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }

            // 単一段落が maxTokens を超える場合はさらに強制分割する
            if ($this->tokenCounter->count($para) > $this->maxTokens) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach ($this->hardSplit($para) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            $candidate = $current === '' ? $para : $current."\n\n".$para;
            if ($this->tokenCounter->count($candidate) > $this->maxTokens && $current !== '') {
                // 現在のチャンクを確定し、末尾をオーバーラップとして次チャンクの先頭に付ける
                $chunks[] = $current;
                $overlap = $this->takeTailTokens($current, $this->overlapTokens);
                $current = $overlap === '' ? $para : $overlap."\n\n".$para;
            } else {
                $current = $candidate;
            }
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * maxTokens を超える単一段落を、句点・改行境界で強制分割する(最終手段)。
     *
     * @return list<string>
     */
    private function hardSplit(string $text): array
    {
        // 句点(。)と改行で細切れにしてから詰め直す
        $units = preg_split('/(?<=。)|\n/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];

        $chunks = [];
        $current = '';
        foreach ($units as $unit) {
            $candidate = $current.$unit;
            if ($this->tokenCounter->count($candidate) > $this->maxTokens && $current !== '') {
                $chunks[] = trim($current);
                $overlap = $this->takeTailTokens($current, $this->overlapTokens);
                $current = $overlap.$unit;
            } else {
                $current = $candidate;
            }
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /**
     * テキスト末尾から概ね n トークンぶんの文字列を取り出す(オーバーラップ用)。
     */
    private function takeTailTokens(string $text, int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $length = mb_strlen($text);
        // 末尾から文字を伸ばしながらトークン数が n に達するまで拡張する
        for ($take = 1; $take <= $length; $take++) {
            $tail = mb_substr($text, $length - $take);
            if ($this->tokenCounter->count($tail) >= $n) {
                return trim($tail);
            }
        }

        return trim($text);
    }
}
