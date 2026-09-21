#!/usr/bin/env python3
"""
Drives the real interface in a real browser.

The PHPUnit suites prove the API cannot be fooled. Nothing proved that the page built on
top of it renders what it should, and the page is where a learner meets the product --
an escaping mistake or a dead button is invisible to every server-side test in the repo.

Each check below drives headless Chrome over the DevTools Protocol and asserts against
the live DOM after the module has run.

    python3 bin/ui-tests.py [--base http://localhost:8080]

Publishes a fixture passage and hides the rest for the duration, then restores every
passage it touched, including on failure or a signal.
"""

import base64, json, pathlib, signal, socket, subprocess, sys, tempfile, time, urllib.request

import websocket

ROOT = pathlib.Path(__file__).resolve().parent.parent
BASE = 'http://localhost:8080'

# The admin surface answers on its own listener, published to the loopback. Driving it
# through BASE would prove nothing about the interface a reviewer actually uses, and
# would quietly pass on a build where the public listener still serves the queue.
ADMIN_BASE = 'http://localhost:8082'

# Markup in a passage body must reach the page as text. The gap marker sits next to it so
# a substitution that runs before escaping is caught by the same fixture.
XSS_BODY = ('En tekst med <img src=x onerror="window.__pwned=1"> og et hul {{1}} midt i. '
            'Resten af teksten fortsætter herefter.')


# ---- talking to the app ----------------------------------------------------

SEED_PHP = r"""
    use Dansk\Domain\Reading\ReadingRepository;
    use Dansk\Support\Db;
    $hidden = Db::fetchAll("SELECT id FROM reading_passages WHERE is_published = 1 AND slug NOT LIKE 'ui-fixture%'");
    Db::execute("UPDATE reading_passages SET is_published = 0");
    $repo = new ReadingRepository();

    // Db::fetchValue hands back PDO's false for a missing row, never null.
    if (!Db::fetchValue("SELECT id FROM reading_passages WHERE slug = 'ui-fixture'")) {
        $repo->save([
            'slug' => 'ui-fixture', 'kind' => 'cloze', 'title' => 'UI fixture',
            'body' => base64_decode('__BODY__'),
            'items' => [['position' => 1, 'options' => [
                ['label' => 'A', 'text' => 'foerste', 'correct' => true],
                ['label' => 'B', 'text' => 'anden'],
                ['label' => 'C', 'text' => 'tredje'],
                ['label' => 'D', 'text' => 'fjerde'],
            ]]],
        ]);
    }
    if (!Db::fetchValue("SELECT id FROM reading_passages WHERE slug = 'ui-fixture-mc'")) {
        $repo->save([
            'slug' => 'ui-fixture-mc', 'kind' => 'mc', 'title' => 'UI fixture mc',
            'body' => 'En kort tekst uden huller, som spoergsmaalene handler om.',
            'items' => [['position' => 1, 'prompt' => 'Hvad handler teksten om?', 'options' => [
                ['label' => 'A', 'text' => 'En kort tekst', 'correct' => true],
                ['label' => 'B', 'text' => 'Noget andet'],
                ['label' => 'C', 'text' => 'Ingenting'],
            ]]],
        ]);
    }
    if (!Db::fetchValue("SELECT id FROM reading_passages WHERE slug = 'ui-fixture-insert'")) {
        $repo->save([
            'slug' => 'ui-fixture-insert', 'kind' => 'insert', 'title' => 'UI fixture insert',
            'body' => 'Foerste saetning. {{1}} Sidste saetning.',
            'bank' => [
                ['label' => 'A', 'text' => 'Den indsatte tekstdel hoerer til her.'],
                ['label' => 'B', 'text' => 'Denne tekstdel passer ingen steder.'],
            ],
            'items' => [['position' => 1, 'correct_label' => 'A']],
        ]);
    }
    if (!Db::fetchValue("SELECT id FROM reading_passages WHERE slug = 'ui-fixture-quiz'")) {
        $repo->save([
            'slug' => 'ui-fixture-quiz', 'kind' => 'quiz', 'title' => 'UI fixture proeve',
            'body' => null, 'pass' => 3, 'vaerdier_min' => 1,
            'items' => [
                ['position' => 1, 'section' => 'laeremateriale', 'prompt' => 'Hvad er hovedstaden?',
                 'options' => [['label' => 'A', 'text' => 'Koebenhavn', 'correct' => true],
                               ['label' => 'B', 'text' => 'Odense'],
                               ['label' => 'C', 'text' => 'Aarhus']]],
                ['position' => 2, 'section' => 'laeremateriale', 'prompt' => 'Hvilket aar kom grundloven?',
                 'options' => [['label' => 'A', 'text' => '1849', 'correct' => true],
                               ['label' => 'B', 'text' => '1864'],
                               ['label' => 'C', 'text' => '1901']]],
                ['position' => 3, 'section' => 'aktuelle', 'prompt' => 'Hvem er statsminister?',
                 'options' => [['label' => 'A', 'text' => 'Den ene', 'correct' => true],
                               ['label' => 'B', 'text' => 'Den anden'],
                               ['label' => 'C', 'text' => 'Den tredje']]],
                ['position' => 4, 'section' => 'vaerdier', 'prompt' => 'Er ytringsfrihed beskyttet?',
                 'options' => [['label' => 'A', 'text' => 'Ja', 'correct' => true],
                               ['label' => 'B', 'text' => 'Nej']]],
            ],
        ]);
    }
    echo json_encode(array_column($hidden, "id"));
"""

FLAG_PHP = r"""
    use Dansk\Support\Db;
    $id = Db::fetchValue("SELECT i.id FROM reading_items i
                          JOIN reading_passages p ON p.id = i.passage_id
                          WHERE p.slug = 'ui-fixture' ORDER BY i.position LIMIT 1");
    // Idempotent: each check flags the item afresh, and one reader may report once.
    Db::execute("DELETE FROM reading_reports WHERE item_id = ?", [$id]);
    Db::execute("INSERT INTO reading_reports (item_id, user_id, anon_key, reason, note)
                 VALUES (?, NULL, ?, 'also_correct', 'Mit svar var ogsaa rigtigt.')",
                [$id, str_repeat('1', 32)]);
    Db::execute("INSERT INTO reading_reports (item_id, user_id, anon_key, reason, note)
                 VALUES (?, NULL, ?, 'no_correct', NULL)", [$id, str_repeat('2', 32)]);
    Db::execute("UPDATE reading_items SET is_flagged = 1, report_count = 2 WHERE id = ?", [$id]);
    echo (int) $id;
"""

ADMIN_PW_PHP = r"""
    echo (string) Dansk\Support\Config::get('admin.password');
"""

CLEAR_THROTTLE_PHP = r"""
    use Dansk\Support\Db;
    Db::execute("DELETE FROM admin_login_attempts");
"""


class AdminAttempts:
    """Mirrors AdminLoginThrottle::MAX_FAILURES; the check only needs the shape."""
    LIMIT = 8


PUBLISH_PHP = r"""
    use Dansk\Support\Db;
    Db::execute("UPDATE reading_passages SET is_published = 0 WHERE slug LIKE 'ui-fixture%'");
    Db::execute("UPDATE reading_passages SET is_published = 1
                  WHERE slug LIKE 'ui-fixture%' AND kind IN (__KINDS__)");
"""

RESTORE_PHP = r"""
    use Dansk\Support\Db;
    Db::execute("UPDATE reading_passages SET is_published = 0 WHERE slug LIKE 'ui-fixture%'");
    Db::execute("UPDATE reading_passages SET is_published = 1 WHERE id IN (__IDS__)");

    // The appeal check files a report, and a fresh browser profile means a fresh
    // anon_key, so two runs look like two independent readers and withdraw the very
    // item the other checks depend on. Restoring publication alone is not enough.
    Db::execute("DELETE r FROM reading_reports r
                 JOIN reading_items i ON i.id = r.item_id
                 JOIN reading_passages p ON p.id = i.passage_id
                 WHERE p.slug LIKE 'ui-fixture%'");
    Db::execute("UPDATE reading_items i
                 JOIN reading_passages p ON p.id = i.passage_id
                 SET i.is_flagged = 0, i.report_count = 0
                 WHERE p.slug LIKE 'ui-fixture%'");
"""


def php(code):
    """Run PHP inside the app container and return its stdout."""
    r = subprocess.run(
        ['docker-compose', 'exec', '-T', 'app', 'php'],
        input="<?php require '/var/www/html/vendor/autoload.php';\n" + code,
        capture_output=True, text=True, cwd=ROOT,
    )
    if r.returncode != 0:
        raise RuntimeError(f'php failed: {r.stderr.strip()}')
    return r.stdout.strip()


def seed():
    """Create the fixture passages, hide everything else, return the ids hidden."""
    body = base64.b64encode(XSS_BODY.encode()).decode()
    return json.loads(php(SEED_PHP.replace('__BODY__', body)))


def only(kinds):
    """Publish just the fixture passages of these kinds, so a round is predictable."""
    quoted = ','.join("'" + k + "'" for k in kinds)
    php(PUBLISH_PHP.replace('__KINDS__', quoted))


def restore(hidden):
    """Put every passage back the way the run found it."""
    ids = ','.join(str(int(i)) for i in hidden) or '0'
    php(RESTORE_PHP.replace('__IDS__', ids))


def correct_indexes():
    """Every answer of the newest session, in order -- read from the server, never the page."""
    return json.loads(php('''use Dansk\\Support\\Db;
        $sid = Db::fetchValue("SELECT id FROM reading_sessions ORDER BY id DESC LIMIT 1");
        echo json_encode(array_map("intval", array_column(Db::fetchAll(
            "SELECT correct_index FROM reading_session_items WHERE session_id = ? ORDER BY position",
            [$sid]), "correct_index")));'''))


def correct_index():
    """The answer, read from the server the way a grader would -- never from the page."""
    return int(php('''use Dansk\\Support\\Db;
        echo (int) Db::fetchValue("SELECT si.correct_index FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             ORDER BY s.id DESC, si.position ASC LIMIT 1");'''))


# ---- driving the browser ---------------------------------------------------

class Browser:
    def __init__(self):
        self.port = self._free_port()
        self.profile = tempfile.mkdtemp(prefix='ui-tests-')
        self.proc = subprocess.Popen(
            ['google-chrome', '--headless=new', '--disable-gpu', '--no-sandbox',
             f'--remote-debugging-port={self.port}', f'--user-data-dir={self.profile}',
             '--window-size=1200,900', 'about:blank'],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        # Chrome refuses a DevTools socket that arrives with an Origin it was not told
        # to expect. Sending none is narrower than opening the endpoint to all origins.
        self.ws = websocket.create_connection(self._page_socket(), timeout=20, suppress_origin=True)
        self.seq = 0

    @staticmethod
    def _free_port():
        with socket.socket() as s:
            s.bind(('127.0.0.1', 0))
            return s.getsockname()[1]

    def _page_socket(self):
        deadline = time.time() + 20
        while time.time() < deadline:
            try:
                pages = json.load(urllib.request.urlopen(f'http://127.0.0.1:{self.port}/json/list'))
                for p in pages:
                    if p.get('type') == 'page':
                        return p['webSocketDebuggerUrl']
            except Exception:
                pass
            time.sleep(0.2)
        raise RuntimeError('Chrome never offered a page to attach to.')

    def send(self, method, **params):
        self.seq += 1
        self.ws.send(json.dumps({'id': self.seq, 'method': method, 'params': params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get('id') == self.seq:
                if 'error' in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get('result', {})

    def js(self, expression):
        r = self.send('Runtime.evaluate', expression=expression,
                      returnByValue=True, awaitPromise=True)
        result = r.get('result', {})
        if r.get('exceptionDetails'):
            raise RuntimeError(f"page threw: {r['exceptionDetails'].get('text')}")
        return result.get('value')

    def goto(self, path, base=None):
        self.send('Page.navigate', url=(base or BASE) + path)
        self.until('document.readyState === "complete"')

    def until(self, expression, timeout=10, what=None):
        deadline = time.time() + timeout
        while time.time() < deadline:
            try:
                if self.js(expression):
                    return True
            except RuntimeError:
                pass
            time.sleep(0.1)
        raise AssertionError(f'timed out waiting for {what or expression}')

    def close(self):
        try:
            self.ws.close()
        finally:
            self.proc.terminate()
            self.proc.wait(timeout=10)


# ---- the checks ------------------------------------------------------------

CHECKS = []


def check(fn):
    CHECKS.append(fn)
    return fn


def start_round(b):
    only(['cloze'])
    b.goto('/read')
    b.until('!!document.querySelector("#go")', what='the start button')
    b.js('document.querySelector("#go").click()')
    b.until('!!document.querySelector(".opt")', what='a paper to be rendered')


@check
def the_start_screen_offers_a_round(b):
    only(['cloze'])
    b.goto('/read')
    b.until('!!document.querySelector("#go")', what='the start button')
    assert b.js('document.querySelector("#go").textContent.trim().length > 0')


@check
def a_round_renders_the_passage_and_its_options(b):
    start_round(b)
    assert b.js('document.querySelector(".passage h2").textContent') == 'UI fixture'
    assert b.js('document.querySelectorAll(".opt").length') == 4
    assert b.js('[...document.querySelectorAll(".opt strong")].map(e => e.textContent).join("")') == 'A.B.C.D.'


@check
def markup_in_a_passage_is_shown_as_text_not_executed(b):
    start_round(b)
    assert b.js('window.__pwned === undefined'), 'passage markup executed'
    assert b.js('document.querySelectorAll(".passage img").length') == 0
    assert b.js('document.querySelector(".passage p").textContent.includes("<img")')


@check
def a_gap_marker_becomes_a_button_rather_than_literal_braces(b):
    start_round(b)
    assert b.js('document.querySelectorAll(".passage .gap").length') == 1
    assert not b.js('document.querySelector(".passage p").textContent.includes("{{1}}")')


@check
def clicking_a_gap_marks_it_current(b):
    start_round(b)
    b.js('document.querySelector(".gap").click()')
    assert b.js('document.querySelector(".gap").getAttribute("aria-current") === "true"')


@check
def the_correct_answer_is_marked_right_and_scores(b):
    start_round(b)
    i = correct_index()
    b.js(f'document.querySelectorAll(".opt")[{i}].click()')
    b.until('document.querySelectorAll(".opt.right").length === 1', what='the answer to be marked')
    assert b.js(f'document.querySelectorAll(".opt")[{i}].classList.contains("right")')
    assert b.js('document.querySelector("#score").textContent.trim()') == '1 / 1'


@check
def a_wrong_answer_is_marked_and_the_right_one_revealed(b):
    start_round(b)
    wrong = (correct_index() + 1) % 4
    b.js(f'document.querySelectorAll(".opt")[{wrong}].click()')
    b.until('document.querySelectorAll(".opt.right").length === 1', what='the answer to be revealed')
    assert b.js(f'document.querySelectorAll(".opt")[{wrong}].classList.contains("wrong")')
    assert b.js('document.querySelector("#score").textContent.trim()') == '0 / 1'


@check
def an_answered_item_cannot_be_answered_again_from_the_page(b):
    start_round(b)
    b.js('document.querySelectorAll(".opt")[0].click()')
    b.until('document.querySelectorAll(".opt.right").length === 1', what='the answer to land')
    assert b.js('[...document.querySelectorAll(".opt")].every(o => o.disabled)')


@check
def answering_fills_the_gap_and_offers_another_round(b):
    start_round(b)
    b.js('document.querySelectorAll(".opt")[0].click()')
    b.until('document.querySelectorAll(".opt.right").length === 1', what='the answer to land')
    assert b.js('document.querySelector(".gap").classList.contains("filled")')
    assert b.js('!document.querySelector("#foot").hidden')


@check
def a_learner_can_appeal_an_item_after_answering(b):
    start_round(b)
    click_option(b, 1, 0)
    b.until('!!document.querySelector("[id^=rep-]")', what='the appeal link')
    b.js('document.querySelector("[id^=rep-]").click()')
    b.until('!document.querySelector("[id^=rep-]")', what='the link to be replaced')
    assert b.js('document.querySelector("[id^=fb-]").textContent.length') > 0


def options_js(position):
    """The options for one item, as a JS expression. Kept out of the checks so the
    quoting lives in exactly one place."""
    return '[...document.querySelectorAll(".opt")].filter(o => o.dataset.pos === "%d")' % position


def click_option(b, position, index):
    b.js(options_js(position) + '[%d].click()' % index)


def start_exam(b):
    only(['mc', 'insert', 'cloze'])
    b.goto('/read')
    b.until('!!document.querySelector("#goExam")', what='the exam button')
    b.js('document.querySelector("#goExam").click()')
    b.until('!!document.querySelector(".opt")', what='the paper to be rendered')


@check
def an_exam_draws_a_text_of_every_task_kind(b):
    start_exam(b)
    assert b.js('document.querySelectorAll(".passage").length') == 3
    assert b.js('document.querySelectorAll(".item").length') == 3


@check
def an_exam_shows_a_countdown(b):
    start_exam(b)
    b.until('/\\d\\d:\\d\\d/.test(document.querySelector("#left").textContent)', what='the clock')
    # 65 minutes, counted down from whatever the server said was left -- a second or
    # two will already have gone by the time the paper is on screen.
    minutes = int(b.js('document.querySelector("#left").textContent').split(':')[0])
    assert 63 <= minutes <= 65, f'clock started at {minutes} minutes'


@check
def an_exam_reveals_nothing_when_an_answer_is_recorded(b):
    start_exam(b)
    click_option(b, 1, 0)
    b.until('document.querySelectorAll(".opt.chosen").length === 1', what='the choice to register')
    # The mark may say "you picked this" and nothing more. Reusing the right/wrong
    # colours would tell a candidate how they did, which is the whole difference
    # between an exam and a drill.
    assert b.js('document.querySelectorAll(".opt.right").length') == 0
    assert b.js('document.querySelectorAll(".opt.wrong").length') == 0
    assert b.js('[...document.querySelectorAll("[id^=fb-]")].every(p => p.hidden)')


@check
def an_exam_answer_can_be_changed_before_handing_in(b):
    start_exam(b)
    click_option(b, 1, 0)
    b.until('document.querySelectorAll(".opt.chosen").length === 1', what='the first choice')
    click_option(b, 1, 1)
    b.until(options_js(1) + '[1].classList.contains("chosen")', what='the revised choice')
    assert not b.js(options_js(1) + '[0].classList.contains("chosen")')


@check
def handing_in_reports_a_karakter_and_a_review(b):
    start_exam(b)
    for pos in (1, 2, 3):
        click_option(b, pos, 0)
    b.until('document.querySelectorAll(".opt.chosen").length === 3', what='every item answered')
    b.js('window.confirm = () => true')
    b.js('document.querySelector("#hand").click()')
    b.until('!!document.querySelector("#final")', what='the result screen')
    assert b.js('document.querySelectorAll(".item").length') == 3
    # Options are shuffled per session, so which index is right is not fixed. The
    # paper is worth five points whatever was clicked.
    total = b.js('document.querySelector("#final").textContent').split('/')[1].strip()
    assert total == '5', f'paper reported out of {total}, expected 5'
    # The karakter is shown, and shown as indicative rather than as an exam grade.
    assert b.js('document.querySelector("#final").nextElementSibling.textContent.trim().length') > 0



VISIBLE_PASSAGES = '[...document.querySelectorAll(".passage")].filter(e => e.getClientRects().length)'


@check
def a_reading_exam_shows_one_question_at_a_time(b):
    start_exam(b)
    assert b.js('document.querySelectorAll(".item").length') == 3
    assert b.js(VISIBLE_ITEMS + '.length') == 1
    assert b.js(VISIBLE_ITEMS + '[0].id') == 'item-1'


@check
def a_reading_tab_opens_its_question_and_the_text_it_belongs_to(b):
    start_exam(b)
    b.js(tab_js(2) + '.click()')
    b.until(VISIBLE_ITEMS + '[0].id === "item-2"', what='the second question')
    # A reading question cannot be answered without its text, and only its own text is
    # any use: three at once is the scrolling this change exists to remove.
    assert b.js(VISIBLE_PASSAGES + '.length') == 1
    assert b.js(VISIBLE_PASSAGES + '[0].dataset.passage') == b.js(VISIBLE_ITEMS + '[0].dataset.passage')


@check
def a_reading_tab_marks_its_question_answered(b):
    start_exam(b)
    assert not b.js(tab_js(1) + '.classList.contains("answered")')
    click_option(b, 1, 0)
    b.until(tab_js(1) + '.classList.contains("answered")', what='the tab to mark it answered')
    assert not b.js(tab_js(2) + '.classList.contains("answered")')


@check
def a_gap_opens_the_question_it_stands_for(b):
    start_exam(b)
    position = b.js('Number(document.querySelector(".gap").dataset.gap)')
    b.js('document.querySelector(".gap").click()')
    b.until(VISIBLE_ITEMS + '[0].id === "item-%d"' % position,
            what='the question the gap stands for')
    # Without this the check passes on a page that shows everything at once.
    assert b.js(VISIBLE_ITEMS + '.length') == 1


@check
def a_round_of_one_question_offers_no_tabs(b):
    # A guard rather than a driver: one question has nowhere to turn to.
    start_round(b)
    assert b.js('document.querySelectorAll(".item").length') == 1
    assert b.js('document.querySelectorAll(".tab").length') == 0


# ---- the indfoedsretsproeve ------------------------------------------------

def start_paper(b, current_affairs=True):
    only(['quiz'])
    b.goto('/proeve')
    b.until('!!document.querySelector("#go")', what='the start button')
    if not current_affairs:
        b.js('document.querySelector("#current").click()')
    b.js('document.querySelector("#go").click()')
    b.until('!!document.querySelector(".opt")', what='the paper to be rendered')


def sit_paper(b, positions):
    """Answer each position correctly, then hand in."""
    for pos, index in zip(positions, correct_indexes()):
        click_option(b, pos, index)
    b.until('document.querySelectorAll(".opt.chosen").length === %d' % len(positions),
            what='every question answered')
    b.js('window.confirm = () => true')
    b.js('document.querySelector("#hand").click()')
    b.until('!!document.querySelector(".verdict")', what='the result screen')


@check
def the_chooser_lists_the_sittings_and_what_passing_takes(b):
    only(['quiz'])
    b.goto('/proeve')
    b.until('!!document.querySelector("#paper option")', what='the paper list')
    assert b.js('document.querySelector("#paper option").textContent') == 'UI fixture proeve'
    # The mark is on the start screen, not only in the result: a candidate should know
    # what they are aiming at before the clock starts.
    assert '3/4' in b.js('document.querySelector("#about").textContent')


@check
def a_paper_renders_its_blocks_and_every_question(b):
    start_paper(b)
    assert b.js('document.querySelectorAll(".item").length') == 4
    assert b.js('document.querySelectorAll(".block").length') == 3
    assert b.js('document.querySelectorAll(".opt").length') == 11
    # Nothing to read: a knowledge paper has no passage pane at all.
    assert b.js('document.querySelectorAll(".passage").length') == 0


VISIBLE_ITEMS = '[...document.querySelectorAll(".item")].filter(e => e.getClientRects().length)'


def tab_js(position):
    """One question's tab, as a JS expression, so the quoting lives in one place."""
    return "document.querySelector('.tab[data-pos=\"%d\"]')" % position


@check
def a_paper_shows_one_question_at_a_time(b):
    start_paper(b)
    # Every question stays in the document, because an answer is recorded against the
    # paper as a whole, but a candidate reads one at a time.
    assert b.js('document.querySelectorAll(".item").length') == 4
    assert b.js(VISIBLE_ITEMS + '.length') == 1
    assert b.js(VISIBLE_ITEMS + '[0].id') == 'item-1'


@check
def a_tab_opens_its_own_question_and_closes_the_others(b):
    start_paper(b)
    b.js(tab_js(3) + '.click()')
    b.until(VISIBLE_ITEMS + '[0].id === "item-3"', what='the third question')
    assert b.js(VISIBLE_ITEMS + '.length') == 1


@check
def a_tab_stays_marked_until_its_question_is_answered(b):
    start_paper(b)
    assert not b.js(tab_js(1) + '.classList.contains("answered")')

    click_option(b, 1, 0)
    b.until(tab_js(1) + '.classList.contains("answered")', what='the tab to mark it answered')
    assert not b.js(tab_js(2) + '.classList.contains("answered")')

    # Answered and unanswered have to be told apart by sight, not only by class name.
    assert b.js('getComputedStyle(%s).backgroundColor !== getComputedStyle(%s).backgroundColor'
                % (tab_js(1), tab_js(2))), 'the two states are painted the same'


@check
def the_questions_are_set_in_large_type(b):
    start_paper(b)
    size = b.js('parseFloat(getComputedStyle(document.querySelector(".prompt")).fontSize)')
    assert size >= 20, f'the question is set at {size}px'


def press(b, code, key):
    """A real key event, as Chrome delivers one, rather than a synthetic dispatch."""
    for kind in ('keyDown', 'keyUp'):
        b.send('Input.dispatchKeyEvent', type=kind, code=code, key=key)


@check
def a_number_key_answers_and_turns_the_page(b):
    start_paper(b)
    press(b, 'Digit2', '2')
    b.until(options_js(1) + '[1].classList.contains("chosen")', what='the second option')
    b.until(VISIBLE_ITEMS + '[0].id === "item-2"', what='the next question')


@check
def a_letter_key_answers_whatever_the_keyboard_layout(b):
    start_paper(b)
    # 'KeyB' is the physical key. On a Russian layout it types 'и', and it must still
    # answer B -- this page is read by people typing on Cyrillic keyboards.
    press(b, 'KeyB', 'и')
    b.until(options_js(1) + '[1].classList.contains("chosen")', what='option B')
    b.until(VISIBLE_ITEMS + '[0].id === "item-2"', what='the next question')


@check
def a_key_with_no_option_behind_it_does_nothing(b):
    start_paper(b)
    b.js(tab_js(4) + '.click()')
    b.until(VISIBLE_ITEMS + '[0].id === "item-4"', what='the last question')
    # The fourth fixture question offers two options; there is no third to choose.
    press(b, 'Digit3', '3')
    press(b, 'KeyC', 'c')
    assert b.js('document.querySelectorAll(".opt.chosen").length') == 0
    assert b.js(VISIBLE_ITEMS + '[0].id') == 'item-4', 'the page turned on a key that chose nothing'


@check
def leaving_a_paper_takes_the_keyboard_with_it(b):
    """A listener left behind answers a paper that is no longer on screen: it posts to
    the abandoned session and then throws where nobody is looking."""
    start_paper(b)
    b.js("""window.__errors = []; window.__posts = [];
            addEventListener('error', e => __errors.push('error: ' + e.message));
            addEventListener('unhandledrejection', e => __errors.push('rejection: ' + e.reason));
            const f = fetch; window.fetch = (...a) => { __posts.push(String(a[0])); return f(...a); };""")
    b.js('window.confirm = () => true')
    b.js('document.querySelector("#leave").click()')
    b.until('!!document.querySelector("#go")', what='the chooser')

    press(b, 'Digit1', '1')
    press(b, 'KeyA', 'a')

    # Both the request and the throw arrive a moment later, so "nothing happened" is a
    # claim that has to be given time to be wrong.
    deadline = time.time() + 2
    while time.time() < deadline:
        answered = [u for u in b.js('window.__posts') if '/answers' in u]
        assert not answered, f'a key answered a paper that was left: {answered}'
        assert b.js('window.__errors') == [], b.js('window.__errors')
        time.sleep(0.2)


@check
def handing_in_waits_until_every_question_is_answered(b):
    start_paper(b)
    answers = correct_indexes()
    assert b.js('document.querySelector("#hand").disabled'), 'an empty paper can be handed in'
    assert b.js('document.querySelector("#remaining").textContent').startswith('4')

    for pos in (1, 2, 3):
        click_option(b, pos, answers[pos - 1])
    b.until('document.querySelectorAll(".opt.chosen").length === 3', what='three answers')
    assert b.js('document.querySelector("#hand").disabled'), 'one question is still unanswered'
    assert b.js('document.querySelector("#remaining").textContent').startswith('1')

    click_option(b, 4, answers[3])
    b.until('!document.querySelector("#hand").disabled', what='handing in to be allowed')
    assert b.js('document.querySelector("#remaining").textContent').strip() == ''


@check
def moving_between_questions_is_easy_to_hit(b):
    start_paper(b)
    # Turning the page is what a candidate does forty-four times; handing in happens once.
    # 44px is the touch target a thumb finds without aiming.
    for which in ('#prev', '#next'):
        box = 'document.querySelector("%s").getBoundingClientRect()' % which
        height, width = b.js(box + '.height'), b.js(box + '.width')
        assert height >= 44, f'{which} is {height}px tall'
        assert width >= 96, f'{which} is {width}px wide'


@check
def a_paper_can_be_left_without_handing_it_in(b):
    start_paper(b)
    b.js('window.confirm = () => true')
    b.js('document.querySelector("#leave").click()')
    b.until('!!document.querySelector("#go")', what='the chooser')
    assert b.js('document.querySelectorAll(".item").length') == 0
    assert b.js('document.querySelector("#leave").hidden'), 'the way out is offered off a paper'


@check
def a_paper_shows_a_forty_five_minute_countdown(b):
    start_paper(b)
    b.until('/\\d\\d:\\d\\d/.test(document.querySelector("#left").textContent)', what='the clock')
    minutes = int(b.js('document.querySelector("#left").textContent').split(':')[0])
    assert 43 <= minutes <= 45, f'clock started at {minutes} minutes'


@check
def a_recorded_answer_reveals_nothing_and_can_be_changed(b):
    start_paper(b)
    click_option(b, 1, 0)
    b.until('document.querySelectorAll(".opt.chosen").length === 1', what='the choice to register')
    assert b.js('document.querySelectorAll(".opt.right, .opt.wrong").length') == 0

    click_option(b, 1, 1)
    b.until(options_js(1) + '[1].classList.contains("chosen")', what='the revised choice')
    assert not b.js(options_js(1) + '[0].classList.contains("chosen")')


@check
def handing_in_a_correct_paper_reports_a_pass(b):
    start_paper(b)
    sit_paper(b, (1, 2, 3, 4))
    assert b.js('document.querySelector(".verdict").classList.contains("pass")')
    assert b.js('document.querySelector(".tally b").textContent').strip() == '4 / 4'
    # The review shows every question again, with the key.
    assert b.js('document.querySelectorAll(".opt.right").length') == 4


@check
def a_round_without_the_current_affairs_block_is_scored_but_not_judged(b):
    start_paper(b, current_affairs=False)
    assert b.js('document.querySelectorAll(".item").length') == 3
    assert not b.js('[...document.querySelectorAll(".block")].some(e => e.textContent.trim() === "Актуальные вопросы")')

    sit_paper(b, (1, 2, 3))
    verdict = b.js('document.querySelector(".verdict")')
    assert not b.js('document.querySelector(".verdict").classList.contains("pass")')
    assert not b.js('document.querySelector(".verdict").classList.contains("fail")')
    assert b.js('document.body.textContent').find('сокращённый') > 0


@check
def every_page_carries_the_way_home(b):
    """The mark is the way back, so it is the same mark everywhere and it sits where a
    reader looks for it: first in the header, top left."""
    for path in ('/', '/read', '/proeve'):
        b.goto(path)
        b.until('!!document.querySelector(".brand")', what='the brand on ' + path)
        assert b.js('document.querySelector(".brand").getAttribute("href")') == '/', path
        assert 'idiomer' in b.js('document.querySelector(".brand").textContent'), path
        assert b.js('document.querySelector("header").firstElementChild'
                    '.classList.contains("brand")'), f'{path} puts something before the mark'


LANGUAGES = ['ru', 'en', 'uk', 'da']


def offered_languages(b):
    return b.js('[...document.querySelectorAll(".lang")].map(e => e.dataset.lang)')


def choose_language(b, code):
    b.js("document.querySelector('.lang[data-lang=\"%s\"]').click()" % code)


@check
def every_page_offers_the_same_languages(b):
    """A language offered on one page and missing on the next drops the reader back into
    Russian halfway through the site, and the fallback is silent."""
    try:
        for path in ('/', '/read', '/proeve'):
            b.goto(path)
            b.until('!!document.querySelector(".lang")', what='the language switch on ' + path)
            assert offered_languages(b) == LANGUAGES, f'{path} offers {offered_languages(b)}'
    finally:
        b.js("localStorage.setItem('ui_lang', 'ru')")


@check
def the_language_chosen_on_one_page_holds_on_the_next(b):
    try:
        only(['quiz'])
        b.goto('/proeve')
        b.until('!!document.querySelector("#go")', what='the start button')
        choose_language(b, 'uk')
        b.until('document.querySelector("#go").textContent === "Почати"', what='Ukrainian')

        b.goto('/read')
        b.until('!!document.querySelector(".lang")', what='the reading page')
        assert 'Тренування' in b.js('document.body.textContent'), 'the reading page fell back'
    finally:
        b.js("localStorage.setItem('ui_lang', 'ru')")


@check
def a_paper_in_progress_does_not_offer_the_language_switch(b):
    # Changing language rebuilds the start screen, which would abandon the round.
    start_paper(b)
    assert b.js('document.querySelector("#langs").hidden'), 'the switch is live during a paper'


def flag_a_fixture_item():
    """Withdraw one fixture item by hand, so the queue has something in it."""
    return int(php(FLAG_PHP))


def admin_password():
    return php(ADMIN_PW_PHP)


SIGNED_IN = ('!document.querySelector("#app").hidden'
             ' || !document.querySelector("#done").hidden'
             ' || !document.querySelector("#flags").hidden')

# The admin page keeps every panel hidden until its bootstrap has answered "is this
# session still good?", so a hidden #app means "not decided yet" as often as it means
# "signed out". SETTLED is the point where the answer exists and SIGNED_IN can be read.
SETTLED = SIGNED_IN + ' || !document.querySelector("#login").hidden'


def sign_in_to_admin(b):
    pw = admin_password()
    if pw == '':
        raise AssertionError('admin.password is not configured, so the queue cannot be reached')

    b.goto('/admin', ADMIN_BASE)
    b.until(SETTLED, what='the admin page to settle on a panel')

    # Only sign in when the session is actually gone. Logging in again regenerates the
    # session id and destroys the old one, so a request already in flight comes back 401
    # -- which is right for the app and wrong for a test that re-authenticates on every
    # visit. A person does not retype their password on each page either.
    if b.js(SIGNED_IN):
        return

    b.js('document.querySelector("#pw").value = ' + json.dumps(pw))
    b.js('document.querySelector("#loginBtn").click()')
    b.until(SIGNED_IN, what='an admin view to appear')


@check
def the_flag_queue_shows_a_withdrawn_item_and_what_was_said(b):
    flag_a_fixture_item()
    sign_in_to_admin(b)
    b.js('document.querySelector("#toFlags").click()')
    b.until('!!document.querySelector(".flag")', what='the flag queue')

    assert b.js('document.querySelectorAll(".flag").length') == 1
    text = b.js('document.querySelector(".flag").textContent')
    assert 'UI fixture' in text
    assert 'Mit svar var ogsaa rigtigt.' in text, 'the note a learner wrote is not shown'
    assert 'no_correct' in text, 'the second report is not shown'


@check
def the_queue_marks_which_option_the_key_calls_correct(b):
    flag_a_fixture_item()
    sign_in_to_admin(b)
    b.js('document.querySelector("#toFlags").click()')
    b.until('!!document.querySelector(".flag")', what='the flag queue')

    # A reviewer cannot judge "my answer was also right" without seeing the key.
    assert b.js('document.querySelectorAll(".flag .opt.right").length') == 1


@check
def clearing_a_flag_empties_the_queue(b):
    flag_a_fixture_item()
    sign_in_to_admin(b)
    b.js('document.querySelector("#toFlags").click()')
    b.until('!!document.querySelector(".flag")', what='the flag queue')
    b.js('document.querySelector(".flag .keep").click()')
    b.until('document.querySelectorAll(".flag").length === 0', what='the queue to empty')

    assert php("use Dansk\\Support\\Db; echo (int) Db::fetchValue("
               "\"SELECT COUNT(*) FROM reading_items WHERE is_flagged = 1\");") == '0'


@check
def the_health_endpoint_keeps_its_internals_to_itself(b):
    b.goto('/')
    payload = b.js("fetch('/api/v1/health').then(r => r.json())")
    # A monitor needs up-or-down. Anything more only narrows the search for a prober.
    assert payload.get('status') == 'ok', payload
    for leaked in ('php', 'env', 'db_error'):
        assert leaked not in payload, 'health leaks %r: %r' % (leaked, payload)


@check
def guessing_the_admin_password_runs_out_of_attempts(b):
    php(CLEAR_THROTTLE_PHP)
    b.goto('/admin', ADMIN_BASE)
    try:
        codes = []
        for _ in range(AdminAttempts.LIMIT + 1):
            codes.append(b.js(
                "fetch('/api/v1/admin/login', {method:'POST',"
                " headers:{'Content-Type':'application/json'},"
                " body: JSON.stringify({password:'not-the-password'})}).then(r => r.status)"))
        assert 429 in codes, 'never rate limited: %r' % codes
        assert codes.index(429) > 3, 'locked out too eagerly: %r' % codes
    finally:
        # Leave nothing behind, or the next run signs in to a locked-out admin.
        php(CLEAR_THROTTLE_PHP)


@check
def the_public_listener_does_not_serve_the_admin_surface(b):
    # The review queue and everything under it answer on the loopback listener only, so
    # a machine with a public address is not offering its database console to whoever
    # scans it. A forged Host header must not move the answer, which is the whole reason
    # the listener marks itself rather than the request being trusted to say where it
    # arrived.
    b.goto('/admin')
    assert b.js('!document.querySelector("#pw")'), 'the login form is served publicly'

    for path in ('/api/v1/admin/review', '/api/v1/admin/reading/flags'):
        status = b.js("fetch('%s').then(r => r.status)" % path)
        assert status == 404, '%s answers %s on the public listener' % (path, status)

    forged = b.js(
        "fetch('/api/v1/admin/review', {headers: {'Dansk-Admin-Listener': '1'}})"
        ".then(r => r.status)")
    assert forged == 404, 'a header moved the admin surface onto the public listener'


# ---- runner ----------------------------------------------------------------

def main():
    global BASE, ADMIN_BASE
    if '--base' in sys.argv:
        BASE = sys.argv[sys.argv.index('--base') + 1]
    if '--admin-base' in sys.argv:
        ADMIN_BASE = sys.argv[sys.argv.index('--admin-base') + 1]

    hidden = seed()
    browser = None
    restored = False

    def cleanup(*_):
        nonlocal restored
        if not restored:
            restored = True
            restore(hidden)
        if browser:
            browser.close()

    signal.signal(signal.SIGINT, lambda *a: (cleanup(), sys.exit(130)))
    signal.signal(signal.SIGTERM, lambda *a: (cleanup(), sys.exit(143)))

    failed = []
    try:
        browser = Browser()
        for fn in CHECKS:
            name = fn.__name__.replace('_', ' ')
            try:
                fn(browser)
                print(f'  {name:62s} ok')
            except Exception as e:
                failed.append((name, e))
                print(f'  {name:62s} FAILED  {e}')
    finally:
        cleanup()

    print()
    if failed:
        print(f'  {len(failed)} of {len(CHECKS)} interface checks failed')
    else:
        print(f'  all {len(CHECKS)} interface checks passed')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
