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
                                                   start.cmd → fetch_bridge/launch.py
                                                   └─ approve? → run → reply
        ◄── BRIDGE v1 status=completed exit=0 + output
```

The tunnel connection is opened *by your PC*, so there is no public IP, no port
forwarding and no inbound hole on either side. The agent only needs a tool that
can fetch a URL — no custom headers, no POST, no signing.

## Start it: double-click `start.cmd`

Windows: double-click **`fetch_bridge\start.cmd`**. macOS/Linux: `./start.sh`.

One click does everything, in this order:

1. Creates `fetch_bridge/.env` with a stable `BRIDGE_TOKEN` if there is none
   (an existing token is reused, so the URL you already shared keeps working).
2. Downloads `cloudflared` into `fetch_bridge/bin/` on first run (about 35 MB;
   skipped afterwards, and the folder is git-ignored).
3. Opens a Cloudflare quick tunnel to `127.0.0.1:8765`.
4. Prints a banner with the public URL, the token, the working directory, and
   **one paste-ready line to give the agent** (also copied to your clipboard on
   Windows and macOS):

   ```
   ====================================================================
     Fetch bridge is up. Ctrl+C stops the bridge and the tunnel.
   ====================================================================
     public URL : https://plain-cities-argue.trycloudflare.com
     token      : 9xQ2…48 characters…
     working dir: C:\

     GIVE THE AGENT THIS LINE:
     https://plain-cities-argue.trycloudflare.com/x/9xQ2…/help
   ```

5. Runs the bridge in that same window — **in auto mode**, so fetched operations
   run without asking. Add `--approve` if you want to type `YES` for each one.

**Ctrl+C stops both** the bridge and the tunnel; the launcher terminates
cloudflared even if the console is closed. The tunnel URL changes every time you
start, the token does not.

The working directory defaults to the **root of the drive the toolset lives on —
`C:\` on Windows** — so file operations work anywhere on the system drive
without configuration. Narrow it with `--cwd` or `BRIDGE_CWD` when you want file
reads and writes confined to one project:

```powershell
fetch_bridge\start.cmd --cwd "C:\Users\me\Documents\Unreal Projects\MyProject"
```

Other launcher options (anything else is passed to `server.py`):

| Flag | Effect |
|---|---|
| `--approve` | ask `YES` for every request instead of running it automatically |
| `--port 9000` | listen on another port |
| `--no-tunnel` | local only, no cloudflared: prints a `127.0.0.1` URL |
| `--tunnel-binary PATH` | use a specific cloudflared instead of downloading one |
| `--tunnel-timeout 60` | wait longer for the tunnel URL (default 30 s) |
| `--new-token` | write a brand-new random token to `.env`, print it, and exit without starting anything |

## Rotate the token

Double-click **`fetch_bridge\rotate_token.cmd`** (macOS/Linux:
`./rotate_token.sh`). It replaces `BRIDGE_TOKEN` in `fetch_bridge/.env` with 32
random bytes, prints the new token, copies it to the clipboard on Windows, and
leaves every other line of `.env` alone.

The tunnel URL does not change, so the agent only needs the new token. A bridge
that is **already running keeps accepting the old token** until you restart it
(Ctrl+C, then `start.cmd`) — the script says so when it finds something listening
on the bridge port. Without the scripts: edit the `BRIDGE_TOKEN=` line yourself
and restart, or delete it and let `start.cmd` mint a new one.

First-run notes: Windows may challenge `cloudflared.exe` with SmartScreen
(More info → Run anyway), and it needs Python 3.9+ on `PATH` (`start.cmd` checks
and tells you if it is missing). A quick-tunnel hostname can take a few seconds
to resolve the very first time.

## Wiring it up by hand

You do not need this if `start.cmd` worked, but the pieces are independent:

```powershell
# 1. token + working directory, in fetch_bridge\.env
"BRIDGE_TOKEN=$(py -c \"import secrets;print(secrets.token_urlsafe(32))\")`nBRIDGE_CWD=C:\" |
  Set-Content "fetch_bridge\.env"

# 2. the bridge (its own window)
py fetch_bridge\server.py

# 3. a tunnel to it (its own window); copy the https hostname it prints
cloudflared tunnel --url http://127.0.0.1:8765
```

`server.py` performs no tunnel management and prints only local URLs;
`launch.py` is the piece that adds cloudflared, the token file and the
paste-ready banner. Without `BRIDGE_TOKEN` the server generates a fresh token on
every start, which invalidates URLs you have handed out.

### Choosing a tunnel

| Tunnel | Good | Watch out |
|---|---|---|
| **Cloudflare quick** (`launch.py` uses this) | free, no account, instant | random hostname each start, slow first resolve |
| **Cloudflare named** | stable hostname on your domain | needs account + domain |
| **Tailscale Funnel** (`tailscale funnel 8765`) | traffic path stays in your tailnet | enable Funnel in the admin console |
| **ngrok** | reserved domain on the free tier | free tier can answer with an HTML interstitial a fetch tool cannot click |

Check any tunnel before involving the agent:

```bash
curl https://your-host/health          # → ok
curl https://your-host/x/<token>/help  # → the protocol card
```

`/health` needs no token. Do **not** bind `0.0.0.0` or forward a router port
instead of using a tunnel — the token would travel in cleartext.

## What the agent can ask for

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

## Modes: auto by default, `--approve` to gate each request

Double-clicking starts the bridge with `--auto`: fetched operations run
immediately, with no prompt. That is what makes unattended use work — and it
means **anyone holding the URL and token can run anything as you**. The banner
says so every time it starts.

To vet each request instead, start it with `--approve`:

```powershell
C:\Users\you\Documents\LocalToolset\fetch_bridge\start.cmd --approve
```

In approval mode the agent's fetch stays open for `BRIDGE_WAIT_SECONDS`
(default 10) while you decide:

* you answer in time → the result comes back in that same response;
* you are slower → the response says `status=awaiting_approval` and the job keeps
  waiting; the agent refetches `job?id=<id>` and picks the result up once you
  approve. It never executes twice;
* you type anything other than `YES`, or stdin is closed → `rejected`, nothing runs.

`server.py` run on its own still defaults to approval; only the launcher flips
the default. If you pass both flags, `--approve` wins.

## Limits and behaviour

| Setting | Default | Notes |
|---|---|---|
| `BRIDGE_CWD` | drive root (`C:\`) | every file path must resolve inside it; `--cwd` overrides |
| `BRIDGE_PORT` / `BRIDGE_HOST` | `8765` / `127.0.0.1` | keep it loopback |
| `BRIDGE_TIMEOUT_SECONDS` | `60` | per shell/MCP job, 1–600; `timeout=` overrides |
| `BRIDGE_WAIT_SECONDS` | `10` | how long a fetch waits inline, 1–25; keep it ≤10, fetch tools give up near 15 s |
| `BRIDGE_RATE_LIMIT` | `120` | authenticated requests per minute |
| `BRIDGE_REQUIRE_NONCE` | `0` | require `nonce=` on job-creating requests |
| `BRIDGE_AUTH_FAILURE_LIMIT` | `20` | wrong tokens per minute before `429`; the real token is unaffected |
| `BRIDGE_MCP_CONFIG` | unset | local JSON file, same format as `archive/supabase-bridge/README.md` |

* File operations are UTF-8 text, 1 MB per file, writes default to create-only
  (`mode=overwrite` or `mode=append` to change that), parent directories must
  exist (`mkdir` creates one level), and `edit` needs exactly one exact match.
* Shell output is captured to disk per job, capped at 4 MB per stream, and can
  be paged while a command is still running.
* Only the last 100 jobs are kept; `job?id=` for an evicted or pre-restart id
  returns 404. Jobs never survive a restart.
* Shell and MCP work is **not** sandboxed: the working directory is a starting
  point, not a boundary. With the default `C:\` that applies to the whole system
  drive. A timeout kills the process group where the OS allows it, which is
  best-effort, especially on Windows.
* `nonce=<unique>` makes retries safe: repeating the same nonce returns the
  original job instead of running the command twice.
* MCP is the same stdio/Streamable-HTTP/SSE client documented in
  `archive/supabase-bridge/README.md`; a new server process is started per
  request, so stateful Unreal plugins may not work.

## MCP through the bridge

The bridge can drive one local MCP server. Point `BRIDGE_MCP_CONFIG` at a JSON
file that describes it:

```json
{"command": "uvx", "args": ["mcp-for-blender"], "env": {}}
```

for a stdio server, or

```json
{"transport": "streamable_http", "url": "http://127.0.0.1:8000/mcp"}
```

(`sse` works too) for a network one. Then `mcp.list` and `mcp.call` proxy to it.
The file describes a **single** server, and the bridge starts it per call:
initialize, one call, exit. Expect a few seconds of startup on every `mcp.call`
and no state carried between calls — the server itself may keep its own.

Blender, end to end (`uvx mcp-for-blender`, with the addon's server running in
Blender on port 9876):

```
GET /x/<token>/mcp.list
GET /x/<token>/mcp.call?tool=get_scene_info
GET /x/<token>/mcp.call?tool=execute_blender_code&args={"code":"import bpy\nprint(len(bpy.data.objects))"}
```

`mcp.list` returns every tool name with its input schema and changes nothing, so
it is always the right first call. `/help` reports which server is configured (or
that none is), so a later agent does not have to guess.

## Security

* **The launcher defaults to auto mode.** Anyone with the URL and token can run
  anything as your user, with no prompt. Use `--approve`, keep the token private,
  and Ctrl+C the bridge when you are not using it.
* The token is a bearer credential in a URL. It lands in your terminal, the
  tunnel provider's logs, and the chat where you sent it. Rotate it by
  double-clicking `rotate_token.cmd` and restarting the bridge; or edit
  `BRIDGE_TOKEN` in `fetch_bridge/.env`, or delete the file to get a brand-new
  one.
* You may choose the token yourself, but it has to survive a URL path: 16–200
  characters of `A-Z a-z 0-9 . _ ~ -`. Spaces, `?`, `#`, `&`, `/`, `%`, quotes and
  non-ASCII are refused at startup — they would be parsed as URL syntax and every
  request would fail with `401`. A memorable phrase is acceptable, a guessable
  one is not: wrong tokens are answered with `401`, and after
  `BRIDGE_AUTH_FAILURE_LIMIT` (default 20) in a minute with `429`, while the
  correct token keeps working.
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

| Symptom | Cause / fix |
|---|---|
| `401 unauthorized` | token missing or wrong, or you gave the agent an old URL |
| `429 too many wrong tokens` | more than `BRIDGE_AUTH_FAILURE_LIMIT` bad tokens in a minute; wait or raise the limit |
| token refused at startup | it contains a character that cannot travel in a URL path; use `A-Z a-z 0-9 . _ ~ -` |
| `403` or an HTML page instead of `BRIDGE` | the tunnel's own interstitial (ngrok free) or a captive proxy |
| `no tunnel URL after 30s` | outbound HTTPS blocked; retry with `--tunnel-timeout 60` and read `%TEMP%\arena-fetch-bridge\tunnel.log` |
| `could not download cloudflared` | no GitHub access; install cloudflared yourself and pass `--tunnel-binary` |
| Python not found on double-click | install Python 3.9+ with "Add python.exe to PATH", or run `py fetch_bridge\launch.py` |
| working dir is not `C:\` | a stale `BRIDGE_CWD` line in `fetch_bridge/.env`; delete it |
| `status=awaiting_approval` every time | nobody typed `YES` in the launcher window |
| fetches hang and then fail around 15 s | the fetch tool's own timeout; use `wait=10` or less, or `detach=1` and poll `job?id=` |
| commands run without asking | the launcher's default; restart with `--approve` |
| `/help` shows the tunnel hostname | expected when launched by `start.cmd`; a bare `server.py` shows `127.0.0.1` |
| `404 unknown operation` | op name typo; fetch `/help` for the list |
| `400 unknown parameter` | the error lists the parameters that op accepts |
| `429 rate limit` | more than `BRIDGE_RATE_LIMIT` authenticated requests in a minute |

## Tests

```bash
python -m unittest discover -s tests -v
```

`tests/test_fetch_bridge.py` starts real listeners on loopback and drives them
over HTTP: authorization, escaping, paging, timeouts, cancellation, nonce
replay, approval/rejection, JSON envelopes, POST, rate limiting, MCP wiring, the
launcher's token file and URL scraping, and the drive-root default. No network
access is needed.
