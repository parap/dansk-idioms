#!/usr/bin/env python3
"""
Builds content/verbs/source.json -- the most frequent Danish verbs and their forms -- from
DSL's open language resources, which stay outside the repository.

    python3 bin/verbs-source.py [DATA_DIR] [N]     # default ~/Claude/danish-verbs/src, 700

DATA_DIR holds the unpacked downloads from korpus.dsl.dk/resources/licences/dsl-open.html
(freq-lemma, ddo-fullform, flexikon) and da_50k.txt from hermitdave/FrequencyWords.
Frequency comes from DSL's lemma list; which form is which from FLEXIKON; every spelling
must also be one Den Danske Ordbog lists.
"""
import csv, json, sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = Path(sys.argv[1]).expanduser() if len(sys.argv) > 1 else Path.home() / "Claude/danish-verbs/src"
N = int(sys.argv[2]) if len(sys.argv) > 2 else 700

SLOTS = {"inf": 2, "pres": 4, "pret": 32, "perf": 512, "imp": 32768}
# Deponent verbs (synes, lykkes) carry their forms in FLEXIKON's passive slots.
DEPONENT_SLOTS = {"inf": 8, "pres": 16, "pret": 64, "perf": 512}
NO_IMPERATIVE = {"kunne", "skulle", "ville", "måtte", "burde", "turde"}
# The DSL lemmatizer credits some inflected forms to the wrong lemma (skød to skamskyde)
# and counts nouns and adjectives as verbs (hele, læge, krone).
MERGE = {"overtog": "overtage", "planlagt": "planlægge", "glemt": "glemme", "glemmer": "glemme",
         "kaldet": "kalde", "foregik": "foregå", "frasælge": "sælge", "skamskyde": "skyde",
         "dødsdømme": "dømme", "fortale": "fortælle", "syne": "synes"}
DROP = {"time", "øje", "nøje", "året", "kvinde", "hele", "live", "læge", "egne", "skønne", "huse",
        "fremme", "forfatte", "krone", "lette", "fore"}
# DDO-valid spellings that are a different word in practice (skabet = "the cupboard").
EXCLUDE_FORMS = {"skabe": {"skabet"}}
# Verbs newer than FLEXIKON; their spellings are still checked against DDO.
MANUAL = {"tjekke": {"inf": ["tjekke"], "pres": ["tjekker"], "pret": ["tjekkede"], "perf": ["tjekket"],
                     "imp": ["tjek"]}}
# A second variant is listed only if it is at least this common, in speech, as the first.
ALT_RATIO = 0.15

total = defaultdict(float)
for line in open(SRC / "freq-lemma/freq-30k-ex.txt", encoding="utf-8"):
    pos, lemma, freq = line.rstrip("\n").split("\t")
    if pos == "V" and lemma not in DROP:
        total[MERGE.get(lemma, lemma)] += float(freq)
lemmas = sorted(total.items(), key=lambda kv: -kv[1])[:N]

ddo = defaultdict(set)
for row in csv.reader(open(next((SRC / "ddo-fullform").glob("ddo-fullforms_*.csv")), encoding="utf-8"),
                      delimiter="\t"):
    if len(row) >= 4 and row[3].startswith("vb"):
        ddo[row[1]].add(row[0])

flex = defaultdict(list)
for block in open(SRC / "flexikon/flexikon.txt", encoding="utf-8").read().split("*\n"):
    lines = block.strip("\n").split("\n")
    if len(lines) >= 3 and lines[1] == "V":
        flex[lines[0]].append([(int(c), f) for c, f in (l.split("\t", 1) for l in lines[2:] if "\t" in l)])

spoken = {}
for line in open(SRC / "da_50k.txt", encoding="utf-8"):
    w, c = line.split()
    spoken[w] = int(c)

def pick(cands):
    # Ties go to the shorter, then the alphabetically first: a set's order changes between runs.
    ranked = sorted(cands, key=lambda f: (-spoken.get(f, 0), len(f), f))
    top = spoken.get(ranked[0], 0)
    return [f for i, f in enumerate(ranked) if i == 0 or (top and spoken.get(f, 0) >= ALT_RATIO * top)]

verbs, problems = [], []
for rank, (lemma, _) in enumerate(lemmas, 1):
    valid = ddo.get(lemma, set())
    deponent = lemma.endswith("s") and not any(c & 2 for e in flex.get(lemma, []) for c, _ in e)
    row = {"rank": rank, "lemma": lemma}
    for slot, bit in (DEPONENT_SLOTS if deponent else SLOTS).items():
        cands = set(MANUAL[lemma][slot]) if lemma in MANUAL else {f for e in flex.get(lemma, []) for c, f in e if c & bit}
        cands -= EXCLUDE_FORMS.get(lemma, set())
        checked = cands & valid if valid else cands
        if slot == "imp" and not checked and not deponent and lemma[:-1] in valid:
            checked = {lemma[:-1]}  # FLEXIKON lacks some imperatives; the bare stem is the regular one
        row[slot] = pick(checked) if checked else []
        if not row[slot] and not (slot == "imp" and lemma in NO_IMPERATIVE):
            problems.append(f"{rank} {lemma}: no {slot}")
    row.setdefault("imp", [])
    row["valid"] = sorted(valid)
    verbs.append(row)

if problems:
    sys.exit("  " + "\n  ".join(problems))
out = ROOT / "content/verbs/source.json"
out.write_text(json.dumps(verbs, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
print(f"  {len(verbs)} verbs -> {out.relative_to(ROOT)}")
