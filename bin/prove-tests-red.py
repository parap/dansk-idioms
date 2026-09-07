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

import subprocess, sys, pathlib, shutil, filecmp, tempfile, os

ROOT = pathlib.Path(__file__).resolve().parent.parent

FAULTS = [
 (1,"EntrySegmenter always-head","src/Import/EntrySegmenter.php",
  "        if (Text::openingScript($chunk) === 'lat') {","        if (true) {","unit"),
 (2,"EntryParser drop colon tier","src/Import/EntryParser.php",
  "        ['colon',   '/\\s*:\\s+/u'],","        // removed","unit"),
 (3,"GLOSS_CUE drop word-gloss","src/Import/TranslationExtractor.php",
  "|слов[оаеу]\\s+\\S+\\s+(означа\\w*|значит|переводится|это)\\s*'","|ZZNEVERMATCHZZ'","unit"),
 (4,"LITERAL_COMPOUND_CUE never matches","src/Import/TranslationExtractor.php",
  "'/(дословно|буквально|букв\\.)\\s+(\\p{Cyrillic}{1,6}\\s+)?(переводится|перевод|значит|означа\\w*)\\b/ui'",
  "'/ZZNEVERMATCHZZ/ui'","unit"),
 (5,"drop lexicographic-phrase check","src/Import/TranslationExtractor.php",
  "            && !$this->isLexicographicPhrase($text, $words)","            && true","unit"),
 (6,"drop isBalanced check","src/Import/TranslationExtractor.php",
  "            && $this->isBalanced($text)","            && true","unit"),
 (7,"Normalizer ASCII-folds Danish","src/Import/Normalizer.php",
  "        $s = mb_strtolower($s, 'UTF-8');",
  "        $s = strtr(mb_strtolower($s, 'UTF-8'), ['æ'=>'ae','ø'=>'o','å'=>'a']);","unit"),
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
 (16,"drop answer-length check","src/Domain/ReviewRepository.php",
  "        if ($words > self::MAX_ANSWER_WORDS || $chars > self::MAX_ANSWER_CHARS) {",
  "        if (false) {","integration"),
]

def run(suite):
    r = subprocess.run(["docker-compose","exec","-T","app","vendor/bin/phpunit","--testsuite",suite],
                       cwd=ROOT, capture_output=True, text=True)
    return r.returncode == 0

survived = []
for num, name, relpath, old, new, suite in FAULTS:
    f = ROOT/relpath
    backup = tempfile.NamedTemporaryFile(delete=False).name
    shutil.copy2(f, backup)
    text = f.read_text(encoding='utf-8')
    if old not in text:
        print(f"  {num:2d}. {name:34s} ANCHOR NOT FOUND — fault not applied")
        os.unlink(backup); continue
    f.write_text(text.replace(old, new, 1), encoding='utf-8')
    if filecmp.cmp(backup, f, shallow=False):
        print(f"  {num:2d}. {name:34s} THE EDIT DID NOT LAND")
        shutil.copy2(backup, f); os.unlink(backup); continue
    green = run(suite)
    shutil.copy2(backup, f); os.unlink(backup)
    verdict = "SURVIVED (green)" if green else "caught (red)"
    if green: survived.append((num, name))
    print(f"  {num:2d}. {name:34s} {verdict}")

print()
if survived:
    print("  FAULTS THAT SURVIVED:")
    for n, s in survived: print(f"    {n}. {s}")
else:
    print("  every injected fault was caught")
sys.exit(1 if survived else 0)
