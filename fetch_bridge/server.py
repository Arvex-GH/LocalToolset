#!/usr/bin/env python3
"""Local fetch bridge: an authenticated HTTP endpoint that runs what you fetch.

This is the pull-free companion to the archived ``archive/supabase-bridge/worker.py``. The queue bridge needs
a Supabase project, a connector, and a worker that polls. This one needs only a
URL: it listens on a local port (normally reached through a tunnel), and every
HTTP GET you *fetch* carries the request. The server runs the operation and
returns the result in the response body, so an agent whose only outbound
capability is "fetch this URL" can run shell commands, read and write files,
and drive a local MCP server.

Each fetched operation becomes a short-lived job. The response reports the
job's status plus a bounded slice of its output; further GETs to
``job?id=...`` wait for more output or page through it by byte offset. That is
what makes long builds usable even though a single fetch is time-bounded.

Security model (README.md has the long version):

* A bearer token in the URL path (``/x/<token>/<op>``). Agents can only issue
  GETs and cannot compute HMACs, so request signing is not available; a
  single-use ``nonce`` gives replay-safe retries instead.
* Every operation asks for local approval by default. ``--auto`` removes that
  barrier and is the only way to get unattended execution.
* Loopback binding by default; public reachability is the tunnel's job (TLS).
* Paths are confined to ``BRIDGE_CWD`` and text is size-capped, but shell and
  MCP operations are not sandboxed in any way.
"""
import argparse
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import subprocess
import sys
import tempfile
import threading
import time
import urllib.parse
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'shared'))
try:
    import actions as bridge_actions
except ImportError as exc:  # pragma: no cover - installation problem
    sys.exit(f'Cannot import shared/actions.py; keep shared/ next to fetch_bridge/ ({exc}).')

VERSION = '1'
MAX_URL_CHARS = 6000
MAX_POST_BYTES = 1_100_000
DEFAULT_LIMIT = 16_384
MAX_LIMIT = 32_768
MAX_CAPTURE_BYTES = 4_000_000
MAX_JOBS_KEPT = 100
MAX_WAIT = 25
DEFAULT_PORT = 8765
TERMINAL = frozenset({'completed', 'failed', 'rejected', 'cancelled'})
RESERVED_TOKENS = frozenset({'x', 'health', 'robots.txt', 'favicon.ico'})
ENV_KEYS = frozenset({
    'BRIDGE_TOKEN', 'BRIDGE_CWD', 'BRIDGE_HOST', 'BRIDGE_PORT',
    'BRIDGE_TIMEOUT_SECONDS', 'BRIDGE_WAIT_SECONDS', 'BRIDGE_STATE_DIR',
    'BRIDGE_MCP_CONFIG', 'BRIDGE_RATE_LIMIT', 'BRIDGE_REQUIRE_NONCE',
    'BRIDGE_PUBLIC_URL', 'BRIDGE_AUTH_FAILURE_LIMIT',
})

ALIASES = {'exec': 'shell', 'run': 'shell', 'sh': 'shell', 'cat': 'read', 'list': 'ls'}
UNIVERSAL_PARAMS = frozenset({
    't', 'token', 'op', 'n', 'nonce', 'wait', 'detach', 'offset', 'len',
    'format', 'stream',
})
OP_PARAMS = {
    'ping': frozenset(),
    'help': frozenset(),
    'shell': frozenset({'cmd', 'timeout'}),
    'read': frozenset({'path'}),
    'write': frozenset({'path', 'text', 'content', 'mode'}),
    'edit': frozenset({'path', 'old', 'new'}),
    'mkdir': frozenset({'path'}),
    'ls': frozenset({'path', 'count'}),
    'mcp.list': frozenset({'cursor'}),
    'mcp.call': frozenset({'tool', 'args'}),
    'job': frozenset({'id'}),
    'jobs': frozenset({'count'}),
    'cancel': frozenset({'id'}),
}
REQUIRED_PARAMS = {
    'shell': ('cmd',),
    'read': ('path',),
    'write': ('path',),
    'edit': ('path', 'old', 'new'),
    'mkdir': ('path',),
    'mcp.call': ('tool',),
}
IMMEDIATE_OPS = frozenset({'ping', 'help', 'jobs', 'cancel'})
WRITE_MODES = frozenset({'create', 'overwrite', 'append'})


TOKEN_PATTERN = re.compile(r'^[A-Za-z0-9._~-]{16,200}$')


def valid_token(token):
    """True when a token can survive the URL path and is not reserved.

    Space, "?", "#", "&", "/", "%", quotes and non-ASCII either change the
    parsed path or need percent-encoding the caller will not produce, so they
    are rejected up front rather than failing every request with 401.
    """
    return (isinstance(token, str) and bool(TOKEN_PATTERN.match(token))
            and token not in RESERVED_TOKENS and token not in OP_PARAMS
            and token not in ALIASES)


class ParamError(ValueError):
    """A request the caller can fix; reported as HTTP 400 with guidance."""


def printable(text, limit):
    """Escape control characters so remote text cannot rewrite a terminal."""
    text = str(text)
    cleaned = ''.join(ch if ch in '\n\t' or ch.isprintable() else '\\x%02x' % ord(ch) for ch in text)
    return cleaned if len(cleaned) <= limit else cleaned[:limit] + f'... ({len(cleaned) - limit} chars hidden)'


def load_env_file(path):
    """Load supported settings without overriding the process environment.

    Same literal semantics as the archived worker: KEY=value lines, full-line
    comments, optional matching quotes, no expansion or inline comments.
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


def _int(value, name, low, high):
    if value is None or value == '':
        return None
    try:
        number = int(str(value), 10)
    except ValueError:
        raise ParamError(f'{name} must be an integer') from None
    if not low <= number <= high:
        raise ParamError(f'{name} must be between {low} and {high}')
    return number


def _flag(value, name):
    if value is None or value == '':
        return False
    if value in ('1', 'true', 'yes', 'on'):
        return True
    if value in ('0', 'false', 'no', 'off'):
        return False
    raise ParamError(f'{name} must be 1 or 0')


def _text(params, name, required=True, limit=bridge_actions.MAX_FILE_BYTES, allow_empty=False):
    value = params.get(name)
    if value is None:
        if required:
            raise ParamError(f'missing required parameter: {name}')
        return None
    if not allow_empty and not value:
        raise ParamError(f'{name} must not be empty')
    if '\x00' in value:
        raise ParamError(f'{name} must not contain NUL characters')
    if len(value.encode('utf-8')) > limit:
        raise ParamError(f'{name} exceeds the {limit} byte limit')
    return value


def build_action(op, params):
    """Validate query parameters and normalise them into an action dict."""
    allowed = OP_PARAMS[op] | UNIVERSAL_PARAMS
    unknown = sorted(set(params) - allowed)
    if unknown:
        raise ParamError('unknown parameter(s): ' + ', '.join(unknown)
                         + '; valid for ' + op + ': ' + ', '.join(sorted(OP_PARAMS[op])))
    missing = [name for name in REQUIRED_PARAMS.get(op, ()) if name not in params]
    if missing:
        raise ParamError('missing required parameter(s): ' + ', '.join(missing))

    action = {'op': op}
    if op == 'shell':
        action['cmd'] = _text(params, 'cmd', limit=10_000)
        action['timeout'] = _int(params.get('timeout'), 'timeout', 1, 600)
    elif op in ('read', 'mkdir', 'ls', 'write', 'edit'):
        action['path'] = _text(params, 'path', required=op != 'ls', limit=4096)
        if op == 'ls' and action['path'] is None:
            action['path'] = '.'
        if op == 'write':
            text = params.get('text')
            if text is None:
                text = params.get('content')
            if text is None:
                raise ParamError('write requires text= (or content=)')
            action['text'] = _text(params, 'text' if 'text' in params else 'content',
                                   allow_empty=True)
            action['mode'] = (params.get('mode') or 'create').strip().lower()
            if action['mode'] not in WRITE_MODES:
                raise ParamError('mode must be create, overwrite, or append')
        elif op == 'edit':
            action['old'] = _text(params, 'old')
            action['new'] = _text(params, 'new', allow_empty=True)
        elif op == 'ls':
            action['count'] = _int(params.get('count'), 'count', 1, 500) or 100
    elif op == 'mcp.list':
        cursor = params.get('cursor')
        if cursor is not None and not isinstance(cursor, str):
            raise ParamError('cursor must be a string')
        action['cursor'] = cursor
    elif op == 'mcp.call':
        action['tool'] = _text(params, 'tool', limit=500)
        raw = params.get('args')
        if raw:
            try:
                arguments = json.loads(raw)
            except json.JSONDecodeError as exc:
                raise ParamError('args must be a JSON object: ' + str(exc)) from None
            if not isinstance(arguments, dict):
                raise ParamError('args must be a JSON object')
        else:
            arguments = {}
        action['arguments'] = arguments
    return action


def default_cwd():
    """The drive/volume root of this installation: C:\\ on Windows, / elsewhere.

    Chosen so a double-clicked launch can read and write anywhere on the system
    drive without configuration. Pass BRIDGE_CWD (or --cwd) to narrow it.
    """
    return Path(Path(__file__).resolve().anchor or os.sep)


class Settings:
    def __init__(self, cwd, token, host='127.0.0.1', port=DEFAULT_PORT, timeout=60,
                 wait=10, state_dir=None, auto=False, rate_limit=120,
                 require_nonce=False, generated_token=False, public_url=None,
                 auth_failure_limit=20):
        self.cwd = Path(cwd).resolve()
        self.token = token
        self.host = host
        self.port = port
        self.timeout = timeout
        self.wait = wait
        self.state_dir = Path(state_dir or Path(tempfile.gettempdir()) / 'arena-fetch-bridge')
        self.auto = auto
        self.rate_limit = rate_limit
        self.require_nonce = require_nonce
        self.generated_token = generated_token
        self.public_url = public_url.rstrip('/') if public_url else None
        self.auth_failure_limit = auth_failure_limit

    @classmethod
    def from_env(cls, cwd=None):
        cwd = cwd or os.environ.get('BRIDGE_CWD') or default_cwd()
        if not Path(cwd).is_dir():
            raise ValueError('BRIDGE_CWD must be an existing directory')
        token = os.environ.get('BRIDGE_TOKEN', '').strip()
        generated = False
        if not token:
            token, generated = secrets.token_urlsafe(24), True
        if not valid_token(token):
            raise ValueError(
                'BRIDGE_TOKEN must be 16-200 characters of A-Z a-z 0-9 . _ ~ - and not a '
                'reserved word, operation name or alias. It is read from the URL path, so '
                'spaces, "?", "#", "&", "/", "%", quotes and non-ASCII cannot match.')
        host = os.environ.get('BRIDGE_HOST', '127.0.0.1')
        port = _int(os.environ.get('BRIDGE_PORT'), 'BRIDGE_PORT', 0, 65_535)
        timeout = _int(os.environ.get('BRIDGE_TIMEOUT_SECONDS'), 'BRIDGE_TIMEOUT_SECONDS', 1, 600)
        wait = _int(os.environ.get('BRIDGE_WAIT_SECONDS'), 'BRIDGE_WAIT_SECONDS', 1, MAX_WAIT)
        rate = _int(os.environ.get('BRIDGE_RATE_LIMIT'), 'BRIDGE_RATE_LIMIT', 1, 10_000)
        failures = _int(os.environ.get('BRIDGE_AUTH_FAILURE_LIMIT'),
                        'BRIDGE_AUTH_FAILURE_LIMIT', 1, 10_000)
        require_nonce = _flag(os.environ.get('BRIDGE_REQUIRE_NONCE'), 'BRIDGE_REQUIRE_NONCE')
        return cls(cwd=cwd, token=token, host=host,
                   port=DEFAULT_PORT if port is None else port,
                   timeout=60 if timeout is None else timeout,
                   wait=10 if wait is None else wait,
                   state_dir=os.environ.get('BRIDGE_STATE_DIR') or None,
                   rate_limit=120 if rate is None else rate,
                   require_nonce=require_nonce, generated_token=generated,
                   public_url=os.environ.get('BRIDGE_PUBLIC_URL') or None,
                   auth_failure_limit=20 if failures is None else failures)


def read_bounded(path, limit=MAX_CAPTURE_BYTES):
    """Read at most `limit` bytes of a captured stream, decoded leniently."""
    try:
        with open(path, 'rb') as stream:
            return stream.read(limit).decode('utf-8', 'replace')
    except OSError:
        return ''


def file_size(path):
    try:
        return os.path.getsize(path)
    except OSError:
        return 0


def kill_tree(process):
    """Best-effort termination of the shell and its descendants."""
    if process.poll() is not None:
        return
    try:
        if os.name == 'posix':
            os.killpg(process.pid, signal.SIGKILL)
        else:
            subprocess.run(['taskkill', '/F', '/T', '/PID', str(process.pid)],
                           stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, timeout=10)
    except (OSError, subprocess.SubprocessError):
        pass
    try:
        process.kill()
    except OSError:
        pass


class Job:
    """One fetched operation; output lives on disk so it can be paged."""

    def __init__(self, action, directory, client):
        self.id = uuid.uuid4().hex[:12]
        self.action = action
        self.op = action['op']
        self.dir = directory / self.id
        self.dir.mkdir(parents=True, exist_ok=True)
        self.client = client
        self.status = 'queued'
        self.body = None
        self.exit_code = None
        self.timed_out = False
        self.cancelled = False
        self.truncated = False
        self.created = time.time()
        self.started = None
        self.finished = None
        self.process = None
        self.done = threading.Event()

    @property
    def elapsed(self):
        end = self.finished or time.time()
        return round(end - (self.started or self.created), 3)

    def summary(self):
        """Single-line, escaped description for logs and job listings."""
        action = self.action
        if action['op'] == 'shell':
            return printable('shell ' + repr(action['cmd']), 120)
        if action['op'] == 'write':
            return f"write {action['path']} ({len(action['text'].encode())} bytes, mode={action['mode']})"
        if action['op'] == 'edit':
            return f"edit {action['path']}"
        if action['op'] in ('read', 'mkdir', 'ls'):
            return f"{action['op']} {action['path']}"
        if action['op'] == 'mcp.call':
            return 'mcp.call ' + printable(action['tool'], 80)
        return action['op']


class Bridge:
    """Owns jobs, approvals, the rate limiter, and nonce de-duplication."""

    def __init__(self, settings, say=print):
        self.settings = settings
        self.say = say
        self.jobs = {}
        self.order = []
        self.lock = threading.Lock()
        self.stdin_lock = threading.Lock()
        self.nonces = {}
        self.nonce_lock = threading.Lock()
        self.rate_lock = threading.Lock()
        self.rate_hits = []
        self.auth_failures = []
        self.auth_lock = threading.Lock()
        self.jobs_root = settings.state_dir / 'jobs'
        shutil.rmtree(self.jobs_root, ignore_errors=True)
        self.jobs_root.mkdir(parents=True, exist_ok=True)

    # -- admission -----------------------------------------------------
    def allow_request(self):
        now = time.monotonic()
        with self.rate_lock:
            self.rate_hits = [stamp for stamp in self.rate_hits if now - stamp < 60]
            if len(self.rate_hits) >= self.settings.rate_limit:
                return False
            self.rate_hits.append(now)
            return True

    def note_auth_failure(self):
        """Record a wrong token; True when guessing should be throttled.

        Only failures are counted and only failures are answered with 429, so a
        flood of guesses cannot lock the real caller out of the bridge.
        """
        now = time.monotonic()
        with self.auth_lock:
            self.auth_failures = [stamp for stamp in self.auth_failures if now - stamp < 60]
            self.auth_failures.append(now)
            return len(self.auth_failures) > self.settings.auth_failure_limit

    def nonce_check(self, nonce):
        """Return an existing job id for a replayed nonce, or register it.

        Nonces make retries idempotent: if a fetch fails after the server ran
        the operation, repeating the same request returns the original result
        instead of running the command twice.
        """
        if not nonce:
            if self.settings.require_nonce:
                raise ParamError('this bridge requires a nonce (n=) on every request '
                                 'that creates a job; use a short unique value')
            return None
        if len(nonce) > 100:
            raise ParamError('nonce must be at most 100 characters')
        now = time.time()
        with self.nonce_lock:
            for key in [k for k, (_, expires) in self.nonces.items() if expires < now]:
                del self.nonces[key]
            return self.nonces.get(nonce, (None, 0))[0]

    def remember_nonce(self, nonce, job_id):
        if nonce:
            with self.nonce_lock:
                self.nonces[nonce] = (job_id, time.time() + 900)

    # -- job lifecycle -------------------------------------------------
    def submit(self, action, client, nonce=None):
        job = Job(action, self.jobs_root, client)
        with self.lock:
            self.jobs[job.id] = job
            self.order.append(job.id)
            while len(self.order) > MAX_JOBS_KEPT:
                stale = self.jobs.pop(self.order.pop(0), None)
                if stale is not None:
                    shutil.rmtree(stale.dir, ignore_errors=True)
        self.remember_nonce(nonce, job.id)
        threading.Thread(target=self._execute, args=(job,), daemon=True).start()
        return job

    def get(self, job_id):
        if not job_id:
            return None
        with self.lock:
            return self.jobs.get(job_id)

    def _execute(self, job):
        try:
            if not self.settings.auto:
                job.status = 'awaiting_approval'
            if not self.settings.auto and not self._approve(job):
                job.status = 'rejected'
                job.body = 'Rejected locally: approval was not given in the terminal.'
                return
            job.status = 'running'
            job.started = time.time()
            job.status = self._perform(job)
        except (ParamError, ValueError, OSError) as exc:
            job.status = 'failed'
            job.body = f'error: {type(exc).__name__}: {exc}'
        except Exception as exc:  # unexpected: never leave the job "running"
            job.status = 'failed'
            job.body = f'error: {type(exc).__name__}: {exc}'
        finally:
            job.finished = time.time()
            job.process = None
            self._write_meta(job)
            job.done.set()

    def _approve(self, job):
        with self.stdin_lock:
            lines = [
                '', '=' * 72,
                f'Fetch bridge: request from {job.client}',
                f'Job {job.id}: {job.summary()}',
                '=' * 72,
            ]
            self.say('\n'.join(lines))
            try:
                answer = input('Type YES to run this with your local account privileges: ')
            except (EOFError, KeyboardInterrupt):
                return False
            return answer.strip() == 'YES'

    def _write_meta(self, job):
        meta = {'id': job.id, 'op': job.op, 'status': job.status,
                'exit_code': job.exit_code, 'timed_out': job.timed_out,
                'created': job.created, 'started': job.started, 'finished': job.finished}
        try:
            (job.dir / 'meta.json').write_text(json.dumps(meta), encoding='utf-8')
        except OSError:
            pass

    def _perform(self, job):
        op = job.op
        if op == 'shell':
            return self._shell(job)
        if op in ('read', 'write', 'edit', 'mkdir', 'ls'):
            job.body = self._file(job.action)
            return 'completed'
        if op in ('mcp.list', 'mcp.call'):
            return self._mcp(job)
        raise ParamError('unsupported operation: ' + op)

    # -- shell ---------------------------------------------------------
    def _shell(self, job):
        action = job.action
        timeout = action['timeout'] or self.settings.timeout
        stdout_path, stderr_path = job.dir / 'stdout.raw', job.dir / 'stderr.raw'
        kwargs = {'shell': True, 'cwd': str(self.settings.cwd),
                  'stdin': subprocess.DEVNULL, 'stdout': subprocess.PIPE,
                  'stderr': subprocess.PIPE}
        if os.name == 'posix':
            kwargs['start_new_session'] = True
        else:
            kwargs['creationflags'] = subprocess.CREATE_NEW_PROCESS_GROUP
        process = subprocess.Popen(action['cmd'], **kwargs)
        job.process = process
        pumps = [
            threading.Thread(target=self._pump, args=(process.stdout, stdout_path), daemon=True),
            threading.Thread(target=self._pump, args=(process.stderr, stderr_path), daemon=True),
        ]
        for pump in pumps:
            pump.start()
        try:
            job.exit_code = process.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            job.timed_out = True
            kill_tree(process)
            try:
                job.exit_code = process.wait(timeout=15)
            except subprocess.TimeoutExpired:
                job.exit_code = None
        for pump in pumps:
            pump.join(15)
        job.truncated = file_size(stdout_path) >= MAX_CAPTURE_BYTES or file_size(stderr_path) >= MAX_CAPTURE_BYTES
        if job.cancelled:
            return 'cancelled'
        if job.timed_out:
            return 'failed'
        return 'completed' if job.exit_code == 0 else 'failed'

    @staticmethod
    def _pump(stream, path):
        """Copy a pipe to disk, keeping at most MAX_CAPTURE_BYTES."""
        written = 0
        try:
            with open(path, 'wb') as sink:
                reader = getattr(stream, 'read1', stream.read)
                while True:
                    chunk = reader(65_536)
                    if not chunk:
                        break
                    if written < MAX_CAPTURE_BYTES:
                        take = min(len(chunk), MAX_CAPTURE_BYTES - written)
                        sink.write(chunk[:take])
                        written += take
                        sink.flush()
        except (OSError, ValueError):
            pass
        finally:
            try:
                stream.close()
            except OSError:
                pass

    # -- files ---------------------------------------------------------
    def _file(self, action):
        op = action['op']
        path = bridge_actions.project_path(action['path'], self.settings.cwd)
        if op == 'read':
            text = bridge_actions.bounded_text(path)
            return text
        if op == 'write':
            if action['mode'] == 'append':
                return self._append(path, action['text'], action['path'])
            try:
                bridge_actions.file_action({'op': 'write', 'path': action['path'],
                                            'content': action['text'],
                                            'overwrite': action['mode'] == 'overwrite'},
                                           self.settings.cwd)
            except FileExistsError:
                raise ValueError(f'file already exists: {action["path"]} '
                                 '(use mode=overwrite or mode=append)') from None
            return (f"wrote {len(action['text'].encode('utf-8'))} bytes to {action['path']} "
                    f"(mode={action['mode']})")
        if op == 'edit':
            result = bridge_actions.file_action({'op': 'edit', 'path': action['path'],
                                                 'old': action['old'], 'new': action['new']},
                                                self.settings.cwd)
            return f"replaced {result['replacements']} occurrence in {action['path']}"
        if op == 'mkdir':
            try:
                path.mkdir()
            except FileExistsError:
                if not path.is_dir():
                    raise ValueError(f'a file already exists at {action["path"]}') from None
                return f'already exists: {action["path"]}/'
            return f'created {action["path"]}/'
        if op == 'ls':
            return self._ls(path, action['path'], action['count'])
        raise ValueError('unsupported file operation')

    def _append(self, path, text, shown):
        data = text.encode('utf-8')
        existing = path.stat().st_size if path.exists() else 0
        if path.exists() and not path.is_file():
            raise ValueError(f'not a regular file: {shown}')
        if existing + len(data) > bridge_actions.MAX_FILE_BYTES:
            raise ValueError('appended file would exceed the 1 MB limit')
        with path.open('ab') as sink:
            sink.write(data)
        return f'appended {len(data)} bytes to {shown} ({existing + len(data)} bytes total)'

    @staticmethod
    def _ls(path, shown, count):
        if not path.is_dir():
            raise ValueError(f'not a directory: {shown}')
        try:
            entries = sorted(path.iterdir(), key=lambda e: (not e.is_dir(), e.name.lower()))
        except OSError as exc:
            raise ValueError(f'cannot list {shown}: {exc}') from None
        lines = []
        for entry in entries[:count]:
            try:
                kind = 'dir ' if entry.is_dir() else 'file'
                size = '' if entry.is_dir() else str(entry.stat().st_size)
            except OSError:
                kind, size = '??? ', '?'
            lines.append(f'{kind} {size:>10}  {entry.name}')
        if len(entries) > count:
            lines.append(f'... {len(entries) - count} more entries (raise count= up to 500)')
        return '\n'.join(lines) or '(empty directory)'

    # -- mcp -----------------------------------------------------------
    def _mcp(self, job):
        action = job.action
        request = {'op': action['op']}
        if action['op'] == 'mcp.list':
            request['cursor'] = action.get('cursor')
        else:
            request['tool'] = action['tool']
            request['arguments'] = action['arguments']
        result = bridge_actions.dispatch(request, str(self.settings.cwd),
                                         action.get('timeout') or self.settings.timeout)
        job.body = json.dumps(result, ensure_ascii=False)
        is_error = result.get('is_error') or (result.get('response') or {}).get('isError')
        return 'failed' if is_error else 'completed'

    # -- rendering -----------------------------------------------------
    def body_text(self, job, stream='both'):
        """Rendered output for one job, byte-for-byte pageable.

        Shell jobs stream to files, so their body is read from disk and can be
        paged while the command is still running. Everything else keeps its
        rendered text in memory. A shell job with a message but no captured
        bytes was rejected, is awaiting approval, or never started.
        """
        if job.op != 'shell':
            return job.body if job.body is not None else ''
        out = read_bounded(job.dir / 'stdout.raw')
        err = read_bounded(job.dir / 'stderr.raw')
        if job.body and not out and not err:
            return job.body
        if stream == 'stdout':
            return out
        if stream == 'stderr':
            return err
        if job.status in TERMINAL and err:
            separator = '' if out.endswith('\n') or not out else '\n'
            return out + separator + '--- stderr ---\n' + err
        return out or err or '(no output)'

    def render(self, job, offset=0, limit=DEFAULT_LIMIT, stream='both', note=None):
        body = self.body_text(job, stream)
        raw = body.encode('utf-8')
        total = len(raw)
        offset = max(0, min(offset, total))
        end = min(total, offset + limit)
        chunk = raw[offset:end].decode('utf-8', 'ignore')
        has_more = end < total
        header = [f'BRIDGE v{VERSION}', f'status={job.status}', f'id={job.id}', f'op={job.op}']
        if job.op == 'shell':
            header += [f'exit={job.exit_code}', f'timed_out={int(job.timed_out)}',
                       f'stdout_bytes={file_size(job.dir / "stdout.raw")}',
                       f'stderr_bytes={file_size(job.dir / "stderr.raw")}']
        header += [f'secs={job.elapsed}', f'offset={offset}', f'total={total}',
                   f'shown={end - offset}', f'has_more={int(has_more)}',
                   f'next_offset={end if has_more else "-"}']
        if job.truncated:
            header.append(f'truncated_at={MAX_CAPTURE_BYTES}')
        lines = [' '.join(header)]
        if has_more:
            lines.append(f'note: more output is available; refetch '
                         f'/x/<token>/job?id={job.id}&offset={end}')
        if note:
            lines.append('note: ' + note)
        return '\n'.join(lines) + '\n\n' + chunk

    def as_json(self, job, offset=0, limit=DEFAULT_LIMIT, stream='both'):
        body = self.body_text(job, stream)
        raw = body.encode('utf-8')
        total = len(raw)
        offset = max(0, min(offset, total))
        end = min(total, offset + limit)
        return json.dumps({
            'id': job.id, 'op': job.op, 'status': job.status,
            'exit_code': job.exit_code, 'timed_out': job.timed_out,
            'body': raw[offset:end].decode('utf-8', 'ignore'),
            'offset': offset, 'total_bytes': total, 'has_more': end < total,
            'next_offset': end if end < total else None,
            'created_at': job.created, 'started_at': job.started, 'finished_at': job.finished,
        }, ensure_ascii=False, indent=2)

    def listing(self, count=20):
        with self.lock:
            ids = list(reversed(self.order))[:count]
            rows = [self.jobs[job_id] for job_id in ids if job_id in self.jobs]
        if not rows:
            return 'no jobs yet'
        return '\n'.join(f'{job.id} {job.status:<9} {job.elapsed:>7.3f}s  {job.summary()}'
                         for job in rows)


HELP_FOOTER = """
Response shape
  First line: BRIDGE v1 status=... id=... op=... [exit= timed_out= stdout_bytes= stderr_bytes=] secs=... offset=... total=... shown=... has_more=... next_offset=...
  Blank line, then the body. status is one of queued, awaiting_approval, running,
  completed, failed, rejected, cancelled. A non-zero exit= or failed status is a
  result, not a transport error: read the body.

Paging and waiting
  job?id=JOBID                 wait (default 10s) for an existing job and show its output
  job?id=JOBID&offset=16384    next page of that output
  job?id=JOBID&stream=stderr   page stderr separately (shell jobs)
  detach=1                     return immediately with the job id
  wait=25                      stay on the request up to 25s
  len=32768                    bytes per response (default 16384, max 32768)
  format=json                  JSON envelope instead of the text one

Creating a job
  shell   cmd=... [timeout=SECONDS (1-600, default 60)]
  read    path=... [stream=stdout|stderr]
  write   path=... text=... [mode=create|overwrite|append]   (create is the safe default)
  edit    path=... old=... new=...      (old must match exactly once)
  mkdir   path=...                      (single level; parent must exist)
  ls      path=... [count=1-500, default 100]
  mcp.list [cursor=...]                  list tools; changes nothing
  mcp.call tool=... [args={"key":"value"}]"""

HELP_MCP = """

MCP (drive an MCP server the user configured on this machine)
  BRIDGE_MCP_CONFIG names one local JSON file describing ONE server:
    {"command": "uvx", "args": ["mcp-for-blender"], "env": {}}
  or, for a network transport,
    {"transport": "streamable_http", "url": "http://HOST:PORT/mcp"}
  (also "sse"). You cannot set it from here: the user edits fetch_bridge/.env and
  restarts the bridge. Current state:
__SUMMARY__
  Usage: call mcp.list first. It returns every tool name with its input schema
  and changes nothing. Then mcp.call tool=NAME&args={<json object>}; args must be
  a JSON object, percent-encoded like any other value (%22 is a double quote).
  Each call starts a fresh server process, initializes it, makes one call and
  exits: budget seconds per call, and carry no state between calls.
  The server runs on this machine with the user's privileges, exactly like shell.
  A failed call is still a result: status=failed or isError=true arrives with the
  reason in the body (unknown tool, server not running, timeout). Page long
  replies with offset= as usual.
  Worked example, Blender through the stdio server mcp-for-blender:
    mcp.list                  -> get_scene_info, execute_blender_code, look, ...
    mcp.call tool=get_scene_info
    mcp.call tool=execute_blender_code&args={"code":"import bpy\\nprint(len(bpy.data.objects))"}
"""

HELP_TAIL = """
Utilities (no job, no approval)
  ping, help, jobs [count=], cancel id=JOBID

Escaping
  Values are URL query components: percent-encode spaces as %20 (a literal "+"
  also means a space), % as %25, & as %26, = as %3D, # as %23, ? as %3F,
  newlines as %0A, double quotes as %22. Keep the whole URL under 6000
  characters: for larger content use mode=append with successive chunks.

Authorization and safety
  The token in the path is a bearer credential. Every operation asks for local
  approval in the server's terminal unless it was started with --auto; if the
  response says awaiting_approval, fetch the same job id again after approving.
  Send nonce=n=SOME-UNIQUE-VALUE to make retries safe: repeating a nonce returns
  the original job instead of executing twice.
"""


def mcp_summary():
    """One or two lines describing the configured MCP server, for /help.

    Read from the config file rather than remembered at launch, so a stale
    bridge still tells the truth about what the next caller will find.
    """
    path = os.environ.get('BRIDGE_MCP_CONFIG')
    if not path:
        return ('  not configured: BRIDGE_MCP_CONFIG is unset, so mcp.list and mcp.call\n'
                '  will fail. Ask the user for a JSON config file and a restart.')
    try:
        with open(path, encoding='utf-8') as stream:
            config = json.load(stream)
    except (OSError, ValueError) as exc:
        return f'  unusable: {path} ({exc})'
    if not isinstance(config, dict):
        return f'  unusable: {path} is not a JSON object'
    transport = config.get('transport') or 'stdio'
    if transport == 'stdio':
        parts = [str(config.get('command') or '?')] + [str(arg) for arg in config.get('args') or []]
        detail = 'stdio ' + ' '.join(parts)
    else:
        detail = f'{transport} {config.get("url", "?")}'
    return f'  configured: {detail}\n  from: {path}'


def help_text(settings):
    origin = settings.public_url or f'http://{settings.host}:{settings.port}'
    base = f'{origin}/x/<token>'
    return f"""BRIDGE v{VERSION} — local fetch bridge
You are talking to a small HTTP listener on the user's computer. Each GET you
fetch is executed there with the local user's privileges; the result comes back
in this response. Nothing is queued from elsewhere: your fetch IS the request.

Address
  GET {base}/<op>?<params>
  Your token is the <token> path segment above (the user has it).
  Also accepted: /<token>/<op>?... , /?t=<token>&op=<op>, and POST with form data.
  Long-poll with wait=10 or less: a fetch tool usually gives up around 15
  seconds. For anything slower use detach=1, then job?id=... to collect output.

Working directory (paths for read/write/edit/ls/mkdir)
  {settings.cwd}
Paths are relative to it and must resolve inside it. Text only, 1 MB per file.

Safety
  Shell commands and MCP tools are NOT sandboxed. Only request what the user
  asked for, and never send secrets back in a URL or response.
{HELP_FOOTER}{HELP_MCP.replace("__SUMMARY__", mcp_summary())}{HELP_TAIL}"""


class BridgeHandler(BaseHTTPRequestHandler):
    server_version = 'FetchBridge/' + VERSION
    sys_version = ''

    @property
    def bridge(self):
        return self.server.bridge

    def log_message(self, fmt, *args):
        return  # replaced by _log, which never records the token

    def do_GET(self):
        self._handle('GET')

    def do_POST(self):
        self._handle('POST')

    def do_HEAD(self):
        self.send_response(405)
        self.send_header('Allow', 'GET, POST')
        self.send_header('Content-Length', '0')
        self.end_headers()

    # -- plumbing ------------------------------------------------------
    def _send(self, code, text, content_type='text/plain; charset=utf-8'):
        data = text.encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(data)))
        self.send_header('Cache-Control', 'no-store')
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Connection', 'close')
        self.end_headers()
        try:
            self.wfile.write(data)
        except OSError:
            pass

    def _read_form(self):
        try:
            length = int(self.headers.get('Content-Length') or 0)
        except ValueError:
            raise ParamError('invalid Content-Length') from None
        if length < 0 or length > MAX_POST_BYTES:
            raise ParamError(f'POST body must be at most {MAX_POST_BYTES} bytes')
        if not length:
            return {}
        body = self.rfile.read(length)
        try:
            text = body.decode('utf-8')
        except UnicodeDecodeError:
            raise ParamError('POST body must be UTF-8') from None
        return {k: v[-1] for k, v in urllib.parse.parse_qs(text, keep_blank_values=True).items()}

    def _handle(self, method):
        started = time.monotonic()
        try:
            code, text, content_type, note = self._dispatch(method)
        except ParamError as exc:
            code, text, content_type, note = 400, f'error: {exc}', 'text/plain; charset=utf-8', 'bad-request'
        except Exception as exc:  # never leak a traceback to the caller
            code, text, content_type = 500, f'error: {type(exc).__name__}', 'text/plain; charset=utf-8'
            note = 'internal-error'
            self.server.bridge.say(f'Internal error handling request: {type(exc).__name__}: {exc}',)
        self._send(code, text, content_type)
        elapsed = time.monotonic() - started
        self.bridge.say(f'{time.strftime("%H:%M:%S")} {self.client_address[0]:>15} '
                        f'{code} {note} {elapsed:.2f}s')

    # -- request handling ---------------------------------------------
    def _dispatch(self, method):
        bridge = self.bridge
        settings = bridge.settings
        target = self.path
        if len(target) > MAX_URL_CHARS:
            return (414, f'error: URL is {len(target)} characters; the limit is {MAX_URL_CHARS}. '
                         'Split large content into mode=append chunks, or use POST.',
                    'text/plain; charset=utf-8', 'url-too-long')
        parts = urllib.parse.urlsplit(target)
        params = {k: v[-1] for k, v in urllib.parse.parse_qs(parts.query, keep_blank_values=True).items()}
        if method == 'POST':
            params.update(self._read_form())
        segments = [urllib.parse.unquote(s) for s in parts.path.split('/') if s]

        if not segments and not params:
            return (200, f'fetch bridge v{VERSION}: listening. Fetch /x/<token>/help.\n',
                    'text/plain; charset=utf-8', 'banner')
        if segments == ['health'] and 't' not in params:
            return 200, 'ok\n', 'text/plain; charset=utf-8', 'health'
        if segments[:1] in (['robots.txt'], ['favicon.ico']):
            return 404, 'not found\n', 'text/plain; charset=utf-8', 'static'

        token, op = None, None
        if len(segments) > 3 or (segments[:1] == ['x'] and len(segments) > 3):
            return (404, 'error: too many path segments; use /x/<token>/<op>\n',
                    'text/plain; charset=utf-8', 'bad-path')
        if segments[:1] == ['x']:
            token = segments[1] if len(segments) > 1 else None
            op = segments[2] if len(segments) > 2 else None
        elif len(segments) == 1 and segments[0] in OP_PARAMS:
            op = segments[0]              # /help?t=<token> — token comes from elsewhere
        elif len(segments) > 2:
            return (404, 'error: too many path segments; use /x/<token>/<op>\n',
                    'text/plain; charset=utf-8', 'bad-path')
        elif segments and segments[0] not in ('health', 'robots.txt', 'favicon.ico'):
            token = segments[0]
            op = segments[1] if len(segments) > 1 else None
        token = token or params.get('t') or params.get('token')
        op = (op or params.get('op') or 'help').lower()
        op = ALIASES.get(op, op)
        if op not in OP_PARAMS:
            return (404, f'error: unknown operation "{printable(op, 40)}". Ops: '
                         + ', '.join(sorted(OP_PARAMS)), 'text/plain; charset=utf-8', 'unknown-op')

        authorization = self.headers.get('Authorization') or ''
        bearer = authorization[7:].strip() if authorization.lower().startswith('bearer ') else ''
        supplied = token or bearer
        if not supplied or not hmac.compare_digest(str(supplied), settings.token):
            if bridge.note_auth_failure():
                return (429, 'error: too many wrong tokens; wait a minute before trying again.',
                        'text/plain; charset=utf-8', 'auth-throttled')
            return (401, 'error: unauthorized. Fetch /x/<token>/<op> with the token from the server '
                         'terminal, or send Authorization: Bearer <token>.',
                    'text/plain; charset=utf-8', 'unauthorized')
        if not bridge.allow_request():
            return (429, 'error: rate limit reached; wait a minute before fetching again.',
                    'text/plain; charset=utf-8', 'rate-limited')

        if op == 'ping':
            return 200, f'pong {time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}\n', \
                'text/plain; charset=utf-8', 'ping'
        if op == 'help':
            return 200, help_text(settings), 'text/plain; charset=utf-8', 'help'
        if op == 'jobs':
            count = _int(params.get('count'), 'count', 1, 100) or 20
            return 200, bridge.listing(count) + '\n', 'text/plain; charset=utf-8', 'jobs'
        if op == 'cancel':
            job = bridge.get(params.get('id') or '')
            if job is None:
                return 404, 'error: unknown job id (jobs live in memory and do not survive a restart)\n', \
                    'text/plain; charset=utf-8', 'cancel-unknown'
            job.cancelled = True
            if job.process is not None:
                kill_tree(job.process)
            return 200, bridge.render(job) if job.done.is_set() else \
                f'BRIDGE v{VERSION} status={job.status} id={job.id} op={job.op}\n\ncancellation requested\n', \
                'text/plain; charset=utf-8', 'cancel'

        wait = min(_int(params.get('wait'), 'wait', 0, MAX_WAIT) if params.get('wait') else settings.wait, MAX_WAIT)
        if _flag(params.get('detach'), 'detach'):
            wait = 0
        offset = _int(params.get('offset'), 'offset', 0, MAX_CAPTURE_BYTES) or 0
        limit = _int(params.get('len'), 'len', 1, MAX_LIMIT) or DEFAULT_LIMIT
        stream = (params.get('stream') or 'both').lower()
        if stream not in ('both', 'stdout', 'stderr'):
            raise ParamError('stream must be stdout, stderr, or both')
        output_format = (params.get('format') or 'text').lower()
        if output_format not in ('text', 'json'):
            raise ParamError('format must be text or json')
        nonce = params.get('n') or params.get('nonce') or None

        note = None
        if op == 'job':
            job = bridge.get(params.get('id') or '')
            if job is None:
                return (404, 'error: unknown job id (jobs live in memory and do not survive a restart)\n',
                        'text/plain; charset=utf-8', 'unknown-job')
        else:
            action = build_action(op, params)
            existing = bridge.nonce_check(nonce)
            if existing and bridge.get(existing) is not None:
                job = bridge.get(existing)
                note = f'duplicate nonce: replaying job {job.id}; nothing was executed again'
                wait = 0
            else:
                job = bridge.submit(action, self.client_address[0], nonce)
        if wait:
            job.done.wait(wait)
        if not job.done.is_set() and note is None:
            note = (f'this job is still {job.status} on the PC; fetch '
                    f'/x/<token>/job?id={job.id} again (add wait=, offset=, stream=) '
                    'to keep waiting for or page through its output')
        if output_format == 'json':
            return 200, bridge.as_json(job, offset, limit, stream), 'text/plain; charset=utf-8', job.op
        return 200, bridge.render(job, offset, limit, stream, note), 'text/plain; charset=utf-8', job.op


def make_server(bridge, host=None, port=None):
    host = host if host is not None else bridge.settings.host
    port = port if port is not None else bridge.settings.port
    httpd = ThreadingHTTPServer((host, port), BridgeHandler)
    httpd.daemon_threads = True
    httpd.bridge = bridge
    return httpd


def main(argv=None):
    parser = argparse.ArgumentParser(
        description='Run a local HTTP listener that executes operations delivered by fetched URLs.',
        allow_abbrev=False)
    parser.add_argument('--auto', action='store_true',
                        help='DANGEROUS: execute every fetched operation without local approval')
    parser.add_argument('--host', default=None, help='bind address (default 127.0.0.1)')
    parser.add_argument('--port', type=int, default=None, help=f'TCP port (default {DEFAULT_PORT})')
    parser.add_argument('--token', default=None, help='override BRIDGE_TOKEN and print the URL to share')
    parser.add_argument('--cwd', default=None, help='override BRIDGE_CWD')
    args = parser.parse_args(argv)

    try:
        load_env_file(Path(__file__).resolve().parent / '.env')
    except (OSError, UnicodeError, ValueError) as exc:
        sys.exit(f'Cannot load fetch_bridge/.env: {exc}')
    try:
        settings = Settings.from_env(args.cwd)
    except ValueError as exc:
        sys.exit(str(exc))
    if args.token:
        if not valid_token(args.token):
            sys.exit('--token must be 16-200 characters of A-Z a-z 0-9 . _ ~ - and not a reserved word.')
        settings.token, settings.generated_token = args.token, False
    if args.host:
        settings.host = args.host
    if args.port is not None:
        settings.port = args.port
    if not 0 <= settings.port <= 65_535:
        sys.exit('--port must be between 0 and 65535')
    settings.auto = args.auto  # approval is the default; only the flag turns it off

    bridge = Bridge(settings)
    try:
        httpd = make_server(bridge)
    except OSError as exc:
        sys.exit(f'Cannot listen on {settings.host}:{settings.port}: {exc}')
    host, port = httpd.server_address[:2]
    shown = f'{host}:{port}'
    print(f'Fetch bridge v{VERSION} listening on http://{shown}', flush=True)
    print(f'  working directory: {settings.cwd}', flush=True)
    print(f'  token: {settings.token}' + ('  (generated for this run)' if settings.generated_token else ''), flush=True)
    print(f'  local URL: http://{shown}/x/{settings.token}/help', flush=True)
    if settings.auto:
        print('WARNING: --auto is enabled. Any request with this token runs immediately as this user. '
              'Stop with Ctrl+C.', flush=True)
    else:
        print('Every operation waits for you to type YES in this terminal. Ctrl+C stops the bridge.', flush=True)
    if settings.generated_token:
        print('  (no BRIDGE_TOKEN was configured, so this one changes on every start; '
              'run start.cmd to keep it stable)', flush=True)
    if host not in ('127.0.0.1', 'localhost', '::1'):
        print(f'WARNING: bound to {host}, not loopback. Use a tunnel (TLS) rather than exposing this port; '
              'the token travels in URLs and is not encrypted without HTTPS.', flush=True)
    try:
        httpd.serve_forever(poll_interval=0.2)
    except KeyboardInterrupt:
        print('\nStopping. Running jobs and pending approvals are abandoned; nothing is retried.')
    finally:
        httpd.server_close()
    return 0


if __name__ == '__main__':
    sys.exit(main())
