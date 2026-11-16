import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('fetch_server', ROOT / 'fetch_bridge' / 'server.py')
fetch_server = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fetch_server)

launch_spec = importlib.util.spec_from_file_location(
    'fetch_launch', ROOT / 'fetch_bridge' / 'launch.py')
launch = importlib.util.module_from_spec(launch_spec)
launch_spec.loader.exec_module(launch)

TOKEN = 'harness-token-0123456789'
PY = sys.executable


class Harness(unittest.TestCase):
    """Runs a real listener on loopback and drives it over HTTP."""

    auto = True
    rate_limit = 10_000

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'work'
        self.root.mkdir()
        self.says = []
        self.bridge = fetch_server.Bridge(self.settings(), say=self.says.append)
        self.httpd = fetch_server.make_server(self.bridge, '127.0.0.1', 0)
        self.thread = threading.Thread(
            target=self.httpd.serve_forever, kwargs={'poll_interval': 0.05}, daemon=True)
        self.thread.start()
        self.addCleanup(self.stop)

    def settings(self, **overrides):
        options = dict(cwd=self.root, token=TOKEN, host='127.0.0.1', port=0, timeout=10,
                       wait=5, state_dir=Path(self.temporary.name) / 'state',
                       auto=self.auto, rate_limit=self.rate_limit)
        options.update(overrides)
        return fetch_server.Settings(**options)

    def stop(self):
        self.httpd.shutdown()
        self.httpd.server_close()
        self.thread.join(5)

    # -- HTTP helpers --------------------------------------------------
    @property
    def base(self):
        return f'http://127.0.0.1:{self.httpd.server_address[1]}'

    def request(self, path, params=None, expect=200, method='GET', headers=None, form=None):
        target = self.base + path
        if params:
            target += '?' + urllib.parse.urlencode(params)
        data = urllib.parse.urlencode(form).encode() if form is not None else None
        request = urllib.request.Request(target, data=data, method=method, headers=headers or {})
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                code, payload = response.status, response.read().decode()
        except urllib.error.HTTPError as error:
            code, payload = error.code, error.read().decode()
        self.assertEqual(code, expect, payload)
        return payload

    def op(self, name, params=None, token=TOKEN, **kwargs):
        path = f'/x/{token}/{name}' if token else f'/{name}'
        return self.request(path, params, **kwargs)

    @staticmethod
    def header(payload):
        """Parse the first line of the envelope into a dict of fields."""
        fields = {}
        for part in payload.split('\n', 1)[0].split()[2:]:
            key, _, value = part.partition('=')
            fields[key] = value
        return fields

    @staticmethod
    def body(payload):
        return payload.split('\n\n', 1)[1] if '\n\n' in payload else ''

    def shell(self, command, **params):
        payload = self.op('shell', dict(cmd=command, **params))
        return self.header(payload), self.body(payload)

    def await_log(self, fragment, timeout=5):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if any(fragment in line for line in self.says):
                return ' '.join(self.says)
            time.sleep(0.02)
        self.fail(f'no log line mentioned {fragment!r}; saw {self.says!r}')


class TransportTests(Harness):
    def test_banner_and_health_need_no_token(self):
        self.assertIn('listening', self.request('/', token=None) if False else self.request('/'))
        self.assertEqual(self.request('/health').strip(), 'ok')
        self.request('/robots.txt', expect=404)

    def test_authorization_is_required(self):
        self.op('help', token='wrong-token-0123456789', expect=401)
        self.op('help', token=None, expect=401)
        self.op('shell', {'cmd': 'echo hi'}, token=None, expect=401)
        self.assertIn('BRIDGE v1', self.op('help'))

    def test_token_can_be_supplied_by_query_or_header(self):
        self.assertIn('BRIDGE v1', self.request('/', {'t': TOKEN, 'op': 'help'}))
        self.assertIn('BRIDGE v1', self.request('/help', headers={'Authorization': 'Bearer ' + TOKEN}))
        self.request('/help', headers={'Authorization': 'Bearer wrong-token-000000'}, expect=401)

    def test_help_documents_operations_and_working_directory(self):
        text = self.op('help')
        for fragment in ('shell', 'read', 'write', 'mcp.call', 'offset=', str(self.root)):
            self.assertIn(fragment, text)

    def test_unknown_operation_and_unknown_parameter(self):
        self.op('nope', expect=404)
        payload = self.op('shell', {'cmd': 'echo hi', 'bogus': '1'}, expect=400)
        self.assertIn('unknown parameter', payload)
        self.assertIn('valid for shell: cmd, timeout', payload)

    def test_ping_and_jobs_listing(self):
        self.assertIn('pong', self.op('ping'))
        header, _ = self.shell('echo listed')
        listing = self.op('jobs')
        self.assertIn(header['id'], listing)
        self.assertIn('completed', listing)

    def test_url_length_is_capped_with_guidance(self):
        payload = self.op('shell', {'cmd': 'x' * 7000}, expect=414)
        self.assertIn('mode=append', payload)

    def test_logs_never_include_the_token_or_arguments(self):
        self.shell('echo logged')
        self.op('write', {'path': 'a.txt', 'text': 'hidden-value'})
        joined = self.await_log('write')
        self.assertNotIn(TOKEN, joined)
        self.assertNotIn('hidden-value', joined)
        self.assertIn('shell', joined)


class ShellTests(Harness):
    def test_standard_output_and_exit_code(self):
        header, body = self.shell('echo hello fetch bridge')
        self.assertEqual(header['status'], 'completed')
        self.assertEqual(header['exit'], '0')
        self.assertIn('hello fetch bridge', body)

    def test_failure_keeps_output_and_reports_exit_code(self):
        header, body = self.shell(f'{PY} -c "import sys; print(\'before\'); sys.exit(3)"')
        self.assertEqual(header['status'], 'failed')
        self.assertEqual(header['exit'], '3')
        self.assertIn('before', body)

    def test_stderr_is_separated_and_pageable_on_its_own(self):
        header, body = self.shell(
            f'{PY} -c "import sys; print(\'out\'); print(\'bad\', file=sys.stderr)"')
        self.assertIn('out', body)
        self.assertIn('--- stderr ---', body)
        self.assertIn('bad', body)
        only_err = self.op('job', {'id': header['id'], 'stream': 'stderr'})
        self.assertEqual(self.body(only_err).strip(), 'bad')

    def test_paging_through_output(self):
        header, _ = self.shell(f'{PY} -c "print(\'x\' * 5000)"', len='1000')
        self.assertEqual(header['has_more'], '1')
        self.assertEqual(header['total'], '5001')
        collected, fields, offset = '', header, 0
        while True:
            page = self.op('job', {'id': fields['id'], 'offset': offset, 'len': '1000'})
            fields = self.header(page)
            collected += self.body(page)
            if fields['has_more'] == '0':
                break
            offset = fields['next_offset']
        self.assertEqual(collected, 'x' * 5000 + '\n')

    def test_timeout_is_reported_and_does_not_hang(self):
        started = time.monotonic()
        header, _ = self.shell(f'{PY} -c "import time; time.sleep(30)"', timeout='1')
        self.assertLess(time.monotonic() - started, 15)
        self.assertEqual(header['timed_out'], '1')
        self.assertEqual(header['status'], 'failed')

    def test_detach_then_poll_until_complete(self):
        header, _ = self.shell(f'{PY} -c "import time; time.sleep(1); print(\'slow done\')"',
                               detach='1')
        self.assertIn(header['status'], ('running', 'completed'))
        finished = self.op('job', {'id': header['id'], 'wait': '20'})
        self.assertEqual(self.header(finished)['status'], 'completed')
        self.assertIn('slow done', self.body(finished))

    def test_cancel_stops_a_detached_job(self):
        header, _ = self.shell(f'{PY} -c "import time; time.sleep(30)"', detach='1')
        self.op('cancel', {'id': header['id']})
        deadline, status = time.monotonic() + 15, None
        while time.monotonic() < deadline:
            status = self.header(self.op('job', {'id': header['id']}))['status']
            if status in ('cancelled', 'failed', 'completed'):
                break
            time.sleep(0.05)
        self.assertEqual(status, 'cancelled')

    def test_unknown_job_is_a_404(self):
        self.assertIn('unknown job id', self.op('job', {'id': 'deadbeef0000'}, expect=404))
        self.op('cancel', {'id': 'deadbeef0000'}, expect=404)


class FileTests(Harness):
    def test_write_read_append_edit_ls_mkdir(self):
        self.assertIn('wrote 4 bytes', self.op('write', {'path': 'notes.txt', 'text': 'one\n'}))
        self.assertEqual(self.body(self.op('read', {'path': 'notes.txt'})), 'one\n')
        self.op('write', {'path': 'notes.txt', 'text': 'two\n', 'mode': 'append'})
        self.assertEqual((self.root / 'notes.txt').read_text(), 'one\ntwo\n')
        self.op('edit', {'path': 'notes.txt', 'old': 'two', 'new': '2'})
        self.assertEqual((self.root / 'notes.txt').read_text(), 'one\n2\n')
        self.assertIn('created', self.op('mkdir', {'path': 'sub'}))
        self.assertIn('already exists', self.op('mkdir', {'path': 'sub'}))
        self.op('write', {'path': 'sub/b.py', 'text': 'print(1)\n'})
        listing = self.op('ls', {'path': 'sub'})
        self.assertIn('b.py', listing)
        self.assertIn('print(1)', self.body(self.op('read', {'path': 'sub/b.py'})))
        self.assertIn('dir', self.op('ls'))

    def test_create_is_the_default_and_overwrite_is_explicit(self):
        self.op('write', {'path': 'keep.txt', 'text': 'first'})
        payload = self.op('write', {'path': 'keep.txt', 'text': 'second'})
        self.assertIn('status=failed', payload)
        self.assertIn('mode=overwrite', payload)
        self.assertEqual((self.root / 'keep.txt').read_text(), 'first')
        self.op('write', {'path': 'keep.txt', 'text': 'second', 'mode': 'overwrite'})
        self.assertEqual((self.root / 'keep.txt').read_text(), 'second')

    def test_edit_requires_a_single_exact_match(self):
        self.op('write', {'path': 'e.txt', 'text': 'a a b'})
        self.assertIn('exactly once', self.op('edit', {'path': 'e.txt', 'old': 'a', 'new': 'z'}))
        self.assertEqual((self.root / 'e.txt').read_text(), 'a a b')

    def test_paths_cannot_escape_the_working_directory(self):
        self.assertIn('outside the working directory',
                      self.op('write', {'path': '../escape.txt', 'text': 'no'}))
        self.assertFalse((Path(self.temporary.name) / 'escape.txt').exists())
        self.assertIn('outside the working directory', self.op('read', {'path': '/etc/hostname'}))

    def test_read_pages_over_the_limit(self):
        content = ''.join(f'line {index:04d}\n' for index in range(400))
        self.op('write', {'path': 'big.txt', 'text': content})
        payload = self.op('read', {'path': 'big.txt', 'len': '512'})
        fields = self.header(payload)
        self.assertEqual(fields['has_more'], '1')
        pages = self.body(payload)
        while fields['has_more'] == '1':
            payload = self.op('job', {'id': fields['id'], 'offset': fields['next_offset'],
                                      'len': '512'})
            fields = self.header(payload)
            pages += self.body(payload)
        self.assertEqual(pages, content)

    def test_missing_file_and_directory_errors_are_readable(self):
        self.assertIn('FileNotFoundError', self.op('read', {'path': 'nope.txt'}))
        self.assertIn('not a directory', self.op('ls', {'path': 'nope.txt'}))
        self.assertIn('FileNotFoundError', self.op('write', {'path': 'deep/file.txt', 'text': 'x'}))

    def test_empty_text_is_allowed_for_write_and_edit(self):
        self.op('write', {'path': 'blank.txt', 'text': ''})
        self.assertEqual((self.root / 'blank.txt').read_text(), '')
        self.op('write', {'path': 'v.txt', 'text': 'xx'})
        self.op('edit', {'path': 'v.txt', 'old': 'xx', 'new': ''})
        self.assertEqual((self.root / 'v.txt').read_text(), '')


class DeduplicationTests(Harness):
    def test_repeated_nonce_replays_the_original_job(self):
        counter = self.root / 'runs.txt'
        command = f'{PY} -c "open(r\'{counter}\', \'a\').write(\'run\\n\')"'
        first, _ = self.shell(command, n='nonce-1')
        payload = self.op('shell', {'cmd': command, 'n': 'nonce-1'})
        self.assertEqual(first['id'], self.header(payload)['id'])
        self.assertIn('duplicate nonce', payload)
        self.assertEqual(counter.read_text().count('run'), 1)

    def test_distinct_nonces_execute_separately(self):
        first, _ = self.shell('echo one', n='a')
        second, _ = self.shell('echo one', n='b')
        self.assertNotEqual(first['id'], second['id'])

    def test_required_nonce_is_enforced_when_configured(self):
        bridge = fetch_server.Bridge(self.settings(require_nonce=True), say=lambda *a: None)
        httpd = fetch_server.make_server(bridge, '127.0.0.1', 0)
        thread = threading.Thread(target=httpd.serve_forever,
                                  kwargs={'poll_interval': 0.05}, daemon=True)
        thread.start()
        self.addCleanup(lambda: (httpd.shutdown(), httpd.server_close(), thread.join(5)))
        base = f'http://127.0.0.1:{httpd.server_address[1]}'
        with self.assertRaises(urllib.error.HTTPError) as caught:
            urllib.request.urlopen(f'{base}/x/{TOKEN}/shell?cmd=echo+hi', timeout=30)
        self.assertEqual(caught.exception.code, 400)
        self.assertIn('requires a nonce', caught.exception.read().decode())


class ApprovalTests(Harness):
    auto = False

    def test_rejection_is_reported_without_running(self):
        with patch('builtins.input', return_value='no'):
            header, body = self.shell('echo should-not-run')
        self.assertEqual(header['status'], 'rejected')
        self.assertIn('Rejected locally', body)

    def test_yes_runs_the_operation(self):
        with patch('builtins.input', return_value='YES'):
            header, body = self.shell('echo approved')
        self.assertEqual(header['status'], 'completed')
        self.assertIn('approved', body)

    def test_awaiting_approval_is_reported_and_the_job_is_resumable(self):
        release = threading.Event()
        with patch('builtins.input', lambda prompt='': release.wait(20) and 'YES'):
            payload = self.op('shell', {'cmd': 'echo later', 'wait': '1'})
            self.assertEqual(self.header(payload)['status'], 'awaiting_approval')
            self.assertIn('still awaiting_approval', payload)
            job_id = self.header(payload)['id']
            release.set()
            done = self.op('job', {'id': job_id, 'wait': '20'})
        self.assertEqual(self.header(done)['status'], 'completed')
        self.assertIn('later', self.body(done))

    def test_closed_stdin_rejects_instead_of_hanging(self):
        with patch('builtins.input', side_effect=EOFError):
            header, _ = self.shell('echo nope')
        self.assertEqual(header['status'], 'rejected')


class EnvelopeTests(Harness):
    def test_json_envelope(self):
        data = json.loads(self.op('shell', {'cmd': 'echo json-body', 'format': 'json'}))
        self.assertEqual(data['status'], 'completed')
        self.assertEqual(data['exit_code'], 0)
        self.assertIn('json-body', data['body'])
        self.assertFalse(data['has_more'])

    def test_post_form_is_accepted(self):
        payload = self.op('write', form={'path': 'posted.txt', 'text': 'via post'},
                          method='POST')
        self.assertIn('wrote 8 bytes', payload)
        self.assertEqual((self.root / 'posted.txt').read_text(), 'via post')


class McpWiringTests(Harness):
    """The MCP transport is shared/actions.py; check the fetch-side wiring only."""

    def test_mcp_results_and_tool_errors_are_rendered(self):
        calls = []

        def fake_dispatch(action, cwd, timeout):
            calls.append((action, cwd, timeout))
            if action['op'] == 'mcp.list':
                return {'truncated': False, 'response': {'tools': [{'name': 'echo'}]}}
            if action['tool'] == 'boom':
                return {'truncated': False, 'is_error': True, 'response': {'isError': True}}
            return {'truncated': False, 'response': {'content': [{'text': 'ok'}]}}

        with patch.object(fetch_server.bridge_actions, 'dispatch', fake_dispatch):
            listing = self.op('mcp.list')
            self.assertIn('echo', listing)
            self.assertIn('"tools"', self.body(listing))
            self.assertEqual(self.header(self.op('mcp.call', {'tool': 'boom'}))['status'], 'failed')
            ok = self.op('mcp.call', {'tool': 'echo', 'args': '{"message":"hi"}'})
            self.assertEqual(self.header(ok)['status'], 'completed')
            self.assertIn('ok', self.body(ok))
        self.assertEqual(calls[-1][0]['arguments'], {'message': 'hi'})
        self.assertEqual(calls[-1][1], str(self.root))
        self.assertEqual(calls[0][2], 10)

    def test_missing_mcp_config_is_reported_not_crashed(self):
        with patch.dict(os.environ, {}, clear=True):
            payload = self.op('mcp.list')
        self.assertEqual(self.header(payload)['status'], 'failed')
        self.assertIn('BRIDGE_MCP_CONFIG', self.body(payload))


class RateLimitTests(Harness):
    rate_limit = 3

    def test_repeated_authenticated_requests_are_throttled(self):
        for _ in range(self.rate_limit):
            self.op('ping')
        self.assertIn('rate limit', self.op('ping', expect=429))


class MainTests(unittest.TestCase):
    """main() must wire the CLI flags into the settings the handler reads."""

    def run_main(self, argv):
        recorded = {}
        real = fetch_server.make_server

        def capture(bridge, host=None, port=None):
            recorded['bridge'] = bridge
            return real(bridge, host, port)

        with tempfile.TemporaryDirectory() as directory, \
                patch.dict(os.environ, {}, clear=True), \
                patch.object(fetch_server, 'make_server', capture), \
                patch.object(fetch_server.ThreadingHTTPServer, 'serve_forever',
                             side_effect=KeyboardInterrupt), \
                patch('sys.stdout'):
            fetch_server.main(argv + ['--token', 'x' * 24, '--cwd', directory, '--port', '0'])
        return recorded['bridge'].settings

    def test_auto_flag_is_applied(self):
        self.assertTrue(self.run_main(['--auto']).auto)

    def test_approval_is_the_default(self):
        self.assertFalse(self.run_main([]).auto)


class ValidationTests(unittest.TestCase):
    def build(self, op, params):
        return fetch_server.build_action(op, params)

    def test_required_and_unknown_parameters(self):
        with self.assertRaises(fetch_server.ParamError):
            self.build('shell', {})
        with self.assertRaises(fetch_server.ParamError):
            self.build('read', {'path': 'x', 'nope': '1'})
        with self.assertRaises(fetch_server.ParamError):
            self.build('write', {'path': 'x'})
        with self.assertRaises(fetch_server.ParamError):
            self.build('shell', {'cmd': 'x', 'timeout': '0'})

    def test_write_modes_and_content_alias(self):
        action = self.build('write', {'path': 'a', 'content': 'b'})
        self.assertEqual((action['text'], action['mode']), ('b', 'create'))
        self.assertEqual(self.build('write', {'path': 'a', 'text': ''})['text'], '')
        with self.assertRaises(fetch_server.ParamError):
            self.build('write', {'path': 'a', 'text': 'b', 'mode': 'clobber'})

    def test_mcp_arguments_must_be_an_object(self):
        self.assertEqual(self.build('mcp.call', {'tool': 't'})['arguments'], {})
        self.assertEqual(self.build('mcp.call', {'tool': 't', 'args': '{"a":1}'})['arguments'],
                         {'a': 1})
        with self.assertRaises(fetch_server.ParamError):
            self.build('mcp.call', {'tool': 't', 'args': '[1]'})
        with self.assertRaises(fetch_server.ParamError):
            self.build('mcp.call', {'tool': 't', 'args': '{bad'})

    def test_control_characters_are_escaped_for_the_terminal(self):
        self.assertEqual(fetch_server.printable('a\x1b[2Kb', 100), 'a\\x1b[2Kb')
        self.assertTrue(fetch_server.printable('x' * 50, 10).startswith('x' * 10))

    def test_env_file_semantics(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / '.env'
            path.write_text('# comment\nBRIDGE_TOKEN="quoted-token-value"\nBRIDGE_PORT=1234\n',
                            encoding='utf-8')
            with patch.dict(os.environ, {}, clear=True):
                fetch_server.load_env_file(path)
                self.assertEqual(os.environ['BRIDGE_TOKEN'], 'quoted-token-value')
                self.assertEqual(os.environ['BRIDGE_PORT'], '1234')
            path.write_text('BRIDGE_UNKNOWN=x\n', encoding='utf-8')
            with patch.dict(os.environ, {}, clear=True), self.assertRaises(ValueError):
                fetch_server.load_env_file(path)

    def test_environment_rejects_weak_or_reserved_tokens(self):
        for token in ('short', 'x' * 32 + '/y', 'robots.txt', 'x'):
            with self.subTest(token=token), patch.dict(os.environ, {'BRIDGE_TOKEN': token}, clear=True):
                with self.assertRaises(ValueError):
                    fetch_server.Settings.from_env()

    def test_generated_token_is_long_enough(self):
        with patch.dict(os.environ, {}, clear=True):
            settings = fetch_server.Settings.from_env()
        self.assertTrue(settings.generated_token)
        self.assertGreaterEqual(len(settings.token), 16)


class LauncherTests(unittest.TestCase):
    """The double-click path: token persistence, asset choice, URL scraping."""

    def test_tunnel_assets_per_platform(self):
        self.assertEqual(launch.tunnel_asset('win32', 'AMD64'),
                         ('cloudflared-windows-amd64.exe', False))
        self.assertEqual(launch.tunnel_asset('win32', 'arm64'),
                         ('cloudflared-windows-arm64.exe', False))
        self.assertEqual(launch.tunnel_asset('linux', 'x86_64'),
                         ('cloudflared-linux-amd64', False))
        self.assertEqual(launch.tunnel_asset('linux', 'aarch64'),
                         ('cloudflared-linux-arm64', False))
        self.assertEqual(launch.tunnel_asset('darwin', 'arm64'),
                         ('cloudflared-darwin-arm64.tgz', True))

    def test_tunnel_url_is_scraped_from_cloudflared_output(self):
        banner = ('2026-11-16T17:20:00Z INF Requesting new quick Tunnel on trycloudflare.com...\n'
                  '2026-11-16T17:20:02Z INF +--------------------------------------------+\n'
                  '2026-11-16T17:20:02Z INF |  https://plain-cities-argue.trycloudflare.com  |\n'
                  '2026-11-16T17:20:02Z INF +--------------------------------------------+\n')
        self.assertEqual(launch.parse_tunnel_url(banner),
                         'https://plain-cities-argue.trycloudflare.com')
        self.assertIsNone(launch.parse_tunnel_url('still connecting'))
        self.assertIsNone(launch.parse_tunnel_url('https://evil.example.com/'))

    def test_token_is_created_once_and_then_reused(self):
        with tempfile.TemporaryDirectory() as directory:
            env = Path(directory) / '.env'
            first = launch.ensure_env_token(env)
            self.assertGreaterEqual(len(first), 16)
            self.assertEqual(env.read_text().strip(), 'BRIDGE_TOKEN=' + first)
            self.assertEqual(launch.ensure_env_token(env), first)
            self.assertEqual(env.read_text().count('BRIDGE_TOKEN'), 1)

    def test_other_env_settings_and_comments_survive(self):
        with tempfile.TemporaryDirectory() as directory:
            env = Path(directory) / '.env'
            env.write_text('# my notes\nBRIDGE_CWD=C:\\Projects\nBRIDGE_TOKEN=\n', encoding='utf-8')
            token = launch.ensure_env_token(env)
            text = env.read_text()
            self.assertIn('# my notes', text)
            self.assertIn('BRIDGE_CWD=C:\\Projects', text)
            self.assertEqual(text.count('BRIDGE_TOKEN'), 1)
            self.assertIn('BRIDGE_TOKEN=' + token, text)

    def test_quoted_token_is_kept_and_weak_tokens_are_replaced(self):
        with tempfile.TemporaryDirectory() as directory:
            env = Path(directory) / '.env'
            env.write_text('BRIDGE_TOKEN="a-kept-token-value-0123456789"\n', encoding='utf-8')
            self.assertEqual(launch.ensure_env_token(env), 'a-kept-token-value-0123456789')
            env.write_text('BRIDGE_TOKEN=short\n', encoding='utf-8')
            replacement = launch.ensure_env_token(env)
            self.assertNotEqual(replacement, 'short')
            self.assertGreaterEqual(len(replacement), 16)

    def test_banner_carries_the_single_line_to_share(self):
        text = launch.banner('https://abc.trycloudflare.com', 'token-value-123456', 'C:\\', False)
        self.assertIn('https://abc.trycloudflare.com/x/token-value-123456/help', text)
        self.assertIn('Ctrl+C stops the bridge and the tunnel', text)
        self.assertIn('GIVE THE AGENT THIS LINE', text)
        self.assertNotIn('clipboard', text)

    def test_banner_states_the_mode(self):
        auto = launch.banner('https://abc.test', 't' * 20, 'C:\\', True)
        self.assertIn('AUTO - every request runs immediately', auto)
        self.assertIn('Restart with --approve', auto)
        self.assertIn('copied to your clipboard', auto)
        approve = launch.banner('https://abc.test', 't' * 20, 'C:\\', False, 'approve')
        self.assertIn('APPROVAL - type YES for every request', approve)
        self.assertNotIn('Restart with --approve', approve)

    def run_launcher(self, extra):
        captured = {}
        with tempfile.TemporaryDirectory() as directory, \
                patch.object(launch, 'HERE', Path(directory)), \
                patch.object(launch.server, 'main', lambda argv: captured.update(argv=argv)):
            self.assertEqual(launch.main(['--no-tunnel'] + extra), 0)
        return captured['argv']

    def test_launcher_runs_auto_by_default(self):
        argv = self.run_launcher([])
        self.assertIn('--auto', argv)

    def test_approve_flag_withholds_auto(self):
        for extra in (['--approve'], ['--approve', '--auto']):
            with self.subTest(extra=extra):
                self.assertNotIn('--auto', self.run_launcher(extra))

    def test_launcher_injects_the_port_once(self):
        argv = self.run_launcher(['--port', '9555'])
        self.assertEqual(argv.count('--port'), 1)
        self.assertEqual(argv[argv.index('--port') + 1], '9555')

    def test_launcher_writes_its_token_file_and_keeps_the_mode_out_of_it(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with patch.object(launch, 'HERE', root), \
                    patch.object(launch.server, 'main', lambda argv: None):
                launch.main(['--no-tunnel'])
            token = (root / '.env').read_text().strip()
            self.assertTrue(token.startswith('BRIDGE_TOKEN='))
            self.assertNotIn('auto', token.lower())

    def test_clipboard_never_passes_stdin_and_input_together(self):
        """Regression: Windows raised ValueError here and killed the launch."""
        calls = {}

        def strict_run(command, **kwargs):  # mirrors subprocess.run's own check
            calls.update(command=command, **kwargs)
            if 'input' in kwargs and 'stdin' in kwargs:
                raise ValueError('stdin and input arguments may not both be used.')
            return subprocess.CompletedProcess(command, 0)

        with patch.object(launch.sys, 'platform', 'win32'), \
                patch.object(launch.subprocess, 'run', strict_run):
            self.assertTrue(launch.copy_to_clipboard('https://example.test/x/t/help'))
        self.assertEqual(calls['command'], ['clip'])
        self.assertIn('input', calls)
        self.assertNotIn('stdin', calls)
        self.assertEqual(calls['input'], b'https://example.test/x/t/help')

    def test_a_broken_clipboard_returns_false_instead_of_raising(self):
        def explode(*args, **kwargs):
            raise OSError('clipboard locked')

        with patch.object(launch.sys, 'platform', 'win32'), \
                patch.object(launch.subprocess, 'run', explode):
            self.assertFalse(launch.copy_to_clipboard('text'))
        with patch.object(launch.sys, 'platform', 'freebsd14'):
            self.assertFalse(launch.copy_to_clipboard('text'))

    def test_stopping_an_absent_or_finished_tunnel_is_safe(self):
        launch.stop_tunnel(None)
        finished = subprocess.Popen([sys.executable, '-c', 'pass'])
        finished.wait(10)
        launch.stop_tunnel(finished)


class HelpAndWaitTests(unittest.TestCase):
    def settings(self, **overrides):
        options = dict(cwd='.', token='x' * 20, public_url=None)
        options.update(overrides)
        return fetch_server.Settings(**options)

    def test_help_advertises_the_public_origin_when_known(self):
        text = fetch_server.help_text(self.settings(public_url='https://words.trycloudflare.com/'))
        self.assertIn('GET https://words.trycloudflare.com/x/<token>/<op>', text)
        self.assertNotIn('127.0.0.1', text)
        local = fetch_server.help_text(self.settings())
        self.assertIn('GET http://127.0.0.1:8765/x/<token>/<op>', local)

    def test_help_warns_about_the_fetch_timeout(self):
        text = fetch_server.help_text(self.settings())
        self.assertIn('wait=10 or less', text)

    def test_inline_wait_defaults_to_ten_seconds(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertEqual(fetch_server.Settings.from_env().wait, 10)
        with patch.dict(os.environ, {'BRIDGE_WAIT_SECONDS': '20'}, clear=True):
            self.assertEqual(fetch_server.Settings.from_env().wait, 20)

    def test_public_url_comes_from_the_environment(self):
        with patch.dict(os.environ, {'BRIDGE_PUBLIC_URL': 'https://abc.test/'}, clear=True):
            self.assertEqual(fetch_server.Settings.from_env().public_url, 'https://abc.test')


class DefaultDirectoryTests(unittest.TestCase):
    def test_default_is_the_volume_root(self):
        expected = Path(fetch_server.__file__).resolve().anchor
        self.assertEqual(fetch_server.default_cwd(), Path(expected))

    def test_environment_uses_the_root_unless_told_otherwise(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertEqual(fetch_server.Settings.from_env().cwd, fetch_server.default_cwd())
        with tempfile.TemporaryDirectory() as directory, \
                patch.dict(os.environ, {'BRIDGE_CWD': directory}, clear=True):
            self.assertEqual(fetch_server.Settings.from_env().cwd, Path(directory).resolve())


if __name__ == '__main__':
    unittest.main()
