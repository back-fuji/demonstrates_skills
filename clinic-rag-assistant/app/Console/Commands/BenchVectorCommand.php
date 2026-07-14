<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// pgvector インデックスのベンチマーク(docs/06 Step 3 / ADR-003)。
// 合成データで「なし vs HNSW vs IVFFlat」の検索p95とRecallのトレードオフを実測する。
class BenchVectorCommand extends Command
{
    protected $signature = 'bench:vector
        {--index=hnsw : hnsw | ivfflat | none}
        {--count=10000 : 合成チャンク数}
        {--queries=50 : 計測クエリ数}';

    protected $description = 'pgvectorインデックス(HNSW/IVFFlat/なし)の検索性能を合成データで比較する';

    public function handle(): int
    {
        $index = (string) $this->option('index');
        $count = (int) $this->option('count');
        $queries = (int) $this->option('queries');
        $dim = (int) config('rag.embedding.dimensions', 1024);
        $k = (int) config('rag.top_k', 8);

        if (! in_array($index, ['hnsw', 'ivfflat', 'none'], true)) {
            $this->error('--index は hnsw | ivfflat | none のいずれか');

            return self::FAILURE;
        }

        $this->info("ベンチ準備: dim={$dim}, count={$count}, index={$index}");
        // 実embeddingを模してクラスタ構造を持つ合成データを使う(一様乱数だと高次元でANN recallが不当に低くなるため)
        $centroids = $this->makeCentroids(100, $dim);
        $this->prepareTable($dim);
        $this->insertSynthetic($count, $dim, $centroids);

        // インデックス構築(時間を計測)
        $buildMs = $this->buildIndex($index, $count);
        $this->line("インデックス構築: {$buildMs} ms");

        // クエリ計測
        $latencies = [];
        $recalls = [];
        for ($i = 0; $i < $queries; $i++) {
            // クエリもクラスタ近傍から生成(実利用の分布を模す)
            $qv = $this->clusteredVectorLiteral($centroids[array_rand($centroids)], $dim);

            $start = microtime(true);
            $annIds = $this->annSearch($qv, $k, $index);
            $latencies[] = (microtime(true) - $start) * 1000;

            // 正解(全件スキャン)と比較して Recall@k を算出
            $exactIds = $this->exactSearch($qv, $k);
            $recalls[] = count(array_intersect($annIds, $exactIds)) / max(1, count($exactIds));
        }

        sort($latencies);
        $p50 = $this->percentile($latencies, 50);
        $p95 = $this->percentile($latencies, 95);
        $recall = array_sum($recalls) / max(1, count($recalls));
        $size = $this->indexSize();

        $this->newLine();
        $this->table(
            ['index', 'count', 'build_ms', 'p50_ms', 'p95_ms', 'recall@k', 'index_size'],
            [[$index, $count, $buildMs, number_format($p50, 2), number_format($p95, 2), number_format($recall, 3), $size]],
        );

        return self::SUCCESS;
    }

    private function prepareTable(int $dim): void
    {
        DB::statement('DROP TABLE IF EXISTS bench_chunks');
        DB::statement("CREATE TABLE bench_chunks (id bigserial PRIMARY KEY, embedding vector({$dim}))");
    }

    /**
     * @param  list<list<float>>  $centroids
     */
    private function insertSynthetic(int $count, int $dim, array $centroids): void
    {
        $bar = $this->output->createProgressBar($count);
        $batch = 500;
        for ($offset = 0; $offset < $count; $offset += $batch) {
            $rows = [];
            $n = min($batch, $count - $offset);
            for ($j = 0; $j < $n; $j++) {
                $c = $centroids[array_rand($centroids)];
                $rows[] = "('".$this->clusteredVectorLiteral($c, $dim)."')";
            }
            DB::statement('INSERT INTO bench_chunks (embedding) VALUES '.implode(',', $rows));
            $bar->advance($n);
        }
        $bar->finish();
        $this->newLine();
    }

    /**
     * K個のランダムなクラスタ中心(単位ベクトル)を生成する。
     *
     * @return list<list<float>>
     */
    private function makeCentroids(int $k, int $dim): array
    {
        $centroids = [];
        for ($i = 0; $i < $k; $i++) {
            $v = [];
            $norm = 0.0;
            for ($d = 0; $d < $dim; $d++) {
                $x = mt_rand(-1000, 1000) / 1000;
                $v[$d] = $x;
                $norm += $x * $x;
            }
            $norm = sqrt($norm) ?: 1.0;
            $centroids[] = array_map(fn ($x) => $x / $norm, $v);
        }

        return $centroids;
    }

    /**
     * クラスタ中心に小さなノイズを加えた単位ベクトルの pgvector リテラルを返す。
     *
     * @param  list<float>  $centroid
     */
    private function clusteredVectorLiteral(array $centroid, int $dim): string
    {
        $v = [];
        $norm = 0.0;
        for ($d = 0; $d < $dim; $d++) {
            // 中心 + ノイズ(0.15)。クラスタ内近傍が明確になる。
            $x = $centroid[$d] + (mt_rand(-1000, 1000) / 1000) * 0.15;
            $v[$d] = $x;
            $norm += $x * $x;
        }
        $norm = sqrt($norm) ?: 1.0;
        for ($d = 0; $d < $dim; $d++) {
            $v[$d] = round($v[$d] / $norm, 6);
        }

        return '['.implode(',', $v).']';
    }

    private function buildIndex(string $index, int $count): int
    {
        $start = microtime(true);
        if ($index === 'hnsw') {
            DB::statement('CREATE INDEX bench_hnsw ON bench_chunks USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)');
        } elseif ($index === 'ivfflat') {
            // lists は概ね sqrt(行数)
            $lists = max(1, (int) round(sqrt($count)));
            DB::statement("CREATE INDEX bench_ivf ON bench_chunks USING ivfflat (embedding vector_cosine_ops) WITH (lists = {$lists})");
        }
        // none: インデックス作成せず(全件スキャン)
        DB::statement('ANALYZE bench_chunks');

        return (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * @return list<int>
     */
    private function annSearch(string $qv, int $k, string $index): array
    {
        // インデックスの精度パラメータを調整(recall/latency のトレードオフ)
        return DB::transaction(function () use ($qv, $k, $index) {
            if ($index === 'ivfflat') {
                DB::statement('SET LOCAL ivfflat.probes = 10');
            } elseif ($index === 'hnsw') {
                DB::statement('SET LOCAL hnsw.ef_search = 40');
            }
            $rows = DB::select("SELECT id FROM bench_chunks ORDER BY embedding <=> ?::vector LIMIT {$k}", [$qv]);

            return array_map(fn ($r) => (int) $r->id, $rows);
        });
    }

    /**
     * @return list<int>
     */
    private function exactSearch(string $qv, int $k): array
    {
        // インデックスを使わせず全件スキャンで厳密解を得る(SET LOCAL はトランザクション内でのみ有効)
        return DB::transaction(function () use ($qv, $k) {
            DB::statement('SET LOCAL enable_indexscan = off');
            DB::statement('SET LOCAL enable_bitmapscan = off');
            $rows = DB::select("SELECT id FROM bench_chunks ORDER BY embedding <=> ?::vector LIMIT {$k}", [$qv]);

            return array_map(fn ($r) => (int) $r->id, $rows);
        });
    }

    private function indexSize(): string
    {
        $row = DB::selectOne("SELECT pg_size_pretty(pg_total_relation_size('bench_chunks')) AS size");

        return $row->size ?? '-';
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, int $p): float
    {
        if ($sorted === []) {
            return 0.0;
        }
        $idx = (int) ceil(($p / 100) * count($sorted)) - 1;
        $idx = max(0, min($idx, count($sorted) - 1));

        return $sorted[$idx];
    }
}
