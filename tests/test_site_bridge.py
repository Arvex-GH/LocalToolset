"""Static checks for the three site_bridge PHP files.

There is no PHP interpreter on the build machine, and the deploy target (shared
hosting) is exactly where a stray brace shows up as a blank 500 with no message.
So these tests parse the PHP the way a compiler would: strip comments and string
literals with a small lexer, then assert that what remains is balanced and that
the three files agree with each other and with the docs.

The design under test is the simplified one: no database, no shared include,
three files, one JSON state file.
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BRIDGE_DIR = ROOT / 'site_bridge'
PHP_FILES = ['bridge.php', 'index.php', 'setup.php']
# bridge.php is the machine-facing endpoint: it must never emit anything that is
# not its own envelope, so it does not close the PHP tag.
PHP_ONLY = ['bridge.php']
OPS = ['ping', 'help', 'selftest', 'ls', 'read', 'write', 'edit', 'mkdir', 'rm',
       'upload', 'php', 'job', 'jobs', 'cancel']


def php_files(names):
    return [(name, (BRIDGE_DIR / name).read_text(encoding='utf-8')) for name in names]


def source(name):
    return (BRIDGE_DIR / name).read_text(encoding='utf-8')


class PhpLexer:
    """Strip comments and literals from the PHP regions of a file.

    Templates (index.php, setup.php) are mostly HTML, so only the <?php ... ?>
    and <?= ... ?> regions are code. Each region is checked on its own: these
    files use the alternative syntax (if (x): ... endif;) so braces never need
    to span two regions, and a region that does not balance is a real typo.
    """

    TAG_OPEN = '<?php'
    TAG_ECHO = '<?='

    def __init__(self, text):
        self.source = text
        self.errors = []
        self.regions = self._regions()
        self.code = self.strip()

    def _regions(self):
        """(code, starting line) for every PHP region, in file order."""
        src = self.source
        regions = []
        index = 0
        length = len(src)
        while index < length:
            open_php = src.find(self.TAG_OPEN, index)
            open_echo = src.find(self.TAG_ECHO, index)
            candidates = [pos for pos in (open_php, open_echo) if pos != -1]
            if not candidates:
                break
            start = min(candidates)
            skip = 3 if start == open_echo else len(self.TAG_OPEN)
            end = src.find('?>', start + skip)
            if end == -1:
                end = length
            line = src.count('\n', 0, start) + 1
            regions.append((src[start + skip:end], line))
            index = end + 2
        return regions

    def strip(self):
        return '\n'.join(self._strip_region(text, line) for text, line in self.regions)

    def _strip_region(self, src, base_line):
        out = []
        i = 0
        length = len(src)
        line = base_line
        while i < length:
            ch = src[i]
            nxt = src[i + 1] if i + 1 < length else ''
            if ch == '\n':
                line += 1
                out.append(ch)
                i += 1
                continue
            if (ch == '/' and nxt == '/') or ch == '#':
                while i < length and src[i] != '\n':
                    i += 1
                continue
            if ch == '/' and nxt == '*':
                end = src.find('*/', i + 2)
                if end == -1:
                    self.errors.append('unterminated /* comment at line %d' % line)
                    return ''.join(out)
                line += src.count('\n', i, end)
                i = end + 2
                continue
            if ch in ('"', "'"):
                quote = ch
                i += 1
                closed = False
                while i < length:
                    if src[i] == '\\':
                        i += 2
                        continue
                    if src[i] == quote:
                        closed = True
                        i += 1
                        break
                    if src[i] == '\n':
                        line += 1
                    i += 1
                if not closed:
                    self.errors.append('unterminated %s string at line %d' % (quote, line))
                continue
            if src.startswith('<<<', i):
                match = re.match(r"<<<[ \t]*(?:'([A-Za-z_]\w*)'|\"?([A-Za-z_]\w*)\"?)", src[i:])
                if match:
                    marker = match.group(1) or match.group(2)
                    i += match.end()
                    closing = re.compile(r'^\s*' + re.escape(marker) + r'\s*;?\s*$', re.M)
                    found = closing.search(src, i)
                    if not found:
                        self.errors.append('unterminated heredoc %s at line %d' % (marker, line))
                        return ''.join(out)
                    line += src.count('\n', i, found.end())
                    i = found.end()
                    continue
            out.append(ch)
            i += 1
        return ''.join(out)


def balanced(code):
    pairs = {')': '(', ']': '[', '}': '{'}
    stack = []
    for index, ch in enumerate(code):
        if ch in '([{':
            stack.append((ch, index))
        elif ch in ')]}':
            if not stack or stack[-1][0] != pairs[ch]:
                return False, 'unexpected %r at offset %d' % (ch, index)
            stack.pop()
    if stack:
        ch, index = stack[-1]
        return False, 'unclosed %r at offset %d' % (ch, index)
    return True, ''


class LexerTests(unittest.TestCase):
    def test_the_lexer_notices_the_failures_it_is_meant_to(self):
        for text in ('<?php echo "unterminated;', '<?php /* open', '<?php $x = <<<TXT\nabc\n'):
            with self.subTest(source=text):
                self.assertTrue(PhpLexer(text).errors)

    def test_the_lexer_ignores_brackets_inside_strings_and_comments(self):
        text = '<?php $a = "}{"; // }}\n/* { */ $b = 1;'
        lexer = PhpLexer(text)
        self.assertEqual(lexer.errors, [])
        self.assertTrue(balanced(lexer.code)[0])

    def test_html_outside_php_is_not_parsed_as_code(self):
        lexer = PhpLexer('<style>.a{}</style><?php if (true): ?>x<?php endif; ?><script>if(a){b()}</script>')
        self.assertTrue(balanced(lexer.code)[0])
        self.assertEqual(len(lexer.regions), 2)


class LayoutTests(unittest.TestCase):
    def test_exactly_three_php_files(self):
        found = sorted(path.name for path in BRIDGE_DIR.glob('*.php'))
        self.assertEqual(found, sorted(PHP_FILES))

    def test_every_file_exists_and_starts_with_php(self):
        for name in PHP_FILES:
            with self.subTest(file=name):
                self.assertTrue(source(name).startswith('<?php'), name)

    def test_the_endpoint_never_closes_the_php_tag(self):
        for name, text in php_files(PHP_ONLY):
            with self.subTest(file=name):
                self.assertNotIn('?>', text, '%s must not close the PHP tag' % name)

    def test_templates_end_with_html(self):
        for name in ('index.php', 'setup.php'):
            with self.subTest(file=name):
                self.assertTrue(source(name).rstrip().endswith('</html>'), name)

    def test_every_region_balances_on_its_own(self):
        for name, text in php_files(PHP_FILES):
            with self.subTest(file=name):
                lexer = PhpLexer(text)
                self.assertEqual(lexer.errors, [], name)
                self.assertGreaterEqual(len(lexer.regions), 1, name)
                for region, line in lexer.regions:
                    ok, detail = balanced(lexer._strip_region(region, line))
                    self.assertTrue(ok, '%s: region at line %d: %s' % (name, line, detail))

    def test_no_short_open_tags(self):
        for name, text in php_files(PHP_FILES):
            with self.subTest(file=name):
                self.assertNotIn('<? ', text.replace('<?php', ''), name)

    def test_files_do_not_include_each_other(self):
        # Deliberate duplication: a half-finished upload of one file must not
        # take the other two down with it.
        for name, text in php_files(PHP_FILES):
            with self.subTest(file=name):
                code = PhpLexer(text).code
                self.assertIsNone(re.search(r'\b(?:require|include)(?:_once)?\b', code), name)


class NoDatabaseTests(unittest.TestCase):
    def test_nothing_touches_a_database(self):
        for name, text in php_files(PHP_FILES):
            with self.subTest(file=name):
                code = PhpLexer(text).code.lower()
                for word in ('mysqli', 'pdo', 'mysql_', 'sb_tokens', 'sb_settings',
                             'sb_activity', 'bridge_lib', 'bridge.secrets'):
                    self.assertNotIn(word, code, '%s mentions %s' % (name, word))

    def test_nothing_calls_a_process_function(self):
        for name, text in php_files(PHP_FILES):
            with self.subTest(file=name):
                code = PhpLexer(text).code
                for call in ('shell_exec(', 'proc_open(', 'popen(', 'system(', 'passthru('):
                    self.assertNotIn(call, code, '%s calls %s' % (name, call))
                self.assertIsNone(re.search(r'(?<![>\w])exec\s*\(', code), name)

    def test_state_file_is_the_only_storage(self):
        for name in PHP_FILES:
            with self.subTest(file=name):
                self.assertIn('bridge_state.json', source(name), name)


class StateTests(unittest.TestCase):
    def test_bridge_and_panel_agree_on_the_state_keys(self):
        for name, text in php_files(PHP_FILES):
            with self.subTest(file=name):
                for key in ('password_hash', 'tokens', 'activity'):
                    self.assertIn("'%s'" % key, text, '%s does not mention %s' % (name, key))

    def test_state_is_written_under_a_lock_and_locked_down(self):
        for name in PHP_FILES:
            with self.subTest(file=name):
                text = source(name)
                self.assertIn('flock(', text, name)
                self.assertIn('0600', text, name)

    def test_tokens_are_long_and_url_safe(self):
        for name in ('bridge.php', 'index.php'):
            with self.subTest(file=name):
                text = source(name)
                self.assertIn("'sb_' . bin2hex(random_bytes(24))", text, name)

    def test_snippet_output_is_captured_not_leaked(self):
        # Capturing ob_get_level() after ob_start() makes the drain loop a
        # no-op: the snippet's echo then flushes past the envelope at shutdown,
        # which is exactly the bug this test exists to stop coming back.
        bridge = source('bridge.php')
        self.assertEqual(bridge.count('ob_start();'), 2)
        for match in re.finditer(r'\$level = ob_get_level\(\);\s*\n\s*ob_start\(\);', bridge):
            self.assertIsNotNone(match)
        self.assertEqual(len(re.findall(r'\$level = ob_get_level\(\);\s*\n\s*ob_start\(\);',
                                        bridge)), 2)
        self.assertIsNone(re.search(r'ob_start\(\);\s*\n\s*\$level', bridge))
        self.assertIn('ob_get_clean()', bridge)

    def test_warnings_cannot_corrupt_the_envelope(self):
        bridge = source('bridge.php')
        self.assertIn("ini_set('display_errors', '0')", bridge)
        self.assertEqual(bridge.count("ini_set('display_errors', '1')"), 2)

    def test_php_snippets_stay_opt_in(self):
        bridge = source('bridge.php')
        self.assertIn("bridge_setting('allow_php', false)", bridge)
        self.assertIn("'allow_php'", source('index.php'))


class AuthTests(unittest.TestCase):
    def test_the_panel_verifies_the_password_and_keeps_a_hash(self):
        panel = source('index.php')
        self.assertIn('password_verify', panel)
        self.assertIn('password_hash(', panel)
        self.assertIn('hash_equals', panel)

    def test_the_session_cookie_is_hardened(self):
        panel = source('index.php')
        self.assertIn('httponly', panel.lower())
        self.assertIn('samesite', panel.lower())
        self.assertIn('session_regenerate_id(true)', panel)

    def test_posts_are_csrf_checked(self):
        panel = source('index.php')
        self.assertIn('function require_csrf', panel)
        self.assertGreaterEqual(panel.count('name="csrf"'), 6)

    def test_login_is_throttled(self):
        self.assertIn('>= 10', source('index.php'))

    def test_setup_refuses_to_run_twice(self):
        setup = source('setup.php')
        self.assertIn('$installed', setup)
        self.assertIn("if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST')", setup)
        self.assertIn('password_hash(', setup)

    def test_the_endpoint_rejects_bad_tokens_and_logs_it(self):
        bridge = source('bridge.php')
        self.assertIn('401', bridge)
        self.assertIn('429', bridge)
        self.assertIn("bridge_record('auth', 'unauthorized'", bridge)


class ContractTests(unittest.TestCase):
    def setUp(self):
        self.bridge = source('bridge.php')
        self.readme = (ROOT / 'README.md').read_text(encoding='utf-8')

    def ops_in_code(self):
        return set(re.findall(r"case '([a-z]+)':", self.bridge))

    def test_the_ops_are_the_expected_set(self):
        self.assertEqual(self.ops_in_code(), set(OPS))

    def test_every_op_has_a_function(self):
        for op in sorted(self.ops_in_code()):
            with self.subTest(op=op):
                self.assertIn('function bridge_op_' + op + '(', self.bridge)

    def test_help_teaches_every_op(self):
        help_text = self.bridge[self.bridge.index('function bridge_help('):]
        help_text = help_text[:help_text.index('function bridge_op_selftest')]
        for op in sorted(self.ops_in_code()):
            with self.subTest(op=op):
                self.assertIn(op, help_text)

    def test_the_readme_documents_every_op(self):
        for op in sorted(self.ops_in_code()):
            with self.subTest(op=op):
                self.assertIn(op, self.readme)

    def test_the_envelope_shape_is_stable(self):
        self.assertIn("'BRIDGE v' . BRIDGE_VERSION", self.bridge)
        self.assertIn("'status=' . $status", self.bridge)
        self.assertIn("$_GET['format']", self.bridge)
        self.assertIn('format=json', self.bridge)

    def test_every_response_is_logged(self):
        start = self.bridge.index('function bridge_respond')
        end = self.bridge.index('\nfunction ', start + 10)
        self.assertIn('bridge_record(', self.bridge[start:end])

    def test_file_ops_stay_inside_the_root(self):
        self.assertIn('function bridge_resolve', self.bridge)
        self.assertIn('path escapes the root', self.bridge)

    def test_the_readme_states_the_no_database_rule(self):
        lower = self.readme.lower()
        self.assertIn('no database', lower)
        for name in PHP_FILES:
            self.assertIn(name, self.readme)


if __name__ == '__main__':
    unittest.main()
