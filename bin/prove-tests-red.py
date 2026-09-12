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

    python3 bin/prove-tests-red.py            # every fault
    python3 bin/prove-tests-red.py 94-99 12   # only those, while working on them

Restores every file it touches, including on failure and on a kill it cannot catch.
"""

import subprocess, sys, pathlib, shutil, filecmp, tempfile, os, signal, atexit, json

ROOT = pathlib.Path(__file__).resolve().parent.parent

# A fault lives in a source file for as long as one suite takes to run. Signal handlers
# cover an interrupt, but SIGKILL, a power cut or a closed container answer to nobody --
# and what survives is a deliberately broken line in the working tree with nothing to say
# it is there. So the injection is recorded on disk before it happens, alongside the
# untouched copy, and the next run puts the file back before doing anything else.
STATE = ROOT / ".prove-tests-red"
LEDGER = STATE / "pending.json"

def record_injection(path, backup):
    STATE.mkdir(exist_ok=True)
    kept = STATE / "original"
    shutil.copy2(backup, kept)
    LEDGER.write_text(json.dumps({
        "path": str(path.relative_to(ROOT)), "original": kept.name
    }), encoding='utf-8')

def clear_injection():
    LEDGER.unlink(missing_ok=True)
    (STATE / "original").unlink(missing_ok=True)

def recover_injection():
    """Puts back a file left injected by a run that was killed outright."""
    if not LEDGER.is_file():
        return
    note = json.loads(LEDGER.read_text(encoding='utf-8'))
    target, kept = ROOT / note["path"], STATE / note["original"]
    if kept.is_file():
        shutil.copy2(kept, target)
        print(f"  a previous run was killed mid-injection — {note['path']} restored\n")
    else:
        print(f"  a previous run was killed mid-injection and the copy of "
              f"{note['path']} is gone. Restore it from git before trusting this run.\n")
        sys.exit(1)
    clear_injection()

recover_injection()

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
 (35,"reading grades from the client","src/Domain/Reading/ReadingSessionService.php",
  "        $isCorrect = ((int) $row['correct_index']) === $chosenIndex;",
  "        $isCorrect = true;","integration"),
 (36,"the option row id reaches the client","src/Domain/Reading/ReadingSessionService.php",
  "            $out[] = ['index' => (int) $option['i'], 'text' => (string) $option['text']];",
  "            $out[] = ['index' => (int) $option['i'], 'text' => (string) $option['text'], 'ref' => $option['ref']];","integration"),
 (37,"an unpublished passage is served","src/Domain/Reading/ReadingSessionService.php",
  "            'SELECT p.id, p.kind FROM reading_passages p\n             WHERE p.is_published = 1",
  "            'SELECT p.id, p.kind FROM reading_passages p\n             WHERE 1 = 1","integration"),
 (38,"a flagged item is still served","src/Domain/Reading/ReadingSessionService.php",
  "             WHERE passage_id = ? AND is_active = 1 AND is_flagged = 0 ORDER BY position',",
  "             WHERE passage_id = ? AND 1 = 1 ORDER BY position',","integration"),
 (39,"options are served in authored order","src/Domain/Reading/ReadingSessionService.php",
  "            shuffle($options);","            // order left as authored","integration"),
 (40,"a client response time is trusted","src/Domain/Reading/ReadingSessionService.php",
  "        $ms        = $responseMs === null ? null : max(0, min(self::MAX_RESPONSE_MS, $responseMs));",
  "        $ms        = $responseMs;","integration"),
 (41,"a drill item may be answered twice","src/Domain/Reading/ReadingSessionService.php",
  "        if ($session['mode'] !== self::EXAM && $row['chosen_index'] !== null) {",
  "        if (false) {","integration"),
 (42,"a signed-in learner is never scheduled","src/Domain/Reading/ReadingSessionService.php",
  "        if ($session['user_id'] !== null) {","        if (false) {","integration"),
 (43,"passage markup escaped after substitution","public/read.html",
  "  return esc(body).replace(/\\{\\{(\\d+)\\}\\}/g, (_, n) =>",
  "  return body.replace(/\\{\\{(\\d+)\\}\\}/g, (_, n) =>","ui"),
 (44,"a wrong answer hides the right one","public/read.html",
  "  if (!res.is_correct) buttons[res.correct_index]?.classList.add('right');",
  "  if (false) buttons[res.correct_index]?.classList.add('right');","ui"),
 (45,"answered drill options stay clickable","public/read.html",
  "  if (!isExam()) buttons.forEach(b => b.disabled = true);",
  "  if (false) buttons.forEach(b => b.disabled = true);","ui"),
 (46,"gap markers never become buttons","public/read.html",
  "  return esc(body).replace(/\\{\\{(\\d+)\\}\\}/g, (_, n) =>",
  "  return esc(body).replace(/\\{\\{(ZZNEVER)\\}\\}/g, (_, n) =>","ui"),
 (47,"an exam reveals the answer before submit","src/Domain/Reading/ReadingSessionService.php",
  "        if ($session['mode'] === self::EXAM) {","        if (false) {","integration"),
 (48,"the elapsed time is not measured by the server","src/Domain/Reading/ReadingSessionService.php",
  "        $elapsed  = (int) $session['elapsed_now'];","        $elapsed  = 0;","integration"),
 (49,"the karakter is hard-coded","src/Domain/Reading/GradeScale.php",
  "        return (string) $karakter;","        return '12';","integration"),
 (50,"a short paper is graded on its raw count","src/Domain/Reading/GradeScale.php",
  "        $points = $max > 0 ? (int) round($scored / $max * (int) $scale['max_points']) : 0;",
  "        $points = $scored;","integration"),
 (51,"the review is available before submit","src/Domain/Reading/ReadingSessionService.php",
  "        if ($session['status'] !== 'submitted') {","        if (false) {","integration"),
 (52,"an exam draws a single text","src/Domain/Reading/ReadingSessionService.php",
  "            ? array_map(fn(string $k): array => $this->pickPassage($k), self::EXAM_KINDS)",
  "            ? [$this->pickPassage('cloze')]","integration"),
 (53,"the exam page shows feedback anyway","public/read.html",
  "  if (isExam()) {\n    buttons.forEach(b => b.classList.remove('chosen'));",
  "  if (false) {\n    buttons.forEach(b => b.classList.remove('chosen'));","ui"),
 (54,"an exam marks a choice right or wrong","public/read.html",
  "    buttons[chosen]?.classList.add('chosen');",
  "    buttons[chosen]?.classList.add(res.is_correct ? 'right' : 'wrong');","ui"),
 (55,"the parser ignores gap/question disagreement","src/Domain/Reading/PassageDocument.php",
  "        if ($markers !== $positions) {","        if (false) {","unit"),
 (56,"the parser accepts two starred options","src/Domain/Reading/PassageDocument.php",
  "        if (count($correct) !== 1) {","        if (false) {","unit"),
 (57,"the parser lets one part fill two gaps","src/Domain/Reading/PassageDocument.php",
  "        if (count($used) !== count(array_unique($used))) {","        if (false) {","unit"),
 (58,"the parser drops the spare-parts rule","src/Domain/Reading/PassageDocument.php",
  "        if (count($bank) <= count($items)) {","        if (false) {","unit"),
 (59,"a multiple-choice text may carry markers","src/Domain/Reading/PassageDocument.php",
  "            if ($markers !== []) {","            if (false) {","unit"),
 (60,"one learner may report an item twice","db/migrations/0003_reading_reports.sql",
  "    UNIQUE KEY uq_reporter (item_id, reporter),",
  "    KEY uq_reporter (item_id, reporter),","integration"),
 (61,"a single report withdraws an item","src/Domain/Reading/ReadingReportRepository.php",
  "            [$after, $after >= self::FLAG_AT ? 1 : 0, $itemId]",
  "            [$after, 1, $itemId]","integration"),
 (62,"an item outside the round may be reported","src/Domain/Reading/ReadingReportRepository.php",
  "        if ($row === null) {\n            throw new RuntimeException('No such item in this round.');",
  "        if (false) {\n            throw new RuntimeException('No such item in this round.');","integration"),
 (63,"clearing a flag leaves the item withdrawn","src/Domain/Reading/ReadingReportRepository.php",
  "        Db::execute('UPDATE reading_items SET is_flagged = 0, report_count = 0 WHERE id = ?', [$itemId]);",
  "        // the item stays withdrawn","integration"),
 (64,"the appeal link never appears","public/read.html",
  "    + ` \u00b7 <button class=\"link\" id=\"rep-${item.position}\">${esc(t('report'))}</button>`;",
  "    + ``;","ui"),
 (65,"the appeal queue hides the answer key","public/admin.html",
  "        <div class=\"opt${Number(o.is_correct) === 1 ? ' right' : ''}\">",
  "        <div class=\"opt\">","ui"),
 (66,"the appeal queue hides what readers said","public/admin.html",
  "          <li><span class=\"reason\">${esc(r.reason)}</span>${r.note ? ' \u2014 ' + esc(r.note) : ''}</li>",
  "          <li></li>","ui"),
 (67,"the appeal queue lists sound items too","src/Domain/Reading/ReadingReportRepository.php",
  "             WHERE i.is_flagged = 1","             WHERE 1 = 1","integration"),
 (68,"a Danish term may be written in Cyrillic","src/Domain/ReviewRepository.php",
  "        if (!preg_match_all('/\\p{Cyrillic}/u', $term, $m)) {","        if (true) {","integration"),
 (69,"a hand-written gloss is dropped","src/Domain/ReviewRepository.php",
  "        if ($explanation !== null && $explanation !== '') {","        if (false) {","integration"),
 (70,"an extra sense claims the primary slot","src/Domain/ReviewRepository.php",
  "                $this->writeTranslation($idiomId, $sense, 'idiomatic', false);",
  "                $this->writeTranslation($idiomId, $sense, 'idiomatic', true);","integration"),
 (71,"an idiom file need not be a list","src/Domain/IdiomFile.php",
  "        if (!is_array($entries) || !array_is_list($entries)) {","        if (false) {","integration"),
 (72,"an entry may omit its translation","src/Domain/IdiomFile.php",
  "            if (!isset($entry[$key]) || !is_string($entry[$key]) || trim($entry[$key]) === '') {",
  "            if (false) {","integration"),
 (73,"an author's shape is ignored","src/Domain/ReviewRepository.php",
  "        $shape ??= $this->classifier->termShape($term);",
  "        $shape = $this->classifier->termShape($term);","integration"),
 (74,"a value outside the ENUM is accepted","src/Domain/ReviewRepository.php",
  "        if ($value === null || in_array($value, $allowed, true)) {","        if (true) {","integration"),
 (75,"reloading leaves a stale classification","src/Domain/ReviewRepository.php",
  "            Db::execute(\n                'UPDATE idioms SET term = ?, term_note = ?, kind = ?, shape = ? WHERE id = ?',",
  "            Db::execute(\n                'UPDATE idioms SET term = term WHERE id = ? AND ? IS NOT NULL AND ? IS NOT NULL AND ? IS NOT NULL',","integration"),
 (76,"a retired sense stays in the pool","src/Domain/ReviewRepository.php",
  "                \"DELETE FROM idiom_translations\n                  WHERE idiom_id = ? AND lang_code = 'ru' AND text_norm = ? AND is_primary IS NULL\",",
  "                \"DELETE FROM idiom_translations\n                  WHERE idiom_id = ? AND lang_code = 'ru' AND text_norm = ? AND 1 = 0\",","integration"),
 (77,"retiring may remove the promoted answer","src/Domain/ReviewRepository.php",
  "AND text_norm = ? AND is_primary IS NULL\",","AND text_norm = ?\",","integration"),
 (78,"debug is on unless disabled","src/Support/Config.php",
  "        return filter_var((string) $appDebug, FILTER_VALIDATE_BOOLEAN);",
  "        return true;","unit"),
 (79,"production may be put in debug","src/Support/Config.php",
  "        if ($appEnv === 'prod') {","        if (false) {","unit"),
 (80,"the login throttle never locks","src/Support/AdminLoginThrottle.php",
  "        if ($age >= self::WINDOW_SECONDS || (int) $row['failures'] < $limit) {",
  "        if (true) {","integration"),
 (81,"the login lock never expires","src/Support/AdminLoginThrottle.php",
  "        if ($age >= self::WINDOW_SECONDS || (int) $row['failures'] < $limit) {",
  "        if ((int) $row['failures'] < $limit) {","integration"),
 (82,"rotating the client bypasses the throttle","src/Support/AdminLoginThrottle.php",
  "        foreach ([$this->key($client), self::GLOBAL_KEY] as $key) {",
  "        foreach ([$this->key($client)] as $key) {","integration"),
 (83,"the client is stored in the clear","src/Support/AdminLoginThrottle.php",
  "        return hash('sha256', $client);","        return $client;","integration"),
 (84,"the health endpoint leaks internals","public/index.php",
  "            if (Config::get('debug')) {","            if (true) {","ui"),
 (85,"the login endpoint skips the throttle","src/Controller/AdminController.php",
  "        $wait   = $this->throttle->retryAfter($client);","        $wait   = null;","ui"),
 (86,"a literal reading is offered as an answer","src/Domain/ReviewRepository.php",
  "            $this->writeTranslation($idiomId, $reading, 'literal', false);",
  "            $this->writeTranslation($idiomId, $reading, 'idiomatic', false);","integration"),
 (87,"a hand-written gloss piles up beside the imported one","src/Domain/ReviewRepository.php",
  "            Db::execute(\n                \"DELETE FROM idiom_explanations WHERE idiom_id = ? AND lang_code = 'ru'\",\n                [$idiomId]\n            );",
  "            // the imported gloss is left in place","integration"),
 (88,"the answer may also be the literal reading","src/Domain/ReviewRepository.php",
  "            if ($reading === '' || $reading === $primary) {","            if ($reading === '') {","integration"),
 (89,"declared synonyms are not recorded","src/Domain/ReviewRepository.php",
  "        foreach ($synonyms as $other) {","        foreach ([] as $other) {","integration"),
 (90,"a synonym that does not exist is accepted","src/Domain/ReviewRepository.php",
  "        if ($otherId === false || $otherId === null) {","        if (false) {","integration"),
 (91,"every declaration starts a rival group","src/Domain/ReviewRepository.php",
  "        if ($group === false || $group === null) {","        if (true) {","integration"),
 (92,"an idiom may be its own synonym","src/Domain/ReviewRepository.php",
  "        if ($otherId === $idiomId) {","        if (false) {","integration"),
 (93,"synonyms are never resolved from a file","src/Domain/IdiomFile.php",
  "            if ($synonyms === []) {","            if (true) {","integration"),
 (94,"the audit ignores declared synonyms","src/Domain/SharedSenseAudit.php",
  "                     WHERE s1.idiom_id = a.idiom_id AND s2.idiom_id = b.idiom_id)",
  "                     WHERE s1.idiom_id = 0 AND s2.idiom_id = 0)","integration"),
 (95,"the audit misses shared literal readings","src/Domain/SharedSenseAudit.php",
  "             WHERE (a.quiz_usable = 1 OR a.sense_type = 'literal')",
  "             WHERE a.quiz_usable = 1","integration"),
 (96,"the audit reports every pair twice","src/Domain/SharedSenseAudit.php",
  "              AND b.idiom_id > a.idiom_id","              AND b.idiom_id <> a.idiom_id","integration"),
 (97,"the audit counts unpublished idioms","src/Domain/SharedSenseAudit.php",
  "             JOIN idioms ia ON ia.id = a.idiom_id AND ia.is_published = 1",
  "             JOIN idioms ia ON ia.id = a.idiom_id","integration"),
 (98,"a synonym group of one is accepted","src/Domain/IdiomFile.php",
  "            if (count($terms) < 2) {","            if (false) {","integration"),
 (99,"an absent idiom makes a group file fatal","src/Domain/IdiomFile.php",
  "            if (count($present) < 2) {","            if (false) {","integration"),
 (100,"a wrapped part loses its tail","src/Domain/Reading/PassageDocument.php",
  "            $bank[array_key_last($bank)]['text'] .= ' ' . trim($line);",
  "            // dropped","unit"),
 (101,"a repeated part letter is accepted","src/Domain/Reading/PassageDocument.php",
  "                if (in_array($m[1], array_column($bank, 'label'), true)) {",
  "                if (false) {","unit"),
 (102,"the admin surface answers anywhere","public/index.php",
  "if (AdminReach::isAdminHandler($handler) && !AdminReach::reachable($_SERVER)) {",
  "if (false) {","ui"),
 (103,"a header opens the admin listener","src/Support/AdminReach.php",
  "        return ($server[self::LISTENER] ?? null) === '1';",
  "        return ($server[self::LISTENER] ?? $server['HTTP_DANSK_ADMIN_LISTENER'] ?? null) === '1';","unit"),
 (104,"a cookie is never marked secure","src/Support/Scheme.php",
  "            'secure'   => self::isHttps($server),",
  "            'secure'   => false,","unit"),
 (105,"the caller opens the forwarded chain","src/Support/Scheme.php",
  "        return strtolower((string) end($hops)) === 'https';",
  "        return strtolower((string) $hops[0]) === 'https';","unit"),
]

def run(suite):
    # The interface checks drive a real browser, so they answer to a different runner.
    # Without them a rendering fault -- an escape that stopped escaping, a button that
    # stopped disabling -- is invisible to every suite in the repo.
    if suite == "ui":
        cmd = [sys.executable, str(ROOT/"bin"/"ui-tests.py")]
    else:
        cmd = ["docker-compose","exec","-T","app","vendor/bin/phpunit","--testsuite",suite]
    r = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True)
    return r.returncode == 0

# Selecting faults keeps a run on the code being worked on short enough to actually run:
# the integration suite takes half a minute, and the whole list is most of an hour. A
# selective run is a development aid -- the gate before a commit is the unselected one.
if len(sys.argv) > 1:
    wanted = set()
    for arg in sys.argv[1:]:
        lo, _, hi = arg.partition('-')
        try:
            wanted.update(range(int(lo), int(hi or lo) + 1))
        except ValueError:
            sys.exit(f"  not a fault number or range: {arg}")
    FAULTS = [f for f in FAULTS if f[0] in wanted]
    missing = wanted - {f[0] for f in FAULTS}
    if missing:
        # Silently running fewer faults than asked for is the failure this whole exercise
        # exists to prevent, one level up.
        sys.exit(f"  no such fault(s): {', '.join(str(n) for n in sorted(missing))}")

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
    clear_injection()

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
    record_injection(f, backup)
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
