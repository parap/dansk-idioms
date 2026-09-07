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

# Markup in a passage body must reach the page as text. The gap marker sits next to it so
# a substitution that runs before escaping is caught by the same fixture.
XSS_BODY = ('En tekst med <img src=x onerror="window.__pwned=1"> og et hul {{1}} midt i. '
            'Resten af teksten fortsætter herefter.')


# ---- talking to the app ----------------------------------------------------

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
    """Publish exactly one known passage; return the ids that were hidden to do it."""
    body = base64.b64encode(XSS_BODY.encode()).decode()
    code = """
        use Dansk\\Domain\\Reading\\ReadingRepository;
        use Dansk\\Support\\Db;
        $hidden = Db::fetchAll("SELECT id FROM reading_passages WHERE is_published = 1 AND slug <> 'ui-fixture'");
        Db::execute("UPDATE reading_passages SET is_published = 0 WHERE slug <> 'ui-fixture'");
        // Db::fetchValue returns PDO's false for a missing row, never null.
        $id = Db::fetchValue("SELECT id FROM reading_passages WHERE slug = 'ui-fixture'");
        if (!$id) {
            $id = (new ReadingRepository())->save([
                'slug' => 'ui-fixture', 'kind' => 'cloze', 'title' => 'UI fixture',
                'body' => base64_decode('%s'),
                'items' => [['position' => 1, 'options' => [
                    ['label' => 'A', 'text' => 'foerste', 'correct' => true],
                    ['label' => 'B', 'text' => 'anden'],
                    ['label' => 'C', 'text' => 'tredje'],
                    ['label' => 'D', 'text' => 'fjerde'],
                ]]],
            ]);
        }
        (new ReadingRepository())->publish((int) $id);
        echo json_encode(array_column($hidden, "id"));
    """ % body
    return json.loads(php(code))


def restore(hidden):
    ids = ','.join(str(int(i)) for i in hidden) or '0'
    php(f'''use Dansk\\Support\\Db;
        Db::execute("UPDATE reading_passages SET is_published = 0 WHERE slug = 'ui-fixture'");
        Db::execute("UPDATE reading_passages SET is_published = 1 WHERE id IN ({ids})");''')


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

    def goto(self, path):
        self.send('Page.navigate', url=BASE + path)
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
    b.goto('/read')
    b.until('!!document.querySelector("#go")', what='the start button')
    b.js('document.querySelector("#go").click()')
    b.until('!!document.querySelector(".opt")', what='a paper to be rendered')


@check
def the_start_screen_offers_a_round(b):
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


# ---- runner ----------------------------------------------------------------

def main():
    global BASE
    if '--base' in sys.argv:
        BASE = sys.argv[sys.argv.index('--base') + 1]

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
