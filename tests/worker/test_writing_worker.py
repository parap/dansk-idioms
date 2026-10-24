"""bin/writing-worker.py against a stand-in for Claude Code.

    python3 -m unittest discover -s tests/worker
"""
import json, os, pathlib, subprocess, sys, tempfile, time, unittest

WORKER = pathlib.Path(__file__).resolve().parents[2] / 'bin/writing-worker.py'
FEEDBACK = {'summary': 'Godt.', 'verdict': 'likely_pass', 'task': [], 'errors': [], 'strengths': [], 'corrected': 'Hej.'}


class WritingWorkerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = pathlib.Path(self.tmp.name)
        for sub in ('queue', 'done'):
            (self.dir / sub).mkdir()
        self.calls = self.dir / 'calls.jsonl'

    def tearDown(self):
        self.tmp.cleanup()

    def claude(self, body):
        """A stand-in that records its arguments and stdin, then runs `body`."""
        stub = self.dir / 'claude'
        stub.write_text(f'''#!/usr/bin/env python3
import json, os, sys
open({str(self.calls)!r}, 'a').write(json.dumps({{'argv': sys.argv[1:], 'stdin': sys.stdin.read(), 'cwd': os.getcwd()}}) + '\\n')
{body}
''')
        stub.chmod(0o755)
        return str(stub)

    def queue(self, name='a1', job=None):
        job = job or {'system': 'Du er lærer.', 'prompt': 'Hej Sune.', 'schema': {'type': 'object'}, 'model': 'sonnet'}
        (self.dir / 'queue' / f'{name}.json').write_text(json.dumps(job))

    def run_worker(self, claude):
        subprocess.run([sys.executable, str(WORKER)], check=True,
                       env={**os.environ, 'WRITING_DIR': str(self.dir), 'CLAUDE_BIN': claude})

    def answered(self, name='a1'):
        return json.loads((self.dir / 'done' / f'{name}.json').read_text())

    def test_answers_a_job_with_the_structured_output_and_its_cost(self):
        self.queue()
        self.run_worker(self.claude(f'''print(json.dumps({{"type": "result", "is_error": False, "structured_output": {json.dumps(FEEDBACK)},
    "modelUsage": {{"claude-opus-5-5": {{}}}}, "usage": {{"input_tokens": 3, "cache_creation_input_tokens": 1000, "cache_read_input_tokens": 500, "output_tokens": 700}}}}))'''))

        self.assertEqual(self.answered(), {'ok': True, 'output': FEEDBACK, 'model': 'claude-opus-5-5', 'input_tokens': 1503, 'output_tokens': 700})
        self.assertEqual(list((self.dir / 'queue').iterdir()), [], 'the job was left in the queue')

    def test_runs_claude_code_bare_of_tools_and_settings_in_an_empty_directory(self):
        self.queue()
        self.run_worker(self.claude('print("{}")'))

        call = json.loads(self.calls.read_text())
        argv = call['argv']
        self.assertIn('-p', argv)
        self.assertEqual(argv[argv.index('--tools') + 1], '')
        self.assertEqual(argv[argv.index('--setting-sources') + 1], '')
        self.assertIn('--no-session-persistence', argv)
        self.assertEqual(argv[argv.index('--system-prompt') + 1], 'Du er lærer.')
        self.assertEqual(argv[argv.index('--model') + 1], 'sonnet')
        self.assertEqual(json.loads(argv[argv.index('--json-schema') + 1]), {'type': 'object'})
        self.assertEqual(call['stdin'], 'Hej Sune.')
        self.assertFalse(pathlib.Path(call['cwd']).exists(), 'claude ran in a directory that outlived it')

    def test_refuses_a_model_it_does_not_offer_without_running_claude(self):
        self.queue(job={'system': 's', 'prompt': 'p', 'schema': {}, 'model': 'claude-fable-5-1'})
        self.run_worker(self.claude('print("{}")'))

        self.assertFalse(self.calls.exists(), 'claude ran on a model that is not offered')
        self.assertFalse(self.answered()['ok'])

    def test_a_failed_run_is_answered_as_a_failure(self):
        self.queue()
        self.run_worker(self.claude('print("usage limit reached", file=sys.stderr); sys.exit(1)'))

        answer = self.answered()
        self.assertFalse(answer['ok'])
        self.assertIn('usage limit reached', answer['error'])

    def test_a_run_without_a_structured_answer_is_a_failure(self):
        self.queue()
        self.run_worker(self.claude('print(json.dumps({"type": "result", "is_error": True, "result": "Credit balance is too low"}))'))

        self.assertEqual(self.answered(), {'ok': False, 'error': 'Credit balance is too low'})

    def test_answers_every_job_queued(self):
        self.queue('a1')
        self.queue('b2')
        self.run_worker(self.claude(f'print(json.dumps({{"structured_output": {json.dumps(FEEDBACK)}, "usage": {{}}}}))'))

        self.assertTrue(self.answered('a1')['ok'])
        self.assertTrue(self.answered('b2')['ok'])

    def test_leaves_a_half_written_job_alone(self):
        (self.dir / 'queue' / 'a1.json.part').write_text('{"sys')
        self.run_worker(self.claude('print("{}")'))

        self.assertFalse(self.calls.exists(), 'a job still being written was answered')
        self.assertTrue((self.dir / 'queue' / 'a1.json.part').exists())

    def test_clears_an_answer_nobody_collected(self):
        stale = self.dir / 'done' / 'old.json'
        stale.write_text('{}')
        os.utime(stale, (time.time() - 3600, time.time() - 3600))
        fresh = self.dir / 'done' / 'new.json'
        fresh.write_text('{}')
        self.run_worker(self.claude('print("{}")'))

        self.assertFalse(stale.exists())
        self.assertTrue(fresh.exists())


if __name__ == '__main__':
    unittest.main()
