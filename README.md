# mysql-internals-lab

実務（PHP / Laravel + MySQL）およびレガシー環境の不具合修正・パフォーマンス改善・バックエンド設計に直結する、**MySQL（InnoDB）の内部構造と Eloquent の挙動をハンズオンで深く検証・習得するための実験リポジトリ**です。

## 目的

フレームワークの抽象化（Eloquent / Query Builder）の裏側で MySQL（InnoDB）が実際にどのような SQL を実行し、どの範囲にどのようなロックを張り、どのように B+Tree や MVCC を扱っているかを **「再現 → 観測 → 解析 → 回避設計」** のサイクルで体得することを目的としています。

## アーキテクチャ（最小構成）

Web サーバー（Nginx 等）やフルセットの Laravel は導入せず、実験と観測に集中できる最小構成としています。

```text
.
├── compose.yaml            # PHP 8.5 CLI + MySQL 8.4 の最小2コンテナ構成
├── composer.json           # illuminate/database + fakerphp/faker のみ
├── bootstrap.php           # Capsule Manager 初期化（Eloquent / Schema / Query Builder）
├── lab                     # 軽量CLI（migrate / seed / fresh / status）
├── docker/
│   ├── php/Dockerfile      # php:8.5-cli + pdo_mysql + composer
│   └── mysql/
│       ├── my.cnf          # slow_query_log / performance_schema / deadlock log 有効化
│       └── init/           # 観測用権限（performance_schema / PROCESS）付与
├── database/
│   ├── migrations/         # 実験用テーブル定義
│   └── seeders/            # 実験用データ投入
├── src/Models/             # Eloquent モデル
├── exercises/              # フェーズ別のハンズオン実験スクリプト
├── road_map.md             # 全7フェーズの体系的学習ロードマップ
└── learning_log.md         # 実験で観測した事実と実務応用の記録
```

## セットアップ手順

### 1. コンテナのビルドと起動

```bash
docker compose up -d --build
```

### 2. PHP 依存パッケージのインストール

```bash
docker compose exec php composer install
```

### 3. 初期マイグレーション・シード実行と接続確認

```bash
docker compose exec php php lab fresh
docker compose exec php php lab status
```

### MySQL クライアントへの直接接続（観測用）

```bash
docker compose exec mysql mysql -u lab_user -plab_secret internals_lab
```

## CLI コマンド一覧

| コマンド | 説明 |
| --- | --- |
| `docker compose exec php php lab migrate` | `database/migrations/` のマイグレーションを順次実行 |
| `docker compose exec php php lab seed` | `database/seeders/` のシーダーを順次実行 |
| `docker compose exec php php lab fresh` | 全テーブルを削除し、`migrate` + `seed` を再実行 |
| `docker compose exec php php lab status` | MySQL バージョン、分離レベル、テーブル行数を確認 |

## 学習カリキュラム概要

詳細なチェックリストは [road_map.md](./road_map.md) を参照してください。

- **Phase 1:** インデックス構造（B+Tree）とロック範囲の可視化（`performance_schema.data_locks`）
- **Phase 2:** デッドロックの意図的再現と解析・回避（`SHOW ENGINE INNODB STATUS`）
- **Phase 3:** Laravel（Eloquent）特有の競合と実践パターン（`lockForUpdate`, `skipLocked`, `upsert`）
- **Phase 4:** 実行計画（`EXPLAIN` / `EXPLAIN ANALYZE`）とインデックス設計・主キー選定（UUIDv4 vs UUIDv7/ULID）
- **Phase 5:** MVCC・Undo/Redo ログと無停止運用（オンライン DDL・MDL 回避）
- **Phase 6:** レガシー環境の不具合調査・安全なデータ修復（フォレンジック＆パッチ）
- **Phase 7:** 運用・障害復旧とスケーリングの勘所（Seek 法、レプリケーション遅延、コネクション管理）
