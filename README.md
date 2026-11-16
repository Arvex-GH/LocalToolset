# Local toolset for Arena agents

Two ways for a cloud agent to run work on your own computer. Both keep the
agent out of your network: your machine either polls out, or serves one
authenticated URL through a tunnel you choose.

| | [`bridge/`](bridge/README.md) — Supabase queue | [`fetch_bridge/`](fetch_bridge/README.md) — fetch bridge |
|---|---|---|
| Request path | row in a Postgres queue | the agent's `fetch` of a URL |
| Needs in the cloud | Supabase project + connector + service-role key | nothing |
| Wake-up | local worker polls every 3 s | listener is already waiting |
| Result | stored in a column, read separately | returned in the same HTTP response |
| Keeps history across restarts | yes | no (in-memory jobs) |
| Setup | schema, wait SQL, `.env`, worker | one Python file, one tunnel |

Both expose the same capabilities — shell commands, text file read/write/edit,
directory listing, and an optional stdio/HTTP MCP client (for example an Unreal
Editor MCP server) — and both require explicit local approval unless you
deliberately start them with `--auto`.

Start here:

- `AGENTS.md` — how an agent should use either transport, and what to be
  careful about.
- `bridge/README.md` — the Supabase queue bridge.
- `fetch_bridge/README.md` — the fetch bridge, including tunnel options
  (Cloudflare, Tailscale Funnel, ngrok) and the URL cookbook.
- `prompt.md` / `fetch_bridge/prompt.md` — paste-in prompts for each transport.

Tests: `python -m unittest discover -s tests -v`.
