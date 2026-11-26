# MySQL Internals Lab — 学習ロードマップ

実務の障害対応・性能チューニング・バックエンド設計に直結する InnoDB の内部構造と Eloquent の挙動を、全 7 フェーズで体系的に検証します。

---

## Phase 1: インデックス構造とロック範囲の可視化

- [x] **1-1. クラスタードインデックスとセカンダリインデックスの内部構造（B+Tree）**
  - 主キーのリーフページに行データ本体が格納される構造と、セカンダリインデックスのリーフに主キー値が入る仕組み（ブックマークルックアップ）を理解する
- [x] **1-2. レコードロック・ギャップロック・ネクストキーロックの発生条件と違い**
  - 一意インデックスの等価検索（存在する行 / 存在しない行）、非一意インデックス検索、範囲検索でロック種別（`REC_NOT_GAP`, `GAP`, `X`）がどう変わるか観測する
- [x] **1-3. インデックスが効かない `WHERE` 句による全行ロック（実質テーブルロック）の再現**
  - フルテーブルスキャン時に全レコード＋ギャップにロックが波及する現象を再現し、本番障害の原因を体感する
- [x] **1-4. トランザクション分離レベルによるロック範囲の変化（`REPEATABLE READ` vs `READ COMMITTED`）**
  - `READ COMMITTED` でギャップロックが原則消える挙動と、MySQL 8.x のデフォルト（`REPEATABLE READ`）とのトレードオフを比較する
- [x] **1-5. `performance_schema.data_locks` / `data_lock_waits` によるリアルタイム観測**
  - インテンションロック（`IX` / `IS`）、`supremum pseudo-record`、ロック待ちの親子関係をクエリで可視化する

---

## Phase 2: デッドロックの意図的再現と解析・回避

- [x] **2-1. 更新順序の不一致による古典的デッドロック**
  - 2セッションが逆順でレコードをロックした際の即時検知（InnoDB による Victim 選定とロールバック）を再現する
- [x] **2-2. ギャップロック起因のデッドロック（在庫引当・連番採番・Upsert 競合）**
  - 複数セッションが同じギャップにギャップロック（互換）を取得した後、`INSERT` 時の Insert Intention Lock で相互待ちになる典型パターンを再現する
- [x] **2-3. 外部キー制約による親テーブルへの共有ロック（Sロック）波及とデッドロック**
  - 子テーブルへの `INSERT` / `UPDATE` 時に親テーブルへ暗黙の S ロックが張られる挙動と、親テーブル更新との競合を検証する
- [x] **2-4. `SHOW ENGINE INNODB STATUS` の読み解き方と回避設計**
  - `LATEST DETECTED DEADLOCK` セクションの読解、ロック順序統一、楽観的ロック（バージョンカラム）、トランザクション粒度縮小の実装パターンを比較する

---

## Phase 3: Laravel（Eloquent）特有の競合と実践パターン

- [x] **3-1. `lockForUpdate()` と `sharedLock()` の発行 SQL とリレーション併用時の挙動**
  - `with()`（別クエリになるためロックが効かない罠）と `JOIN` の違い、トランザクション外でのロック指定が無意味になる挙動を検証する
- [x] **3-2. `updateOrCreate()` / `firstOrCreate()` のレースコンディションと `upsert()` の罠**
  - `SELECT` → `INSERT` 間の競合による Duplicate Entry、および `INSERT ... ON DUPLICATE KEY UPDATE` 時のギャップロック・Auto Increment 消費を検証する
- [x] **3-3. `lockForUpdate()->skipLocked()` を用いたキュー・バッチの安全な並列処理**
  - ワーカー並列実行時に二重処理とロック待ちを同時に防ぐ `FOR UPDATE SKIP LOCKED`（および `NOWAIT`）の挙動を確認する
- [x] **3-4. ネストしたトランザクションと `SAVEPOINT` の挙動（発展）**
  - Laravel の `DB::transaction()` をネストした際に発行される `SAVEPOINT` / `ROLLBACK TO SAVEPOINT` と、`attempts`（リトライ）指定時の副作用・注意点を確認する
- [x] **3-5. Eloquent のクエリ速度・メモリ最適化（N+1・`whereHas`・大量データ処理）**
  - `with()` による Eager Loading、`whereHas`（相関サブクエリ）vs `JOIN` の実行計画と速度差、`chunk()`（`OFFSET` 劣化）vs `chunkById()`（Seek 法）vs `cursor()`、バルク `insert()` の性能比較を行う

---

## Phase 4: 実行計画（EXPLAIN）とインデックス設計・主キー選定

- [x] **4-1. `EXPLAIN` / `EXPLAIN ANALYZE` の読み方（`type`, `key`, `rows`, `Extra`）**
  - `const` / `ref` / `range` / `index` / `ALL` の違いと、`EXPLAIN ANALYZE` による実測コスト・実処理行数の読み取りを習得する
- [x] **4-2. カバリングインデックス（`Using index`）と複合インデックスのカラム順序設計**
  - 左端一致の原則、等価条件 → 範囲条件 → `ORDER BY`（`Using filesort` 回避）の設計セオリーを検証する
- [x] **4-3. 暗黙の型変換・関数適用・前方一致以外によるインデックス無効化の検証**
  - 文字列カラムへの数値検索、`DATE(created_at)`、`OR` 条件、低カーディナリティカラムでのオプティマイザ判断（Index Dive / 統計情報）を検証する
- [x] **4-4. サロゲートキー選定（Auto Increment vs UUIDv4 vs ULID / UUIDv7）とページ分割（Page Split）**
  - ランダムな UUIDv4 挿入時に発生する B+Tree ページ分割・フラグメンテーション・テーブルサイズ肥大化を実測比較する
- [x] **4-5. InnoDB バッファプールと統計情報（`ANALYZE TABLE`）の理解（発展）**
  - なぜ同じクエリでも統計情報の偏りで実行計画が急変するのか、`innodb_buffer_pool_size` とディスク I/O の関係を整理する
- [x] **4-6. サブクエリ・集計・`SELECT *` のパフォーマンスチューニング**
  - 相関サブクエリ vs `JOIN`、一時テーブル（`Using temporary`）を発生させない `GROUP BY` / `DISTINCT`、巨大な `TEXT`/`JSON` カラムを `SELECT *` で読むことによるメモリ・ページI/O 劣化とテーブル分割・カバリングの勘所を検証する

---

## Phase 5: MVCC・Undo/Redo ログと無停止運用（オンライン DDL）

- [x] **5-1. MVCC（スナップショット読み）と長時間トランザクションによる Undo ログ肥大化**
  - Read View の仕組み（一貫性読み取り vs 現在読み取り）を理解し、長時間オープンなトランザクションが `History List Length` を増加させクエリ性能を劣化させる現象を観測する
- [ ] **5-2. Redo ログとバイナリログ（binlog）の役割とクラッシュリカバリ（WAL / 2PC）**
  - WAL（Write-Ahead Logging）、`innodb_flush_log_at_trx_commit` と `sync_binlog` の耐久性・性能トレードオフを理解する
- [ ] **5-3. `ALTER TABLE` によるメタデータロック（MDL）事故の再現とオンライン DDL**
  - 長時間 `SELECT` 中に `ALTER TABLE` を流すと後続の全クエリが `Waiting for table metadata lock` で詰まる事故を再現し、`ALGORITHM=INSTANT / INPLACE`・`LOCK=NONE` の適用条件を検証する
- [ ] **5-4. 無停止スキーマ変更の設計パターン（Expand and Contract）**
  - カラム名変更・型変更・NOT NULL 制約追加をダウンタイムなしで安全にリリースする段階的マイグレーション手順を整理する

---

## Phase 6: レガシー環境の不具合調査・安全なデータ修復（フォレンジック＆パッチ）

- [ ] **6-1. 孤児レコード・重複レコードの検出とウィンドウ関数を用いた安全なクレンジング**
  - 外部キー・UNIQUE 制約が欠如したレガシーDBから不整合データを特定し、`ROW_NUMBER()` 等で安全に重複排除する手順を実践する
- [ ] **6-2. NULL の 3 値論理（`NOT IN` と `NULL` の罠）と照合順序（Collation）のバグ再現**
  - `NOT IN (SELECT ...)` に `NULL` が混入した際の 0 件返却バグ、および `utf8mb4_general_ci` vs `utf8mb4_unicode_ci` vs `utf8mb4_0900_ai_ci` による絵文字・濁点・大文字小文字の判定差異を検証する
- [ ] **6-3. トランザクション境界のバグ（外部 API 呼び出し・コミット前の非同期ジョブ発火）**
  - トランザクション内で外部 API を叩くことによるロック長期化と、コミット前にキュー投入したジョブが未コミットデータを参照して失敗する問題（`afterCommit` による解決）を検証する
- [ ] **6-4. 大量データパッチの分割実行（チャンク処理）と冪等性の担保**
  - 巨大テーブルに対する一括 `UPDATE` / `DELETE` の危険性（ロック長期化・Undo/binlog 肥大化・レプリカ遅延）と、主キー範囲によるチャンク分割＋冪等設計を実装する
- [ ] **6-5. `mysqlbinlog` による変更履歴調査（フォレンジック）の基礎**
  - ROW 形式の binlog から「いつ・どのトランザクションが・どの行をどう書き換えたか」を抽出する手順を体験する

---

## Phase 7: 運用・障害復旧とスケーリングの勘所（発展領域）

- [ ] **7-1. 深いページネーション（`OFFSET` 問題）とカーソルベースページネーション（Seek 法）**
  - `LIMIT 20 OFFSET 100000` が遅くなる理由（遅延結合 vs Seek 法）を大量データで実測比較する
- [ ] **7-2. レプリケーション遅延（Replication Lag）による書き込み直後の参照不整合**
  - Read/Write 分離構成（Laravel の `read`/`write` 接続と `sticky` オプション）における Read-after-Write 不整合とアプリ側の対処設計を整理する
- [ ] **7-3. コネクション管理（PHP の 1 リクエスト 1 接続特性とコネクション枯渇）**
  - `max_connections`、`wait_timeout`、Persistent Connection の罠、およびコネクションプーリング（ProxySQL / RDS Proxy）の役割を理解する
- [ ] **7-4. スロークエリログ解析とパフォーマンス調査の定石**
  - `mysql.slow_log` / `mysqldumpslow` と `performance_schema.events_statements_summary_by_digest` を使ってボトルネッククエリを特定する実務フローを実践する
- [ ] **7-5. バルク処理・パーティショニングとアーカイブ戦略（発展）**
  - ループ `INSERT` vs バルク `INSERT`（トランザクションまとめ）の速度ベンチマーク、肥大化テーブルのパーティショニング（Partition Pruning）と履歴データのアーカイブ設計を学ぶ
