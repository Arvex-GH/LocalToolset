"""Structured, approval-gated queue actions. No remote-controlled MCP configuration."""
import asyncio
import json
import os
from pathlib import Path

PREFIX = '/bridge '
MAX_FILE_BYTES = 1_000_000
MAX_RESULT_BYTES = 32_768


def parse_command(command):
    if not command.startswith(PREFIX):
        return {'op': 'shell', 'command': command}
    try:
        action = json.loads(command[len(PREFIX):])
    except json.JSONDecodeError as exc:
        raise ValueError('Invalid /bridge JSON: ' + str(exc)) from exc
    if not isinstance(action, dict) or action.get('op') not in ('read', 'write', 'edit', 'mcp.list', 'mcp.call'):
        raise ValueError('Unknown or invalid bridge operation')
    return action


def project_path(root, value):
    if not isinstance(value, str) or not value or '\x00' in value:
        raise ValueError('path must be a non-empty string')
    rel = Path(value)
    if rel.is_absolute():
        raise ValueError('Absolute paths are not allowed')
    root = Path(root).resolve()
    result = (root / rel).resolve()
    if result == root or root not in result.parents:
        raise ValueError('Path is outside BRIDGE_CWD')
    return result


def bounded_text(path):
    with path.open('rb') as f:
        data = f.read(MAX_FILE_BYTES + 1)
    if len(data) > MAX_FILE_BYTES:
        raise ValueError('File exceeds 1 MB limit')
    return data.decode('utf-8')


def file_action(action, root):
    path = project_path(root, action.get('path'))
    op = action['op']
    if op == 'read':
        text = bounded_text(path)
        data = text.encode('utf-8')
        return {'path': action['path'], 'text': data[:MAX_RESULT_BYTES].decode('utf-8', 'ignore'),
                'truncated': len(data) > MAX_RESULT_BYTES, 'size_bytes': len(data)}
    if op == 'write':
        content = action.get('content')
        overwrite = action.get('overwrite', False)
        if not isinstance(content, str) or not isinstance(overwrite, bool):
            raise ValueError('write requires string content and optional boolean overwrite')
        if len(content.encode('utf-8')) > MAX_FILE_BYTES:
            raise ValueError('Content exceeds 1 MB limit')
        # Parent must already exist; avoid surprising directory creation.
        with path.open('w' if overwrite else 'x', encoding='utf-8', newline='') as f:
            f.write(content)
        return {'path': action['path'], 'bytes_written': len(content.encode('utf-8'))}
    if op == 'edit':
        old, new = action.get('old'), action.get('new')
        if not isinstance(old, str) or not old or not isinstance(new, str):
            raise ValueError('edit requires a nonempty old string and a new string')
        text = bounded_text(path)
        if text.count(old) != 1:
            raise ValueError('old must match exactly once; file was not changed')
        content = text.replace(old, new, 1)
        if len(content.encode('utf-8')) > MAX_FILE_BYTES:
            raise ValueError('Result exceeds 1 MB limit')
        with path.open('w', encoding='utf-8', newline='') as f:
            f.write(content)
        return {'path': action['path'], 'replacements': 1}
    raise ValueError('Unknown file operation')


async def _mcp_session(session, action):
    if action['op'] == 'mcp.list':
        cursor = action.get('cursor')
        if cursor is not None and not isinstance(cursor, str):
            raise ValueError('mcp.list cursor must be a string')
        result = await session.list_tools(cursor=cursor)
    else:
        tool, arguments = action.get('tool'), action.get('arguments', {})
        if not isinstance(tool, str) or not tool or not isinstance(arguments, dict):
            raise ValueError('mcp.call requires a tool name and arguments object')
        result = await session.call_tool(tool, arguments=arguments)
    data = result.model_dump(mode='json', by_alias=True, exclude_none=True)
    encoded = json.dumps(data, ensure_ascii=True).encode('utf-8')
    if len(encoded) > MAX_RESULT_BYTES:
        return {'truncated': True, 'is_error': bool(data.get('isError')), 'preview': encoded[:MAX_RESULT_BYTES].decode('utf-8', 'ignore')}
    return {'truncated': False, 'response': data}


async def stdio_mcp_action(action, config):
    try:
        from mcp import ClientSession, StdioServerParameters
        from mcp.client.stdio import stdio_client
    except ImportError as exc:
        raise ValueError('MCP support requires: python -m pip install "mcp>=1,<2"') from exc
    if not isinstance(config, dict) or not isinstance(config.get('command'), str) or not config['command']:
        raise ValueError('Set BRIDGE_MCP_CONFIG to a local JSON configuration file')
    args = config.get('args', [])
    env = config.get('env', {})
    if not isinstance(args, list) or not all(isinstance(x, str) for x in args):
        raise ValueError('MCP config args must be a list of strings')
    if not isinstance(env, dict) or not all(isinstance(k, str) and isinstance(v, str) for k, v in env.items()):
        raise ValueError('MCP config env must be a string-to-string object')
    # No Supabase key is passed to the MCP server. SDK supplies its safe default env.
    if 'SUPABASE_SECRET_KEY' in env:
        raise ValueError('Do not pass the Supabase secret key to the MCP server')
    params = StdioServerParameters(command=config['command'], args=args, env=env)
    async with stdio_client(params) as (read, write):
        async with ClientSession(read, write) as session:
            await session.initialize()
            return await _mcp_session(session, action)


async def http_mcp_action(action, config):
    """MCP over Streamable HTTP or SSE transport (e.g. Unreal on port 8000)."""
    try:
        from mcp import ClientSession
    except ImportError as exc:
        raise ValueError('MCP support requires: python -m pip install "mcp>=1,<2"') from exc

    transport = config.get('transport', 'streamable_http')
    url = config.get('url')
    if not isinstance(url, str) or not url:
        raise ValueError('HTTP MCP config requires a url (e.g. http://127.0.0.1:8000/mcp)')
    headers = config.get('headers', {})
    if not isinstance(headers, dict) or not all(isinstance(k, str) and isinstance(v, str) for k, v in headers.items()):
        raise ValueError('HTTP MCP config headers must be a string-to-string object')

    if transport == 'streamable_http':
        try:
            from mcp.client.streamable_http import streamable_http_client
        except ImportError as exc:
            raise ValueError('Streamable HTTP transport requires mcp>=1,<2') from exc
        async with streamable_http_client(url, headers=headers) as (read, write):
            async with ClientSession(read, write) as session:
                await session.initialize()
                return await _mcp_session(session, action)
    elif transport == 'sse':
        try:
            from mcp.client.sse import sse_client
        except ImportError as exc:
            raise ValueError('SSE transport requires mcp>=1,<2') from exc
        async with sse_client(url, headers=headers) as (read, write):
            async with ClientSession(read, write) as session:
                await session.initialize()
                return await _mcp_session(session, action)
    else:
        raise ValueError('Unknown MCP transport: ' + str(transport) + ' (use "stdio", "streamable_http", or "sse")')


async def mcp_action(action, config):
    """Dispatch to the configured MCP transport (stdio | streamable_http | sse)."""
    transport = config.get('transport', 'stdio')
    if transport == 'stdio':
        return await stdio_mcp_action(action, config)
    if transport in ('streamable_http', 'sse'):
        return await http_mcp_action(action, config)
    raise ValueError('Unknown MCP transport: ' + str(transport) + ' (use "stdio", "streamable_http", or "sse")')


def dispatch(action, root, timeout):
    if action['op'] in ('read', 'write', 'edit'):
        return file_action(action, root)
    config_path = os.environ.get('BRIDGE_MCP_CONFIG') or 'D:\\mcp-config.json'
    if not config_path:
        raise ValueError('Set BRIDGE_MCP_CONFIG to a local MCP JSON file')
    with open(config_path, encoding='utf-8') as f:
        config = json.load(f)
    return asyncio.run(asyncio.wait_for(mcp_action(action, config), timeout=timeout))
