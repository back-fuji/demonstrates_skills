<?php

namespace App\Services\Rag;

use RuntimeException;

// resources/prompts/ 配下のプロンプトをバージョン指定で読み込む。
// 評価ハーネスが prompt_version を記録できるよう、プロンプトは外部ファイルで管理する。
class PromptRepository
{
    public function load(string $name, string $version): string
    {
        $path = resource_path("prompts/{$name}_{$version}.md");
        if (! is_file($path)) {
            throw new RuntimeException("プロンプトが見つかりません: {$path}");
        }

        return (string) file_get_contents($path);
    }
}
