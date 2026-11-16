# Local toolset for Arena agents

Two ways for a cloud agent to run work on your own computer. Both keep the
agent out of your network: your machine either serves one authenticated URL
through a tunnel you choose, or polls a queue you own.

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
- `prompt.md` / `fetch_bridge/prompt.md` — paste-in prompts for each transport.

Tests: `python -m unittest discover -s tests -v`.
