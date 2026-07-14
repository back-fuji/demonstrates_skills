# demonstrates_skills

実務スキルを証明するためのポートフォリオリポジトリです。
「作れる」だけでなく、**品質を継続的に担保できる・性能を計測して改善できる** ことをコードとドキュメントで示すことを目的としています。

## プロジェクト一覧

| プロジェクト | 概要 | 証明したいスキル |
|---|---|---|
| [clinic-rag-assistant](./clinic-rag-assistant/) | 美容クリニック向け 社内ナレッジRAG検索システム | AI統合(RAG/pgvector)、LLM品質評価、負荷試験と性能改善、設計判断の言語化(ADR) |

## このリポジトリの読み方

各プロジェクトは以下の3層構成で「実務での仕事の進め方」を再現しています。

1. **設計ドキュメント** (`docs/`) — 要件定義から設計判断(ADR)までを先に固め、実装はその後
2. **実装** — Laravel / Vue / PostgreSQL(pgvector) / Claude API
3. **検証** — LLM出力の回帰テスト(評価ハーネス)と、k6による負荷試験のbefore/after記録

## 技術スタック

- **Backend**: PHP 8.3 / Laravel 11
- **Frontend**: Vue 3 / TypeScript
- **DB**: PostgreSQL 16 + pgvector
- **AI**: Anthropic Claude API (Messages API / embeddings)
- **Infra**: Docker Compose(ローカル) / AWS ECS Fargate(想定構成をdocsに記載)
- **負荷試験**: k6
- **キャッシュ**: Redis
