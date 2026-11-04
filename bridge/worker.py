#!/usr/bin/env python3
"""Local command worker. Approval is required unless --auto is explicitly enabled."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

from actions import parse_command, dispatch

MAX_OUTPUT = 32_768
ENV_KEYS = frozenset({
    'SUPABASE_URL', 'SUPABASE_SECRET_KEY', 'BRIDGE_CWD',
    'BRIDGE_POLL_SECONDS', 'BRIDGE_TIMEOUT_SECONDS', 'BRIDGE_MCP_CONFIG',
})


def load_env_file(path):
    """Load supported settings without overriding the process environment.

    Values are literal: no expansion, escapes, or inline comments. Matching
    outer single/double quotes are stripped; Windows backslashes are preserved.
    """
    path = Path(path)
    if not path.exists():
        return
    values = {}
    with path.open(encoding='utf-8-sig') as stream:
        for line_number, line in enumerate(stream, 1):
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            name, separator, value = line.partition('=')
            name, value = name.strip(), value.strip()
            if not separator or name not in ENV_KEYS or name in values:
                raise ValueError(f'Invalid or duplicate setting on line {line_number}')
            if value.startswith(('"', "'")):
                if len(value) < 2 or value[-1] != value[0]:
                    raise ValueError(f'Unmatched quote on line {line_number}')
                value = value[1:-1]
            values[name] = value
    for name, value in values.items():
        os.environ.setdefault(name, value)


def request(base, key, method, path, payload=None, prefer=None):
    headers = {"apikey": key, "Authorization": "Bearer " + key, "Content-Type": "application/json"}
    if prefer:
        headers['Prefer'] = prefer
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(base.rstrip('/') + '/rest/v1/' + path, data=data, headers=headers, method=method)
    with urllib.request.urlopen(req, timeout=15) as response:
        body = response.read()
        return json.loads(body) if body else None


def execute(command, cwd, timeout):
    # Temporary files keep process output out of RAM, but do not cap disk use.
    with tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr:
        try:
            completed = subprocess.run(command, shell=True, cwd=cwd, stdin=subprocess.DEVNULL,
                                       stdout=stdout, stderr=stderr, timeout=timeout)
            status, code = 'completed', completed.returncode
        except subprocess.TimeoutExpired:
            status, code = 'failed', None
        def read_output(f):
            f.seek(0, os.SEEK_END)
            size = f.tell()
            f.seek(0)
            return {'text': f.read(MAX_OUTPUT).decode('utf-8', errors='replace'), 'truncated': size > MAX_OUTPUT}
        return status, {'exit_code': code, 'timed_out': code is None,
                        'stdout': read_output(stdout), 'stderr': read_output(stderr)}


def approved(auto):
    if auto:
        print('AUTO MODE: executing without local approval.', flush=True)
        return True
    try:
        return input('Execute with your local account privileges? Type YES to approve: ') == 'YES'
    except EOFError:
        return False


def main(argv=None):
    parser = argparse.ArgumentParser(description='Run the local Supabase command worker.', allow_abbrev=False)
    parser.add_argument('--auto', action='store_true', help='DANGEROUS: automatically execute every queued command without approval')
    args = parser.parse_args(argv)
    try:
        load_env_file(Path(__file__).resolve().parent / '.env')
    except (OSError, UnicodeError, ValueError) as exc:
        sys.exit(f'Cannot load bridge/.env: {exc}')
    base = os.environ.get('SUPABASE_URL', '').rstrip('/')
    key = os.environ.get('SUPABASE_SECRET_KEY', '')
    if not base.startswith('https://') or not key:
        sys.exit('Set SUPABASE_URL (https) and SUPABASE_SECRET_KEY in bridge/.env or your environment.')
    cwd = Path(os.environ.get('BRIDGE_CWD', '.')).resolve()
    if not cwd.is_dir():
        sys.exit('BRIDGE_CWD must be an existing directory.')
    try:
        interval = float(os.environ.get('BRIDGE_POLL_SECONDS', '3'))
        timeout = float(os.environ.get('BRIDGE_TIMEOUT_SECONDS', '60'))
        if not 1 <= interval <= 300 or not 1 <= timeout <= 600:
            raise ValueError()
    except ValueError:
        sys.exit('BRIDGE_POLL_SECONDS must be 1–300; BRIDGE_TIMEOUT_SECONDS must be 1–600.')
    worker = str(uuid.uuid4())
    print(f'Bridge {worker} started; working directory: {cwd}.', flush=True)
    if args.auto:
        print('WARNING: AUTO MODE ENABLED. Every queued shell, file, and MCP request will run without approval as this user. Stop with Ctrl+C.', flush=True)
    else:
        print('Every command requires local approval.', flush=True)
    while True:
        try:
            rows = request(base, key, 'POST', 'rpc/arena_bridge_claim', {'p_worker_id': worker})
            if not rows:
                time.sleep(interval)
                continue
            job = rows[0]
            print('\n' + '=' * 60 + '\nRequest ' + job['id'] + '\nWorking directory: ' + str(cwd) + '\nCommand (Python string representation):\n' + repr(job['command']) + '\n' + '=' * 60, flush=True)
            if approved(args.auto):
                try:
                    action = parse_command(job['command'])
                    if action['op'] == 'shell':
                        status, result = execute(action['command'], str(cwd), timeout)
                    else:
                        result = dispatch(action, cwd, timeout)
                        status = 'failed' if result.get('is_error') or result.get('response', {}).get('isError') else 'completed'
                except Exception as exc:
                    status, result = 'failed', {'error': type(exc).__name__, 'message': str(exc)[:2000]}
            else:
                status, result = 'rejected', {'message': 'Not approved locally.'}
            path = 'arena_bridge_commands?id=eq.' + urllib.parse.quote(job['id'], safe='') + '&worker_id=eq.' + worker + '&status=eq.running'
            payload = {'status': status, 'result': result, 'finished_at': __import__('datetime').datetime.now(__import__('datetime').timezone.utc).isoformat()}
            # If the network fails here, retry reporting the SAME result, never rerun the command.
            while True:
                try:
                    updated = request(base, key, 'PATCH', path, payload, 'return=representation')
                    if not updated:
                        print('Warning: result was not saved; job state changed. Do not rerun blindly.', file=sys.stderr, flush=True)
                    else:
                        print('Result sent:', status, flush=True)
                    break
                except (OSError, urllib.error.URLError) as exc:
                    print('Result upload failed; retrying without rerunning command:', type(exc).__name__, file=sys.stderr, flush=True)
                    time.sleep(interval)
        except (OSError, urllib.error.URLError, ValueError, KeyError) as exc:
            print('Polling error:', type(exc).__name__, str(exc), file=sys.stderr, flush=True)
            time.sleep(interval)


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        print('\nStopped. Running jobs are not automatically retried.')
