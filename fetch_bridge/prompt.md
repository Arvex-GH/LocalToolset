# Local fetch bridge prompt

I run a small HTTP listener on my own computer that executes what you fetch. It
is not a queue and there is nothing in the cloud to poll: your fetch *is* the
request, and the response carries the result.

Base URL (replace `<token>` with the token from the server terminal):

```text
https://<your-tunnel-host>/x/<token>
```

Start by fetching `https://<your-tunnel-host>/x/<token>/help`. It returns the
full protocol card for this server: the working directory, every operation,
escaping rules, paging, and statuses. Read it before sending real work.

How I want you to use it:

- Only run what I asked for. Shell commands and MCP tools run as my local user
  with no sandbox, so confirm anything sensitive or irreversible with me first,
  and never send secrets, keys, or tokens back through a URL or response.
- I approve each operation manually in a terminal unless I told you I started it
  with `--auto`. If a response says `status=awaiting_approval`, refetch
  `job?id=<the id it gave you>&wait=20` instead of resubmitting the request.
- For anything that may run longer than ~15 seconds, use `detach=1`, then poll
  `job?id=<id>&wait=20` and page output with `offset=` rather than starting the
  command again.
- Add `nonce=<some unique value>` to job-creating requests so a retried fetch
  returns the original result instead of running the command twice.
- Read the response body, not the HTTP status code: a failing command is a
  normal HTTP 200 with `status=failed` and `exit=` plus output attached.

Setup instructions and the security model are in
`fetch_bridge/README.md`. If you cannot reach the URL, tell me what failed
(401, 404, an HTML page instead of a `BRIDGE` line) rather than guessing.
