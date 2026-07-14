<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Services\Ingest\DocumentIngestor;
use Illuminate\Database\Seeder;

// database/seeders/corpus/*.md のダミー文書を取り込む。
// 取り込みは同期実行し、シード完了時点で全文書を completed にする(HANDOFF 1-4 完了条件)。
class CorpusSeeder extends Seeder
{
    public function run(): void
    {
        $dir = database_path('seeders/corpus');
        if (! is_dir($dir)) {
            $this->command?->warn("コーパスディレクトリが見つかりません: {$dir}");

            return;
        }

        $files = glob($dir.'/*.md') ?: [];
        if ($files === []) {
            $this->command?->warn('コーパス文書(*.md)が見つかりません。');

            return;
        }

        /** @var DocumentIngestor $ingestor */
        $ingestor = app(DocumentIngestor::class);

        $completed = 0;
        $failed = 0;

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $title = $this->extractTitle($content) ?? pathinfo($file, PATHINFO_FILENAME);
            $category = $this->extractCategory($content) ?? 'manual';

            $document = Document::query()->create([
                'title' => $title,
                'category' => $category,
                'content' => $content,
                'status' => Document::STATUS_PROCESSING,
                'version' => 1,
            ]);

            try {
                $ingestor->ingest($document, 1);
                $completed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->command?->error("取り込み失敗: {$title} — {$e->getMessage()}");
            }
        }

        $this->command?->info("コーパス取り込み完了: completed={$completed} / failed={$failed} / total=".count($files));
    }

    private function extractTitle(string $content): ?string
    {
        foreach (preg_split('/\R/u', $content) as $line) {
            // /u 必須(日本語タイトルがバイト境界で切断されないように)
            if (preg_match('/^#\s+(.+\S)\s*$/u', $line, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    private function extractCategory(string $content): ?string
    {
        if (preg_match('/<!--\s*category:\s*(procedure|policy|faq|manual|rule)\s*-->/u', $content, $m)) {
            return $m[1];
        }

        return null;
    }
}
