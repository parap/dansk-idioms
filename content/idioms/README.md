# Hand-added idioms

The imported corpus can always be replayed from its Telegram export. An idiom somebody
simply knows has no export to replay, so these files are the source of truth and the
database is derived from them.

```bash
docker-compose exec app php bin/idiom-import.php          # every file here
docker-compose exec app php bin/audit-shared-senses.php   # gate before sharing
```

Re-running is safe: an idiom already present keeps its id, the newest translation takes
the answer slot, and the rest are kept alongside it.

## An entry

```json
{
  "term":     "at forholde sig til (noget)",
  "kind":     "phrase",
  "shape":    "verbal",
  "ru":       "отреагировать на что-либо",
  "also":     ["разобраться с чем-либо"],
  "literal":  ["the word-for-word reading"],
  "synonyms": ["another Danish term meaning the same"],
  "retire":   ["a wrong sense to delete outright"],
  "explain":  "the full meaning, shown after the learner answers"
}
```

- **`ru` is the answer** and must fit a quiz option: at most 6 words and 60 characters.
  A longer gloss has no distractors of comparable length, which gives it away on sight.
- **`also`** are further accepted senses, kept for reverse rounds and the distractor pool.
- **`explain`** is where the rest of the meaning goes, so nothing an author knows is lost.
- **`literal`** is the word-for-word reading. It is stored but never offered as an answer:
  a literal gloss is the best distractor there is and a terrible answer.
- **`retire`** deletes a sense outright. Demoting a wrong translation is not enough — it
  stays in the distractor pool, where obvious metalanguage tells a reader what to rule out.
- **`(noget)` / `(nogen)`** stay visible in the term and are stripped from the dedupe key,
  so `at forholde sig til (noget)` keys as `forholde sig til`.
- A Danish term containing Cyrillic letters is refused. `forholде` looks right, is a
  different word, and would key as its own idiom that nothing ever matches.

## `synonyms.json`

A list of term lists. Every member of a group ends up in one synonym group, and neither
direction of the quiz will offer one member as another's wrong answer.

```json
[["sgu", "fandeme"], ["at hoppe på", "at melde sig på banen"]]
```

It is separate from the entry files because it describes relationships over the *imported*
corpus, which this repository cannot reproduce. A group naming an idiom that is not
present is skipped rather than fatal, so a fresh database still loads.

## Why the audit matters

The distractor picker excludes options belonging to the idiom being asked about — but not
another idiom's copy of the same words. Two idioms sharing a sense is therefore enough for
one to be offered as the other's wrong answer, and a learner who picks a genuinely correct
translation is marked down for it. Reverse rounds are worse: the options are the Danish
terms themselves, and near-duplicate rejection compares text, so two terms that merely
mean the same thing pass straight through.

`bin/audit-shared-senses.php` lists every undeclared pair and exits non-zero while any
remain. Where a pair really means the same thing, declare it. Where it does not, remove the
shared sense from whichever idiom it fits worse.
