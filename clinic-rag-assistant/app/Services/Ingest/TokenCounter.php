<?php

namespace App\Services\Ingest;

// トークン数の概算(チャンク分割の閾値判定用)。
// 正確なトークナイザではなく、CJK文字は1文字=1トークン、
// ASCII は空白区切りの語=1トークンとみなす決定的なヒューリスティック。
// チャンクサイズ制御には十分な精度で、単体テストの再現性も確保できる。
class TokenCounter
{
    public function count(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        // CJK(漢字・ひらがな・カタカナ)を抽出して1文字1トークンで数える
        $cjkCount = preg_match_all(
            '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]/u',
            $text
        );

        // CJK を除去した残り(ASCII/ラテン語など)を空白区切りの語数で数える
        $nonCjk = preg_replace(
            '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]/u',
            ' ',
            $text
        );
        $words = preg_split('/\s+/u', trim($nonCjk), -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($words) ? count($words) : 0;

        return $cjkCount + $wordCount;
    }
}
