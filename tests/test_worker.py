import importlib.util
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
