# Verb drills

One file per set of 25 verbs, served at `/verbs?s=<slug>`. The format is the reading one
(`content/reading/README.md`) with `kind: verbs` and no text section.

```
kind: verbs
slug: verbformer-001-025
title: Verber 1–25

--- questions ---
1. er (nutid) — быть. Navneform?
> navneform være · nutid er · datid var · førnutid været · bydeform vær — быть
* være
  var
  været
  vær
```

The files are generated, not written by hand, in two steps. `bin/verbs-source.py` builds
`source.json` -- the 700 most frequent verbs in DSL's lemma list, with forms from FLEXIKON
checked against Den Danske Ordbog -- from DSL downloads kept outside the repository.
`bin/verbs-generate.py` writes the drills from `source.json` and the hand-written
`translations.json`, and needs nothing else. Each question shows the verb in one form
and asks for another -- navneform, nutid, datid, førnutid or bydeform, as Danish courses
name them -- never two forms spelled alike (kunne is both navneform and datid). The pair
is chosen per verb from an md5 of the verb, so regenerating changes nothing. The wrong
options are the verb's other forms, then a regular-looking form of the wrong conjugation
that DDO does not list as valid. Every question carries the whole verb with its meaning as
a note, shown once it is answered. Edit the generator and
regenerate rather than editing a file.

The `verbgenkend-*` sets ask the other way round: an irregular form (`gik — datid af …?`,
`kan — nutid af …?`) and four infinitives. Only verbs whose datid or nutid cannot be read
off the infinitive are asked, and a prefixed verb only when its base is not on the list;
a verb that also has the shown form is never offered as a wrong answer.

The list at `/verbs` is in load order, and the importer loads files in name order, so the
names keep the form drills (`verbformer-`) ahead of the recognition sets (`verbgenkend-`).
Each tile there shows a picture of one verb from its set, chosen by hand in `PICTURES` at
the top of `public/verbs.html`; a set missing from it gets a plain book.

```bash
python3 bin/verbs-source.py ~/Claude/danish-verbs/src   # only when the verb list itself changes
python3 bin/verbs-generate.py
docker-compose exec app php bin/reading-import.php --publish content/verbs/*.txt
```
