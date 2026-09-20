# 18 — MySQL 8.4 Compatibility Review

Documentation review of the planned schema (`03`–`12`, as reconciled with OD-04/OD-08/OD-10) against **MySQL 8.4 LTS**, the confirmed production engine (MySQL 8.4.6, utf8mb4, PHP 8.2.33, Laravel 12 `mysql` driver). No migrations exist; this document lists what is compatible, what must be written in a specific way, and what must be proven by the first migration spike.

**Method and limits.** This is a static review against MySQL 8.0/8.4 documented behaviour. **No MySQL 8.4 server is available in this environment** (the local XAMPP server is MariaDB 10.4.32, which is *not* production-equivalent and was deliberately not used as evidence). Items marked **VERIFY** must be confirmed on a real MySQL 8.4 instance before the corresponding migration is accepted (§7).

**Verdict:** **no blocking incompatibility found.** Eight adjustments are required (§4) — all are ways of *writing* the migrations, not design changes.

## 1. Version assumptions

| Assumption | Value |
|---|---|
| Target engine | MySQL **8.4 LTS** (production 8.4.6), InnoDB |
| Documented minimum | **8.0.19** — CHECK enforcement (8.0.16), `ALTER TABLE … DROP CONSTRAINT` (8.0.19). Development should use 8.4.x. |
| Laravel driver | `mysql` (never `mariadb`) |
| Not used | MariaDB syntax/behaviour, MySQL 5.7 behaviour, triggers, stored routines, window/CTE-dependent schema logic |
| Connection | `charset utf8mb4`, `collation utf8mb4_unicode_ci`, `strict true`, `timezone '+00:00'` (§3.10) |

## 2. Summary

| Area | Verdict | Required change |
|---|---|---|
| CHECK constraints | OK — **VERIFY** FK-column combination | A-1, A-2, A-3 |
| Foreign keys / ON DELETE | OK | A-1 (write history FKs with the default clause) |
| Unique indexes | OK | — |
| Generated columns + UNIQUE | OK — **VERIFY** | A-7 (explicit types) |
| Index lengths / collations | OK | A-4, A-5 |
| `DECIMAL(12,2)` money | OK | application must not use floats |
| Row locking / `SELECT … FOR UPDATE` | OK | — |
| Isolation assumptions | OK | A-6 (CAS updates change a column) |
| ULID / `public_id` | OK | lower-case discipline (§3.8) |
| JSON columns | OK | A-2 (drop `JSON_VALID` CHECKs) |
| VARCHAR status + PHP enums | OK | A-5 (exact-match vocabularies) |
| utf8mb4 | OK | A-4 |
| `REGEXP` / `SUBSTRING` in CHECK | OK — **VERIFY** | none expected |
| Time zone / sql_mode | OK | A-8 |

## 3. Detailed review

### 3.1 CHECK constraints and foreign keys
* MySQL enforces CHECKs since 8.0.16 and 8.4 keeps this. Permitted expressions: literals, deterministic built-in functions and operators. The design's CHECKs use only the row's own columns and `IN`, `IS NULL`, comparison, arithmetic, `LPAD`, `SUBSTRING`, `MOD`, `REGEXP` — all permitted. **No CHECK uses a subquery, `NOW()`, a user variable, another table, or an AUTO_INCREMENT column** (verified by review of `14` §3.2).
* **Restriction:** MySQL rejects a CHECK on a column that participates in a foreign key with a `CASCADE`, `SET NULL` or `SET DEFAULT` referential action (error 3823), and rejects such an action on a CHECK-constrained column. Review of every CHECK-constrained FK column:

| CHECK-constrained FK column | FK action in this design |
|---|---|
| `payments.membership_term_id`, `payments.order_id` | history ⇒ default (`NO ACTION`) |
| `documents.membership_application_id/payment_id/job_application_id/membership_details_request_id/purged_by_user_id` | history ⇒ default |
| `carts.user_id` | default (users never deleted) |
| `orders.coupon_id`, `orders.shipping_method_id` | default |
| `inventory_transactions.order_item_id` | default |
| `membership_applications.decided_by_user_id` | default |
| `membership_status_history.membership_application_id/membership_id` | default |
| `membership_terms.membership_plan_id` | default |
| `audit_logs.user_id` | default |

  Columns with `CASCADE` (`blog_post_blog_tag.*`, `cart_items.cart_id`, `product_images.product_id`, `blog_comments.blog_post_id`) and `SET NULL` (`blog_posts.author_id/blog_category_id`, `created_by_user_id` columns, `products.product_category_id`, `carts.coupon_id`, `job_postings.posted_by_user_id`) **appear in no CHECK** — compatible.
* **`RESTRICT` vs `NO ACTION`:** InnoDB treats them identically (immediate check). The policy documents say "RESTRICT"; **the migrations should simply omit the ON DELETE/ON UPDATE clause (default `NO ACTION`)** — Laravel's `foreignId()->constrained()` without `restrictOnDelete()`. This removes any doubt about whether an explicit `RESTRICT` clause is treated as a "referential action" for the CHECK rule (**VERIFY** in the spike; expected fine either way). → **A-1**.
* **CHECK names are unique per schema** (not per table) in MySQL. → **A-3**: name every CHECK `{table}_{rule}` (e.g. `payments_exactly_one_purpose`, `memberships_number_format`).
* A CHECK whose expression evaluates to `NULL` **passes**. The design guards nullable columns explicitly (`IS NULL OR …`, `(a IS NULL) <> (b IS NULL)`); comparisons that are meant to *require* a value use `NOT NULL` columns or explicit `IS NOT NULL`.
* Adding a CHECK by `ALTER TABLE` is fine on empty tables; add all CHECKs in the same migration immediately after `Schema::create` (`DB::statement`). Laravel 12 has no `check()` helper.

### 3.2 Unique indexes
Standard InnoDB unique indexes; multiple `NULL`s are permitted in a UNIQUE index (relied on deliberately for `verification_token`, `guest_token`, `memberships.user_id`, generated `*_key` columns, `(payment_id, gateway_reference)`). Case-insensitive collation makes `users.email`, `slug`, `sku`, `coupons.code` case-insensitively unique. **No issue.**

### 3.3 Index lengths and collations (utf8mb4 = up to 4 bytes/char; DYNAMIC row format, key limit 3072 bytes)

| Index | Bytes | OK? |
|---|---|---|
| `users.email` VARCHAR(255) | 1020 | ✓ |
| `open_email_key` (virtual VARCHAR(255)) | 1020 | ✓ |
| `(email, status, decided_at)` | ≈ 1020 + 120 + 5 | ✓ |
| `payment_webhooks (gateway VARCHAR(30), event_id VARCHAR(191))` | 120 + 764 = 884 | ✓ |
| `documents (disk VARCHAR(30), storage_path VARCHAR(500))` | 120 + 2000 = **2120** as utf8mb4 | ✓ but wide → **A-4: declare both `ascii`** (530) |
| `template_key` VARCHAR(100) | 400 | ✓ |
| `slug` VARCHAR(255) | 1020 | ✓ |
| `sku` VARCHAR(64) | 256 | ✓ |
| ULIDs, token hashes, membership numbers, category codes (`ascii_bin`) | 26 / 64 / 9 / 1 | ✓ |

Row-size (65,535-byte definition limit): widest tables (`payments` with `payment_url VARCHAR(2048)`, `documents`, `membership_applications`, `audit_logs`) total well below the limit; TEXT columns are off-page pointers. **No issue.**

**Collation mixing:** all string FKs are BIGINT (none are string FKs), so cross-collation FK errors cannot occur. Comparisons between an `ascii_bin` column and a `utf8mb4_unicode_ci` literal coerce safely; comparing two *columns* of different collations in a join would raise "illegal mix" only if both are explicit — none of the reconciliation queries do this. Laravel's connection collation `utf8mb4_unicode_ci` is used for all tables created without an explicit collation (do **not** rely on the server default `utf8mb4_0900_ai_ci`; set `collation` in `config/database.php` to match). → **A-4/A-8.**

### 3.4 Generated columns
Seven `VIRTUAL` columns (`14` §4) with UNIQUE secondary indexes. MySQL 8.4 supports secondary and unique indexes on virtual generated columns in InnoDB. Constraints satisfied: expressions are deterministic, use only the same row's columns, no subqueries; the base columns' FKs use the default action (MySQL forbids `CASCADE`/`SET NULL` on base columns of *stored* generated columns; not applicable to virtual columns here, and none is used); no FK references a generated column; none is `NOT NULL`. `IF(cond, col, NULL)` yields the type of `col` — declare the generated column with an explicit compatible type (`BIGINT UNSIGNED`, `VARCHAR(255)`, `VARCHAR(64)`) → **A-7**. **VERIFY** in the spike (creating a table with a virtual column + UNIQUE index in one statement, inserting duplicates of `NULL`).

### 3.5 `DECIMAL(12,2)` money
Exact fixed-point; arithmetic in CHECKs (`total = subtotal − discount + shipping`, `line_total = unit_price * quantity`) is exact in DECIMAL. No unsigned DECIMAL is used (deprecated). **Application rule:** never cast money to float; use Eloquent `decimal:2` (string) casts or integer minor-unit arithmetic in PHP and pass strings to the database. Sums (`SUM(decimal)`) are exact.

### 3.6 Row locking and `SELECT … FOR UPDATE`
InnoDB record locks. The membership-number lock (`SELECT … FOR UPDATE` on `membership_number_sequences` by the unique key `(category, year)`) locks the single existing row (no gap lock when the row exists); the *miss* path (row absent) takes a gap lock and can deadlock under concurrency — handled by the documented pattern (locking read first; on miss `INSERT … ON DUPLICATE KEY UPDATE id = id`; retry via `DB::transaction(..., attempts: 3)`; or pre-create next year's rows). Lock order used by the actions: application (CAS) → sequence row → member insert; renewals: term → payment. Foreign-key checks take shared locks on parent rows; the documented lock ordering (`15` §4) keeps them consistent. `NOWAIT`/`SKIP LOCKED` are available in 8.x but not needed. `innodb_lock_wait_timeout` (default 50 s) is acceptable; Laravel treats deadlock/lock-timeout as retryable in `DB::transaction`.

### 3.7 Transaction isolation
Default `REPEATABLE READ`. Plain `SELECT`s read a consistent snapshot; **locking reads and UPDATEs see the latest committed data**, which is what the sequence, CAS and stock logic rely on. No design step depends on `READ COMMITTED` or `SERIALIZABLE`. `AUTO_INCREMENT` uses interleaved lock mode (8.x default): primary-key gaps are possible and irrelevant — no business number depends on `AUTO_INCREMENT` (the membership sequence is a separate counter). **No issue.**

### 3.8 ULIDs / public IDs
`CHAR(26) ascii_bin`, UNIQUE. Laravel's `HasUlids` generates **lower-case** ULIDs; a binary collation is case-sensitive, so generate, store and look up **only through the trait/route binding** (lower-case). The binary collation preserves ULID sort order. Signed URLs embed the `public_id`; access always requires the signature (or ownership), never the ID alone. **No issue.**

### 3.9 JSON columns
Only `payments.metadata`, `payment_webhooks.payload`, `audit_logs.old_values/new_values` (+ framework `notifications.data` as TEXT). Native `JSON` validates on write, so **`JSON_VALID` CHECKs are redundant** and are removed from the design (→ **A-2**). No JSON default values (columns default `NULL`); no JSON indexing; payload size bounded by `max_allowed_packet`. Eloquent `array`/`AsCollection` casts work.

### 3.10 utf8mb4, time zone and SQL mode
* utf8mb4 everywhere. Do not use `utf8`/`utf8mb3`.
* **Time zone:** `TIMESTAMP` values are converted using the session time zone; pin the Laravel `mysql` connection to `'timezone' => '+00:00'` and `app.timezone = UTC` so instants are stored as UTC regardless of the SiteGround server zone. (Business `DATE` columns use the configured business timezone, OD-09.) → **A-8**.
* Default `sql_mode` in 8.4 includes `STRICT_TRANS_TABLES` and `ONLY_FULL_GROUP_BY`; keep Laravel `strict => true`. Reconciliation queries (`15` §5) and report queries must be `ONLY_FULL_GROUP_BY`-clean (the ones written are).
* `explicit_defaults_for_timestamp` is ON (default): `timestamps()` columns are nullable with no implicit default; append-only tables declare `created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` explicitly.
* MySQL 8.4 disables the `mysql_native_password` authentication plugin by default; the database user must use `caching_sha2_password` (supported by PHP 8.2's mysqlnd). Ops item, not a schema item.

### 3.11 VARCHAR status fields with PHP enums
Status/type columns are `VARCHAR` (no native ENUM). For ACI-closed vocabularies a CHECK `IN (...)` is the backstop. **Note:** with a case-insensitive collation `CHECK (status IN ('active'))` also accepts `'ACTIVE'`. PHP backed-enum casting is exact-match, so the harmless-but-untidy case-insensitive acceptance is avoided by declaring CHECK-guarded vocabulary columns `ascii` + `ascii_bin` → **A-5**. Adding a vocabulary value = `ALTER TABLE … DROP CONSTRAINT name; ADD CONSTRAINT name CHECK (…)` (8.0.19+).

### 3.12 `REGEXP` / `SUBSTRING` in CHECK
`membership_number REGEXP '^[SPV][0-9]{8}$'` — MySQL 8 uses ICU regular expressions; the pattern is standard. On a `_bin` column matching is case-sensitive, so lower-case letters are rejected. `SUBSTRING(membership_number,2,2)`, `LPAD(number_year MOD 100, 2, '0')` are deterministic built-ins. **VERIFY** the three in the spike.

## 4. Required adjustments (implementation-form; no design change)

| ID | Adjustment | Where documented |
|---|---|---|
| **A-1** | Declare history-bearing FKs **without** an explicit ON DELETE/ON UPDATE clause (default `NO ACTION` ≡ `RESTRICT`). Never put `CASCADE`/`SET NULL` on a column used in a CHECK. | `01` §3.4 policy unchanged in meaning; this doc |
| **A-2** | Do **not** add `JSON_VALID` CHECKs on native JSON columns. | `09`, `14` (updated) |
| **A-3** | Give every CHECK a schema-unique name `{table}_{rule}`. | `14` |
| **A-4** | Server-generated machine identifiers are `ascii`: `documents.disk`, `documents.storage_path`, plus already-ascii `public_id`, `token_hash`, `membership_number`, `code`, `checksum_sha256`. (Free-text/user content stays utf8mb4.) | `07`, `14` |
| **A-5** | CHECK-guarded vocabulary columns use `ascii_bin` (exact-match), matching PHP enums. | `14` §3.1 |
| **A-6** | Every compare-and-set `UPDATE` must change at least one column (MySQL reports *changed* rows; an update that sets a column to its current value reports 0 even though it matched). CAS statements always move a status/timestamp/counter. | `04` §10.1, `06` §5.1, `15` |
| **A-7** | Declare generated-column types explicitly; keep them `VIRTUAL`, nullable, and never an FK target. | `14` §4 |
| **A-8** | Configure the `mysql` connection: `charset utf8mb4`, `collation utf8mb4_unicode_ci`, `strict true`, `timezone '+00:00'`; do not rely on server defaults (`utf8mb4_0900_ai_ci`). | this doc |

## 5. Runtime behaviours to code against
* CAS pattern: `UPDATE t SET status='x', <timestamp>=… WHERE id=? AND status='y'` and test `affected == 1` (A-6).
* Duplicate-key handling: catch SQLSTATE `23000`/error 1062 for idempotent inserts (webhooks, payments, refunds) and treat as "already processed".
* Deadlocks (1213) and lock-wait timeouts (1205): retry the whole transaction (`DB::transaction($fn, 3)`), never retry a partial one.
* `ONLY_FULL_GROUP_BY`: aggregate or group every selected column.
* Money: strings/`decimal:2`; never float.
* Membership number: generated only inside the first-activation transaction; the model marks `membership_number`, `number_year`, `number_sequence`, `membership_category_id` immutable (R-25).

## 6. Test database requirements
* **Database-integrity/integration tests run on MySQL 8.x matching production (target 8.4)**, in a dedicated database (e.g. `aci_test`) — not SQLite. SQLite cannot reproduce row locks, MySQL CHECK enforcement, generated-column UNIQUE indexes, `NO ACTION` FKs, or InnoDB deadlock/transaction behaviour.
* `phpunit.xml` currently defaults to SQLite in-memory; it is **not modified** by this documentation task. The integration suite overrides it (environment variables or `.env.testing`) with `DB_CONNECTION=mysql`. Pure unit tests with no database-integrity assertions may keep SQLite.
* Concurrency tests need real parallel connections (separate processes/workers), so they cannot run inside a single wrapping transaction.
* Minimum test list: (1) N parallel first-activations in one category/year → strictly distinct, gap-free-on-success sequence values; (2) failed activation rolls back its number; (3) two concurrent activations of one approved application → one member (and two concurrent approvals → one decision); (4) renewal never changes `membership_number` and never calls the generator; (5) duplicate webhook delivery → one business effect; (6) payment with both/neither purpose FK → rejected by the database; (7) `amount <= 0` rejected; introductory term with a fee/payment rejected; (8) oversell attempt rejected by the stock CHECKs; (9) second open application for one email rejected by `open_email_key`; (10) an `information_schema.CHECK_CONSTRAINTS` assertion that every expected CHECK exists and is enforced (guards against silent loss on a different server).
* Local development should run MySQL 8.4 side by side with XAMPP (separate data directory/port) so XAMPP's MariaDB is untouched; the MariaDB instance must not be used to validate migrations.

## 7. Items to VERIFY in the first migration spike (on MySQL 8.4)

| # | Behaviour | Fallback if it fails |
|---|---|---|
| 1 | CHECK on a column that also has a plain FK (default action) — e.g. `payments` exactly-one-purpose — and with an explicit `RESTRICT` | Enforce the XOR through a `VIRTUAL` generated flag column that is CHECKed instead of the FK columns themselves; keep application guard + reconciliation Q2 |
| 2 | Virtual generated column + UNIQUE index created in the same `CREATE TABLE`; multiple NULLs accepted; duplicate non-NULL rejected | Use `STORED` generated columns (allowed while base-column FKs are not CASCADE/SET NULL) |
| 3 | `REGEXP` + `SUBSTRING` + `LPAD … MOD` CHECK on `membership_number` | Split into simpler CHECKs (length + regexp; components equality) |
| 4 | `ALTER TABLE … DROP CONSTRAINT <check>; ADD CONSTRAINT …` | `DROP CHECK <name>` (also valid in 8.4) |
| 5 | `caching_sha2_password` connectivity from the SiteGround PHP 8.2.33 build | Ask hosting to provision a supported user; ops only |

## 8. Conclusion
The design is compatible with MySQL 8.4 as written. The eight adjustments (A-1 … A-8) are implementation details for the migrations, and the five spike items (§7) prove the few behaviours that cannot be confirmed without a live 8.4 server. No table, column, constraint or relationship needs to change.
