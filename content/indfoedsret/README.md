# Indfødsretsprøven

One document per published exam. The ministry puts every paper and its answer sheet
online as two separate PDFs; `bin/indfoedsret-convert.php` joins them into the authoring
format, and these documents are the source of truth from there on — the database is
derived, and so is any correction to the wording of a question.

```bash
docker-compose exec app php bin/indfoedsret-convert.php ~/Documents/Claude/dansk-indfoedsret/pdf
docker-compose exec app php bin/reading-import.php content/indfoedsret/*.txt
docker-compose exec app php bin/reading-import.php --publish content/indfoedsret/indfoedsret-2026-06-03.txt
```

Converting again overwrites a document, so a hand-made correction survives only in a
commit. The command says which files it rewrote.

## The exam

45 questions in 45 minutes, multiple choice with two or three options. Questions 1–35 are
set from the ministry's own study material, 36–40 ask about current affairs, and 41–45
about Danish values. Passing takes 36 correct **and** at least 4 of the last 5. Before
late 2021 a paper was 40 questions with no values block, and passing took 32.

## A document

```
kind: quiz
slug: indfoedsret-2026-06-03
title: Indfødsretsprøven 3. juni 2026
pass: 36
vaerdier_min: 4

--- questions ---
[laeremateriale]

1. Hvornår trådte Danmarks første grundlov i kraft?
* 1849
  1864
  1901

[vaerdier]

41. Er det muligt at skifte juridisk køn?
* Ja
  Nej
```

- **A quiz paper has no `--- text ---` section.** There is nothing to read before
  answering, and a document that carries one is refused.
- **The options keep the paper's own A/B/C order**, so a document and the published PDF
  can be read side by side. The star marks the correct one.
- **Every question names its block**, and `pass` applies to the paper while
  `vaerdier_min` applies to the values block alone. A pass mark the paper cannot reach is
  refused at import.
- The 2020 sheets state no pass mark. The headers are then left out rather than filled
  with a number nobody published.

## The current-affairs block ages

Questions 36–40 are about the months before the exam: which minister was appointed, which
party gained, what the winter was like. They are worth keeping — they are what the paper
asked — but a round built from them trains nobody years later. The block is stored on
every question for that reason, so a round can leave it out.

## Why the answer sheet is read as glyphs, not as text

The sheets are two-column tables, and some of them contain a letter that is never
rendered: the May and November 2025 sheets each draw a B six tenths of a point above the
C printed on row 19. A line-based extraction emits both, in an order that says nothing
about which one a reader sees, and picking the wrong one marks a correct answer wrong
while the import reports success.

`AnswerKey` therefore reads glyph positions and takes the letter on the row number's own
baseline; a row whose two nearest letters are equally close is refused rather than
guessed. The conversion reports how many glyphs it ignored, and a sheet that suddenly
ignores several is worth opening.
