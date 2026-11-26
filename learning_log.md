# Learning Log

各学習項目を完了するごとに、以下のフォーマットで「学んだ原理」「実験で観測した事実」「実務への応用ポイント」を記録します。

---

## テンプレート

```markdown
### [Phase X-Y] テーマ名（YYYY-MM-DD）

- **仮説:** 実験前に予測した挙動
- **観測した事実:** 実際に実行した SQL / `performance_schema.data_locks` / `EXPLAIN` などの出力結果
- **内部構造・原因の整理:** なぜその挙動になったのか（InnoDB / Eloquent の仕組み）
- **実務への応用ポイント:** 設計・コードレビュー・障害対応で気をつけること
```

---

<!-- ここから下に学習記録を追記していきます -->

### [Phase 1] インデックス構造とロック範囲の可視化（2026-11-21）

- **学んだ原理・内部構造:**
  - InnoDB ではインデックスごとに独立した B+Tree が構築され、主キー（クラスタードインデックス）のリーフに行本体、セカンダリインデックスのリーフに「セカンダリキー + 主キー値」が格納される。非一意セカンダリインデックスでは分岐ノードにも `(セカンダリキー, 主キー)` の複合キーが含まれる。
  - 主キーのデータ型が大きい（例：`CHAR(36)` の UUID）と、すべてのセカンダリインデックスのリーフ/分岐ノードに主キー値がコピーされるため、インデックスサイズが連鎖的に肥大化する。
  - `REPEATABLE READ` ではファントムリード防止のため、インデックスレコード本体とギャップ（直後レコードに紐づけて管理）に対して、`X,REC_NOT_GAP`（レコードロック）、`X,GAP`（ギャップロック）、`X`（ネクストキーロック）が張られる。
- **観測した事実（`performance_schema.data_locks`）:**
  - **ケース1（`WHERE id = 20 FOR UPDATE` / 存在する主キー）:** `PRIMARY` 上の `data=20` に `X,REC_NOT_GAP` のみ。
  - **ケース2（`WHERE id = 25 FOR UPDATE` / 存在しない主キー）:** `PRIMARY` 上の直後レコード `data=30` に `X,GAP`（区間 `(20, 30)` をロック）。
  - **ケース3（`WHERE category_id = 20 FOR UPDATE` / 非一意セカンダリ）:** `products_category_id_index` 上の `20, 20` と `20, 30` に `X`（ネクストキーロック）、`30, 40` に `X,GAP` が張られ、同時に `PRIMARY` 上の `20`, `30` にも `X,REC_NOT_GAP` が張られた。
  - **ケース4（`WHERE name = 'Ergonomic Mouse' FOR UPDATE` / インデックスなし）:** `PRIMARY` の全レコード（`10` 〜 `50`）および `supremum pseudo-record` すべてに `X`（実質テーブルロック）。
  - **ケース5（`READ COMMITTED`）:** ケース3 の `X,GAP` が消滅しすべて `X,REC_NOT_GAP` に縮小。ケース4 では条件不一致の行が即座に解放され、`data=20` の `X,REC_NOT_GAP` のみが残った。
  - **ケース6（1-5: `IS` ロックと `data_lock_waits` の親子関係）:** Session A で `sharedLock()`（`IS` + `S,REC_NOT_GAP` on `20`）を保持中に Session B で `id=20` を `UPDATE` すると、テーブルレベルの `IS` と `IX` は共に `GRANTED` となり、レコードレベルで `X,REC_NOT_GAP` が `WAITING` になった。`performance_schema.data_lock_waits` で `waiting_trx (2329)` → `blocking_trx (562948419796992)` の親子関係を特定できた。
- **実務への応用ポイント:**
  - `lockForUpdate()` を使う際は `WHERE` 句に必ず適切なインデックスが効くことを確認する（インデックス欠如は全行ロックによるサービス停止に直結する）。
  - 存在しない行への `FOR UPDATE` や非一意インデックスのロックでは周囲のギャップがロックされ、無関係な新規 `INSERT` をブロックする点に注意する。
  - 主キーには `BIGINT UNSIGNED` や `BINARY(16)` などコンパクトな型を選定し、セカンダリインデックスの肥大化を防ぐ。

### [Phase 2-1] 更新順序の不一致による古典的デッドロック（2026-11-21）

- **学んだ原理・内部構造:**
  - 2つ以上のトランザクションが互いに相手のロックを待つ循環待ち（デッドロック）が発生すると、InnoDB は Wait-for Graph でサイクルを即時検知し、`innodb_lock_wait_timeout` を待たずにロールバックコストが小さい側（Victim）を自動ロールバックして `SQLSTATE[40001] / 1213` を返す。
  - `SHOW ENGINE INNODB STATUS` の `LATEST DETECTED DEADLOCK` では、`HOLDS THE LOCK(S)` と `WAITING FOR THIS LOCK TO BE GRANTED` を突き合わせることで競合箇所を特定できる。`PRIMARY` 上のロックでは物理レコード全体（`id`・`DB_TRX_ID`・`DB_ROLL_PTR`・`sku` 等）がダンプされる。
- **観測した事実:**
  - Session A が `id = 10`、Session B が `id = 20` を `lockForUpdate()` で保持した状態で互いに逆の ID を要求すると、即座に Session B が Victim（`WE ROLL BACK TRANSACTION (2)`）としてエラー `1213` でロールバックされ、Session A が続行できた。
- **実務への応用ポイント:**
  - 複数レコードをロックする際は、`WHERE id IN (...) ORDER BY id`（`PRIMARY` 順のため `Using filesort` なしで走査可能）や PHP 側での事前 `sort()` によって **ロック取得順序を統一** し、循環待ちを構造上発生させない。
  - それでも発生し得るデッドロックに備え、`DB::transaction(..., $attempts)` 等でリトライ可能な設計にする。

### [Phase 2-2] ギャップロック起因のデッドロック（2026-11-21）

- **学んだ原理・内部構造:**
  - ギャップロック（`X,GAP`）同士は互いにブロックせず両立する。しかし `INSERT` 時に要求される **Insert Intention Lock（`X,GAP,INSERT_INTENTION`）** は他セッションの `X,GAP` によってブロックされる。
  - そのため「存在しない行を `FOR UPDATE` で確認してから `INSERT` する（Check-Then-Act）」処理を 2 セッションが同時実行すると、異なる ID（`25` と `26`）であっても同じギャップ（`(20, 30)`）を互いに握った状態で Insert Intention Lock を待ち合い、デッドロックになる。
- **観測した事実:**
  - Session A（`id=25`）と Session B（`id=26`）の両方が、`FOR UPDATE` 時点で同じ `id=30`（`heap no 4`）に対する `lock_mode X locks gap before rec` を獲得して通過し、続く `INSERT` で両者とも `lock_mode X locks gap before rec insert intention waiting` となって Session B が Victim でロールバックされた。
- **実務への応用ポイント:**
  - 一意制約を張ったうえで「先に `INSERT`（または `UPDATE`）を試みて重複例外をハンドリングする」設計にし、`FOR UPDATE` → `INSERT` パターンを避ける。
  - 必要に応じて `READ COMMITTED` の採用や `DB::transaction(..., $attempts)` によるリトライを組み合わせる。

### [Phase 2-3] 外部キー制約による親テーブルへの S ロック波及とデッドロック（2026-11-22）

- **学んだ原理・内部構造:**
  - 外部キー制約を持つ子テーブルへ `INSERT` / `UPDATE` を行うと、InnoDB は参照整合性を保証するため親テーブルの該当行に **暗黙の S ロック（`S,REC_NOT_GAP`）** を取得する。
  - S ロック同士は両立するため複数セッションが同じ親行の S ロックを同時に保持でき、その後双方が親行の `UPDATE`（S → X ロック昇格）を行うと、互いの S ロック解放を待ち合ってデッドロックが発生する。
- **観測した事実:**
  - `order_items` への `INSERT` 後、同じ `products.id = 10` を `decrement('stock')` で更新した 2 セッションが、`LATEST DETECTED DEADLOCK` 上で共に `lock mode S locks rec but not gap` を保持したまま `lock_mode X locks rec but not gap waiting` となり、Session B が Victim としてロールバックされた。
- **実務への応用ポイント:**
  - 子テーブルへ `INSERT` する前に親テーブルを `lockForUpdate()` で先に X ロック確保する（または親の更新を先に行う）ことで S → X 昇格デッドロックを防げる。
  - 単純な相対更新は `decrement()` / `increment()` を使うと SQL 側でアトミックに計算される（ただし在庫下限チェック等がある場合は `lockForUpdate()` か `WHERE stock >= N` の併用が必須）。

### [Phase 2-4] `SHOW ENGINE INNODB STATUS` の読み解き方と回避設計（2026-11-22）

- **学んだ原理・内部構造:**
  - **デッドロック（`1213`）とロック待ちタイムアウト（`1205`）の違い:** 双方のセッションが DB ロックを待ち合う循環待ちのみ Wait-for Graph で即時検知（`1213`）される。片方がトランザクションを開いたままアプリ側（外部 API やスリープ等）で止まっている場合、もう片方は `innodb_lock_wait_timeout` まで待ち続けて `1205` エラーになる。
  - **楽観的ロック（CAS: Compare-And-Swap）:** `version` カラムを持ち、`UPDATE ... WHERE id = ? AND version = ?` の更新件数（0件なら競合）で検証することで、ロックを保持せずに Lost Update とデッドロックを回避できる。
- **観測した事実:**
  - `03_deadlock_fk_a.php` 側だけ先に `UPDATE` を実行し `b` 側が PHP 待機中だったケースでは、15秒後に `1205 Lock wait timeout exceeded` が発生することを確認。
  - `04_optimistic_lock.php` で同一レコードを 2 コネクションからロックなしで読んだあと、`version` 条件付きで順次更新すると Session A は `1 件`、Session B は `0 件` となり競合を安全に検知できた。
- **実務への応用ポイント:**
  - 「ロック順序の統一」「トランザクション粒度の縮小（外部処理をトランザクション外へ）」「楽観的ロック（画面編集等）」を処理特性に応じて使い分ける。

### [Phase 3-1] `lockForUpdate()` と `sharedLock()` の発行 SQL とリレーション併用時の挙動（2026-11-23）

- **学んだ原理・内部構造:**
  - `beginTransaction()` なしで `lockForUpdate()` を実行しても、MySQL の Auto-commit によりステートメント終了と同時にロックが即解放される。
  - Eloquent の `with()`（Eager Loading）は `JOIN` ではなく独立した別の `SELECT` クエリを発行するため、親クエリに付けた `lockForUpdate()` はリレーション先へ伝播しない。
- **観測した事実:**
  - トランザクション外での `lockForUpdate()` 直後は `data_locks` が `0 件` だった。
  - `OrderItem::with('product')->lockForUpdate()->get()` では `order_items` のみに `FOR UPDATE` が付き、`products` への `SELECT ... WHERE id IN (10)` にはロックが付かなかった。
  - `with(['product' => fn ($q) => $q->lockForUpdate()])` にすることで `products`（`PRIMARY`, `data=10`, `X,REC_NOT_GAP`）にもロックが張られることを確認した。
- **実務への応用ポイント:**
  - ロックは必ずトランザクション境界の内側で取得し、リレーション先もロックが必要な場合はクロージャ内で明示的に `lockForUpdate()` を指定する（またはロック順序統一のため親テーブルを先にロックしてから子を読む）。

### [Phase 3-2] `updateOrCreate()` / `firstOrCreate()` の競合と `upsert()` の罠（2026-11-23）

- **学んだ原理・内部構造:**
  - `firstOrCreate()` / `updateOrCreate()` は「ロックなしの `SELECT` → `INSERT`」の 2 クエリに分かれるため、同時実行時に `1062 Duplicate entry` のレースコンディションを起こす。Laravel 11+ の `createOrFirst()` は先に `INSERT`（トランザクション内なら `SAVEPOINT` 付き）を試み、重複例外（`UniqueConstraintViolationException`）をキャッチして `useWritePdo()->first()` で取得する設計になっている。
  - `upsert()` は `INSERT ... ON DUPLICATE KEY UPDATE` を発行するが、InnoDB（`innodb_autoinc_lock_mode = 2`）は挿入か更新かを確定する前に `AUTO_INCREMENT` を払い出すため、既存行の更新や重複エラーだけでも連番が消費され欠番が生じる。
- **観測した事実:**
  - `updateOrCreate()` が `select ... limit 1` → `insert` の 2 本を発行すること、および 2 コネクションが同時に `SELECT` で `null` を引くと後続の `INSERT` で `1062 Duplicate entry` になることを再現した。
  - 4 行しか存在しない状態で `upsert()` 2 回（2行＋1行）と重複失敗 1 回を経た結果、新規行の `id` が `7` まで飛ぶことを確認した。
- **実務への応用ポイント:**
  - 同時リクエストが来る作成処理では `firstOrCreate()` を避け、`UNIQUE` 制約＋`createOrFirst()`（または重複例外キャッチ）を使う。
  - `upsert()` を高頻度で回すテーブルの主キーには必ず `BIGINT UNSIGNED` を採用し、32bit `INT` の枯渇を防ぐ。

### [Phase 3-3] `FOR UPDATE SKIP LOCKED` による並列バッチ処理（2026-11-23）

- **学んだ原理・内部構造:**
  - `illuminate/database`（Laravel 11 含む）には `skipLocked()` メソッドは存在せず、`->lock('for update skip locked')`（`NOWAIT` なら `'for update nowait'`）を文字列で渡す仕様になっている（`MySqlGrammar::compileLock`）。
  - `SKIP LOCKED` は他セッションがロック中の行をスキップして次の未ロック行を即座に取得できる。
  - **セカンダリインデックスの並び順とギャップロックの罠:** `status` に非一意インデックスがあり `REPEATABLE READ` の場合、Worker A が `('pending', 1)` に張ったネクストキーロックは直前のギャップ `(-∞, ('pending', 1))` もロックする。そのため Worker B が `id=2` の `status` を辞書順で手前に来る `'done'`（`'d' < 'p'`）へ更新しようとすると、インデックス挿入先が同じギャップに落ちてブロックされる。
- **観測した事実:**
  - Worker A が `id=1` を掴んだ状態で Worker B を実行すると、`SKIP LOCKED` により数ミリ秒で `id=2` を取得できた。
  - 一方、Worker B が `status` を `'done'` へ直接更新したケースでは `1205 Lock wait timeout` でブロックされたが、`'processing'` 等（または `READ COMMITTED`）ではブロックされずに完了できることを確認した。
- **実務への応用ポイント:**
  - キューのステータス値は数値 Enum（`0:pending < 1:processing < 2:done`）のように状態遷移が進むほど値が大きくなる設計にするか、`READ COMMITTED` と `SKIP LOCKED` を組み合わせる。
  - `SKIP LOCKED` を使うクエリの `WHERE` 条件には必ずインデックスを張り、意図しない全行スキップを防ぐ。

### [Phase 3-4] ネストしたトランザクションと `SAVEPOINT` の挙動（2026-11-23）

- **学んだ原理・内部構造:**
  - MySQL は真のネストトランザクションを持たないため、Laravel（`ManagesTransactions`）は 2 段目以降の `transaction()` で `SAVEPOINT` / `ROLLBACK TO SAVEPOINT` を発行して部分ロールバックを実現している。
  - ただし MySQL 側でデッドロック（`1213`）が起きた場合は `SAVEPOINT` ではなく親トランザクション全体が強制ロールバックされるため、ネスト内側で `attempts > 1` を指定するとアプリ側と DB 側の状態認識がズレて不整合や `SAVEPOINT does not exist` エラーを引き起こす。
  - また、トップレベルの `transaction(..., $attempts)` 内に外部 API 呼び出し等の副作用を書くと、リトライ時に複数回実行されてしまう。
- **観測した事実:**
  - 外側トランザクション内で内側 `transaction()` が例外を投げ、外側で `try-catch` すると、`inner_item` だけがロールバックされ `outer_before` と `outer_after` のみがコミットされた（`id=2` は欠番）。
  - `transaction(..., 3)` 内でデッドロック例外を発生させると、外部 API カウンタが 3 回インクリメントされることを確認した。
- **実務への応用ポイント:**
  - `attempts`（リトライ）は必ず最外殻（トップレベル）の `DB::transaction()` にだけ指定する。
  - 外部 API 呼び出し・メール送信・キュー投入はトランザクションの外に出すか、`afterCommit()` 経由で実行する。

### [Phase 3-5] Eloquent のクエリ速度・メモリ最適化（2026-11-23）

- **学んだ原理・内部構造:**
  - ループ内の単発 `INSERT` は毎回ネットワーク往復とトランザクション確定が走るため、バルク `insert()` でまとめて送ることで劇的に高速化する。
  - `chunk()` は `LIMIT / OFFSET` を使うため後ろのページほど全スキャン＋破棄が増えて劣化する（さらにループ内の削除・状態変化で行ズレが起きる）が、`chunkById()` は `WHERE id > ?` の Seek 法で `PRIMARY` B+Tree 上を直接ジャンプするため常に一定速度で安全に処理できる。
  - `whereHas()` は `EXISTS (...)` の相関サブクエリを発行するため、条件次第では外側テーブルをフルスキャンする重い実行計画になりやすい。
- **観測した事実:**
  - 2,000 件の挿入で、1件ずつ `INSERT`（`6380.47 ms`）に対し 500件ずつのバルク `INSERT`（`85.38 ms`）は約 75 倍高速だった。
  - `chunk()` が `limit 500 offset 500/1000/...` を発行する一方、`chunkById()` は `where id > ? order by id asc limit 500` を発行することを確認した。
  - `OrderItem::whereHas('product', ...)` が `where exists (select * from products where ...)` を発行することを確認した。
- **実務への応用ポイント:**
  - バッチでの大量データ挿入は `array_chunk` + バルク `insert()`、大量データ走査は `chunk()` ではなく `chunkById()`（またはメモリ最小の `cursor()`）を選ぶ。
  - `whereHas()` を大量テーブルに使う場合は `EXPLAIN` を確認し、必要なら `JOIN` や `whereIn` に書き換える。

### [Phase 4-1] `EXPLAIN` / `EXPLAIN ANALYZE` の読み方（2026-11-24）

- **学んだ原理・内部構造:**
  - `EXPLAIN` はクエリを実行せず統計情報から予測計画を立て、`EXPLAIN ANALYZE` は実際にクエリを実行して各ステップの予測コストと実測時間（`actual time=開始..終了`）・実測行数をツリー表示する。
  - アクセスタイプ（`type`）は `const > ref > range > index > ALL` の順に高速で、実務では `ALL` / 全件 `index` や `Extra` の `Using filesort` / `Using temporary` を危険サインとして監視する。
- **観測した事実:**
  - 10,000 件の `perf_logs` に対し、`id=100` で `const`（`rows=1`）、`category=10` で `ref`（`rows=100`, 実測 `0.788 ms`）、`id BETWEEN` で `range`、`SELECT category` で `index`（`Using index`）、`payload` 検索で `ALL`（実測 `10000` 行フルスキャン, `4.1 ms`）になることを確認した。
- **実務への応用ポイント:**
  - `EXPLAIN` で `type`, `possible_keys` vs `key`, `rows`（JOIN時は積）, `Extra` を確認し、本番では `EXPLAIN ANALYZE` を安易に更新系や重いクエリへ打たないよう検証環境・レプリカで分析する。

### [Phase 4-2] カバリングインデックスと複合インデックスの設計（2026-11-24）

- **学んだ原理・内部構造:**
  - 複合インデックスは左端のカラムから順にソートされた 1 本の B+Tree であるため、原則として **等価条件（`=`）→ 範囲条件（`>=` 等）／`ORDER BY`** の順に定義する（範囲条件の後ろのカラムにはインデックスが効かない）。
  - セカンダリインデックスのリーフにはインデックス列と主キーが含まれるため、`SELECT` 句をその範囲内に絞ると `PRIMARY` へのブックマークルックアップが不要になる（カバリングインデックス：`Using index`）。
- **観測した事実:**
  - 20,000 件の `orders_perf` に対し、`idx_good_order (user_id, status, ordered_at)` を設計すると `rows=1` かつ `Backward index scan`（`Using filesort` なし）で取得でき、`SELECT id, user_id, status, ordered_at` では `Using index` が付いた。
  - 先頭列 `user_id` を抜いたクエリでは左端一致が崩れ、`idx_bad_order` へフォールバックして `rows=9292` に悪化した。
- **実務への応用ポイント:**
  - 複合インデックスは「よく使う等価条件 → 範囲／ソート」の順に組み、一覧取得等で不要な `SELECT *` を避けてカバリングインデックスを狙う。

### [Phase 4-3] インデックス無効化とオプティマイザの判断（2026-11-25）

- **学んだ原理・内部構造:**
  - 暗黙の型変換（`VARCHAR` 列への数値比較）、カラムへの関数適用（`DATE(col)`）、中間/後方一致 `LIKE '%...'` は、B+Tree の元のソート順を使えなくなるためインデックス探索が無効化される。
  - 同じインデックスでも、ヒット率が極端に高い（例：95%）低カーディナリティ条件では、オプティマイザが「大量のブックマークルックアップ（ランダム I/O）よりフルテーブルスキャン（`ALL`）の方が速い」と判断してインデックスを無視する。
  - なお、`SELECT id` のみ等カバリングインデックスが成立する場合は `ALL` を避けて `type=index`（全インデックス走査）になるが、`rows` は全件になるため依然として重い。
- **観測した事実:**
  - `SELECT *` で比較すると、暗黙型変換・`DATE()` 適用・`LIKE '%...'`・`is_active = 1 (95%)` がすべて `type=ALL | key=NULL | rows=9928` となった。
  - 一方、文字列型一致・範囲検索（`>= AND <`）・前方一致（`LIKE 'user100%'`）・`is_active = 0 (5%)` はそれぞれ `type=ref` / `range` に改善し、`rows` も `1` 〜 `720` に激減した。
- **実務への応用ポイント:**
  - PHP 側からバインドする型と DB カラム型を必ず一致させ、日時検索は関数ではなく範囲条件で書く。
  - 論理削除フラグやステータス単体の低カーディナリティ列は単独インデックスにせず、複合インデックスに組み込む。

### [Phase 4-4] 主キー選定とページ分割（Page Split）の実験（2026-11-25）

- **学んだ原理・内部構造:**
  - InnoDB の `PRIMARY` B+Tree は主キー順に並んだ 16KB ページで構成されるため、`AUTO_INCREMENT`（8B）や時系列ソート可能な `UUIDv7`（`BINARY(16)`）は常に右端へ追記されページ分割が起きにくい。一方ランダムな `UUIDv4`（`CHAR(36)`）は既存ページの途中へ割り込み挿入が発生し、ページ分割による充填率低下とサイズ膨張を招く。
  - InnoDB は一定サイズを超えると 1MB（1024KB）のエクステント単位で領域確保するため、`INDEX_LENGTH` の段階的増加や `DATA_FREE`（4MB の予備エクステント）として現れ、ページ分割によるページ内空き領域は `DATA_LENGTH` の膨張に現れる。
- **観測した事実:**
  - 20,000 件挿入後のサイズ比較で、`pk_auto_inc`（DATA `1552 KB` / INDEX `528 KB`）、`pk_uuid_v7`（DATA `1552 KB` / INDEX `1552 KB`）、`pk_uuid_v4`（DATA `2576 KB` / INDEX `1552 KB`）となり、`UUIDv7` は本体サイズを `AUTO_INCREMENT` と同等に抑えられた一方、`UUIDv4` は約 1.66 倍に肥大化した。
- **実務への応用ポイント:**
  - 単一 DB なら `BIGINT UNSIGNED AUTO_INCREMENT`、アプリ側での分散採番が必要なら `ULID` / `UUIDv7` を `BINARY(16)` で格納し、`CHAR(36)` のランダム `UUIDv4` 主キーは避ける。

### [Phase 4-5] InnoDB バッファプールと統計情報（`ANALYZE TABLE`）（2026-11-26）

- **学んだ原理・内部構造:**
  - InnoDB は 16KB ページをメモリ（InnoDB Buffer Pool）上にキャッシュしており、`read_requests`（論理読み込み）に対する `reads`（物理ディスク読み込み）の割合が低いほど（ヒット率 99% 以上が目安）高速に動作する。
  - オプティマイザはランダムサンプリングによる統計情報（`Cardinality`＝ユニーク値数の推定値）をもとに実行計画を決めるため、大量更新後に偏りが起きると実行計画が急変することがあり、`ANALYZE TABLE` で再計算できる。
  - 複合インデックスの設計では「等価条件 → 範囲／ソート」が最優先であり、その上で等価条件同士はカーディナリティが高い列を先に置く。
- **観測した事実:**
  - `@@innodb_buffer_pool_size` は `128MB`、実験環境でのヒット率は `91.576%`（`requests=16642, reads=1402`）だった。
  - `ANALYZE TABLE orders_perf` 後の `SHOW INDEX` で、`id`（`20128`）や `ordered_at`（`20000`）は高カーディナリティ、`user_id`（`500`）は中カーディナリティとして記録されていることを確認した。
- **実務への応用ポイント:**
  - 本番 DB では `innodb_buffer_pool_size` を物理メモリの 70〜80% 程度に確保し、ヒット率低下（ディスク I/O 増）を監視する。
  - 実行計画が突然悪化した際は `SHOW INDEX` と `ANALYZE TABLE` で統計情報のズレを確認する。

### [Phase 4-6] サブクエリ・集計・`SELECT *` のチューニング（2026-11-26）

- **学んだ原理・内部構造:**
  - `GROUP BY` でインデックス順に並んでいない列を指定すると、集計用の一時テーブル（`Using temporary`）が作られる。インデックスの先頭列で `GROUP BY` すればストリーミング集計でき一時テーブルが不要になる。
  - ヒット件数が多い範囲検索で `SELECT *` を行うと、ブックマークルックアップのコストが高すぎてオプティマイザがインデックスを諦め、フルテーブルスキャン（`Table scan`）に落ちることがある。必要カラムだけに絞ってカバリングインデックスを効かせると劇的に高速化する。
- **観測した事実:**
  - `GROUP BY status` では `Using index; Using temporary` が発生し、`GROUP BY user_id` では `Using index` のみで解消した。
  - `orders_perf` の 8,000 件範囲検索で、`SELECT *` は 20,000 行フルスキャン（`15.9 ms`）だったのに対し、カバリング指定では `idx_good_order` の 8,000 行走査（`4.17 ms`、約 4 倍高速）になった。
- **実務への応用ポイント:**
  - 集計クエリでは `GROUP BY` 列を意識したインデックス設計を行い、一覧や集計では `SELECT *` を避けてカバリングインデックスを活用する。

### [Phase 5-1] MVCC（スナップショット読み）と Undo ログ肥大化（2026-11-26）

- **学んだ原理・内部構造:**
  - `UPDATE` / `DELETE` のたびに古い世代が Undo ログへ退避され、`DB_ROLL_PTR` で繋がれたバージョンチェーンが形成される。
  - `REPEATABLE READ` では最初の `SELECT` 時点で Read View が撮影され、通常の `SELECT`（Consistent Read）はバージョンチェーンを遡って開始時点の値を返す一方、`FOR UPDATE`（Current Read）は最新行を直接ロックして読む。
  - 長時間トランザクションが Read View を保持し続けると、パージスレッドが Undo ログを削除できなくなり、`History List Length` が肥大化して全クエリの参照性能を劣化させる。
- **観測した事実:**
  - Session A が `stock=50` を読んだ状態で Session B が 100 回 `increment('stock')` を実行すると、`History List Length` が `0` → `101` に増加した。
  - 同じ Session A のトランザクション内で、通常の `SELECT` は `50`（スナップショット読み）、`lockForUpdate()` は `150`（現在読み取り）を返すことを確認した。
- **実務への応用ポイント:**
  - `commit` / `rollBack` 忘れや長時間バッチ（`mysqldump --single-transaction` 等）による長時間 Read View 保持を避け、`History List Length` を監視指標に含める。

### [Phase 5-2] Redo ログと binlog の役割とクラッシュリカバリ（2026-11-26）

- **学んだ原理・内部構造:**
  - **Redo ログ（WAL）:** 変更内容を末尾追記（シーケンシャル I/O）で記録し、本体（`.ibd`）への反映を後回し（Dirty Page として Buffer Pool に保持 → バックグラウンド Flush）にすることで高速性と耐久性を両立する。
  - **binlog（`ROW` 形式）:** `COMMIT` 時にだけ変更前後の行データを書き出し、末尾の `Xid` イベントで 2 フェーズコミット（2PC）の確定印を残すことで Redo ログとの整合性を保証する。
- **観測した事実:**
  - `innodb_flush_log_at_trx_commit = 1` / `sync_binlog = 1` / `binlog_format = ROW` / `innodb_redo_log_capacity = 100MB` であることを確認。
  - `products.id = 10` の `stock` を 1 行 `increment` してコミットすると、`SHOW BINARY LOG STATUS` の Position が `452 Byte` 進むことを観測した。
- **実務への応用ポイント:**
  - プライマリ DB では `innodb_flush_log_at_trx_commit = 1` と `sync_binlog = 1` を維持して完全 ACID を担保する。
  - `ROW` 形式では 1 行の変更でも行全体が記録されるため、大量一括更新時はチャンク分割して binlog 肥大化とレプリケーション遅延を防ぐ。
