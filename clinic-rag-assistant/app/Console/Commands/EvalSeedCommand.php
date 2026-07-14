<?php

namespace App\Console\Commands;

use App\Models\EvalCase;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

// database/eval/cases/*.yaml をゴールデンデータセット(eval_cases)へ投入する。
// YAML をマスタとし Git で差分レビューできるようにする(docs/05 §3)。
class EvalSeedCommand extends Command
{
    protected $signature = 'eval:seed';

    protected $description = 'YAMLの評価ケースを eval_cases テーブルへ投入する';

    public function handle(): int
    {
        $dir = database_path('eval/cases');
        $files = glob($dir.'/*.yaml') ?: [];
        if ($files === []) {
            $this->error("評価ケースが見つかりません: {$dir}");

            return self::FAILURE;
        }

        $count = 0;
        foreach ($files as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $data = Yaml::parseFile($file);

            EvalCase::query()->updateOrCreate(
                ['key' => $key],
                [
                    'question' => (string) ($data['question'] ?? ''),
                    'category' => (string) ($data['category'] ?? 'fact'),
                    'expected_chunk_keys' => (array) ($data['expected_chunk_keys'] ?? []),
                    'expected_answer_points' => (array) ($data['expected_answer_points'] ?? []),
                    'is_active' => true,
                ],
            );
            $count++;
        }

        $this->info("評価ケースを投入しました: {$count} 件");

        return self::SUCCESS;
    }
}
