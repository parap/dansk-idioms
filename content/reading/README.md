# Passage documents

One file per passage. `bin/reading-import.php` reads them; nothing else writes reading
content, so these files are the source of truth and the database is derived.

```
kind: cloze
slug: cykler-i-byen
title: Cykler i byen

--- text ---
Hver morgen ruller tusindvis af cyklister ind mod centrum. {{1}} har
kommunen bygget nye stier, og det {{2}} at flere tør cykle til arbejde.

--- questions ---
1.
* Derfor
  Alligevel
  Dernæst
  Til gengæld

2.
* betyder
  betyde
  betydning
  betydet
```

- `kind` is `cloze`, `mc`, `insert` or `write`.
- Wrapped lines rejoin; a blank line stays a paragraph break.
- `{{1}}`, `{{2}}` … are the gaps, and must match the question numbers exactly.
- A star marks the correct option. Exactly one per question, at least three options.
- A line opening with `>` under the question is a note, shown once the question is answered.
  Notes are display only: loading the directory again writes changed notes onto a passage
  already loaded, even one that has been sat, where `--replace` would be refused.

**Multiple choice** carries the question on the numbered line and has no gap markers:

```
--- questions ---
1. Hvorfor begyndte hun at overveje et skift?
* Hun savnede tid til at fordybe sig
  Hun ville tjene mere
  Hun flyttede til en anden by
```

**Insertion** removes whole sentences. The parts are lettered A, B, C … in order in their
own section, each gap names one, and the section must offer more parts than there are gaps
— the spare ones fit nowhere, so the task cannot be finished by elimination alone. A part
runs to a couple of lines and wraps like any other text: a line that does not open the
next letter continues the part above it, so `I Danmark er der …` is a sentence rather than
part I.

```
--- text ---
Siden fagforeningernes opkomst {{1}} har man diskuteret arbejdstiden. {{2}}

--- parts ---
A I Danmark er der hver dag 35.000 sygemeldinger.
B Det forventes at nye medarbejdere møder ind.
C Der er dog udfordringer ved tilpassede forhold.

--- questions ---
1. A
2. C
```

**Written gaps** (`write`, Opgave 3 of Modultest DU3.4) are typed, not chosen. Every line
under a gap is an answer it accepts, with no star; the first is the one a review shows. A
typed answer is compared without case, spaces or punctuation at its ends, so list every word
the gap genuinely takes -- `der` and `som`, `selvom` and `selv om` -- and only those.

```
--- questions ---
2.
der
som
> Подлежащее относительного придаточного — der или som.
```

**A worked example** opens a cloze or insertion task the way the Modultest paper prints
it: gap `{{0}}` in the text and question `0.` underneath, written like any other question
of the task. The page shows it answered, and it scores nothing. An insertion example names
a part of its own, so the bank then offers more parts than the gaps and the example use.

```
--- text ---
Der står kød på menuen, {{0}} danskerne laver aftensmad.

--- questions ---
0.
  da
  selvom
* når
  men
```

## Loading

```bash
docker-compose exec app php bin/reading-import.php content/reading/*.txt
docker-compose exec app php bin/reading-import.php --publish content/reading/cykler.txt
docker-compose exec app php bin/reading-import.php --replace content/reading/cykler.txt
```

Passages arrive unpublished; publishing is a separate act so an unfinished text cannot
reach a learner. `--replace` refuses a passage whose items a round has already served,
because replacing cascades to them.

## Shape

Measured from past papers, as guidance rather than a rule the importer enforces:
a Læseforståelse 2 text runs 500–760 words; a multiple-choice question is around 17 words
and its options around 13; cloze options are one or two words; an inserted part is 25–35.
A real paper is 3 questions, 5 insertion gaps and 8 cloze gaps.

## Modultest DU3, module 4

Files named `du3m4-v<variant>-opg<task>-…` are modelled on that test: task 1 is a cloze of
about 330 words with eight gaps and an example, task 2 an insertion with five gaps, an
example and two spare parts, task 3 a written gap task of ten words, task 4 six short
everyday texts with one question each. A `paper: du3m4-v<variant>` header groups a variant:
`/modultest` sits its four tasks together in 50 minutes, offers it once all four are
published, and the reading rounds never draw a passage that has a paper. Loading the
directory again writes a changed paper, and a changed example with its text and part, onto
a passage already loaded.

## Prøve i Dansk 2, past papers

Files named `pd2-<year><s|v>-opg<task>-…` are past Prøve i Dansk 2 reading papers as printed
(`s` the May-June term, `v` November-December), sat together on `/pd2` under the 90 minutes of
both reading parts, one point per answer. Each keeps the task number of the paper, so a
paper whose pages are missing simply lacks that task. Opgave 1 types short answers (`write`),
Opgave 3 types the words from its box (`write`), Opgave 4 is an insertion, and Opgave 5 is an
insertion turned round: the interview questions stand in the text and its paragraphs are the parts.

