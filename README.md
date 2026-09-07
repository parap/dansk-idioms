# Dansk idiomer

A trainer for Danish idioms with Russian explanations, built from a Telegram group
export. Ten idioms per round, four candidate translations each; pick one, read the
explanation, get a score.

Plain PHP 8.3 + MySQL 8, no framework, no JavaScript build step, all in Docker.

---

## Quick start

```bash
docker-compose up -d --build
docker-compose exec app composer install
docker-compose exec app php bin/migrate.php
bin/load-export.sh                 # import your Telegram export
```

| URL | What |
|---|---|
| http://localhost:8080 | the site |
| http://localhost:8080/admin | review queue |
| http://localhost:8081 | Adminer (server `db`, user `dansk`, password `dansk`) |
| http://localhost:8080/api/v1/health | health + database check |

The container publishes **8080** because host Apache owns :80, and the database
publishes nothing because host MySQL owns 127.0.0.1:3306.

---

## Using the site

**Playing.** Press *Начать раунд*. Answer with the mouse or with <kbd>A</kbd>–<kbd>D</kbd> /
<kbd>1</kbd>–<kbd>4</kbd>; <kbd>Enter</kbd> moves on. The full explanation appears after every
answer, right or wrong.

**Two directions**, chosen on the home screen and remembered per browser:

- **датский → русский** — a Danish idiom, four Russian meanings. Tests recognition.
- **русский → датский** — a meaning, four Danish idioms. Tests production, which is the
  harder and more useful skill: an idiom is recognisable long before it is available.

They need different distractors. Forward matches on Russian verb parity, length, register
and kind. Reverse matches on the *infinitive* marker instead — an `at …` phrase among bare
nouns is identifiable without knowing either — plus length and kind, and rejects any term
sharing a content word with the answer. Term shape and translation shape disagree on 26% of
idioms, so each direction reads its own.

**Accounts** are optional. Without one your progress lives in a browser cookie and you
still get history and best score. With one you get spaced repetition: each idiom you
answer is scheduled by SM-2, and later rounds put what is due first. Rounds you played
before registering are adopted into the new account.

**Interface language** switches in the header (RU/EN) and is remembered per browser.
This is the *interface* only — the answers themselves are Russian until English
translations exist.

**"Мой ответ тоже верный"** appears under a wrong answer. Use it: two independent
reports on the same pairing automatically block that distractor from appearing against
that answer again. It is the only feedback loop the quiz has.

**Installing to a phone.** The site is a PWA — "Add to home screen" gives it an icon
and a standalone window. Offline it will show the shell but cannot serve a scored
round, by design: caching questions would mean shipping the answers to the browser.

---

## Adding new idioms

Post in the Telegram group as usual, then export and load. **Re-export the whole
history every time** — there is no need to narrow the date range.

### 1. Export

Telegram Desktop → **⋮ → Export chat history** → format **HTML**, media not needed.

> HTML rather than JSON on purpose. The HTML export carries the message ids *and*
> timestamps with a UTC offset (`29.08.2026 21:01:39 UTC+01:00`), where the JSON
> export gives naive local time that cannot be corrected afterwards.

### 2. Load

```bash
bin/load-export.sh                       # newest ChatExport_* in ~/Downloads
bin/load-export.sh /path/to/ChatExport_… # or name the folder
```

Multi-part exports (`messages2.html`, …) are handled automatically.

### 3. Review what it was unsure about

Open http://localhost:8080/admin. Entries the parser could not confidently split wait
there, lowest confidence first. <kbd>Enter</kbd> accepts, <kbd>N</kbd> skips,
<kbd>R</kbd> rejects a chunk that is not an idiom at all. Extracted candidates appear
as clickable chips, so you are usually picking rather than typing.

### Re-importing is safe

Everything upserts:

- messages key on `(source, tg_message_id)`, entries on `(message, entry_index)`
- idioms dedupe on a normalised term, so an idiom already present is recognised
- **entries you corrected are never overwritten.** A reviewed entry is marked `fixed`,
  and the decision, the corrected term and the link to the idiom all survive a re-parse

Running it against an unchanged export reports `idioms created 0`. That is the check
that idempotency still holds.

### What to watch in the output

```
  messages read            199
  entries parsed           358
    auto-accepted          328     ← went straight in
    needs review            26     ← waiting for you at /admin
    rejected                 4     ← not idiom entries
  idioms created             0     ← 0 on an unchanged re-import
  entries with no answer    27
  orphaned                   1
  PUBLISHED BUT UNANSWERABLE 0     ← must always be 0
```

**`PUBLISHED BUT UNANSWERABLE`** must stay 0; anything else means idioms have silently
disappeared from the quiz. **`orphaned`** counts idioms whose term a later parser
change rewrote, leaving the old row behind with nothing pointing at it.

### Writing posts the importer handles well

It reads what you already write, so none of this is required — but these are the shapes
it reads most reliably.

**Separate each entry with a zero-width space**, as you do now. It is what tells the
importer where one idiom ends and the next begins. A line starting in Latin is a new
entry; a line starting in Cyrillic continues the one above.

**Bold the Danish headword.** It is the highest-confidence signal there is, and the
only thing that rescues a post opening in Russian prose
(`Глагольная конструкция **at mærke efter** — …`).

**Put the short translation on the head line**, then elaborate:

```
​at slå pjalterne sammen — объединиться, съехаться
​Значение: Разговорный фразеологизм о совместной жизни или деле.
​Объяснение: Буквально «сложить лохмотья вместе».
```

`Значение:`, `Объяснение:`, `Перевод:`, `Дословно:` and `Этимология:` are recognised as
structured fields.

**A few things to avoid**, each of which caused a wrong answer at some point:

- **Two forms in one headword** — `Rodekasser / at være ekspert i rodekasser`. Only one
  becomes the term (the `at …` form); the other is kept as a note. Better to pick one.
- **Glossing a component word with `«…»`** — in `Слово grus означает «гравий, щебень,
  труха»`, that quote defines *grus*, not the idiom. It is now detected and excluded,
  but the pattern is fragile.
- **Respelling Danish in Cyrillic** as the meaning — «экспертом в родекассерах» is not
  a translation.
- **Meta-description as the meaning** — `Значение: Яркое метафорическое выражение` says
  what kind of thing it is, not what it means.
- Keep the primary meaning **under about six words**; longer readings are kept but
  cannot be used as quiz options.

---

## Administration

The review queue is behind a password. **There is no default** — with none configured,
admin login refuses every attempt with 503. Set it in the gitignored `config/local.php`:

```php
<?php return ['admin' => ['password' => 'a-long-random-string']];
```

or via `ADMIN_PASSWORD` in the environment. (A committed default is a published
credential the moment the repository is public.)

### Exposing the site publicly

The machine is behind NAT, so a tunnel is needed. A quick Cloudflare tunnel needs no
account and opens no inbound port:

```bash
docker run -d --name dansk_tunnel --network danskdiv_default --restart unless-stopped \
  cloudflare/cloudflared:latest tunnel --no-autoupdate --url http://app:80
docker logs dansk_tunnel | grep -o 'https://.*trycloudflare.com'
```

The URL changes on every restart, and Cloudflare terminates the TLS, so your traffic is
readable at their edge. `docker rm -f dansk_tunnel` stops it instantly.

---

## Commands

| Command | Purpose |
|---|---|
| `bin/load-export.sh [dir]` | import the newest (or named) Telegram export |
| `php bin/import.php --file=… [--dry-run]` | import one file; `--dry-run` writes nothing |
| `php bin/migrate.php [--status]` | apply pending migrations |
| `php bin/reclassify.php [--dry-run]` | recompute derived shape after changing heuristics |
| `vendor/bin/phpunit` | the whole suite (65 tests) |
| `vendor/bin/phpunit --testsuite unit` | parser and text logic only, no database |
| `vendor/bin/phpunit --testsuite integration` | real SQL against a scratch schema |
| `python3 bin/prove-tests-red.py` | break the code on purpose; every fault must be caught |

Prefix with `docker-compose exec app` for the PHP ones.

---

## How the importer reads a post

Entries are split on U+200B, then continuation lines are merged back: a chunk opening in
Latin is a new entry, one opening in Cyrillic continues the previous. Measured over the
real export that rule is exact on 618 of 619 chunks.

Each entry is then split into term and explanation by a cascade, most confident first:

| Strategy | How |
|---|---|
| `bold` | a Latin-only `<strong>` span is the headword |
| `script` | the separator must lie before the first Cyrillic character, which makes interior colons and dashes unreachable |
| `newline` | the headword alone on its own line under Russian prose |
| `separator` | no Cyrillic at all in the entry |
| `fallback` | split at the first Cyrillic character; always sent to review |

Each parse is scored, and anything below the threshold goes to the review queue instead
of being published. Translations are then pulled from the head line, the labelled
fields, and `«…»` quotes — with quotes that gloss *another* word excluded.

---

## Development

```
public/     document root — front controller, PWA shell, assets
src/        Http/ Support/ Import/ Domain/ Controller/
bin/        migrate, import, reclassify, load-export
db/         numbered .sql migrations
tests/      parser fixtures — the regression contract
```

Interface strings live in one `STRINGS` object at the top of `public/app.html`, keyed by
language. Everything user-facing goes through `t('key')`, which falls back to Russian.
A value may be a map of CLDR plural categories, selected by `Intl.PluralRules`, so
Russian gets its three forms (1 раунд / 2 раунда / 5 раундов).

### Tests

Two suites, because the failures came from two different places.

**`unit`** — 40 tests over parsing, extraction and normalisation. Every case is taken
from the real export and encodes a failure that was actually observed: a gloss of a
component word served as the answer, a literal reading outranking the meaning, a
transliterated Danish word offered as Russian, `trim()` cutting a Cyrillic letter in
half. These are fast and catch extraction regressions the moment a rule changes.

**`integration`** — 25 tests against a scratch `dansk_test` schema, built from the real
migrations and dropped afterwards. This suite exists because *every* expensive bug in
this project lived in code that talks to the database and was unreachable from a unit
test: a primary flag lost on upsert (which left 320 of 349 idioms unanswerable), a
human correction severed by a re-parse, 18 idioms deleted by a wrong definition of
"orphaned", publication that was one-way. The load-bearing assertions are:

- importing an unchanged export twice changes **nothing** — the corpus snapshot must be
  identical, which alone would have caught four of those
- a reviewed entry keeps its status, its corrected term and its link to the idiom
- every published idiom has exactly one usable primary translation
- `is_primary` is never `0`, only `1` or `NULL`
- the correct answer never appears in a question payload, and grading reads the stored
  row rather than the request
- distractors never repeat, never come from the same idiom, and always match the answer
  on verb parity

**A suite that has only ever passed proves nothing.** `bin/prove-tests-red.py` injects 16
faults one at a time — an inverted guard, a dropped filter, a column missing from an
upsert, grading taken from the client's own claim — and requires the suite to fail for
each. Every injection is guarded by a byte comparison against a copy of the original, so
an edit that silently fails to apply cannot be read as a passing check. A fault that
survives means an uncovered case or genuinely equivalent behaviour; read the code it
touches to decide which. Run it after changing anything in `src/`.

The integration suite takes ~9 seconds, nearly all of it re-importing the fixture in
each test's `setUp`. Cleanup between tests uses `DELETE`, not `TRUNCATE`: TRUNCATE is
DDL and InnoDB recreates the tablespace, which at 20 tables per test was over half the
suite's runtime (23s down to 9s from that one change).

The scratch schema needs a grant, applied automatically on a fresh volume by
`docker/mysql/01-test-database.sql`. On an existing volume, run it once by hand:

```bash
docker-compose exec db mysql -uroot -proot \
  -e "GRANT ALL ON \`dansk_test\`.* TO 'dansk'@'%'; FLUSH PRIVILEGES;"
```

### Gotchas worth knowing

**`utf8mb4_0900_ai_ci` is accent-insensitive.** `'har' = 'hår'`, `'o' = 'ø'` and
`'ae' = 'æ'` all evaluate true, so a UNIQUE index under it rejects `hår` once `har`
exists. Every normalised column is `utf8mb4_0900_as_cs`; case is folded in PHP. The same
insensitivity makes `LIKE '%\x02%'` match any string containing *any* ignorable
character — use `INSTR(BINARY col, …)` to hunt for control characters.

**Always pass `--default-character-set=utf8mb4` to the `mysql` CLI.** The client in the
`mysql:8.0` image negotiates latin1, and Danish text then round-trips *visually*
correctly while being stored wrong.

**`trim($s, "…«»")` is byte-based.** `…` contributes `0x80`, the trailing byte of many
Cyrillic letters, so trimming cuts `р` in half. Use `Text::trimPunctuation()`.

**`LIMIT ?` cannot be a bound parameter** with `ATTR_EMULATE_PREPARES = false` — MySQL
rejects `LIMIT '10'`. Validated integers are interpolated.

**`word_count` is `TINYINT UNSIGNED`**, so `word_count - 5` wraps instead of going
negative (error 1690). Cast to `SIGNED` first.

**`idiom_translations.is_primary` is `1` or `NULL`, never `0`.** One primary per
(idiom, language) is enforced by `UNIQUE (idiom_id, lang_code, is_primary)` relying on
MySQL ignoring NULLs.

**Migrations do not run in a transaction.** MySQL commits implicitly on DDL, so a
wrapper cannot roll one back and only makes `commit()` throw, masking the real error.

**The service worker is network-first for HTML.** It was cache-first once, which served
every visitor a permanently stale page. `sw.js` itself is sent `no-cache` so a bad
caching strategy can never become unfixable.

**`docker-compose`, not `docker compose`** — this box has the standalone v2.5.1 binary.
