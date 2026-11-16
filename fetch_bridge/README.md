# Fetch bridge — the URL you fetch *is* the command

A local HTTP listener on your computer that executes what an agent requests by
fetching a URL. No Supabase project, no database, no connector, no polling
worker: one authenticated endpoint, one request, one answer.

```
 agent (cloud)                              your computer
 ─────────────                              ─────────────
 fetch https://xyz.trycloudflare.com/x/TOKEN/shell?cmd=dir
        │                                          ▲
        └──► tunnel (TLS, dials out) ──────────────┘
                                                   fetch_bridge/server.py
                                                   └─ approve? → run → reply
        ◄── BRIDGE v1 status=completed exit=0 + output
```

The tunnel connection is opened *by your PC*, so there is no public IP, no port
forwarding and no inbound hole on either side. The agent only needs a tool that
can fetch a URL — no custom headers, no POST, no signing.

## Why this exists next to `bridge/worker.py`

| | Supabase queue (`bridge/`) | Fetch bridge (`fetch_bridge/`) |
|---|---|---|
| Transport | row in `arena_bridge_commands` | query string of a fetched URL |
| Wake-up | worker polls every 3 s | server already listening |
| Authentication | service-role key (bypasses RLS) | bearer token in the URL path |
| Result | `result` JSON column, read separately | returned in the same HTTP response |
| Waiting | `arena_bridge_wait(id, 15)` | `job?id=...&wait=20` on the same listener |
| Needs in the cloud | Supabase project + connector | nothing |
| Survives restarts | rows persist | jobs are in memory |

Use the queue when you want a durable audit trail or jobs that can run while no
agent is in a turn. Use this when you want zero cloud setup and one round trip.

## 1. Start the server

Python 3.9+, no third-party packages (MCP support is the optional exception).
From the repository root:

```powershell
# Windows PowerShell
$env:BRIDGE_TOKEN = -join ((48..57) + (97..102) | Get-Random -Count 32 | ForEach-Object {[char]$_})
$env:BRIDGE_CWD = "C:\Users\You\MyProject"
py fetch_bridge/server.py
```

```bash
# macOS / Linux
export BRIDGE_TOKEN=$(python3 -c "import secrets; print(secrets.token_hex(24))")
export BRIDGE_CWD="$HOME/MyProject"
python3 fetch_bridge/server.py
```

Or keep the settings in a private local file: copy `fetch_bridge/.env.example`
to `fetch_bridge/.env` and edit it (it is git-ignored). The server reads that
file automatically, and real environment variables win over it.

The server prints:

```
Fetch bridge v1 listening on http://127.0.0.1:8765
  working directory: /home/you/MyProject
  token: 3f0c…(32+ characters)
  agent URL: http://127.0.0.1:8765/x/3f0c…/help
```

Keep it bound to loopback (the default). The tunnel connects to it locally.

By default **every operation waits for you to type `YES`** in that terminal.
`--auto` removes that barrier — read the security section before using it.

## 2. Expose it with a tunnel

Pick one. Two terminals: one for the server, one for the tunnel.

### Cloudflare quick tunnel — recommended first try

Free, no account, prints an HTTPS URL immediately.

```powershell
winget install --id Cloudflare.cloudflared      # Windows
cloudflared tunnel --url http://127.0.0.1:8765
```

```bash
brew install cloudflared                        # macOS
cloudflared tunnel --url http://127.0.0.1:8765  # Linux: use the .deb/.rpm or the release binary
```

The banner contains the address to use:

```
Your quick Tunnel has been created! Visit it at:
https://random-words-here.trycloudflare.com
```

The hostname changes every restart, and random hostnames can be slow to resolve
the first time. For a stable name, use a named Cloudflare tunnel with a domain
you own, or one of the options below.

### Tailscale Funnel — no third-party proxy reading your traffic

```bash
tailscale funnel 8765     # → https://machine.tailnet-name.ts.net/
```

Needs Funnel enabled for the tailnet in the admin console. The endpoint is
reachable by anyone with the URL, but the traffic path stays in Tailscale.

### ngrok

```bash
ngrok config add-authtoken <your-token>
ngrok http 8765
```

Useful, with one caveat: the free tier can answer browser-shaped requests with
an HTML "you are about to visit" interstitial instead of forwarding them. An
agent's fetch tool cannot click through it and has no cookie jar. A reserved
domain (`ngrok http --domain=you.ngrok-free.app 8765`) usually avoids it.

### Other options

`npx localtunnel --port 8765`, `bore local 8765 --to bore.pub`, `zrok share
public`, or an SSH reverse tunnel to a VPS you control
(`ssh -R 80:127.0.0.1:8765 you@your-vps`). Anything that gives you a public
HTTPS URL forwarding to `127.0.0.1:8765` works.

### Check it before involving the agent

```bash
curl https://random-words-here.trycloudflare.com/health          # → ok
curl https://random-words-here.trycloudflare.com/x/<token>/help  # → protocol card
```

`/health` needs no token, so it also proves the tunnel without exposing
anything. Do **not** bind `0.0.0.0` or forward the router port instead of using
a tunnel — the token would travel in cleartext.

## 3. Hand the URL to the agent

Give it the base URL and the token, e.g.
`https://random-words-here.trycloudflare.com/x/<token>/help`, and ask it to
fetch that first so it learns the protocol. `fetch_bridge/prompt.md` is a
ready-to-paste prompt.

Both parts are bearer credentials — anything that has the URL and token can run
commands as you (subject to your approval gate). Restart the server with a new
`BRIDGE_TOKEN` to revoke a URL you have shared. Stop the tunnel and server when
you are done.

## 4. What the agent can ask for

```text
GET /x/<token>/help                                   protocol card (fetch this first)
GET /x/<token>/shell?cmd=git%20status&wait=20
GET /x/<token>/shell?cmd=winget%20list&detach=1       returns at once with a job id
GET /x/<token>/job?id=<job>&wait=20                   wait, then read output
GET /x/<token>/job?id=<job>&offset=16384              next 16 KiB page
GET /x/<token>/job?id=<job>&stream=stderr             stderr separately
GET /x/<token>/read?path=Source/Main.cpp
GET /x/<token>/write?path=Source/New.cpp&text=hello%0A
GET /x/<token>/write?path=Source/New.cpp&text=more&mode=append
GET /x/<token>/edit?path=Source/Main.cpp&old=foo&new=bar
GET /x/<token>/ls?path=Source
GET /x/<token>/mcp.list
GET /x/<token>/mcp.call?tool=some_tool&args={"level":"/Game/Map"}
```

Parameters are ordinary URL query components, so the agent must percent-encode
`%20` (space), `%0A` (newline), `%25` (`%`), `%26` (`&`), `%3D` (`=`), `%2B`
(`+`), `%3C` (`<`) and `%22` (`"`). A literal `+` in a value is read as a space.
URLs are capped at 6000 characters — build bigger files with `mode=append`.
`POST` with form data is also accepted for scripts, but the agent path is GET.

The response is plain text so fetch tools return it verbatim:

```
BRIDGE v1 status=completed id=6f1c2a9b8d10 op=shell exit=0 timed_out=0 stdout_bytes=57 stderr_bytes=0 secs=0.31 offset=0 total=57 shown=57 has_more=0 next_offset=-

hello from your machine
```

`status` is one of `queued`, `awaiting_approval`, `running`, `completed`,
`failed`, `rejected`, `cancelled`. A failed command is still HTTP 200 with the
output attached — read the body, not the status code.

## Approval, approval timeouts, and `--auto`

Default mode asks you to type `YES` for **every** operation, including reads.
The agent's fetch stays open for `BRIDGE_WAIT_SECONDS` (default 15) while you
decide:

* you answer in time → the result comes back in that same response;
* you are slower → the response says `status=awaiting_approval` and the job
  keeps waiting for you; the agent refetches `job?id=<id>` and picks up the
  result once you approve;
* you type anything else, or stdin is closed → the job is `rejected`, and
  nothing runs.

`--auto` skips the prompt. Anyone who knows the URL and token can then run
anything as your user, including reading credentials and exfiltrating files.
Use a dedicated Supabase-free VM or a least-privileged OS account if you enable
it, and only on a URL you keep private. Existing queued jobs are unaffected;
auto mode applies to the running process only.

## Limits and behaviour

| Setting | Default | Notes |
|---|---|---|
| `BRIDGE_CWD` | current directory | every file path must resolve inside it |
| `BRIDGE_PORT` / `BRIDGE_HOST` | `8765` / `127.0.0.1` | keep it loopback |
| `BRIDGE_TIMEOUT_SECONDS` | `60` | per shell/MCP job, 1–600; `timeout=` overrides |
| `BRIDGE_WAIT_SECONDS` | `15` | how long a fetch waits inline, 1–25 |
| `BRIDGE_RATE_LIMIT` | `120` | authenticated requests per minute |
| `BRIDGE_REQUIRE_NONCE` | `0` | require `nonce=` on job-creating requests |
| `BRIDGE_MCP_CONFIG` | unset | local JSON file, same format as `bridge/README.md` |

* File operations are UTF-8 text, 1 MB per file, writes default to create-only
  (`mode=overwrite` or `mode=append` to change that), parent directories must
  exist (`mkdir` creates one level), and `edit` needs exactly one exact match.
* Shell output is captured to disk per job, capped at 4 MB per stream, and can
  be paged while a command is still running.
* Only the last 100 jobs are kept; `job?id=` for an evicted or pre-restart id
  returns 404. Jobs never survive a restart.
* Shell and MCP work is **not** sandboxed: the working directory is a starting
  point, not a boundary. A timeout kills the process group where the OS allows
  it, which is best-effort, especially on Windows.
* `nonce=<unique>` makes retries safe: repeating the same nonce returns the
  original job instead of running the command twice.
* MCP is the same stdio/Streamable-HTTP/SSE client documented in
  `bridge/README.md`; a new server process is started per request, so stateful
  Unreal plugins may not work.

## Security

* The token is a bearer credential in a URL. It lands in your terminal, the
  tunnel provider's logs, and the chat where you sent it. Rotate it by
  restarting with a different `BRIDGE_TOKEN`.
* TLS ends at the tunnel provider, which can see command text and output. Use a
  provider you trust, or Tailscale Funnel / your own VPS, and never send
  passwords or keys through the bridge.
* Command output is written under the OS temp directory
  (`$TMPDIR/arena-fetch-bridge/jobs`), which is cleared when the server starts.
  Do not run commands that print secrets.
* Path confinement stops `..` traversal and symlink escapes for file
  operations. It is not an OS sandbox; shell and MCP calls can go anywhere.
* Remote text is escaped before it is printed to your terminal, so a malicious
  command string cannot rewrite your screen. Approval still shows the exact
  command, so read it.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `401 unauthorized` | token missing from the path, or the tunnel URL changed |
| `403`/HTML page instead of `BRIDGE` | the tunnel's own interstitial (ngrok free) or a captive proxy |
| `404 unknown operation` | op name typo; fetch `/help` for the list |
| `400 unknown parameter` | a parameter the op does not accept; the error lists valid ones |
| `status=awaiting_approval` every time | nothing is typed in the server terminal |
| fetches feel slow the first time | quick-tunnel hostname resolution; a named tunnel avoids it |
| `429 rate limit` | more than `BRIDGE_RATE_LIMIT` authenticated requests in a minute |

## Tests

```bash
python -m unittest discover -s tests -v
```

`tests/test_fetch_bridge.py` starts real listeners on loopback and drives them
over HTTP: authorization, escaping, paging, timeouts, cancellation, nonce
replay, approval/rejection, JSON envelopes, POST, rate limiting, and the MCP
wiring (with a stubbed transport, so no MCP SDK is needed).
