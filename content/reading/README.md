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

- `kind` is `cloze`, `mc` or `insert`.
- Wrapped lines rejoin; a blank line stays a paragraph break.
- `{{1}}`, `{{2}}` … are the gaps, and must match the question numbers exactly.
- A star marks the correct option. Exactly one per question, at least three options.

**Multiple choice** carries the question on the numbered line and has no gap markers:

```
--- questions ---
1. Hvorfor begyndte hun at overveje et skift?
* Hun savnede tid til at fordybe sig
  Hun ville tjene mere
  Hun flyttede til en anden by
```

**Insertion** removes whole sentences. The parts are lettered in their own section, each
gap names one, and the section must offer more parts than there are gaps — the spare ones
fit nowhere, so the task cannot be finished by elimination alone.

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
