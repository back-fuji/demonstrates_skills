# ADR-003: ベクトルインデックスはHNSWを採用する(Phase 3で実測検証)

- Status: Accepted(Phase 3の計測で再評価)
- Date: 2026-07

## Context

pgvectorのANN(近似最近傍)インデックスにはHNSWとIVFFlatがある。

| 観点 | HNSW | IVFFlat |
|---|---|---|
| 検索速度/精度 | 高速・高recall | やや劣る(probes次第) |
| 構築時間/メモリ | 重い | 軽い |
| データ追加への耐性 | 追加に強い | 構築後の大量追加でクラスタが歪み、精度劣化 → 再構築が必要 |
| 空テーブルへの構築 | 可 | 不可(データが必要) |

## Decision

HNSW(m=16, ef_construction=64)を採用する。

## Rationale

1. **文書は継続的に追加・更新される**運用のため、追加に強いHNSWが適する。IVFFlatは定期再構築の運用負担が生じる
2. チャンク数百〜数万件の規模ではHNSWの構築コスト(メモリ・時間)は問題にならない
3. そもそもこの規模なら**インデックスなし(全件スキャン)でも成立しうる**ため、Phase 3で「なし vs HNSW vs IVFFlat」を1万チャンクで実測し、p95とRecallのトレードオフ表を作成して本ADRを検証する

## Consequences

- (+) 追加運用がシンプル、検索p95が安定
- (−) メモリ使用量は増える(RDSインスタンスサイズ選定時に考慮)
- 計測結果はdocs/06の結果テンプレートに追記し、必要ならStatusをSupersededに変更する

## 実測(Phase 3, 合成1万チャンク・dim=1024・クラスタ構造データ)

`php artisan bench:vector` による計測。詳細は docs/06 §8 Step 3。

| index | 構築時間 | p95 | Recall@8 |
|---|---|---|---|
| なし(全件スキャン) | ~0 ms | 17.14 ms | 1.000 |
| HNSW(m=16, ef_construction=64, ef_search=40) | 5,611 ms | **1.47 ms** | **0.998** |
| IVFFlat(lists≈√n, probes=10) | 1,158 ms | 1.76 ms | 0.988 |

- 1万件規模では全件スキャンでも p95≈17ms で成立(ADR-001 の「この規模ならインデックスなしでも成立しうる」を実証)。
- HNSW は最良のRecall(0.998)を約12倍高速に達成。文書が継続追加される運用でも再構築不要。
- IVFFlat は構築が速い(1.2s)がRecallがわずかに劣り、大量追加でクラスタが歪むと再構築が要る。
- 以上より **Decision(HNSW採用)を実測で支持**。Status は Accepted のまま。
