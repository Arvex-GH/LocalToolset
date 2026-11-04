# Arena local command bridge (prototype)

This is a local, approval-gated worker, not a network server: it makes outbound HTTPS requests to a Supabase queue every 3 seconds by default. Arena can enqueue commands and read results using its Supabase connector. No Edge Functions are necessary. A terminal on your computer must remain open to approve each command by typing `YES`. This is **not yet an Unreal MCP client**: commands run in your system shell; an MCP integration would be a separate addition.

## Security — read before running

- Every approved command runs with **all the privileges of your local user**. The working directory is only a starting directory, not a sandbox: commands can access other folders, network, credentials, and programs. Carefully inspect every command; do not approve commands you don't understand. Use a separate OS user or VM for stronger isolation.
- The worker uses a Supabase **secret/service-role key**. This key has broad project-wide privileges and bypasses RLS. Use a dedicated Supabase project, keep the key private, and revoke/rotate it if exposed. Never share it in chat, put it in source control, or use it in frontend code. Anyone possessing the key may enqueue requests, read results, or access other project data. Approval protects against automatic local execution, not against unauthorized queue access.
- Command text and output are stored in Supabase and visible to project administrators and service-role key holders. Don't run commands that print passwords, tokens, or sensitive files. Output is truncated to 32 KiB per stream before upload, but temporary files can consume disk while a command runs. Running commands can themselves write arbitrary amounts of data.
- A timeout terminates the shell process but **does not guarantee its descendants are terminated**, especially on Windows. Commands can launch persistent processes; inspect them before approving.

## Setup on your computer

1. Install Python 3.9 or later. Download this repository/branch to your computer. The worker requires no Python packages.
2. The queue schema is in `schema.sql`; it has already been deployed to the `ArenaConnectMCP` project for this session. If using a fresh project, apply that file once in its SQL editor. The schema is not intended to be reapplied to the same project.
3. In Supabase Project Settings → API Keys, obtain a **secret** key for this dedicated project. Set it as an environment variable *locally*, not in a tracked file or chat. The publishable key will not work. Restrict access to your terminal and environment.
4. Open a terminal in the downloaded repository. For Windows PowerShell (replace values locally):

```powershell
$env:SUPABASE_URL = 'https://dtcdcdsnjvihzpzhijss.supabase.co'
$env:SUPABASE_SECRET_KEY = Read-Host 'Supabase secret key'
$env:BRIDGE_CWD = 'C:\path\to\your\project'
py bridge/worker.py
```

On macOS/Linux:

```sh
export SUPABASE_URL='https://dtcdcdsnjvihzpzhijss.supabase.co'
read -rs SUPABASE_SECRET_KEY; export SUPABASE_SECRET_KEY; echo
export BRIDGE_CWD='/path/to/your/project'
python3 bridge/worker.py
```

The shell will retain these environment variables until it exits; close it when finished. You can set `BRIDGE_POLL_SECONDS` (1–300, default 3) and `BRIDGE_TIMEOUT_SECONDS` (1–600, default 60). Stop the worker with Ctrl+C. Use only one worker unless you're intentionally sharing the queue: **there is no device targeting**, so any connected worker can claim any pending job.

## Usage from Arena

Ask Arena to submit a command. It can use the Supabase connector to insert a row:

```sql
insert into public.arena_bridge_commands(command) values ('echo hello from the local bridge') returning id, status;
```

The worker atomically claims one pending row. After inspecting it locally, type `YES` to execute or anything else to reject. Arena can query the result:

```sql
select id, status, result from public.arena_bridge_commands where id = '<actual id>';
```

Commands are executed at most once by this worker, not necessarily exactly once: if it crashes after claiming or during execution, the row remains `running` and the command might or might not have run. If a result upload fails, the worker retries uploading without rerunning. Never automatically reset a `running` job; first inspect the local machine and logs. Rows remain in the database until an administrator deletes them. The connector itself does not continuously listen: Arena has to query again to see results.

## Testing

`python -m unittest discover -s tests -v` tests local command execution, timeouts and output truncation without reaching Supabase. An end-to-end test requires a local worker and local approval, plus access to the project.
