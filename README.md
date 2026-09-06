# dansk.div — Danish idiom trainer

Quiz app for Danish idioms with Russian explanations, sourced from a Telegram export.
Plain PHP 8.3 + PDO, no framework, dockerized. Full plan:
`~/.claude/plans/ok-i-have-a-elegant-stearns.md`

## Running it

```bash
docker-compose up -d --build
docker-compose exec app composer install
docker-compose exec app php bin/migrate.php
```

| URL | What |
|---|---|
| http://localhost:8080 | app shell |
| http://localhost:8080/api/v1/health | health + DB check |
| http://localhost:8080/api/v1/stats | corpus counters |
| http://localhost:8081 | Adminer (server `db`, user `dansk`, pass `dansk`) |

Ports: the container publishes **8080** because host Apache owns :80, and the DB
publishes **nothing** because host MySQL owns 127.0.0.1:3306.

## Gotchas learned the hard way

**Always pass `--default-character-set=utf8mb4` to the `mysql` CLI.** The client in the
`mysql:8.0` image negotiates **latin1** by default. Danish characters then round-trip
*visually* correctly (mangled on insert, un-mangled on select) while being stored wrong,
which silently invalidates any manual test of Danish text.

```bash
docker-compose exec db mysql --default-character-set=utf8mb4 -uroot -proot dansk
```

PHP is unaffected: the PDO DSN pins `charset=utf8mb4`.

**Accent-insensitive collation is a real hazard, verified here.** Under MySQL 8's default
`utf8mb4_0900_ai_ci`, `'har' = 'hår'`, `'o' = 'ø'` and `'ae' = 'æ'` all evaluate true, so a
UNIQUE index rejects `hår` once `har` exists. Every normalized/unique column is therefore
declared `COLLATE utf8mb4_0900_as_cs`; case is folded in PHP before insert.

**`idiom_translations.is_primary` is `1` or `NULL`, never `0`.** The single-primary-per-
(idiom, lang) rule is enforced by `UNIQUE (idiom_id, lang_code, is_primary)` relying on
MySQL ignoring NULLs. Writing `0` would collapse every non-primary row into one.
(A STORED generated column was the first attempt; MySQL refuses `ON DELETE CASCADE` on a
foreign key over a column a stored generated column reads.)

**Migrations do not run in a transaction.** MySQL implicitly commits on every DDL
statement, so a wrapping transaction cannot roll a failed migration back and only makes
`commit()` throw afterwards, masking the real error. A failed migration is repaired by hand.

**`docker-compose`, not `docker compose`** — this box has the standalone v2.5.1 binary.

## Layout

```
public/     document root — front controller, assets, PWA shell
src/        Http/ Support/ Import/ Domain/ Controller/
bin/        migrate.php, later import.php / reparse.php
db/         numbered .sql migrations
tests/      parser fixtures (the regression contract for the importer)
```

`index.html` in the project root is the old placeholder served by *host* Apache at
`http://dansk.div`; it is outside the container docroot and unused by the app.

## Importer notes

`php bin/import.php --file=storage/exports/messages.html [--dry-run]`

Re-running is safe: messages, raw entries and translations all upsert, and a `raw_entries`
row whose `status` is `fixed` (human-edited) is never overwritten by a re-parse.

**Never use `trim($s, "…«»")` on UTF-8.** `trim()` with a character list is byte-based, and
`…` (E2 80 A6) contributes `0x80` — the trailing byte of many Cyrillic letters. It cut `р`
(D1 80) in half and MySQL rejected the row with *Incorrect string value*. Use
`Text::trimPunctuation()`, which is `preg_replace` with `/u`.

Parser strategies, in confidence order: `bold` (Telegram `<strong>` headword) → `script`
(separator confined to the pre-Cyrillic prefix) → `newline` (headword alone on its own line)
→ `separator` → `fallback`. Run `vendor/bin/phpunit` after touching any of them; the fixtures
encode every failure mode observed in the real export.

## Review queue

`http://localhost:8080/admin` — password from `admin.password` in config
(default `dansk-admin`; override in the gitignored `config/local.php`). This is an
interim gate until real accounts arrive in Phase 2.

Keyboard-driven: <kbd>Enter</kbd> accept, <kbd>N</kbd> skip, <kbd>R</kbd> reject.
Extracted candidates appear as clickable chips; literal glosses are struck through
because they are retained as distractors but must never be the answer.

**Accepting writes `raw_entries.status = 'fixed'`, which a re-import must never
overwrite.** That protection covers not just the status but the corrected term and —
most importantly — `idiom_id`. An earlier version protected only the status, so
re-importing silently set `idiom_id` back to NULL, orphaning the reviewed idiom from
its source message and undercounting `seen_count`. Verified by importing twice and
asserting the link survives.

## Quiz (Phase 2)

`http://localhost:8080` — ten idioms per round, four options, explanation after each answer.
Keyboard: <kbd>A</kbd>–<kbd>D</kbd> or <kbd>1</kbd>–<kbd>4</kbd> to answer, <kbd>Enter</kbd> to continue.

**Anti-cheat.** Questions are generated and stored server-side before being served, and
`correct_index` is never present in a question payload. Grading reads the stored row, never
the request body. `response_ms` is client-supplied and clamped to 0–300000.

**Distractors** are not random — that would let anyone find the answer by length, register or
shape without knowing Danish. Candidates are filtered (word count ±3, no declared synonym, no
reported block, never another idiom from the same round), then near-duplicates are rejected
(token Jaccard ≥ 0.34, substring containment), then scored on length, shape, register and kind.
About 40% of questions reserve a slot for another idiom's *literal* gloss — register-matched,
vivid and guaranteed wrong.

**Offline** is shell-only by design: `/api/*` is never cached, because caching a scored quiz
would ship the correct answers to the client.

Two MySQL gotchas encoded in the code:
- `LIMIT ?` cannot be a bound parameter with `ATTR_EMULATE_PREPARES = false` — PDO sends it as
  a string and MySQL rejects `LIMIT '10'`. Validated integers are interpolated instead.
- `word_count` is `TINYINT UNSIGNED`, so `word_count - 5` wraps around rather than going
  negative (error 1690). Casts to `SIGNED` before arithmetic.

## Interface language

UI strings live in a single `STRINGS` object at the top of `public/app.html`, keyed by
language code (`ru` is the default, `en` supplied). Nothing else in the page hard-codes
user-facing text — everything goes through `t('key')`, which falls back to Russian for any
key a translation has not filled in yet. Adding a language means adding one block.

The choice is remembered in `localStorage` under `ui_lang` and switched from the header.
Note this is *interface* language only; it is independent of `idiom_translations.lang_code`,
which is the language of the answers themselves.

To check a translation is complete, compare the keys used in code against each block —
all must be present and none unused.
