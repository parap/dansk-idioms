#!/usr/bin/env python3
"""
Writes the verb drills in content/verbs/ from content/verbs/source.json (built by
bin/verbs-source.py) and the hand-written content/verbs/translations.json.

    python3 bin/verbs-generate.py [OUT_DIR]     # default content/verbs

The files it writes are the source of truth for bin/reading-import.php; change this script
and regenerate rather than editing them.
"""
import json, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "content/verbs"
SET_SIZE = 25
LABEL = {"pret": "Datid", "perf": "Førnutid"}

rows = json.load(open(ROOT / "content/verbs/source.json", encoding="utf-8"))
tr = json.load(open(ROOT / "content/verbs/translations.json", encoding="utf-8"))
valid = {r["lemma"]: set(r["valid"]) for r in rows}

def fakes(lemma, slot):
    """Regular-looking forms of the wrong conjugation; deponents get theirs from the -s-less base."""
    dep = lemma.endswith("s") and not lemma.endswith("ss")
    inf = lemma[:-1] if dep else lemma
    # se, ske, le: a short verb keeps its vowel; spille -> spil- before -t, as Danish spells it
    stem = inf[:-1] if inf.endswith("e") and len(inf) > 3 else inf
    short = stem[:-1] if len(stem) > 2 and stem[-1] == stem[-2] and stem[-1] not in "aeiouyæøå" else stem
    joined = [] if short.endswith("t") else [short + ("te" if slot == "pret" else "t")]  # no styrt+t
    out = [stem + "ede", *joined, inf + "de"] if slot == "pret" else [stem + "et", *joined, inf + "t"]
    return [f + "s" for f in out] if dep else out

def question(row, slot):
    answer = row[slot][0]
    other = "perf" if slot == "pret" else "pret"
    taken = set(row[slot]) | {answer}
    pool = row[other][:1] + row["pres"][:1] + [f for f in fakes(row["lemma"], slot) if f not in valid[row["lemma"]]] + row["inf"][:1]
    wrong = []
    for f in pool:
        if f not in taken and f not in wrong:
            wrong.append(f)
    if len(wrong) < 2:  # the site needs three options at least
        raise SystemExit(f"{row['lemma']}: only {wrong} as wrong options")
    meaning = tr[row["lemma"]].split(";")[0].strip()
    return f"{row['inf'][0]} — {meaning}. {LABEL[slot]}?", answer, wrong[:3]

OUT.mkdir(parents=True, exist_ok=True)
for start in range(0, len(rows), SET_SIZE):
    chunk = rows[start:start + SET_SIZE]
    lo, hi = chunk[0]["rank"], chunk[-1]["rank"]
    lines = ["kind: verbs", f"slug: verber-{lo:03d}-{hi:03d}", f"title: Verber {lo}–{hi}", "", "--- questions ---"]
    for n, row in enumerate(chunk, 1):
        prompt, answer, wrong = question(row, "pret" if row["rank"] % 2 else "perf")
        lines += [f"{n}. {prompt}", f"* {answer}", *[f"  {w}" for w in wrong], ""]
    (OUT / f"verber-{lo:03d}-{hi:03d}.txt").write_text("\n".join(lines), encoding="utf-8")
print(f"  {len(rows)} verbs in {len(range(0, len(rows), SET_SIZE))} sets -> {OUT}")


# ---- "Genkend verbet": a form whose verb is not obvious, asked for its infinitive ----

def regular_pret(lemma, pret):
    """Whether a datid is the stem plus a regular ending, so the verb is plain to see."""
    dep = lemma.endswith("s") and not lemma.endswith("ss")
    inf = lemma[:-1] if dep else lemma
    if dep and pret.endswith("s"):
        pret = pret[:-1]
    stem = inf[:-1] if inf.endswith("e") and len(inf) > 3 else inf
    short = stem[:-1] if len(stem) > 2 and stem[-1] == stem[-2] and stem[-1] not in "aeiouyæøå" else stem
    return pret in {stem + "ede", stem + "te", short + "te", stem + "de", inf + "de", inf + "ede"}

PREFIXES = ("af", "an", "be", "bi", "bo", "del", "efter", "fast", "for", "fore", "fort", "forud", "frem",
            "gen", "gennem", "inde", "mod", "øde",
            "grund", "ind", "iværk", "løs", "med", "mis", "ned", "offentlig", "om", "op", "opret", "over",
            "plan", "på", "råd", "til", "und", "under", "ud", "vare", "ved")

def base_in_list(lemma, lemmas):
    """A prefixed verb (fortsætte) whose base (sætte) is itself on the list."""
    return any(lemma.startswith(p) and lemma[len(p):] in lemmas for p in PREFIXES)

def recognition_questions():
    import difflib
    lemmas = {r["lemma"] for r in rows}
    asked = []
    for r in rows:
        if base_in_list(r["lemma"], lemmas):
            continue
        if r["pret"][0] != r["inf"][0] and not regular_pret(r["lemma"], r["pret"][0]):
            asked.append((r, r["pret"][0], "datid"))
        if r["pres"][0] not in (r["inf"][0] + "r", r["inf"][0] + "er", r["inf"][0]) and not r["lemma"].endswith("s"):
            asked.append((r, r["pres"][0], "nutid"))
    questions = []
    for r, form, label in asked:
        answer = r["inf"][0]
        # A verb that also has this form would be a second right answer (led: lide, and lede's imperative)
        pool = [x["inf"][0] for x in rows
                if x["inf"][0] != answer and form not in valid[x["lemma"]] and not base_in_list(x["lemma"], lemmas)]
        wrong = sorted(pool, key=lambda w: -(difflib.SequenceMatcher(None, w, answer).ratio()
                                            + difflib.SequenceMatcher(None, w, form).ratio()))[:3]
        questions.append((f"{form} — {label} af …?", answer, wrong))
    return questions

GENKEND_MAX = 25
genkend = recognition_questions()
sets = -(-len(genkend) // GENKEND_MAX)
size = -(-len(genkend) // sets)  # even sets rather than a short last one
for k, start in enumerate(range(0, len(genkend), size), 1):
    chunk = genkend[start:start + size]
    lines = ["kind: verbs", f"slug: genkend-verbet-{k}", f"title: Genkend verbet {k}", "", "--- questions ---"]
    for n, (prompt, answer, wrong) in enumerate(chunk, 1):
        lines += [f"{n}. {prompt}", f"* {answer}", *[f"  {w}" for w in wrong], ""]
    (OUT / f"genkend-verbet-{k}.txt").write_text("\n".join(lines), encoding="utf-8")
print(f"  {len(genkend)} recognition questions in {k} sets")
