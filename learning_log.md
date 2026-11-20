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
