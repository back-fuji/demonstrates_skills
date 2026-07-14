# 04. DB設計

## 1. ER概要

```
documents 1 ──── * chunks
sessions  1 ──── * messages(user質問 / assistant回答)
messages  1 ──── * message_sources(回答が引用したchunk)
eval_cases(ゴールデンセット) ──── eval_runs 1 ──── * eval_results
```

## 2. テーブル定義

### documents
| カラム | 型 | 説明 |
|---|---|---|
| id | bigint PK | |
| title | varchar(255) | 文書タイトル |
| category | varchar(50) | procedure / policy / faq / manual / rule |
| content | text | 原文(Markdown) |
| status | varchar(20) | processing / completed / failed |
| error_message | text nullable | 取り込み失敗理由 |
| version | integer default 1 | **楽観ロック用**(ADR-005) |
| deleted_at | timestamp nullable | 論理削除 |
| created_at / updated_at | timestamp | |

### chunks
| カラム | 型 | 説明 |
|---|---|---|
| id | bigint PK | |
| document_id | bigint FK | |
| section_path | varchar(500) | 見出し階層(例: `予約ポリシー > 当日キャンセル`) |
| content | text | チャンク本文 |
| token_count | integer | 概算トークン数 |
| embedding | vector(1024) | pgvector。次元数は採用embeddingモデルに合わせる |
| is_active | boolean default true | 文書更新時に旧チャンクをfalse化 |
| created_at | timestamp | |

インデックス:
```sql
-- Phase 1: HNSW(ADR-003参照。Phase 3でIVFFlatと比較計測)
CREATE INDEX chunks_embedding_hnsw ON chunks
  USING hnsw (embedding vector_cosine_ops)
  WITH (m = 16, ef_construction = 64);

-- 検索は常に is_active = true 条件が付くため部分インデックスも検討
CREATE INDEX chunks_active_document ON chunks (document_id) WHERE is_active = true;
```

### sessions / messages / message_sources
| テーブル | 主要カラム |
|---|---|
| sessions | id(ULID), user_id, created_at |
| messages | id(ULID), session_id, role(user/assistant), content, latency_ms, cached(bool), created_at |
| message_sources | message_id, chunk_id, score(類似度), rank |

message_sourcesを残す理由: 「どの回答がどのチャンクを根拠にしたか」の監査証跡であり、評価ハーネスの分析素材(検索が外れたのか、生成が外れたのかの切り分け)になる。

### eval_cases(ゴールデンセット)
| カラム | 型 | 説明 |
|---|---|---|
| id | bigint PK | |
| question | text | テスト質問 |
| expected_chunk_ids | jsonb | 正解として引用されるべきchunk群(Recall計測用) |
| expected_answer_points | jsonb | 回答に含まれるべき要点のリスト(Judge採点用) |
| category | varchar(50) | fact / policy / comparison / no_answer(答えてはいけない質問) |
| is_active | boolean | |

### eval_runs / eval_results
| テーブル | 主要カラム |
|---|---|
| eval_runs | id, git_sha, prompt_version, model, started_at, finished_at, summary(jsonb: recall@k, judge平均点 等) |
| eval_results | eval_run_id, eval_case_id, retrieved_chunk_ids(jsonb), recall_hit(bool), judge_score(1-5), judge_reason(text), answer(text), latency_ms |

## 3. チャンク分割戦略(概要、詳細はADR-002)

- Markdownの見出し(h2/h3)単位で分割し、`section_path`に階層を保持
- 1チャンクの目安: 200〜500トークン。超過時は段落境界で分割し、前チャンク末尾100トークンをオーバーラップ
- 各チャンク先頭に「文書タイトル + セクションパス」を文脈として付与してからembedding生成(検索精度向上の定石)

## 4. 検索クエリ

```sql
SELECT c.id, c.content, c.section_path, d.title,
       1 - (c.embedding <=> :query_embedding) AS score
FROM chunks c
JOIN documents d ON d.id = c.document_id AND d.deleted_at IS NULL
WHERE c.is_active = true
ORDER BY c.embedding <=> :query_embedding
LIMIT 8;
```

- `<=>` はコサイン距離演算子。`1 - 距離` を類似度スコアとして扱う
- スコア0.5未満のチャンクはアプリ層で除外(no_answer判定)
