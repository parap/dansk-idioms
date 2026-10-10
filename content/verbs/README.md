# Verb drills

One file per set of 25 verbs, served at `/verbs?s=<slug>`. The format is the reading one
(`content/reading/README.md`) with `kind: verbs` and no text section.

```
kind: verbs
slug: verber-001-025
title: Verber 1–25

--- questions ---
1. være — быть. Datid?
* var
  været
  er
  værede
```

The files are generated, not written by hand: `~/Claude/danish-verbs/scripts/make_drills.py`
builds them from the 700 most frequent verbs in DSL's lemma list, with forms from
FLEXIKON checked against Den Danske Ordbog. Odd ranks ask for the datid (præteritum), even
ranks for the førnutid's participle, named as Danish courses name them. The wrong options are the verb's other forms and a regular-looking
form of the wrong conjugation that DDO does not list as valid. Edit the generator and
regenerate rather than editing a file.

The `genkend-verbet-*` sets ask the other way round: an irregular form (`gik — datid af …?`,
`kan — nutid af …?`) and four infinitives. Only verbs whose datid or nutid cannot be read
off the infinitive are asked, and a prefixed verb only when its base is not on the list;
a verb that also has the shown form is never offered as a wrong answer.

```bash
python3 ~/Claude/danish-verbs/scripts/make_drills.py content/verbs
docker-compose exec app php bin/reading-import.php --publish content/verbs/*.txt
```
