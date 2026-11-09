import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('setup_linux', ROOT / 'bridge' / 'setup_linux.py')
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)


class LinuxSetupTests(unittest.TestCase):
    def test_config_is_private_and_loadable(self):
        import sys
        sys.path.insert(0, str(ROOT / 'bridge'))
        import worker
        with tempfile.TemporaryDirectory() as temp, patch.dict(os.environ, {}, clear=True):
            root = Path(temp)
            workdir = root / 'a folder'
            workdir.mkdir()
            config = root / '.env'
            setup.create_config(config, 'https://example.supabase.co/', 'secret=some"value', workdir)
            self.assertEqual(stat.S_IMODE(config.stat().st_mode) & 0o077, 0)
            worker.load_env_file(config)
            self.assertEqual(os.environ['SUPABASE_URL'], 'https://example.supabase.co')
            self.assertEqual(os.environ['SUPABASE_SECRET_KEY'], 'secret=some"value')
            self.assertEqual(os.environ['BRIDGE_CWD'], str(workdir))

    def test_existing_config_is_not_overwritten(self):
        with tempfile.TemporaryDirectory() as temp:
            config = Path(temp) / '.env'
            config.write_text('original')
            with self.assertRaises(FileExistsError):
                setup.create_config(config, 'https://example.com', 'key', temp)
            self.assertEqual(config.read_text(), 'original')

    def test_invalid_values_do_not_create_file(self):
        with tempfile.TemporaryDirectory() as temp:
            config = Path(temp) / '.env'
            for url, secret, directory in [('http://example.com', 'key', temp),
                                            ('https://example.com/path', 'key', temp),
                                            ('https://example.com', 'bad\nkey', temp),
                                            ('https://example.com', 'key', Path(temp) / 'missing')]:
                with self.subTest(url=url, directory=directory), self.assertRaises(ValueError):
                    setup.create_config(config, url, secret, directory)
                self.assertFalse(config.exists())

    def test_launcher_help_works_outside_repo(self):
        import subprocess
        result = subprocess.run([str(ROOT / 'bridge' / 'run-linux.sh'), '--help'],
                                cwd='/', text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('--auto', result.stdout)

if __name__ == '__main__':
    unittest.main()
