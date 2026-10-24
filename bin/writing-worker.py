#!/usr/bin/env python3
"""
Answers the writing checks the app queues, with Claude Code on this host's subscription.

The app runs in a container and Claude Code on the host, so they meet in storage/writing:
SubscriptionWritingReviewer drops a job into queue/ and waits for done/. The systemd path
unit deploy/systemd/dansk-writing.path starts this whenever a job appears.

Claude Code runs without tools, sessions, settings or MCP servers, in an empty directory:
the review is one structured answer, and the owner's own setup must not reach it.

    python3 bin/writing-worker.py          # answer everything queued, then exit
"""
import json, os, pathlib, subprocess, sys, tempfile, time

ROOT = pathlib.Path(os.environ.get('WRITING_DIR', pathlib.Path(__file__).resolve().parent.parent / 'storage/writing'))
CLAUDE = os.environ.get('CLAUDE_BIN', str(pathlib.Path.home() / '.local/bin/claude'))
# Inside the 170 seconds the app waits, so an answer never arrives for a job already abandoned.
SECONDS = 150
# An answer the app gave up on waiting for is never read; it goes after this long.
STALE_SECONDS = 600


def run_claude(job):
    with tempfile.TemporaryDirectory() as empty:
        return subprocess.run(
            [CLAUDE, '-p', '--tools', '', '--no-session-persistence', '--setting-sources', '',
             '--strict-mcp-config', '--output-format', 'json', '--effort', 'medium',
             '--system-prompt', job['system'], '--json-schema', json.dumps(job['schema'])],
            input=job['prompt'], capture_output=True, text=True, cwd=empty, timeout=SECONDS)


def answer(job):
    try:
        run = run_claude(job)
    except subprocess.TimeoutExpired:
        return {'ok': False, 'error': f'Claude Code took longer than {SECONDS} seconds'}
    try:
        result = json.loads(run.stdout)
    except ValueError:
        return {'ok': False, 'error': f'Claude Code exited {run.returncode}: {run.stderr.strip()[:300]}'}
    if result.get('is_error') or result.get('structured_output') is None:
        return {'ok': False, 'error': str(result.get('result') or result.get('subtype') or 'no structured answer')[:300]}
    usage = result.get('usage', {})
    return {
        'ok': True,
        'output': result['structured_output'],
        'model': next(iter(result.get('modelUsage') or {}), ''),
        'input_tokens': sum(usage.get(k, 0) for k in ('input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens')),
        'output_tokens': usage.get('output_tokens', 0),
    }


def main():
    queue, done = ROOT / 'queue', ROOT / 'done'
    for old in done.glob('*.json'):
        if time.time() - old.stat().st_mtime > STALE_SECONDS:
            old.unlink(missing_ok=True)
    for job_file in sorted(queue.glob('*.json'), key=lambda p: p.stat().st_mtime):
        # Claimed by renaming: a job the app has already taken back is simply gone.
        claimed = job_file.with_suffix('.work')
        try:
            job_file.rename(claimed)
        except FileNotFoundError:
            continue
        try:
            reply = answer(json.loads(claimed.read_text()))
        except (ValueError, KeyError) as e:
            reply = {'ok': False, 'error': f'unreadable job: {e}'}
        part = done / (job_file.stem + '.part')
        part.write_text(json.dumps(reply, ensure_ascii=False))
        part.rename(done / job_file.name)
        claimed.unlink(missing_ok=True)
    return 0


if __name__ == '__main__':
    sys.exit(main())
