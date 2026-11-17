# Local toolset for Arena agents

Three ways for a cloud agent to run work on infrastructure you control. The
agent either fetches one authenticated URL, polls a queue you own, or reaches a
website you host — you pick the one that matches where the work has to happen.

| | [`fetch_bridge/`](fetch_bridge/README.md) — default | [`archive/supabase-bridge/`](archive/supabase-bridge/README.md) — archived |
|---|---|---|
| Request path | the agent's `fetch` of a URL | row in a Postgres queue |
| Needs in the cloud | nothing | Supabase project + connector + service-role key |
| Wake-up | listener is already waiting | local worker polls every 3 s |
| Result | returned in the same HTTP response | stored in a column, read separately |
| Keeps history across restarts | no (in-memory jobs) | yes |
| Setup | double-click `fetch_bridge\start.cmd` (auto mode; `--approve` gates each request) | schema, wait SQL, `.env`, worker |

Both expose the same capabilities — shell commands, text file read/write/edit,
directory listing, and an optional stdio/HTTP MCP client (for example an Unreal
Editor MCP server) — and both require explicit local approval unless you
deliberately start them with `--auto`.

Start here:

- `AGENTS.md` — how an agent should use either transport, and what to be
  careful about.
- `fetch_bridge/README.md` — the fetch bridge, including tunnel options
  (Cloudflare, Tailscale Funnel, ngrok) and the URL cookbook.
- `archive/supabase-bridge/README.md` — the earlier Supabase queue bridge,
  archived but still working; its schema and wait SQL live beside it.
- `shared/actions.py` — file, path-confinement and MCP helpers used by both.
- `site_bridge/` — the PHP variant for a website on shared hosting (PHP only, no
  shell, no database, ~10 s per request). Exactly three files: `bridge.php`
  (token-gated endpoint: `ls`, `read`, `write`, `edit`, `mkdir`, `rm`, `upload`,
  opt-in `php`, `job`/`jobs`/`cancel` for work longer than one request, and
  `ping`/`help`/`selftest`), `index.php` (password-protected panel:
  issue/revoke/delete tokens, flip settings, live feed of every request) and
  `setup.php` (first run: choose the admin password, then delete it). Upload the
  three into `htdocs`, open `setup.php` once, sign in, create a token, hand the
  agent `https://<your-site>/bridge.php?t=<token>&op=help`. State is one
  `bridge_state.json` — password hash, tokens, settings, activity — kept above
  the web root when the host allows it.
- `prompt.md` / `fetch_bridge/prompt.md` — paste-in prompts for each transport.

Tests: `python -m unittest discover -s tests -v`.
