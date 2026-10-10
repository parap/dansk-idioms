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
    python3 bin/prove-tests-red.py --anchors  # seconds: does the list still fit the code
    python3 bin/prove-tests-red.py --changed  # the faults in files this branch changed

A fault may name the tests that must each go red on their own, written after the suite as
`unit:testOne,testTwo`. A rule stated in one place is broken in one place, so a whole-suite
run reports the first door that noticed and says nothing about the others -- including a
door whose test has since been deleted. `--anchors` checks that every anchor still names
exactly one site and every named witness still exists, and deploy.sh calls it.

Faults are injected into a copy of the tree, served and tested by the `mutants` container,
so the working tree, the developer's site and the developer's test schema are never
touched: commit, edit or run the suite while it works.
"""

import subprocess, sys, pathlib, shutil, filecmp, tempfile, os, atexit

ROOT = pathlib.Path(__file__).resolve().parent.parent

# The copy the faults go into. It sits inside the project so the mutants container can
# mount it, and is gitignored. A run killed outright leaves a fault there and nowhere else;
# the next run's sync puts the file back.
TREE = ROOT / ".prove-tests-red" / "tree"
COPY_EXCLUDES = [".git/", ".prove-tests-red/", ".claude/", ".idea/", "backups/", ".phpunit.cache/",
                 "storage/exports/*", "storage/logs/*", "storage/indfoedsret/*", "__pycache__/"]

# Where the mutants container serves the copy, for the interface checks.
MUTANTS_BASE = "http://localhost:" + os.environ.get("MUTANTS_PORT", "8083")
MUTANTS_ADMIN_BASE = "http://localhost:" + os.environ.get("MUTANTS_ADMIN_PORT", "8084")

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
  "                    is_primary  = IF(source = \\'manual\\', is_primary, VALUES(is_primary)),","                    ","integration"),
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
  "    private const POINTS = ['mc' => 2, 'insert' => 2, 'cloze' => 1, 'quiz' => 1, 'video' => 1, 'verbs' => 1];",
  "    private const POINTS = ['mc' => 2, 'insert' => 2, 'cloze' => 2, 'quiz' => 1, 'video' => 1, 'verbs' => 1];","integration"),
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
 # Each of the next three states a rule that every door into a reading round shares, so
 # one injection reaches all of them at once and each door answers with its own witness.
 (37,"an unpublished text is served","src/Domain/Reading/ReadingSessionService.php",
  "    private const PUBLISHED = 'p.is_published = 1';",
  "    private const PUBLISHED = '1 = 1';",
  "integration:testAnUnpublishedPassageIsNeverServed,testAnUnpublishedPaperIsNeverSat,"
  "testAnUnpublishedTextsQuestionDoesNotComeBackAsAMistake"),
 (38,"a withdrawn or flagged question is served","src/Domain/Reading/ReadingSessionService.php",
  "    private const SERVABLE = 'i.is_active = 1 AND i.is_flagged = 0';",
  "    private const SERVABLE = '1 = 1';",
  "integration:testAFlaggedItemIsLeftOutAndTheTotalDropsWithIt,"
  "testAFlaggedQuestionDoesNotComeBackAsAMistake"),
 (39,"options are served in authored order","src/Domain/Reading/ReadingSessionService.php",
  "        shuffle($options);\n\n        $correctIndex = null;",
  "        $correctIndex = null;",
  "integration:testOptionOrderIsDecidedPerSessionRatherThanByTheAuthor,"
  "testOptionOrderInAMistakesRoundIsDecidedPerRound"),
 (153,"a split post loses its author","src/Support/CommunicatorWebhook.php",
  "        $done = $this->publishParts($group, $parts, $draft['entities'], $draft['kind'], $press['from'] ?? [], time());",
  "        $done = $this->publishParts($group, $parts, $draft['entities'], $draft['kind'], [], time());",
  "unit:testTheAuthorReachesTheCorpusWhicheverWayItWasPublished"),
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
  "    + (res.is_correct ? '' : ` \u00b7 <button class=\"link\" id=\"rep-${item.position}\">${esc(t('report'))}</button>`);",
  "    + '';","ui"),
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
 (106,"answer sheet takes the first glyph, not the one on the row","src/Import/Indfoedsret/AnswerKey.php",
  "        sort($candidates);","        // removed","unit"),
 (107,"answer sheet guesses between two equally close letters","src/Import/Indfoedsret/AnswerKey.php",
  "        if (count($candidates) > 1 && $candidates[1][0] - $candidates[0][0] < self::MARGIN) {",
  "        if (false) {","unit"),
 (108,"values requirement read as absent when the wording is new","src/Import/Indfoedsret/AnswerKey.php",
  "        if (str_contains($prose, 'v\u00e6rdier') && $block === null) {","        if (false) {","unit"),
 (109,"any numbered line opens a question","src/Import/Indfoedsret/PaperParser.php",
  "            if (preg_match(self::QUESTION, $line, $m) && (int) $m[1] === $expected) {",
  "            if (preg_match(self::QUESTION, $line, $m)) {","unit"),
 (110,"paper need not carry the questions it declares","src/Import/Indfoedsret/PaperParser.php",
  "        if (count($questions) !== $total) {","        if (false) {","unit"),
 (111,"page footers read as part of the paper","src/Import/Indfoedsret/PaperParser.php",
  "            if (trim($line) === '' || preg_match(self::FOOTER, $line)) {",
  "            if (trim($line) === '') {","unit"),
 (112,"document stars the first option, not the answer","src/Import/Indfoedsret/PaperDocument.php",
  "                $lines[] = ($label === $key['answers'][$question['position']] ? '* ' : '  ') . $option;",
  "                $lines[] = ($i === 0 ? '* ' : '  ') . $option;","unit"),
 (113,"a sheet from another paper is accepted","src/Import/Indfoedsret/PaperDocument.php",
  "        if ($questions !== $answers) {","        if (false) {","unit"),
 (114,"quiz question may name no block","src/Domain/Reading/PassageDocument.php",
  "                    if ($block === null) {","                    if (false) {","unit"),
 (115,"item loses the block it was asked in","src/Domain/Reading/ReadingRepository.php",
  "            [$passageId, $item['position'], $item['section'] ?? null, self::POINTS[$kind], $item['prompt'] ?? null,",
  "            [$passageId, $item['position'], null, self::POINTS[$kind], $item['prompt'] ?? null,","integration"),
 (116,"a pass mark nobody can reach is stored","src/Domain/Reading/ReadingRepository.php",
  "        if ($pass !== null && $pass > count($doc['items'])) {","        if (false) {","integration"),
 (117,"a reading round may draw a knowledge paper","src/Domain/Reading/ReadingSessionService.php",
  "               AND p.kind IN (' . $kinds . ')",
  "               AND (p.kind IN (' . $kinds . ') OR 1 = 1)","integration"),
 (118,"the values block is not required to pass","src/Domain/Reading/PassMark.php",
  "        $passed = $correct >= $pass\n            && ($vaerdierMin === null || $vaerdierCorrect >= $vaerdierMin);",
  "        $passed = $correct >= $pass;","unit"),
 (119,"a shortened round is judged as the whole paper","src/Domain/Reading/PassMark.php",
  "        if ($questions !== $paperQuestions) {","        if (false) {","unit"),
 (120,"the current-affairs block is asked whatever was chosen","src/Domain/Reading/ReadingSessionService.php",
  "            onlySections: $currentAffairs ? null : ['laeremateriale', 'vaerdier'],",
  "            onlySections: null,","integration"),
 (121,"a question forgets which block asked it","src/Domain/Reading/ReadingSessionService.php",
  "                'section'  => $row['section'],","                'section'  => 'laeremateriale',","integration"),
 (122,"the verdict is not recorded with the session","src/Domain/Reading/ReadingSessionService.php",
  "            [$elapsed, $isLate ? 1 : 0, $scored, $karakter, $verdict, $id]",
  "            [$elapsed, $isLate ? 1 : 0, $scored, $karakter, null, $id]","integration"),
 (123,"the exam page marks an answer right as it is given","public/proeve.html",
  "  buttons.find(b => Number(b.dataset.index) === chosen)?.classList.add('chosen');",
  "  buttons.find(b => Number(b.dataset.index) === chosen)?.classList.add('right');","ui"),
 (124,"a knowledge paper runs the reading exam's clock","src/Domain/Reading/ReadingSessionService.php",
  "    private const PAPER_SECONDS = 2700;","    private const PAPER_SECONDS = 3900;","ui"),
 (126,"communicator: unset secret lets everyone in","src/Support/Communicator.php",
  "        if ($configured === null || $configured === '') {\n            return false;\n        }",
  "        if ($configured === null || $configured === '') {\n            return true;\n        }","unit"),
 (127,"communicator: secret not compared","src/Support/Communicator.php",
  "        return $offered !== null && hash_equals($configured, $offered);",
  "        return true;","unit"),
 (128,"communicator: anyone may publish","src/Support/Communicator.php",
  "        return (string) ($message['chat']['id'] ?? '') === $ownerChatId;",
  "        return true;","unit"),
 (129,"communicator: tag doubled on redelivery","src/Support/Communicator.php",
  "        if (preg_match('/' . preg_quote($tag, '/') . '(?![\\p{L}\\p{N}_])/u', $text) === 1) {\n            return $text;\n        }",
  "        // removed","unit"),
 (130,"communicator: video seen as text","src/Support/Communicator.php",
  "        if (isset($message['video']) || isset($message['video_note'])) {\n            return 'video';\n        }",
  "        if (false) {\n            return 'video';\n        }","unit"),
 (132,"communicator: a video file sent as a file is text","src/Support/Communicator.php",
  "        return str_starts_with($mime, 'video/') ? 'video' : 'text';",
  "        return 'text';","unit"),
 (131,"communicator: tag matched inside a word","src/Support/Communicator.php",
  "'(?![\\p{L}\\p{N}_])/u'","'/u'","unit"),
 (133,"webhook: secret not checked","src/Support/CommunicatorWebhook.php",
  "        if (!Communicator::accepts($this->settings['secret'] ?? null, $offeredSecret)) {",
  "        if (false) {","unit"),
 (134,"webhook: anyone's message is published","src/Support/CommunicatorWebhook.php",
  "        if (!Communicator::fromOwner($message, $this->owner())) {",
  "        if (false) {","unit"),
 (135,"webhook: an empty post reaches the group","src/Support/CommunicatorWebhook.php",
  "        if (trim($raw) === '') {","        if (false) {","unit"),
 (136,"webhook: publishes with no group configured","src/Support/CommunicatorWebhook.php",
  "        return $group === '' ? null : $group;","        return $group;",
  "unit:testAnUnconfiguredGroupPublishesNothing,testAPressWithNoGroupConfiguredPublishesNothing"),
 (137,"webhook: a service update is treated as a post","src/Support/CommunicatorWebhook.php",
  "        if (!is_array($message)) {","        if (false) {","unit"),
 (138,"communicator: offsets counted in characters, not UTF-16 units","src/Import/Text.php",
  "            $units += mb_ord($char, 'UTF-8') >= 0x10000 ? 2 : 1;",
  "            $units += 1;","unit"),
 (139,"communicator: an entity running past the text is kept","src/Support/Communicator.php",
  "            $entity['length'] = min((int) ($entity['length'] ?? 0), $limit - $offset);",
  "            $entity['length'] = (int) ($entity['length'] ?? 0);","unit"),
 (140,"communicator: an entity starting past the text is kept","src/Support/Communicator.php",
  "            if ($offset >= $limit) {\n                continue;\n            }",
  "            if (false) {\n                continue;\n            }","unit"),
 (141,"ingest: the tag rides into the corpus","src/Support/CommunicatorIngest.php",
  "            'text'          => (string) ($update['text'] ?? ''),",
  "            'text'          => (string) ($update['text'] ?? '') . \"\\n\\n#text\",","unit"),
 (142,"ingest: records the private message, not the group post","src/Support/CommunicatorIngest.php",
  "            'tg_message_id' => $publishedId,",
  "            'tg_message_id' => (int) ($update['message_id'] ?? 0),","unit"),
 (143,"ingest: the time is filed in the server's zone","src/Support/CommunicatorIngest.php",
  "            ->setTimezone(new \\DateTimeZone('UTC'));",
  "            ->setTimezone(new \\DateTimeZone('Europe/Copenhagen'));","unit"),
 (144,"webhook: publishes before the boundaries are settled","src/Support/CommunicatorWebhook.php",
  "        if ($parts === null) {\n            return $this->offer($raw, $message);",
  "        if (false) {\n            return $this->offer($raw, $message);","unit"),
 (145,"webhook: a failure to store is reported as a failure to publish","src/Support/CommunicatorWebhook.php",
  "            !$done['stored']         => 'published_not_stored',",
  "            !$done['stored']         => 'failed',",
  "unit:testAFailureToStoreDoesNotLookLikeAFailureToPublish"),
 (146,"webhook: the refusal is never reported to the owner","src/Support/CommunicatorWebhook.php",
  "            $this->tell(\n                'Не опубликовала: в сообщении несколько идиом без невидимых'",
  "            $this->silence(\n                'Не опубликовала: в сообщении несколько идиом без невидимых'","unit"),
 (147,"webhook: the note goes to the group instead of the owner","src/Support/CommunicatorWebhook.php",
  "            $this->api->sendMessage($owner, $text, $entities);",
  "            $this->api->sendMessage((string) $this->group(), $text, $entities);","unit"),
 (149,"webhook: anyone's press is obeyed","src/Support/CommunicatorWebhook.php",
  "        if ($owner === null || (string) ($press['from']['id'] ?? '') !== $owner) {",
  "        if (false) {","unit"),
 (150,"webhook: a claimed draft is acted on twice","src/Support/CommunicatorWebhook.php",
  "        if ($draft === null) {\n            $this->acknowledge($press, 'Уже сделано');\n\n            return 'already_decided';\n        }",
  "        $draft ??= ['text' => '', 'entities' => [], 'kind' => 'text', 'owner_chat_id' => ''];","unit"),
 (151,"communicator: a piece's formatting keeps the whole text's offsets","src/Support/Communicator.php",
  "            $entity['offset'] = $lo - $start;",
  "            $entity['offset'] = $lo;","unit"),
 (152,"webhook: splitting publishes one post anyway","src/Support/CommunicatorWebhook.php",
  "            ? $this->segmenter->proposeSplitParts($draft['text'])",
  "            ? [['text' => $draft['text'], 'offset' => 0]]","unit"),
 (154,"the appeal link shows after a correct answer too","public/read.html",
  "    + (res.is_correct ? '' : ` \u00b7 <button","    + (false ? '' : ` \u00b7 <button","ui"),
 (155,"a forwarded quote goes out as one post","src/Import/EntrySegmenter.php",
  "            return $this->partsAtSeparators($text);",
  "            return [['text' => rtrim($text), 'offset' => 0]];",
  "unit:testAForwardedQuoteIsPublishedIdiomByIdiom,testTextCarryingTheSeparatorIsCutAtIt"),
 (156,"a Russian line after the separator becomes its own post","src/Import/EntrySegmenter.php",
  "            if ($current !== null && $this->isEntryHead($chunk, true) && !$this->isPreamble($current)) {",
  "            if ($current !== null && !$this->isPreamble($current)) {",
  "unit:testARussianLineAfterTheSeparatorStaysWithItsEntry"),
 (157,"idioms one to a line still wait for a press","src/Import/EntrySegmenter.php",
  "        return $bare ? null : $this->proposeSplitParts($text);",
  "        return null;",
  "unit:testIdiomsOneToALineArePublishedAsSeparatePosts,testHeadwordLinesWithoutTheSeparatorAreCutAtTheLines"),
 (158,"a bare Latin line is cut at unasked","src/Import/EntrySegmenter.php",
  "        return $bare ? null : $this->proposeSplitParts($text);",
  "        return $this->proposeSplitParts($text);",
  "unit:testABareLatinLineMakesTheCutAGuess,testUnclearBoundariesPublishNothingYet"),
 (159,"the headword is not made bold","src/Support/CommunicatorWebhook.php",
  "                Communicator::withBoldHead($part['text'], Communicator::sliceEntities(",
  "                (fn($t, $e) => $e)($part['text'], Communicator::sliceEntities(",
  "unit:testEachIdiomIsBoldInItsOwnPost,testAnIdiomSentAloneIsBoldToo,testAForwardedQuoteIsPublishedIdiomByIdiom"),
 (160,"the author's own bold is doubled","src/Support/Communicator.php",
  "                return $entities;\n            }\n        }\n\n        return [['type' => 'bold'",
  "                break;\n            }\n        }\n\n        return [['type' => 'bold'",
  "unit:testTheAuthorsOwnBoldIsLeftAsItIs"),
 (161,"bold counts the invisible separator out","src/Support/Communicator.php",
  "        $offset = self::utf16Length($m[1]);",
  "        $offset = 0;",
  "unit:testAForwardedQuoteIsPublishedIdiomByIdiom"),
 (162,"a Russian note before the dash loses the bold","src/Support/Communicator.php",
  "\\s+(?:\\([^()\\n]*\\p{Cyrillic}[^()\\n]*\\)\\s+)?[—–-]",
  "\\s+[—–-]",
  "unit:testARussianNoteInBracketsIsLeftOutOfTheBold"),
 (163,"an idiom already in the corpus is published again","src/Support/CommunicatorWebhook.php",
  "            if ($term !== null) {\n                $skipped[] = $term;",
  "            if (false) {\n                $skipped[] = $term;",
  "unit:testAnIdiomAlreadyInTheCorpusGoesNowhere,testNothingNewPublishesNothing,testAPressedSplitLeavesOutWhatIsKnownToo"),
 (164,"a skipped idiom is skipped in silence","src/Support/Communicator.php",
  "        $section('Уже есть на сайте, не публиковала', $known, true);",
  "        $section('Уже есть на сайте, не публиковала', [], true);",
  "unit:testTheOwnerIsToldWhatWasLeftOut"),
 (165,"nothing new is reported as published","src/Support/CommunicatorWebhook.php",
  "            $done['published'] === 0 => $done['known'] === 0 ? 'screened' : 'nothing_new',",
  "            false => '',",
  "unit:testNothingNewPublishesNothing"),
 (166,"known idioms are looked up by the whole line, not the term","src/Support/KnownIdioms.php",
  "        $norm  = Normalizer::term((new EntryParser())->parse($entry)->term ?? '');",
  "        $norm  = Normalizer::term($entry);",
  "integration:testAKnownIdiomIsFoundUnderItsStoredTerm,testTheExplanationDoesNotDecide"),
 (167,"an exported bot post carries its tag into the corpus","src/Import/Importer.php",
  "            $message['text'] = Communicator::untagged($message['text']);",
  "",
  "integration:testTheBotsTagStaysOutOfTheCorpus"),
 (168,"an imported reading takes a human's primary away","src/Import/Importer.php",
  "                    is_primary  = IF(source = \\'manual\\', is_primary, VALUES(is_primary)),",
  "                    is_primary  = VALUES(is_primary),",
  "integration:testAnImportedReadingEqualToAHumanPrimaryLeavesItPrimary"),
 (169,"an entry awaiting review replaces an accepted idiom's readings","src/Import/Importer.php",
  "                    if ($status === 'auto_accepted' || $created) {",
  "                    if (true) {",
  "integration:testAnEntryAwaitingReviewLeavesAnAcceptedIdiomAlone"),
 (170,"an imported reading makes a human's reading unusable","src/Import/Importer.php",
  "                    quiz_usable = IF(source = \\'manual\\', quiz_usable, VALUES(quiz_usable)),",
  "                    quiz_usable = VALUES(quiz_usable),",
  "integration:testAnImportedReadingLeavesAHumansReadingUsable"),
 (171,"an imported explanation lands beside a human's","src/Import/Importer.php",
  "            \"SELECT 1 FROM idiom_explanations WHERE idiom_id = ? AND lang_code = ? AND source = 'manual'\",",
  "            \"SELECT 0\",",
  "integration:testAHumansExplanationIsTheOnlyOne"),
 (172,"an object placeholder is cut out of the reading","src/Import/TranslationExtractor.php",
  "                static fn(array $m): string => preg_match(self::OBJECT_PLACEHOLDER, $m[0]) === 1 ? $m[0] : '',",
  "                static fn(array $m): string => '',",
  "unit:testAOneLinePostGivesItsReadingWithoutGapsOrLabels"),
 (173,"a cut aside leaves a gap before the comma","src/Import/TranslationExtractor.php",
  "            $head = preg_replace('/\\s+(?=[,.;:])/u', '', Text::collapseWhitespace($head)) ?? $head;",
  "            $head = Text::collapseWhitespace($head);",
  "unit:testAOneLinePostGivesItsReadingWithoutGapsOrLabels"),
 (174,"the kind of phrase rides into the reading","src/Import/TranslationExtractor.php",
  "            $head = $this->trimReading(preg_split('/[.;]\\s+(?=\\p{Lu})/u', $head, 2)[0], true);",
  "            $head = $this->trimReading($head, true);",
  "unit:testAOneLinePostGivesItsReadingWithoutGapsOrLabels"),
 (175,"a quoted reading is lost when the head is cut first","src/Import/TranslationExtractor.php",
  "        if ($head !== null && $head !== '' && !str_contains($head, '«')) {\n            // \"напугать",
  "        if ($head !== null && $head !== '') {\n            // \"напугать",
  "unit:testAOneLinePostGivesItsReadingWithoutGapsOrLabels"),
 (176,"a long reading is cut at its object placeholder","src/Import/TranslationExtractor.php",
  "|\\s+\\((?!(?:' . self::OBJECT_PRONOUNS . ')-)/u', $s, 2);",
  "|\\s+\\(/u', $s, 2);",
  "unit:testAOneLinePostGivesItsReadingWithoutGapsOrLabels"),
 (177,"a trailing placeholder loses its closing bracket","src/Import/TranslationExtractor.php",
  "        return $closes && !str_ends_with($trimmed, ')') ? $trimmed . ')' : $trimmed;",
  "        return $trimmed;",
  "unit:testAOneLinePostGivesItsReadingWithoutGapsOrLabels"),
 (178,"an etymology is offered as the answer","src/Import/TranslationExtractor.php",
  "            . '|калька|^(?:из|от)\\s+\\p{L}+(?:ского|цкого)(?!\\p{L}))/ui',",
  "            . ')/ui',",
  "unit:testAOneLinePostGivesItsReadingWithoutGapsOrLabels"),
 (179,"an old wording of a reading outlives the corrected one","src/Import/Importer.php",
  "                    text        = IF(source = \\'manual\\', text, VALUES(text)),\n",
  "",
  "integration:testAReadingTheImportNowGetsRightReplacesItsOldWording"),
 (180,"\"устойчивое сочетание\" is offered as the answer","src/Import/TranslationExtractor.php",
  "            '/(выражени\\w*|оборот\\w*|сочетани\\w*|",
  "            '/(выражени\\w*|оборот\\w*|словосочетани\\w*|",
  "unit:testAOneLinePostGivesItsReadingWithoutGapsOrLabels"),
 (181,"a piece holding no idiom is published","src/Support/CommunicatorWebhook.php",
  "            if ($head === null) {\n                $refused[]",
  "            if (false) {\n                $refused[]",
  "unit:testWhatHoldsNoIdiomReachesNeitherTheGroupNorTheSite,testOnlyTheIdiomsOfAMixedMessageArePublished,testAPressedSplitLeavesOutWhatHoldsNoIdiom"),
 (182,"a message of links is offered for splitting","src/Support/CommunicatorWebhook.php",
  "fn(string $p): bool => ($this->idiom)($p) !== null) === []) {",
  "fn(string $p): bool => ($this->idiom)($p) !== null) === [] && false) {",
  "unit:testWhatHoldsNoIdiomReachesNeitherTheGroupNorTheSite"),
 (183,"a link passes for a headword","src/Support/IdiomScreen.php",
  " || preg_match('~https?://|www\\.|t\\.me/~i', $term) === 1) {",
  ") {",
  "unit:testWhatHoldsNoIdiomIsNamedNothing,testWhatHoldsNoIdiomReachesNeitherTheGroupNorTheSite"),
 (184,"a headword with no Russian meaning passes for an idiom","src/Support/IdiomScreen.php",
  "        return Text::hasCyrillic((string) $parsed->explanation) ? $term : null;",
  "        return $term;",
  "unit:testWhatHoldsNoIdiomIsNamedNothing"),
 (185,"the owner hears nothing of what went where","src/Support/CommunicatorWebhook.php",
  "        $this->tell(...Communicator::report($sent, $skipped, $refused));",
  "",
  "unit:testTheOwnerIsToldWhatWentToTheGroupAndWhatToTheSite,testThePressGetsTheSameReport,testTheOwnerIsToldWhatWasScreenedOut"),
 (186,"the site's answer is dropped from the report","src/Support/CommunicatorWebhook.php",
  "                $sent[] = [$head, is_string($site) ? $site : null];",
  "                $sent[] = [$head, null];",
  "unit:testTheOwnerIsToldWhatWentToTheGroupAndWhatToTheSite"),
 (187,"an idiom waiting for review is reported as on the site","src/Support/Communicator.php",
  "        $section('На сайт (' . count($where('published')) . ')', $where('published'), true);",
  "        $section('На сайт (' . count($sent) . ')', array_column($sent, 0), true);",
  "unit:testTheOwnerIsToldWhatWentToTheGroupAndWhatToTheSite"),
 (188,"a failure to store is left out of the report","src/Support/CommunicatorWebhook.php",
  "                $sent[] = [$head, 'failed'];",
  "                $sent[] = [$head, null];",
  "unit:testAFailureToStoreIsNamedInTheReport"),
 (189,"the site state is assumed, not read back","src/Support/CommunicatorIngest.php",
  "        return $this->stateOf($publishedId);",
  "        return 'published';",
  "integration:testAnIdiomTheImportIsUnsureOfWaitsForReview,testAnIdiomTheImportCouldNotReadIsRejected"),
 (190,"the site state is read from another post","src/Support/CommunicatorIngest.php",
  "AND m.tg_message_id = ?\",",
  "AND m.tg_message_id <= ?\",",
  "integration:testTheAnswerIsAboutThisPostAlone"),
 (191,"the report reaches the owner without its bold","src/Support/CommunicatorWebhook.php",
  "            $this->api->sendMessage($owner, $text, $entities);",
  "            $this->api->sendMessage($owner, $text);",
  "unit:testTheIdiomsInTheReportAreBold"),
 (192,"what holds no idiom is bolded as one","src/Support/Communicator.php",
  "        $section('Отсеяла, идиомы не нашла', $refused, false);",
  "        $section('Отсеяла, идиомы не нашла', $refused, true);",
  "unit:testWhatHoldsNoIdiomIsNotBold"),
 (193,"a bold span is placed before the bullet","src/Support/Communicator.php",
  "                    $entities[] = ['type' => 'bold', 'offset' => self::utf16Length($text), 'length' => self::utf16Length($item)];",
  "                    $entities[] = ['type' => 'bold', 'offset' => self::utf16Length($text) - 2, 'length' => self::utf16Length($item)];",
  "unit:testTheIdiomsInTheReportAreBold"),
 (194,"the example's Danish is not bold","src/Support/Communicator.php",
  "            $entities[] = ['type' => 'bold', 'offset' => self::utf16Length($text), 'length' => self::utf16Length('at gå agurk')];",
  "",
  "unit:testWhatHoldsNoIdiomIsNotBold"),
 (195,"a verb drill sits a film of the same name","src/Domain/Reading/ReadingSessionService.php",
  "        return $this->startNamedDrill(self::VERBS_KIND, $userId, $anonKey, $slug);",
  "        return $this->startNamedDrill(self::VIDEO_KIND, $userId, $anonKey, $slug);",
  "integration:testRefusesAFilmAskedForAsAVerbSet"),
 (196,"the verb list offers films","src/Domain/Reading/ReadingRepository.php",
  "            $this->publishedSets('verbs')",
  "            $this->publishedSets('video')",
  "integration:testPublishedVerbSetsListEachSetWithItsQuestionCount"),
 (197,"the verb page is not a document","src/Support/Shell.php",
  "        '/verbs'  => '/verbs.html',\n",
  "",
  "unit:testTheVerbPageHasItsOwnDocument"),
 (198,"a literal gloss demotes the answer chosen in review","src/Domain/ReviewRepository.php",
  "            if ($c['sense_type'] === 'literal' && !in_array(Normalizer::translation($c['text']), $chosen, true)) {",
  "            if ($c['sense_type'] === 'literal') {",
  "integration:testAnAnswerThatIsAlsoALiteralGlossStaysThePrimary"),
 (199,"a note never reaches the learner","src/Domain/Reading/ReadingSessionService.php",
  "            'note'          => $row['note'],\n",
  "",
  "integration:testTheNoteArrivesWithTheAnswerAndNotBefore"),
 (200,"a note is matched to any question at its position","src/Domain/Reading/ReadingRepository.php",
  "WHERE passage_id = ? AND position = ? AND prompt <=> ? AND NOT (note <=> ?)",
  "WHERE passage_id = ? AND position = ? AND (prompt <=> ? OR 1) AND NOT (note <=> ?)",
  "integration:testARefreshLeavesAQuestionThatNoLongerMatches"),
 (201,"a note line is read as an option","src/Domain/Reading/PassageDocument.php",
  "            if (preg_match('/^\\s*>\\s?(.*)$/', $line, $m)) {",
  "            if (false) {",
  "unit:testANoteLineIsKeptApartFromTheOptions"),
 (202,"notes are only written for a fresh set","src/Domain/Reading/ReadingRepository.php",
  "        foreach ($doc['items'] as $item) {\n            $changed += Db::execute(",
  "        foreach ([] as $item) {\n            $changed += Db::execute(",
  "integration:testNotesAreRefreshedInPlaceOnASetAlreadyServed"),
]
def phpunit(*args, service="mutants"):
    return subprocess.run(
        ["docker-compose","--profile","mutants","exec","-T",service,"vendor/bin/phpunit",*args],
        cwd=ROOT, capture_output=True, text=True,
    )

def run(suite):
    """
    Does anything still pass that should not, with the fault in place?

    `suite` is a suite name, optionally followed by `:` and the tests that must each go
    red on their own. A rule stated in one place is broken in one place, so a whole-suite
    run reports the first door that noticed and nothing about the rest.

    Returns (green, why): green is True when the fault went unnoticed.
    """
    # The interface checks drive a real browser, so they answer to a different runner.
    # Without them a rendering fault -- an escape that stopped escaping, a button that
    # stopped disabling -- is invisible to every suite in the repo.
    if suite == "ui":
        r = subprocess.run([sys.executable, str(ROOT/"bin"/"ui-tests.py"),
                            "--base", MUTANTS_BASE, "--admin-base", MUTANTS_ADMIN_BASE],
                           cwd=ROOT, capture_output=True, text=True)
        return r.returncode == 0, None

    name, _, witnesses = suite.partition(':')
    if witnesses == '':
        # The first failure answers the question; only a surviving fault runs to the end.
        return phpunit("--testsuite", name, "--stop-on-failure", "--stop-on-error").returncode == 0, None

    for test in witnesses.split(','):
        r = phpunit("--testsuite", name, "--filter", test)
        # A filter matching nothing passes, which would read as a surviving fault and
        # send the reader hunting for a missing guard instead of a renamed test.
        if "No tests executed" in r.stdout:
            return True, f"no test named {test}"
        if r.returncode == 0:
            return True, f"{test} did not notice"

    return False, None

# Whether every anchor still names exactly one site, without running a single test.
#
# A fault whose anchor stops matching is not injected, and the rule it was written for is
# then guarded by nothing while the run it was left out of still reports success. Only a
# whole-list run notices, and a whole-list run takes long enough that it is not the thing
# standing between a refactor and a deploy. This check is seconds, reads no
# test output, and is what deploy.sh calls.
args = [a for a in sys.argv[1:] if a not in ('--anchors', '--changed')]
anchors_only = '--anchors' in sys.argv[1:]

# A commit is gated by the faults in the files its branch changed, uncommitted edits
# included; the whole list runs before a deploy, where a deleted test elsewhere shows up.
if '--changed' in sys.argv[1:]:
    git = lambda *a: subprocess.run(["git", *a], cwd=ROOT, capture_output=True, text=True, check=True).stdout
    changed = set(git("diff", "--name-only", git("merge-base", "HEAD", "master").strip()).split())
    FAULTS = [f for f in FAULTS if f[2] in changed]
    if not FAULTS:
        print("  no fault lives in a file this branch changed")
        sys.exit(0)

# Selecting faults by number keeps a run on the code being worked on short enough to run
# while writing it.
if args:
    wanted = set()
    for arg in args:
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

if anchors_only:
    # A witness that no longer exists reads as a surviving fault, but only to whoever
    # runs that fault. PHPUnit is asked rather than the files read, so a witness
    # inherited from a base class still counts.
    unknown = []
    for wanted_suite in sorted({s.partition(':')[0] for _, _, _, _, _, s in FAULTS if ':' in s}):
        r = phpunit("--testsuite", wanted_suite, "--list-tests", service="app")
        # A listing that failed is empty, and an empty listing reports every witness as gone.
        if r.returncode != 0 or '::' not in r.stdout:
            sys.exit(f"  could not list the {wanted_suite} tests, so no witness was checked"
                     f" (run from the checkout the app container mounts):\n"
                     f"  {(r.stderr.strip() or r.stdout.strip())[-400:]}")
        listed = r.stdout
        # A data-provider test is listed once per data set, its name followed by "set".
        known = {line.rpartition('::')[2].strip().partition('"')[0]
                 for line in listed.splitlines() if '::' in line}
        for num, name, _, _, _, s in FAULTS:
            head, _, witnesses = s.partition(':')
            if head != wanted_suite or witnesses == '':
                continue
            unknown += [(num, name, w) for w in witnesses.split(',') if w not in known]
    if unknown:
        print("  NAMED WITNESSES THAT NO LONGER EXIST:")
        for num, name, w in unknown:
            print(f"    {num}. {name} — no test named {w}")
        sys.exit(1)

    print(f"  every anchor names exactly one site, every witness exists"
          f" ({len(FAULTS)} faults)")
    sys.exit(0)

# The copy is the working tree as it stands now, uncommitted edits included; later edits
# do not reach this run. Checksums, not timestamps: a fault left by a killed run can share
# its original's size and age.
TREE.mkdir(parents=True, exist_ok=True)
subprocess.run(["rsync", "-a", "--checksum", "--delete",
                *[f"--exclude={e}" for e in COPY_EXCLUDES], f"{ROOT}/", f"{TREE}/"], check=True)
compose = ["docker-compose", "--profile", "mutants"]
subprocess.run([*compose, "up", "-d", "--build", "--quiet-pull", "mutants"], cwd=ROOT, check=True,
               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
atexit.register(lambda: subprocess.run([*compose, "stop", "mutants"], cwd=ROOT,
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))

# A fault proves something only against a tree that passes without it. On a tree already
# red -- a syntax error, a failing test -- every fault reads as caught.
# The interface checks too: an unreachable mutants site would read every fault as caught.
for base in sorted({s.partition(':')[0] for *_, s in FAULTS}):
    if not (run(base)[0] if base == "ui" else phpunit("--testsuite", base).returncode == 0):
        print(f"  the {base} suite is red before any fault is injected — nothing can be proven")
        sys.exit(1)

survived = []
# A fault that never reached the file proves nothing, so a skip fails the run rather
# than printing a note. An anchor string that drifts during a refactor would otherwise
# drop its fault from the suite silently, and the run would still report success.
skipped = []
for num, name, relpath, old, new, suite in FAULTS:
    f = TREE/relpath
    backup = tempfile.NamedTemporaryFile(delete=False).name
    shutil.copy2(f, backup)
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
        green, why = run(suite)
        verdict = "SURVIVED (green)" if green else "caught (red)"
        if green: survived.append((num, name, why))
        print(f"  {num:2d}. {name:34s} {verdict}{'' if why is None else f' — {why}'}")
    finally:
        shutil.copy2(backup, f)
        os.unlink(backup)

print()
if survived:
    print("  FAULTS THAT SURVIVED:")
    for n, s, why in survived: print(f"    {n}. {s}{'' if why is None else f' — {why}'}")
if skipped:
    print("  FAULTS THAT WERE NEVER APPLIED:")
    for n, s, why in skipped: print(f"    {n}. {s} — {why}")
if not survived and not skipped:
    print("  every injected fault was caught")
sys.exit(1 if survived or skipped else 0)