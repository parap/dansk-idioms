"""
Proves the suite can go red.

A check that has only ever passed proves nothing. This breaks the code on purpose, one
fault at a time, and requires the suite to fail for each. A fault that survives means
either an uncovered case or genuinely equivalent behaviour -- read the code it touches
to decide which, rather than adding a test reflexively.

The faults are listed here rather than chosen while looking at the tests: breaks picked
by reading the tests are the ones the tests already catch.

Each injection is guarded by a byte comparison against a copy of the original. An edit
that silently fails to apply leaves the suite running against unmodified code, and a
"red" run that was never red is the one outcome this exercise exists to rule out.

    python3 bin/prove-tests-red.py

Restores every file it touches, including on failure.
"""

import subprocess, sys, pathlib, shutil, filecmp, tempfile, os, signal, atexit

ROOT = pathlib.Path(__file__).resolve().parent.parent

FAULTS = [
 (1,"EntrySegmenter always-head","src/Import/EntrySegmenter.php",
  "        if (Text::openingScript($chunk) === 'lat') {","        if (true) {","unit"),
 (2,"EntryParser drop colon tier","src/Import/EntryParser.php",
  "        ['colon',   '/\\s*:\\s+/u'],","        // removed","unit"),
 (3,"GLOSS_CUE drop word-gloss","src/Import/TranslationExtractor.php",
  "|слов[оаеу]\\s+\\S+\\s+(означа\\w*|значит|переводится|это)\\s*'","|ZZNEVERMATCHZZ'","unit"),
 (4,"LITERAL_COMPOUND_CUE never matches","src/Import/TranslationExtractor.php",
  "'/(?<!\\p{L})(дословно|буквально|букв\\.)\\s+(\\p{Cyrillic}{1,6}\\s+)?'",
  "'/ZZNEVERMATCHZZ'","unit"),
 (5,"drop lexicographic-phrase check","src/Import/TranslationExtractor.php",
  "            && !$this->isLexicographicPhrase($text, $words)","            && true","unit"),
 (6,"drop isBalanced check","src/Import/TranslationExtractor.php",
  "            && $this->isBalanced($text)","            && true","unit"),
 (7,"Normalizer ASCII-folds Danish","src/Import/Normalizer.php",
  "        $s = mb_strtolower($s, 'UTF-8');\n        $s = Text::trimPunctuation($s);",
  "        $s = strtr(mb_strtolower($s, 'UTF-8'), ['æ'=>'ae','ø'=>'o','å'=>'a']);\n        $s = Text::trimPunctuation($s);","unit"),
 (8,"trimPunctuation byte-based","src/Import/Text.php",
  "        return preg_replace('/^' . $class . '+|' . $class . '+$/u', '', $s) ?? $s;",
  "        return trim($s, \" \\t\\n\\r.,;:!?…\\\"«»„“”-–—*\");","unit"),
 (9,"hasVerb infinitives only","src/Import/Classifier.php",
  "                . '|лся|лась|лось|лись'                            // reflexive past","                . '' // removed","unit"),
 (10,"drop verb-parity filter","src/Domain/DistractorService.php",
  "        $sql .= ($correct['shape'] ?? '') === 'verbal'\n            ? \" AND t.shape = 'verbal'\"\n            : \" AND t.shape <> 'verbal'\";",
  "        // removed","integration"),
 (11,"grade from client claim","src/Domain/QuizService.php",
  "        $isCorrect = ((int) $row['correct_index']) === $chosenIndex;","        $isCorrect = true;","integration"),
 (12,"leak correct_index","src/Domain/QuizService.php",
  "            'score'     => ['correct' => (int) $row['correct_count']],",
  "            'score'     => ['correct' => (int) $row['correct_count']], 'correct_index' => 1,","integration"),
 (13,"upsert drops is_primary","src/Import/Importer.php",
  "                    is_primary  = VALUES(is_primary),","                    ","integration"),
 (14,"drop fixed-status protection","src/Import/Importer.php",
  "                status            = IF(raw_entries.status = \\'fixed\\', \\'fixed\\', VALUES(status))",
  "                status            = VALUES(status)","integration"),
 (15,"skip ensurePrimaries","src/Import/Importer.php",
  "            $this->ensurePrimaries($lang);","            // skipped","integration"),
 (17,"reverse drops infinitive parity","src/Domain/DistractorService.php",
  "        $sql .= ($correct['term_shape'] ?? '') === 'verbal'\n            ? \" AND i.shape = 'verbal'\"\n            : \" AND i.shape <> 'verbal'\";",
  "        // removed","integration"),
 (18,"reverse prompt leaks the term","src/Domain/QuizService.php",
  "                'text'     => $reverse ? $row['translation'] : $row['term'],",
  "                'text'     => $row['term'],","integration"),
 (19,"reverse offers Russian options","src/Domain/QuizService.php",
  "        $options = [['ref' => (int) $correct['idiom_id'], 'text' => (string) $correct['term'], 'correct' => true]];",
  "        $options = [['ref' => (int) $correct['idiom_id'], 'text' => (string) $correct['text'], 'correct' => true]];","integration"),
 (20,"importer overrides a manual primary","src/Import/Importer.php",
  "                    !$yieldPrimary && $t['is_primary'] ? 1 : null,",
  "                    $t['is_primary'] ? 1 : null,","integration"),
 (21,"stale rows are never removed","src/Import/Importer.php",
  "            \"DELETE FROM idiom_translations\n             WHERE idiom_id = ? AND lang_code = ? AND source = 'import'\n               AND text_norm NOT IN ($placeholders)\",",
  "            \"SELECT 1 FROM idiom_translations WHERE idiom_id = ? AND lang_code = ? AND source = 'import' AND text_norm NOT IN ($placeholders)\",","integration"),
 (22,"cue matches inside a word","src/Import/TranslationExtractor.php",
  "                '/(?<!\\p{L})(?:переводится как|означает|значит)(?!\\p{L})\\s*[:\\-–—]?\\s*([^.;]{2,60})/ui',",
  "                '/(?:переводится как|означает|значит)\\s*[:\\-–—]?\\s*([^.;]{2,60})/ui',","unit"),
 (16,"drop answer-length check","src/Domain/ReviewRepository.php",
  "        if ($words > self::MAX_ANSWER_WORDS || $chars > self::MAX_ANSWER_CHARS) {",
  "        if (false) {","integration"),
 (23,"SM-2 ease floor inverted","src/Domain/Sm2.php",
  "'ease'          => max(self::MIN_EASE, $ease - 0.2),",
  "'ease'          => min(self::MIN_EASE, $ease - 0.2),","unit"),
 (24,"SM-2 second interval collapses","src/Domain/Sm2.php",
  "            2       => 6,","            2       => 1,","unit"),
 (25,"SM-2 interval uses the rewarded ease","src/Domain/Sm2.php",
  "default => (int) round($intervalDays * $ease),",
  "default => (int) round($intervalDays * min(self::MAX_EASE, $ease + 0.1)),","unit"),
 (26,"ULID loses a timestamp character","src/Support/Ulid.php",
  "    private const TIME_CHARS = 10;","    private const TIME_CHARS = 9;","unit"),
 (27,"ULID alphabet admits I/L/O/U","src/Support/Ulid.php",
  "    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';",
  "    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUV';","unit"),
 (28,"reading slug loses accent sensitivity","db/migrations/0002_reading.sql",
  "    slug         VARCHAR(96) COLLATE utf8mb4_0900_as_cs NOT NULL,",
  "    slug         VARCHAR(96) NOT NULL,","integration"),
 (29,"an option may answer two items","db/migrations/0002_reading.sql",
  "    UNIQUE KEY uq_item_correct (correct_option_id),",
  "    KEY uq_item_correct (correct_option_id),","integration"),
 (30,"marker/item agreement dropped","src/Domain/Reading/ReadingRepository.php",
  "        if ($markers !== $positions) {","        if (false) {","integration"),
 (31,"cloze scored at two points","src/Domain/Reading/ReadingRepository.php",
  "    private const POINTS = ['mc' => 2, 'insert' => 2, 'cloze' => 1];",
  "    private const POINTS = ['mc' => 2, 'insert' => 2, 'cloze' => 2];","integration"),
 (32,"unpublished passages are served","src/Domain/Reading/ReadingRepository.php",
  "             WHERE is_published = 1 AND kind = ? ORDER BY id',",
  "             WHERE kind = ? ORDER BY id',","integration"),
 (33,"an item may mark two options correct","src/Domain/Reading/ReadingRepository.php",
  "            if (count($correct) !== 1) {","            if (false) {","integration"),
 (34,"an insertion bank needs no decoys","src/Domain/Reading/ReadingRepository.php",
  "        if (count($labels) <= count($doc['items'])) {","        if (false) {","integration"),
]

def run(suite):
    r = subprocess.run(["docker-compose","exec","-T","app","vendor/bin/phpunit","--testsuite",suite],
                       cwd=ROOT, capture_output=True, text=True)
    return r.returncode == 0

ambiguous = []
for num, name, relpath, old, new, suite in FAULTS:
    n = (ROOT/relpath).read_text(encoding='utf-8').count(old)
    if n != 1:
        ambiguous.append((num, name, relpath, n))
if ambiguous:
    print("  ANCHORS THAT DO NOT IDENTIFY EXACTLY ONE SITE:")
    for num, name, relpath, n in ambiguous:
        found = "not found" if n == 0 else f"{n} occurrences"
        print(f"    {num}. {name} — {found} in {relpath}")
    print("\n  Nothing was injected. Re-derive each anchor from the current source.")
    sys.exit(1)

# The working tree must never be left holding an injected fault. A signal arriving
# mid-run would otherwise leave a deliberately broken line in a source file with nothing
# to report it, and the next commit ships it.
pending = None

def restore_pending():
    global pending
    if pending is None:
        return
    path, backup = pending
    pending = None
    shutil.copy2(backup, path)
    os.unlink(backup)

def on_signal(signum, _frame):
    restore_pending()
    print(f"\n  interrupted by {signal.Signals(signum).name} — working tree restored")
    sys.exit(130)

signal.signal(signal.SIGINT, on_signal)
signal.signal(signal.SIGTERM, on_signal)
atexit.register(restore_pending)

survived = []
# A fault that never reached the file proves nothing, so a skip fails the run rather
# than printing a note. An anchor string that drifts during a refactor would otherwise
# drop its fault from the suite silently, and the run would still report success.
skipped = []
for num, name, relpath, old, new, suite in FAULTS:
    f = ROOT/relpath
    backup = tempfile.NamedTemporaryFile(delete=False).name
    shutil.copy2(f, backup)
    pending = (f, backup)
    try:
        text = f.read_text(encoding='utf-8')
        if old not in text:
            print(f"  {num:2d}. {name:34s} ANCHOR NOT FOUND — fault not applied")
            skipped.append((num, name, "anchor not found"))
            continue
        f.write_text(text.replace(old, new, 1), encoding='utf-8')
        if filecmp.cmp(backup, f, shallow=False):
            print(f"  {num:2d}. {name:34s} THE EDIT DID NOT LAND")
            skipped.append((num, name, "the edit did not land"))
            continue
        green = run(suite)
        verdict = "SURVIVED (green)" if green else "caught (red)"
        if green: survived.append((num, name))
        print(f"  {num:2d}. {name:34s} {verdict}")
    finally:
        restore_pending()

print()
if survived:
    print("  FAULTS THAT SURVIVED:")
    for n, s in survived: print(f"    {n}. {s}")
if skipped:
    print("  FAULTS THAT WERE NEVER APPLIED:")
    for n, s, why in skipped: print(f"    {n}. {s} — {why}")
if not survived and not skipped:
    print("  every injected fault was caught")
sys.exit(1 if survived or skipped else 0)
