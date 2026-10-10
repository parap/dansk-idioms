#!/usr/bin/env python3
"""
Writes the verb drills in content/verbs/ from content/verbs/source.json (built by
bin/verbs-source.py) and the hand-written content/verbs/translations.json.

    python3 bin/verbs-generate.py [OUT_DIR]     # default content/verbs

The files it writes are the source of truth for bin/reading-import.php; change this script
and regenerate rather than editing them.
"""
import hashlib, json, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "content/verbs"
SET_SIZE = 25
# Danish course names for the forms a question shows or asks for.
LABEL = {"inf": "navneform", "pres": "nutid", "pret": "datid", "perf": "førnutid", "imp": "bydeform"}

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

def pairs(row):
    """Every (shown, asked) pair whose forms share no spelling: kunne's datid is its infinitive."""
    slots = [k for k in LABEL if row[k]]
    return [(a, b) for a in slots for b in slots if a != b and not set(row[a]) & set(row[b])]

def candidates(row, shown, slot):
    """The answer and the wrong options when `shown` is given and `slot` is asked."""
    answer = row[slot][0]
    taken = set(row[slot]) | set(row[shown])
    # The verb's own other forms first: telling them apart is the point. Then made-up
    # regular forms, never one DDO lists for this verb.
    others = [row[k][0] for k in ("pret", "perf", "pres", "inf", "imp") if k not in (slot, shown) and row[k]]
    # infinitive + r is the nutid a learner builds for a modal (måtter, kunner)
    made_up = [f for f in fakes(row["lemma"], slot if slot in ("pret", "perf") else "pret") + [row["inf"][0] + "r"]
               if f not in valid[row["lemma"]]]
    wrong = []
    for f in others + made_up:
        if f not in taken and f not in wrong:
            wrong.append(f)
    return answer, wrong[:3]

def question(row):
    # md5, not hash(): Python's string hash changes between runs, and the drills must not.
    options = pairs(row)
    start = int(hashlib.md5(row["lemma"].encode()).hexdigest(), 16) % len(options)
    ordered = options[start:] + options[:start]
    # Three wrong options where the verb has them; two (the site's minimum) otherwise.
    for need in (3, 2):
        for shown, slot in ordered:
            answer, wrong = candidates(row, shown, slot)
            if len(wrong) >= need:
                meaning = tr[row["lemma"]].split(";")[0].strip()
                return f"{row[shown][0]} ({LABEL[shown]}) — {meaning}. {LABEL[slot].capitalize()}?", answer, wrong
    raise SystemExit(f"{row['lemma']}: no pair of forms leaves two wrong options")

OUT.mkdir(parents=True, exist_ok=True)
for start in range(0, len(rows), SET_SIZE):
    chunk = rows[start:start + SET_SIZE]
    lo, hi = chunk[0]["rank"], chunk[-1]["rank"]
    slug = f"verbformer-{lo:03d}-{hi:03d}"
    lines = ["kind: verbs", f"slug: {slug}", f"title: Verber {lo}–{hi}", "", "--- questions ---"]
    for n, row in enumerate(chunk, 1):
        prompt, answer, wrong = question(row)
        lines += [f"{n}. {prompt}", f"* {answer}", *[f"  {w}" for w in wrong], ""]
    (OUT / f"{slug}.txt").write_text("\n".join(lines), encoding="utf-8")
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
    lines = ["kind: verbs", f"slug: verbgenkend-{k}", f"title: Genkend verbet {k}", "", "--- questions ---"]
    for n, (prompt, answer, wrong) in enumerate(chunk, 1):
        lines += [f"{n}. {prompt}", f"* {answer}", *[f"  {w}" for w in wrong], ""]
    (OUT / f"verbgenkend-{k}.txt").write_text("\n".join(lines), encoding="utf-8")
print(f"  {len(genkend)} recognition questions in {k} sets")
