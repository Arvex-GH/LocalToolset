import asyncio
import importlib.util
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'bridge'))
import actions

class ActionTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)

    def test_parse_legacy_and_bad_structured(self):
        self.assertEqual(actions.parse_command('echo hi')['op'], 'shell')
        with self.assertRaises(ValueError):
            actions.parse_command('/bridge invalid')
        with self.assertRaises(ValueError):
            actions.parse_command('/bridge {"op":"other"}')

    def test_file_actions(self):
        actions.file_action({'op':'write','path':'a.txt','content':'one two'}, self.root)
        self.assertEqual(actions.file_action({'op':'read','path':'a.txt'}, self.root)['text'], 'one two')
        actions.file_action({'op':'edit','path':'a.txt','old':'two','new':'three'}, self.root)
        self.assertEqual((self.root/'a.txt').read_text(), 'one three')
        with self.assertRaises(FileExistsError):
            actions.file_action({'op':'write','path':'a.txt','content':'new'}, self.root)
        with self.assertRaises(ValueError):
            actions.file_action({'op':'edit','path':'a.txt','old':'missing','new':'x'}, self.root)
        self.assertEqual((self.root/'a.txt').read_text(), 'one three')

    def test_path_escape_and_symlinks(self):
        with self.assertRaises(ValueError):
            actions.file_action({'op':'read','path':'../secret'}, self.root)
        with tempfile.TemporaryDirectory() as outside:
            try:
                (self.root/'link').symlink_to(outside, target_is_directory=True)
            except (OSError, NotImplementedError):
                self.skipTest('symlink unavailable')
            with self.assertRaises(ValueError):
                actions.file_action({'op':'write','path':'link/file','content':'x'}, self.root)

    def test_mcp_missing_configuration(self):
        with patch.dict(os.environ, {}, clear=True):
            with self.assertRaisesRegex(ValueError, 'BRIDGE_MCP_CONFIG'):
                actions.dispatch({'op':'mcp.list'}, self.root, 5)

    @unittest.skipUnless(importlib.util.find_spec('mcp'), 'optional MCP SDK not installed')
    def test_mcp_stdio_list_and_call(self):
        config = {'command':sys.executable,
                  'args':[str(Path(__file__).with_name('mock_mcp_server.py'))],
                  'env': {'PYTHONPATH': os.environ.get('PYTHONPATH', '')}}
        listing = asyncio.run(actions.mcp_action({'op':'mcp.list'}, config))
        self.assertIn('echo_message', [t['name'] for t in listing['response']['tools']])
        result = asyncio.run(actions.mcp_action({'op':'mcp.call','tool':'echo_message',
                                                 'arguments':{'message':'hello'}}, config))
        self.assertIn('echo: hello', str(result['response']))

if __name__ == '__main__':
    unittest.main()
