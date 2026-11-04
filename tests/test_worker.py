import importlib.util
import os
from pathlib import Path
import tempfile
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'bridge'))
spec = importlib.util.spec_from_file_location('worker', Path(__file__).resolve().parents[1] / 'bridge' / 'worker.py')
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)

class WorkerTests(unittest.TestCase):
    def test_env_loads_literal_values_and_preserves_environment(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {'SUPABASE_URL': 'https://override.example'}, clear=True):
            env = Path(directory) / '.env'
            env.write_text('# comment\nSUPABASE_URL=https://example.test\nSUPABASE_SECRET_KEY="secret=abc"\nBRIDGE_CWD=C:\\Users\\Example Name\n', encoding='utf-8')
            worker.load_env_file(env)
            self.assertEqual(os.environ['SUPABASE_URL'], 'https://override.example')
            self.assertEqual(os.environ['SUPABASE_SECRET_KEY'], 'secret=abc')
            self.assertEqual(os.environ['BRIDGE_CWD'], r'C:\Users\Example Name')

    def test_env_missing_and_invalid_do_not_leak_or_partially_load(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {}, clear=True):
            env = Path(directory) / '.env'
            worker.load_env_file(env)
            env.write_text('SUPABASE_SECRET_KEY=private\nUNKNOWN=x\n', encoding='utf-8')
            with self.assertRaises(ValueError) as error:
                worker.load_env_file(env)
            self.assertNotIn('private', str(error.exception))
            self.assertNotIn('SUPABASE_SECRET_KEY', os.environ)
            env.write_text('SUPABASE_SECRET_KEY="unterminated\n', encoding='utf-8')
            with self.assertRaises(ValueError):
                worker.load_env_file(env)

    def test_main_reads_env_next_to_worker_before_polling(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {}, clear=True):
            folder = Path(directory)
            (folder / '.env').write_text(
                'SUPABASE_URL=https://example.test\nSUPABASE_SECRET_KEY=test-key\nBRIDGE_CWD=' + directory + '\n', encoding='utf-8')
            with patch.object(worker, '__file__', str(folder / 'worker.py')), \
                 patch.object(worker, 'request', side_effect=KeyboardInterrupt) as poll, \
                 patch('sys.stdout'), self.assertRaises(KeyboardInterrupt):
                worker.main(['--auto'])
            self.assertEqual(poll.call_args.args[:2], ('https://example.test', 'test-key'))

    def test_duplicate_env_setting_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            env = Path(directory) / '.env'
            env.write_text('BRIDGE_CWD=a\nBRIDGE_CWD=b\n', encoding='utf-8')
            with self.assertRaises(ValueError):
                worker.load_env_file(env)

    def test_auto_skips_prompt(self):
        with patch('builtins.input', side_effect=AssertionError('must not prompt')):
            self.assertTrue(worker.approved(True))

    def test_default_requires_exact_yes_and_rejects_eof(self):
        for answer, expected in [('YES', True), ('yes', False), ('', False)]:
            with self.subTest(answer=answer), patch('builtins.input', return_value=answer):
                self.assertEqual(worker.approved(False), expected)
        with patch('builtins.input', side_effect=EOFError):
            self.assertFalse(worker.approved(False))

    def test_help_includes_auto_and_bad_flag_fails(self):
        with patch('sys.stdout'), self.assertRaises(SystemExit) as cm:
            worker.main(['--help'])
        self.assertEqual(cm.exception.code, 0)
        with patch('sys.stderr'), self.assertRaises(SystemExit) as cm:
            worker.main(['--aut'])
        self.assertEqual(cm.exception.code, 2)

    def test_execution_and_exit_code(self):
        with tempfile.TemporaryDirectory() as directory:
            status, result = worker.execute('python -c "print(123)"', directory, 10)
        self.assertEqual(status, 'completed')
        self.assertEqual(result['exit_code'], 0)
        self.assertIn('123', result['stdout']['text'])

    def test_timeout(self):
        with tempfile.TemporaryDirectory() as directory:
            status, result = worker.execute('python -c "import time; time.sleep(3)"', directory, 1)
        self.assertEqual(status, 'failed')
        self.assertTrue(result['timed_out'])

    def test_output_truncated(self):
        with tempfile.TemporaryDirectory() as directory:
            _, result = worker.execute('python -c "print(\'x\'*40000)"', directory, 10)
        self.assertTrue(result['stdout']['truncated'])
        self.assertEqual(len(result['stdout']['text']), worker.MAX_OUTPUT)

if __name__ == '__main__':
    unittest.main()
