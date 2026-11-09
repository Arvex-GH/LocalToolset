#!/usr/bin/env python3
"""Configure a local bridge/.env interactively without installing packages."""
import getpass
import os
from pathlib import Path
import sys
import warnings
from urllib.parse import urlsplit

DEFAULT_URL = 'https://dtcdcdsnjvihzpzhijss.supabase.co'
ENV_FILE = Path(__file__).resolve().parent / '.env'


def create_config(path, url, secret, directory):
    """Create a private config exactly once. Never overwrite existing settings."""
    parts = urlsplit(url)
    if (parts.scheme != 'https' or not parts.hostname or parts.username is not None
            or parts.password is not None or parts.query or parts.fragment
            or parts.path not in ('', '/') or '\n' in url or '\r' in url):
        raise ValueError('Supabase URL must be an HTTPS project origin (without a path or credentials).')
    if not secret or any(c in secret for c in '\r\n\x00') or secret != secret.strip():
        raise ValueError('A nonempty single-line secret key without surrounding whitespace is required.')
    directory = Path(directory).expanduser().resolve()
    if not directory.is_dir() or any(c in str(directory) for c in '\r\n\x00'):
        raise ValueError('Working directory must be an existing single-line path.')
    # The loader strips matching outer quotes but leaves inner quotes and backslashes literal.
    content = (f'SUPABASE_URL="{url.rstrip("/")}"\n'
               f'SUPABASE_SECRET_KEY="{secret}"\n'
               f'BRIDGE_CWD="{directory}"\n')
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as stream:
            stream.write(content)
    except BaseException:
        # A failed write must not leave a partial config for the next run.
        try:
            os.unlink(path)
        except OSError:
            pass
        raise


def main():
    if ENV_FILE.exists():
        print(f'{ENV_FILE} already exists; it was not changed. Edit it locally if needed.')
        return 0
    if not sys.stdin.isatty():
        print('Run setup in an interactive terminal; the secret must not be provided through a pipe.', file=sys.stderr)
        return 1
    print('Create a private local bridge/.env. Never share your Supabase secret key.')
    try:
        url = input(f'Supabase project URL [{DEFAULT_URL}]: ').strip() or DEFAULT_URL
        directory = input('Absolute path to an existing working directory: ').strip()
        if not directory or not Path(directory).expanduser().is_absolute():
            raise ValueError('Enter an absolute path to an existing working directory.')
        with warnings.catch_warnings():
            warnings.simplefilter('error', getpass.GetPassWarning)
            secret = getpass.getpass('Supabase secret key (input hidden): ')
        create_config(ENV_FILE, url, secret, directory)
    except (ValueError, OSError, EOFError, getpass.GetPassWarning) as exc:
        print(f'Setup failed: {exc}', file=sys.stderr)
        return 1
    print(f'Created {ENV_FILE} with owner-only permissions. Start with ./bridge/run-linux.sh')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit('\nSetup cancelled.')
