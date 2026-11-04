# Arena local command bridge (prototype)

This is a local, approval-gated worker, not a network server: it makes outbound HTTPS requests to a Supabase queue every 3 seconds by default. Arena can enqueue commands and read results using its Supabase connector. No Edge Functions are necessary. By default, a terminal on your computer must remain open to approve each command by typing `YES`. An explicitly enabled `--auto` mode skips approvals; see its security warning below. In addition to legacy shell commands, structured `/bridge` commands provide direct text file operations and an optional standard stdio MCP client. The worker launches the locally configured MCP server for each MCP request; configure and install an Unreal MCP implementation separately.

## Security — read before running

- Every approved shell command and MCP tool runs with **all the privileges of your local user**. The working directory is only a starting directory, not a sandbox: commands can access other folders, network, credentials, and programs. Carefully inspect every command; do not approve commands you don't understand. Use a separate OS user or VM for stronger isolation.
- The worker uses a Supabase **secret/service-role key**. This key has broad project-wide privileges and bypasses RLS. Use a dedicated Supabase project, keep the key private, and revoke/rotate it if exposed. Never share it in chat, put it in source control, or use it in frontend code. Anyone possessing the key may enqueue requests, read results, or access other project data. Approval protects against automatic local execution, not against unauthorized queue access.
- **`--auto` removes the approval barrier completely.** Anyone or anything that can insert into the queue—including an attacker with project access or a compromised key—can run shell commands, read and write files, and invoke MCP tools as your local OS user. Existing pending commands will also run. It does not grant Windows administrator rights unless you launched the worker as an administrator. Use a dedicated project, least-privilege OS account or VM, keep credentials private, review/clear unexpected pending requests before starting, and stop the worker when not in use. Never enable auto mode on an untrusted queue.
- Command text and output are stored in Supabase and visible to project administrators and service-role key holders. Don't run commands that print passwords, tokens, or sensitive files. Output is truncated to 32 KiB per stream before upload, but temporary files can consume disk while a command runs. Running commands can themselves write arbitrary amounts of data.
- A timeout terminates the shell process but **does not guarantee its descendants are terminated**, especially on Windows. Commands can launch persistent processes; inspect them before approving.

## Setup on your computer

1. Install Python 3.9 or later. Download this repository/branch to your computer. File and shell operations require no Python packages; MCP support requires the optional SDK described below.
2. The queue schema is in `schema.sql`; it has already been deployed to the `ArenaConnectMCP` project for this session. If using a fresh project, apply that file once in its SQL editor, then apply `wait.sql` once. The wait function has also been deployed to ArenaConnectMCP. The schema is not intended to be reapplied to the same project.
3. Copy the example file to a private local `.env` file. In Windows PowerShell, from the repository root:

   ```powershell
   Copy-Item bridge/.env.example bridge/.env
   notepad bridge/.env
   ```

   On macOS/Linux: `cp bridge/.env.example bridge/.env`, then open `bridge/.env` in a text editor. Fill in your Supabase **secret** key from Project Settings → API Keys and the absolute path to your project. The URL is prefilled for ArenaConnectMCP. For example:

   ```dotenv
   SUPABASE_URL=https://dtcdcdsnjvihzpzhijss.supabase.co
   SUPABASE_SECRET_KEY=your-secret-key-goes-here
   BRIDGE_CWD=C:\Users\YourName\MyProject
   ```

   This is a local plaintext file containing a powerful credential. Restrict filesystem access, backups, and sharing; `.gitignore` excludes it, but verify it is never force-added to Git or sent in chat. **Never paste the real key into this example, an issue, or the agent conversation.** Anyone or any command with access to the file or the worker's environment can read the key. Use a dedicated Supabase project and a least-privileged OS account or VM.
4. From the repository root, start the worker (it automatically reads `bridge/.env` regardless of the current working directory):

   ```powershell
   py bridge/worker.py
   ```

   On macOS/Linux: `python3 bridge/worker.py`. The file supports simple `KEY=value` lines and full-line `#` comments. Matching outer quotes are optional; values are literal (no variable expansion, backslash escaping, or inline comments). Existing environment variables override file values, including variables left over in an open terminal. You can also continue setting variables directly without a file. Restart the worker after editing the file.

To explicitly enable automatic execution instead of per-command approval, start the same worker with `--auto`:

```powershell
py bridge/worker.py --auto
```

On macOS/Linux: `python3 bridge/worker.py --auto`. Auto mode lasts only for that process. Stop it with Ctrl+C; start without `--auto` to return to approval mode. **This bypasses approval for every shell, file, and MCP operation, including commands already pending.**

The `.env` file persists on disk until you remove it; environment variables set in a shell persist until that shell exits. You can set `BRIDGE_POLL_SECONDS` (1–300, default 3) and `BRIDGE_TIMEOUT_SECONDS` (1–600, default 60). Stop the worker with Ctrl+C. Use only one worker unless you're intentionally sharing the queue: **there is no device targeting**, so any connected worker can claim any pending job.

## Usage from Arena

Ask Arena to submit a command. It can use the Supabase connector to insert a row:

```sql
insert into public.arena_bridge_commands(command) values ('echo hello from the local bridge') returning id, status;
```

The worker atomically claims one pending row. In default mode, after inspecting it locally, type `YES` to execute or anything else to reject. In `--auto` mode, it executes without asking. Arena can query the result:

```sql
select id, status, result from public.arena_bridge_commands where id = '<actual id>';
```

Commands are executed at most once by this worker, not necessarily exactly once: if it crashes after claiming or during execution, the row remains `running` and the command might or might not have run. If a result upload fails, the worker retries uploading without rerunning. Never automatically reset a `running` job; first inspect the local machine and logs. Rows remain in the database until an administrator deletes them.

### Waiting for results from Arena

After enqueuing, Arena should call the Supabase SQL connector with the returned job ID:

```sql
select id, status, result from public.arena_bridge_wait('<actual job uuid>', 15);
```

This database function checks approximately once per second and returns as soon as the job reaches `completed`, `failed`, or `rejected`, or after at most 15 seconds with its current status. If still pending or running, Arena may call it again while the user request remains active; if a reasonable overall deadline is reached, report that the job is still pending/running rather than assuming failure or resubmitting it. A missing row returns no rows. This is **database-side polling invoked via the connector**, not a persistent background listener or a direct connection from the Arena sandbox. It cannot continue independently between turns, and connector or database timeouts may end a wait early. The worker's separate `BRIDGE_POLL_SECONDS` setting controls how quickly it picks up jobs (3 seconds by default; use 1 for lower latency).

## Testing

`python -m unittest discover -s tests -v` tests file operations and local command execution without reaching Supabase. If the MCP SDK is installed it also runs an integration test against a local fake stdio MCP server; otherwise that test is skipped. An end-to-end test requires a local worker and local approval, plus access to the project.


## Structured commands (no database migration required)

Place `/bridge ` followed by a JSON object in the existing `command` column. Any command **without** that exact prefix remains a legacy shell command. Malformed structured commands fail; they are never executed as shell. By default all actions, including reads, MCP discovery, and MCP calls, require the same local `YES` approval. Explicit `--auto` mode skips it for all actions. Examples of command values:

```text
/bridge {"op":"read","path":"Source/example.cpp"}
/bridge {"op":"write","path":"Source/new.cpp","content":"Hello\n"}
/bridge {"op":"edit","path":"Source/example.cpp","old":"old text","new":"new text"}
/bridge {"op":"mcp.list"}
/bridge {"op":"mcp.call","tool":"actual_tool_name","arguments":{}}
```

When inserting SQL, the command text is a SQL string; escape SQL single quotes as usual. Paths must be relative to `BRIDGE_CWD` and resolved inside it; symlinks pointing outside are rejected. Direct file operations handle UTF-8 text only; read and edit inputs and written output are limited to 1 MB; read results are truncated to 32 KiB. Write creates a new file by default: use `"overwrite":true` to overwrite an existing file. Parent directories must exist. Edit uses an exact, case-sensitive match that must occur exactly once. These checks are not an OS sandbox: concurrent filesystem modifications can change paths between validation and use, and shell/MCP access can bypass path restrictions entirely. Avoid using the bridge with untrusted local users or concurrently modified project directories.

## Optional Unreal MCP setup

There are many different Unreal MCP implementations. For example, [FFZackFair92/unreal-engine-mcp](https://github.com/FFZackFair92/unreal-engine-mcp) documents a stdio command `python -m unreal_mcp.server`; [SallahBoussettah/UnrealMCP](https://github.com/SallahBoussettah/UnrealMCP) documents a Python stdio MCP server and a separate Unreal Editor plugin. Their installation, editor configuration, available tools, supported engine versions and behavior differ. Follow the specific project's setup, inspect/trust its code, and make sure it works locally first. This bridge does not install an Unreal plugin, start the editor, or implement an Unreal protocol itself.

The bridge supports **stdio MCP servers only**. Install the official Python MCP SDK in the same Python environment used for the worker (Python 3.10+):

```sh
python -m pip install "mcp>=1,<2"
```

Create a private local JSON configuration file, **outside the repository**, containing the executable, arguments, and optionally environment values needed by your chosen MCP server. Example for FFZackFair92's implementation (adjust the executable and installation to your system):

```json
{"command":"python", "args":["-m","unreal_mcp.server"], "env":{}}
```

Set `BRIDGE_MCP_CONFIG` to the absolute path of that file before starting the worker, e.g. `$env:BRIDGE_MCP_CONFIG = 'C:\path\to\mcp-config.json'` in PowerShell or `export BRIDGE_MCP_CONFIG='/path/to/mcp-config.json'` on macOS/Linux. Config is chosen locally and cannot be set by queue requests. Keep credentials out of Git and do not put the Supabase secret in this config. The MCP SDK passes a limited default environment plus the config's explicit environment to the child process, not the worker's full environment.

Use `mcp.list` first to discover the real tool names, input schemas and an optional `nextCursor`; provide that as `"cursor"` in another `mcp.list` request for the next page. Then submit `mcp.call` with the exact `tool` and `arguments`. The raw MCP response is returned up to 32 KiB; larger responses return a truncated JSON preview. Tool-reported `isError` marks the job failed. A new MCP server process and session is created per request, so servers that require persistent state across calls may not work. The worker's timeout covers the MCP session, but a timeout does **not** guarantee that a side effect in the editor was cancelled. Check Unreal before retrying. MCP tools can read or modify arbitrary project assets and may execute code regardless of the file path restrictions; approve only understood requests. This adapter has been tested against a fake MCP server, not a live Unreal Editor.
