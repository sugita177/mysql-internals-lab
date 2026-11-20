# Phase 1: インデックス構造とロック範囲の可視化

## 実験テーブル `products` の構造

| カラム | インデックス | 初期データ |
| --- | --- | --- |
| `id` | PRIMARY KEY（クラスタード） | `10, 20, 30, 40, 50`（10刻みでギャップあり） |
| `sku` | UNIQUE INDEX | `SKU-010` 〜 `SKU-050` |
| `category_id` | INDEX（非一意セカンダリ） | `10, 20, 20, 30, 40` |
| `name` | なし | 商品名 |

## 実験の進め方（2つのターミナルを使用）

1. **ターミナル 1（Session A）:** `exercises/phase1/01_lock_scope_session_a.php` の `// TODO:` を実装して実行し、トランザクションを開いた状態で一時停止させる。
   ```bash
   docker compose exec php php exercises/phase1/01_lock_scope_session_a.php
   ```
2. **ターミナル 2（Session B）:** `exercises/phase1/01_lock_scope_session_b.php` の `// TODO:` を実装して実行し、`performance_schema.data_locks` で張られているロックを観測したり、競合クエリ（`INSERT` / `UPDATE`）がブロックされるかを検証する。
   ```bash
   docker compose exec php php exercises/phase1/01_lock_scope_session_b.php
   ```

## 検証シナリオ（順に試す）

1. **ケース1（主キー・存在する行）:** `WHERE id = 20` に排他ロック（`FOR UPDATE`）
2. **ケース2（主キー・存在しない行）:** `WHERE id = 25` に排他ロック
3. **ケース3（非一意セカンダリインデックス）:** `WHERE category_id = 20` に排他ロック
4. **ケース4（インデックスなしカラム）:** `WHERE name = 'Ergonomic Mouse'` に排他ロック
5. **ケース5（分離レベル変更）:** Session A を `READ COMMITTED` にしてケース 3・4 を再検証
