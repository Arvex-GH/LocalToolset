#!/usr/bin/env python3
"""One-step launcher: tunnel, token, bridge, and a single URL to hand over.

Double-click ``start.cmd`` (Windows) or run ``./start.sh`` (macOS/Linux), or
call this file directly. It:

1. keeps a stable token in ``fetch_bridge/.env`` (creating it if needed),
2. downloads ``cloudflared`` into ``fetch_bridge/bin/`` if it is not there yet,
3. opens a Cloudflare quick tunnel to the local bridge,
4. prints the public URL, the token and one paste-ready agent line,
5. runs the bridge in this console, and stops the tunnel when you hit Ctrl+C.

Pass ``--new-token`` to only rotate the token in ``.env`` and print it.

It runs the bridge with ``--auto`` by default: fetched operations execute without
asking. Pass ``--approve`` to be prompted for ``YES`` on every request instead.

Standard library only. Arguments other than the launcher's own are passed
straight to ``server.py`` (for example ``start.cmd --auto`` or ``--port 9000``).
"""
import argparse
import atexit
import os
from pathlib import Path
import platform
import re
import secrets
import shutil
import signal
import socket
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.error
import urllib.request

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import server  # noqa: E402  (local module, loaded after the path is set)

TUNNEL_URL = re.compile(r'https://[a-z0-9][a-z0-9-]*\.trycloudflare\.com')
DOWNLOAD_ROOT = 'https://github.com/cloudflare/cloudflared/releases/latest/download/'
TRUST_PAGE = 'https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/'


class LaunchError(RuntimeError):
    """Something the user can act on."""


def tunnel_asset(system=None, machine=None):
    """Release asset for this platform, and whether it ships as a .tgz."""
    system = (system or sys.platform).lower()
    machine = (machine or platform.machine()).lower()
    arch = 'arm64' if machine in ('arm64', 'aarch64') else 'amd64'
    if system.startswith('win'):
        return f'cloudflared-windows-{arch}.exe', False
    if system == 'darwin':
        return f'cloudflared-darwin-{arch}.tgz', True
    return f'cloudflared-linux-{arch}', False


def ensure_env_token(env_path):
    """Read BRIDGE_TOKEN from .env, generating and storing one if absent or unusable.

    Rewrites only that one line, so comments and other settings survive, and
    repeated launches keep the same token (and therefore the same URL).
    """
    lines, token = _read_env(env_path)
    if not server.valid_token(token):
        token = secrets.token_urlsafe(32)
    _write_env(env_path, lines, token)
    return token


def _read_env(env_path):
    """Lines of .env without the token line, plus the token it held."""
    env_path = Path(env_path)
    lines, token = [], ''
    if env_path.exists():
        for raw in env_path.read_text(encoding='utf-8-sig').splitlines():
            name, separator, value = raw.partition('=')
            if separator and name.strip() == 'BRIDGE_TOKEN':
                token = value.strip().strip('"').strip("'")
                continue
            lines.append(raw)
    return lines, token


def _write_env(env_path, lines, token):
    """Put the token back, leaving every other line untouched."""
    env_path = Path(env_path)
    content = '\n'.join(list(lines) + ['BRIDGE_TOKEN=' + token]) + '\n'
    if not env_path.exists() or env_path.read_text(encoding='utf-8-sig') != content:
        env_path.write_text(content, encoding='utf-8')


def rotate_env_token(env_path):
    """Replace the stored token with a fresh random one; (new, previous)."""
    lines, previous = _read_env(env_path)
    token = secrets.token_urlsafe(32)
    _write_env(env_path, lines, token)
    return token, previous


def port_listening(port, host='127.0.0.1', timeout=0.3):
    """True when something already accepts connections on that local port."""
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return True
    except OSError:
        return False


def rotate_token(env_path, say=print):
    """Write a new token and show it, without starting a bridge or a tunnel."""
    try:
        token, previous = rotate_env_token(env_path)
    except OSError as exc:
        say(f'Cannot write {env_path}: {exc}')
        return 1
    copied = copy_to_clipboard(token)
    try:
        server.load_env_file(env_path)
    except ValueError:
        pass
    port = int(os.environ.get('BRIDGE_PORT') or server.DEFAULT_PORT)
    lines = [f'New token written to {env_path}', '',
             f'  token : {token}', '',
             'Paste this token to the agent; the tunnel URL does not change.']
    if copied:
        lines.insert(4, 'It is already on your clipboard.')
    if port_listening(port):
        lines += ['', f'A bridge is still listening on port {port}. Restart it (Ctrl+C,',
                  'then start.cmd) so it accepts the new token; until then the old',
                  'token still works.']
        if previous:
            lines += ['', 'The previous token stops working after that restart.']
    else:
        lines += ['', f'Nothing is listening on port {port}: start.cmd will use this token.']
        if previous:
            lines += ['', 'The previous token no longer works.']
    say('\n'.join(lines))
    return 0


def download(url, destination, say=print):
    say(f'downloading {url.rsplit("/", 1)[-1]} …')
    request = urllib.request.Request(url, headers={'User-Agent': 'fetch-bridge-launcher'})
    with urllib.request.urlopen(request, timeout=120) as response, \
            open(destination, 'wb') as sink:
        total, done, shown = response.length or 0, 0, 0
        step = max(total // 5, 1 << 20) if total else 10 << 20
        while True:
            chunk = response.read(1 << 16)
            if not chunk:
                break
            sink.write(chunk)
            done += len(chunk)
            if done - shown >= step:
                shown = done
                say(f'  … {done >> 20} MiB' + (f' of {total >> 20} MiB' if total else ''))
        if total:
            say(f'  … {total >> 20} MiB done')
    return destination


def ensure_tunnel(binary_dir, say=print):
    """Path to a usable cloudflared, downloading it on first use."""
    found = (os.environ.get('BRIDGE_TUNNEL')
             or shutil.which('cloudflared') or shutil.which('cloudflared.exe'))
    if found:
        return Path(found)
    binary_dir.mkdir(parents=True, exist_ok=True)
    name = 'cloudflared.exe' if sys.platform.startswith('win') else 'cloudflared'
    target = binary_dir / name
    if not target.exists():
        asset, packed = tunnel_asset()
        try:
            if packed:
                archive = binary_dir / asset
                download(DOWNLOAD_ROOT + asset, archive, say)
                with tarfile.open(archive) as bundle:
                    member = next((m for m in bundle.getmembers()
                                   if m.isfile() and m.name.rstrip('/').endswith('cloudflared')), None)
                    if member is None:
                        raise LaunchError('unexpected archive layout in ' + asset)
                    member.name = name
                    bundle.extract(member, binary_dir)
                archive.unlink()
            else:
                download(DOWNLOAD_ROOT + asset, target, say)
        except (OSError, urllib.error.URLError) as exc:
            raise LaunchError(
                f'could not download cloudflared ({exc}).\n'
                f'Install it yourself and either put it on PATH or set BRIDGE_TUNNEL '
                f'to its full path. Downloads: {TRUST_PAGE}') from None
        if not sys.platform.startswith('win'):
            target.chmod(0o755)
    try:
        probe = subprocess.run([str(target), '--version'], stdin=subprocess.DEVNULL,
                               stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=30)
    except (OSError, subprocess.SubprocessError) as exc:
        raise LaunchError(f'cloudflared at {target} cannot be executed: {exc}') from None
    if probe.returncode != 0:
        raise LaunchError(f'cloudflared at {target} failed its self-check:\n'
                          + probe.stdout.decode('utf-8', 'replace')[:500])
    return target


def parse_tunnel_url(text):
    """First public URL mentioned in cloudflared's output, if any."""
    match = TUNNEL_URL.search(text)
    return match.group(0) if match else None


def start_tunnel(binary, port, log_path, timeout, say=print):
    """Open a quick tunnel and return (process, public_url, log_handle)."""
    log = open(log_path, 'wb')
    process = subprocess.Popen(
        [str(binary), 'tunnel', '--url', f'http://127.0.0.1:{port}'],
        stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=log)
    atexit.register(stop_tunnel, process)
    deadline = time.monotonic() + timeout
    say('opening the Cloudflare tunnel …')
    while time.monotonic() < deadline:
        if process.poll() is not None:
            log.flush()
            tail = Path(log_path).read_bytes().decode('utf-8', 'replace').strip().splitlines()
            log.close()
            raise LaunchError('cloudflared exited before publishing a URL:\n  '
                              + '\n  '.join(tail[-6:] or ['(no output)']))
        try:
            text = Path(log_path).read_bytes().decode('utf-8', 'replace')
        except OSError:
            text = ''
        url = parse_tunnel_url(text)
        if url:
            return process, url, log
        time.sleep(0.25)
    log.flush()
    stop_tunnel(process)
    log.close()
    raise LaunchError(f'no tunnel URL after {timeout}s; is outbound HTTPS allowed? '
                      f'See {log_path} or pass --tunnel-timeout 60.')


def stop_tunnel(process):
    """Best-effort shutdown; safe to call more than once."""
    if process is None or process.poll() is not None:
        return
    try:
        process.terminate()
        process.wait(timeout=5)
    except (OSError, subprocess.SubprocessError):
        try:
            process.kill()
        except OSError:
            pass


def copy_to_clipboard(text):
    command = None
    if sys.platform.startswith('win'):
        command = ['clip']
    elif sys.platform == 'darwin':
        command = ['pbcopy']
    if not command:
        return False
    try:
        # input= supplies stdin; passing stdin= as well raises ValueError, and a
        # clipboard that is unavailable or locked must never stop the launch.
        subprocess.run(command, input=text.encode(), stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL, timeout=5)
        return True
    except Exception:
        return False


def banner(public_url, token, cwd, copied, mode='auto'):
    agent_line = f'{public_url}/x/{token}/help'
    line = '=' * 68
    if mode == 'approve':
        mode_line = 'APPROVAL - type YES for every request'
        advice = '  Each fetched operation waits for your YES in this window.'
    else:
        mode_line = 'AUTO - every request runs immediately, with no confirmation'
        advice = ('  Anyone with this URL and token can run anything as you.\n'
                  '  Restart with --approve to confirm each request instead.')
    rows = [
        '', line,
        '  Fetch bridge is up. Ctrl+C stops the bridge and the tunnel.',
        line,
        f'  public URL : {public_url}',
        f'  token      : {token}',
        f'  working dir: {cwd}',
        f'  mode       : {mode_line}',
        '',
        advice,
        '',
        '  GIVE THE AGENT THIS LINE:',
        f'  {agent_line}',
        '',
        '  The tunnel URL changes on every start; this token does not.',
    ]
    if copied:
        rows.append('  (the line above was copied to your clipboard)')
    rows += [line, '']
    return '\n'.join(rows)


def install_exit_signals():
    """Turn SIGTERM/SIGHUP into KeyboardInterrupt so the tunnel is stopped."""
    def handler(signum, frame):
        raise KeyboardInterrupt
    for name in ('SIGTERM', 'SIGHUP', 'SIGBREAK'):
        number = getattr(signal, name, None)
        if number is None:
            continue
        try:
            signal.signal(number, handler)
        except (OSError, ValueError):
            pass


def main(argv=None):
    install_exit_signals()
    parser = argparse.ArgumentParser(
        description='Start the fetch bridge and a Cloudflare tunnel in one step.',
        allow_abbrev=False)
    parser.add_argument('--approve', action='store_true',
                        help='ask for YES on every request instead of running automatically')
    parser.add_argument('--no-tunnel', action='store_true',
                        help='run the bridge only (localhost); do not start cloudflared')
    parser.add_argument('--tunnel-binary', default=None,
                        help='use this cloudflared instead of downloading/looking it up')
    parser.add_argument('--tunnel-timeout', type=float, default=30,
                        help='seconds to wait for the tunnel URL (default 30)')
    parser.add_argument('--port', type=int, default=None,
                        help=f'local port for the bridge (default {server.DEFAULT_PORT})')
    parser.add_argument('--new-token', action='store_true',
                        help='write a fresh random token to fetch_bridge/.env and exit')
    parser.add_argument('--cwd', default=None,
                        help='working directory for file operations (default: drive root)')
    args, forwarded = parser.parse_known_args(argv)

    if args.new_token:
        return rotate_token(HERE / '.env')
    if args.tunnel_binary:
        os.environ['BRIDGE_TUNNEL'] = args.tunnel_binary
    port = args.port or int(os.environ.get('BRIDGE_PORT') or server.DEFAULT_PORT)

    try:
        token = ensure_env_token(HERE / '.env')
    except OSError as exc:
        sys.exit(f'Cannot write {HERE / ".env"}: {exc}')
    os.environ['BRIDGE_TOKEN'] = token
    if args.cwd:
        forwarded += ['--cwd', args.cwd]
    # The launcher runs unattended by default; --approve restores the server's
    # own per-request YES prompt and wins over an explicit --auto.
    server_args = [flag for flag in forwarded if flag != '--auto']
    if not args.approve:
        server_args.append('--auto')
    cwd = args.cwd or os.environ.get('BRIDGE_CWD') or server.default_cwd()

    process = log = None
    public_url = None
    try:
        if args.no_tunnel:
            print(f'Running without a tunnel; local URL only (port {port}).', flush=True)
            public_url, copied = None, False
        else:
            binary = ensure_tunnel(HERE / 'bin')
            log_path = Path(tempfile.gettempdir()) / 'arena-fetch-bridge' / 'tunnel.log'
            log_path.parent.mkdir(parents=True, exist_ok=True)
            process, public_url, log = start_tunnel(binary, port, log_path, args.tunnel_timeout)
            os.environ['BRIDGE_PUBLIC_URL'] = public_url
            copied = copy_to_clipboard(f'{public_url}/x/{token}/help')
            print(banner(public_url, token, cwd, copied,
                         'approve' if args.approve else 'auto'), flush=True)
        server.main(server_args + ['--port', str(port)])
    except LaunchError as exc:
        sys.exit(f'\n{exc}')
    except KeyboardInterrupt:
        pass
    finally:
        if log is not None:
            stop_tunnel(process)
            try:
                log.close()
            except OSError:
                pass
            if public_url:
                print('Tunnel closed.', flush=True)
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        print('\nStopped.')
