"""Static checks for site_bridge/*.php.

There is no PHP interpreter on the build machine, and the deploy target (shared
hosting) is exactly where a stray brace shows up as a blank 500 with no message.
So these tests parse the PHP the way a compiler would: strip comments and string
literals with a small lexer, then assert the code that remains is balanced and
that the files agree with each other and with the docs.

They are not a substitute for running the thing — the first deploy still ends
with op=selftest — but they catch the failures that cost an upload each.
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BRIDGE_DIR = ROOT / 'site_bridge'
PHP_FILES = ['bridge_lib.php', 'bridge.php', 'index.php', 'setup.php',
             'bridge.secrets.example.php']
# Files that are pure PHP and must not emit anything outside their own envelope.
PHP_ONLY = ['bridge_lib.php', 'bridge.php', 'bridge.secrets.example.php']


def php_files(names):
    return [(name, (BRIDGE_DIR / name).read_text(encoding='utf-8')) for name in names]


class PhpLexer:
    """Strip comments and literals from the PHP regions of a file.

    Templates (index.php, setup.php) are mostly HTML, so only the <?php ... ?>
    and <?= ... ?> regions are code. Each region is checked on its own: these
    files use the alternative syntax (if (x): ... endif;) so braces never need
    to span two regions, and a region that does not balance is a real typo.
    """

    TAG_OPEN = '<?php'
    TAG_ECHO = '<?='

    def __init__(self, source):
        self.source = source
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
        code_parts = []
        for text, line in self.regions:
            code_parts.append(self._strip_region(text, line))
        return '\n'.join(code_parts)

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
        for source in ('<?php echo "unterminated;', '<?php /* open', '<?php $x = <<<TXT\nabc\n'):
            with self.subTest(source=source):
                self.assertTrue(PhpLexer(source).errors)

    def test_the_lexer_ignores_brackets_inside_strings_and_comments(self):
        source = '<?php $a = "}{"; // }}\n/* { */ $b = 1;'
        lexer = PhpLexer(source)
        self.assertEqual(lexer.errors, [])
        self.assertTrue(balanced(lexer.code)[0])


class PhpFileTests(unittest.TestCase):
    def test_every_file_exists_and_is_php(self):
        for name in PHP_FILES:
            with self.subTest(file=name):
                path = BRIDGE_DIR / name
                self.assertTrue(path.is_file(), '%s is missing' % name)
                self.assertTrue(path.read_text(encoding='utf-8').startswith('<?php'), name)

    def test_php_only_files_never_close_the_tag(self):
        for name, source in php_files(PHP_ONLY):
            with self.subTest(file=name):
                self.assertNotIn('?>', source, '%s must not close the PHP tag' % name)

    def test_templates_end_with_html_not_php(self):
        for name in ('index.php', 'setup.php'):
            with self.subTest(file=name):
                source = (BRIDGE_DIR / name).read_text(encoding='utf-8')
                self.assertTrue(source.rstrip().endswith('</html>'), name)

    def test_every_region_balances_on_its_own(self):
        for name, source in php_files(PHP_FILES):
            with self.subTest(file=name):
                lexer = PhpLexer(source)
                self.assertEqual(lexer.errors, [], name)
                self.assertGreaterEqual(len(lexer.regions), 1, name)
                for text, line in lexer.regions:
                    code = lexer._strip_region(text, line)
                    ok, detail = balanced(code)
                    self.assertTrue(ok, '%s: region at line %d: %s' % (name, line, detail))

    def test_no_short_open_tags(self):
        for name, source in php_files(PHP_FILES):
            with self.subTest(file=name):
                self.assertNotIn('<? ', source.replace('<?php', ''), name)

    def test_nothing_calls_a_process_function(self):
        for name, source in php_files(PHP_FILES):
            with self.subTest(file=name):
                code = PhpLexer(source).code
                for call in ('shell_exec(', 'proc_open(', 'popen(', 'system(', 'passthru('):
                    self.assertNotIn(call, code, '%s calls %s' % (name, call))
                self.assertIsNone(re.search(r'(?<![>\w])exec\s*\(', code), name)

    def test_installed_check_and_password_hash_are_present(self):
        panel = (BRIDGE_DIR / 'index.php').read_text(encoding='utf-8')
        setup = (BRIDGE_DIR / 'setup.php').read_text(encoding='utf-8')
        self.assertIn('password_verify', panel)
        self.assertIn('password_hash', setup)
        self.assertIn('httponly', panel)
        self.assertIn('hash_equals', panel)
        self.assertIn('hash_equals', setup)


class TokenAndSchemaTests(unittest.TestCase):
    def setUp(self):
        self.lib = (BRIDGE_DIR / 'bridge_lib.php').read_text(encoding='utf-8')
        self.bridge = (BRIDGE_DIR / 'bridge.php').read_text(encoding='utf-8')

    def test_tokens_are_long_and_url_safe(self):
        self.assertIn("const SB_TOKEN_PREFIX = 'sb_';", self.lib)
        self.assertIn("bin2hex(random_bytes(24))", self.lib)
        # 3 + 48 characters, every one of them an unreserved URL character.
        pattern = re.search(r"SB_TOKEN_PREFIX \. bin2hex\(random_bytes\(24\)\)", self.lib)
        self.assertIsNotNone(pattern, 'token construction changed')

    def test_only_hashes_are_stored(self):
        self.assertIn("hash('sha256', $token)", self.lib)
        schema = self.lib
        self.assertIn('token_hash CHAR(64) NOT NULL UNIQUE', schema)
        self.assertNotIn('token_value', schema)

    def test_every_table_the_code_touches_is_created_by_the_schema(self):
        created = set(re.findall(r'CREATE TABLE IF NOT EXISTS (\w+)', self.lib))
        self.assertEqual(created, {'sb_settings', 'sb_tokens', 'sb_activity'})
        for name, source in (('lib', self.lib), ('bridge', self.bridge),
                             ('panel', (BRIDGE_DIR / 'index.php').read_text(encoding='utf-8'))):
            used = set(re.findall(r'\b(?:FROM|INTO|UPDATE|TABLE)\s+(sb_\w+)', source))
            self.assertTrue(used <= created, '%s references uncreated tables: %s'
                            % (name, used - created))

    def test_schema_is_idempotent(self):
        self.assertEqual(self.lib.count('CREATE TABLE IF NOT EXISTS'), 3)

    def test_token_lookup_ignores_expired_and_revoked_rows(self):
        self.assertIn("revoked_at", self.lib)
        self.assertIn("expires_at", self.lib)
        self.assertIn('sb_token_lookup', self.bridge)


class BridgeContractTests(unittest.TestCase):
    def setUp(self):
        self.bridge = (BRIDGE_DIR / 'bridge.php').read_text(encoding='utf-8')
        self.readme = (BRIDGE_DIR / 'README.md').read_text(encoding='utf-8')

    def ops_in_code(self):
        return set(re.findall(r"case '([a-z]+)':", self.bridge))

    def test_the_readme_documents_every_op(self):
        ops = self.ops_in_code()
        self.assertTrue(ops)
        for op in sorted(ops):
            with self.subTest(op=op):
                self.assertIn(op, self.readme)

    def test_every_op_has_a_function(self):
        for op in sorted(self.ops_in_code()):
            with self.subTest(op=op):
                self.assertIn('bridge_op_' + op + '(', self.bridge)

    def test_help_card_documents_the_ops_it_serves(self):
        help_text = self.bridge[self.bridge.index('function bridge_help'):]
        help_text = help_text[:help_text.index('function bridge_op_selftest')]
        for op in sorted(self.ops_in_code()):
            with self.subTest(op=op):
                self.assertIn(op, help_text)

    def test_logging_happens_on_every_response(self):
        self.assertIn('sb_log(', self.bridge)
        respond = self.bridge[self.bridge.index('function bridge_respond'):]
        respond = respond[:respond.index('function bridge_params_summary')]
        self.assertIn('sb_log(', respond)

    def test_unauthorized_requests_are_logged_and_throttled(self):
        self.assertIn('sb_recent_auth_failures', self.bridge)
        self.assertIn('429', self.bridge)
        self.assertIn('401', self.bridge)

    def test_php_snippets_are_opt_in(self):
        self.assertIn("if (empty($config['allow_php']))", self.bridge)
        example = (BRIDGE_DIR / 'bridge.secrets.example.php').read_text(encoding='utf-8')
        self.assertIn("'allow_php' => false", example)

    def test_file_ops_stay_inside_the_root(self):
        self.assertIn('function bridge_resolve', self.bridge)
        self.assertIn('path escapes the root', self.bridge)


if __name__ == '__main__':
    unittest.main()
