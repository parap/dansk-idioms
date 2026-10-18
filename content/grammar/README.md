# Grammar drills

One file per set, served at `/grammar?s=<slug>`. The format is the reading one
(`content/reading/README.md`) with `kind: grammar` and no text section.

```
kind: grammar
slug: datid-foernutid-1
title: Datid eller førnutid

--- questions ---
1. I går ___ jeg en ny jakke. (købe)
> «I går» — законченный момент в прошлом → datid. Вчера я купил новую куртку.
* købte
  har købt
  køber
  købede
```

- A set covers one rule. Its slug is the rule's stem and a number (`ordstilling-1`,
  `ordstilling-2`); the page picks the tile's picture by the stem, in `PICTURES` at the top of
  `public/grammar.html`.
- A set holds up to a hundred questions, and a round asks twenty of them: the ones this
  learner has been asked least, in the set's order. A new question goes at the end under the
  next number. Loading a set already in the database adds the numbers it lacks and leaves
  the stored questions as they are, so a question a round has served is changed only in its note.
- A question is a sentence with a gap written `___`, the verb's infinitive in brackets when
  the form is asked, or `Vælg den rigtige sætning.` with whole sentences as options.
- **Every question has a note**: the rule in one or two sentences, in Russian, then the
  sentence translated. A test refuses a shipped set with a question that lacks one.
- **Only one option may be right without context.** Where spoken Danish accepts a second
  form — `Hun gik hjem` beside `Hun er gået hjem`, `Måske han kommer` beside
  `Måske kommer han` — that form is left out of the options rather than marked wrong; a
  learner marked down for correct Danish learns to distrust the set.
- **Passiv**: the s-form and blive + participle are both right in the present and after a
  modal verb (`porten lukkes` / `porten bliver lukket kl. 22`, lex.dk «passiv»), and `er bygget`
  stands beside `blev bygget`, so a question offers one of each pair, never both.
- Wrong options are the mistakes learners make: the other tense, the other auxiliary,
  the other word order. A form that does not exist (`købede`, `har blevet`) is a fair
  distractor; a nonsense string is not.

```bash
docker-compose exec app php bin/reading-import.php --publish content/grammar/*.txt
```

The deploy loads the directory without `--publish`, so a new set reaches the server
unpublished. `--publish` skips a set already loaded, so on the server a set no round has served
yet is published with `--replace --publish`.
