# site_bridge — the same protocol, on a PHP host

`fetch_bridge` is for a computer with no public address: it listens and dials out
through a tunnel. A website already has a public address, so this variant needs
neither: one PHP file answers the agent's fetches directly, and a small panel
issues the tokens it is allowed to use.

```
 agent (cloud)                              your website (PHP)
 ─────────────                              ──────────────────
 fetch https://site/bridge.php?t=TOKEN&op=ls&path=.
        │                                          ▲
        └──────── ordinary HTTPS ──────────────────┘
        ◄── BRIDGE v1 status=completed … + listing
```

No tunnel, no daemon, no cron, no shell. Everything happens inside one PHP
request, which is all shared hosting offers.

## What is different from `fetch_bridge`

| | `fetch_bridge` (PC) | `site_bridge` (host) |
|---|---|---|
| reaches the agent via | Cloudflare quick tunnel | its own HTTPS URL |
| token lives in | `fetch_bridge/.env` | MySQL, issued by the panel |
| token lifetime | until you rotate | expiry + revoke, per session |
| commands | real shell | `php` snippets, `sql`, file ops |
| long work | `detach=1` + `job?id=` | same, but ticks are capped near 10 s |
| audit | terminal output | `sb_activity`, shown live in the panel |

## Files

| File | Job |
|---|---|
| `bridge.php` | the endpoint the agent fetches |
| `index.php` | the panel: sign in, issue/revoke tokens, watch activity |
| `setup.php` | run once: creates the tables, stores the admin password, then delete it |
| `bridge_lib.php` | shared config, MySQL, token and logging code |
| `bridge.secrets.example.php` | copy to `bridge.secrets.php` and fill in the database |

## Deploying on InfinityFree

1. **Create the database.** VistaPanel → *MySQL Databases*. Note the **host**
   (`sql1XX.infinityfree.com` — not `localhost`), the database name, the user and
   the password.
2. **Upload the files.** File Manager or FTP, into `htdocs/`: `bridge.php`,
   `index.php`, `setup.php`, `bridge_lib.php`.
3. **Create `bridge.secrets.php`** from the example, one level *above* `htdocs`
   (setup.php prints the exact path it expects). Fill in the `db` block; keep
   `allow_php` false until you want it.
4. **Open `https://yoursite/ setup.php`** and set the admin password. It creates
   `sb_settings`, `sb_tokens` and `sb_activity`.
5. **Delete `setup.php`.** It refuses to reinstall on its own, but there is no
   reason to leave an installer online.
6. **Open the panel**, create a token, and give the agent:

   ```
   https://yoursite/bridge.php?t=sb_…&op=help
   ```

   The first fetch returns the protocol card; everything after that is up to
   what you asked for.

## Ops

| op | does |
|---|---|
| `ping` / `help` / `selftest` | liveness, the protocol card, what the host allows |
| `ls` `read` `write` `edit` `mkdir` `rm` `upload` | files under the configured root |
| `sql&db=NAME&q=…&params=[…]` | prepared queries against a listed database |
| `php&code=…` | run a snippet (needs `allow_php => true`) |
| `php&detach=1` then `job&id=…` | the same, split into ~5 s ticks |
| `jobs` / `cancel` | list and drop jobs |

The envelope is the same as `fetch_bridge`: one `BRIDGE v1 status=… ` header
line, `note:` lines, blank line, then the paged body (`offset=`, `len=`,
`format=json`). A 401 means the token is unknown, expired or revoked; a 429 means
you are going too fast and should stop, not retry in a loop.

### Long jobs, honestly

Shared hosts kill a request after roughly 10 seconds and there is no cron and no
background process. `detach=1` therefore does not fork anything: it stores the
snippet and runs the first tick; every `job&id=…` request runs the next one. The
snippet restarts from the top each time, so it must resume from `$BRIDGE_STATE`
and stop before `bridge_tick_left()` runs out:

```php
$BRIDGE_STATE['i'] = (int) ($BRIDGE_STATE['i'] ?? 0);
while ($BRIDGE_STATE['i'] < 200 && bridge_tick_left() > 0.5) {
    $BRIDGE_STATE['i']++;
    echo "item {$BRIDGE_STATE['i']}\n";
}
$BRIDGE_DONE = $BRIDGE_STATE['i'] >= 200;
```

That is a real constraint, not a detail: a "long job" here is a sequence of short
requests driven by whoever is polling.

## Security

* A token is the whole lock. It grants the file ops, the listed databases, and
  `php` if enabled — on a public URL, with no prompt in between. Issue one per
  session, give it an expiry, revoke it when the session ends.
* Only the SHA-256 hash of a token is stored, and the value is shown once when
  it is created. A database leak does not hand over working tokens.
* The panel password is a bcrypt hash in `sb_settings`. Ten failures in ten
  minutes from one address lock sign-in; tokens are unaffected.
* Keep `bridge.secrets.php` above the web root. If it has to sit beside
  `index.php`, setup.php adds a deny rule to `.htaccess` — belt and braces, but
  the parent directory is genuinely better.
* `allow_php` defaults to **false**. Turning it on means the token holder can run
  any PHP this account can run. Turn it on for a session, then turn it off.
* Job files live in `bridge_state/` and contain the code that was executed; the
  directory writes its own `.htaccess` deny, or point `state_dir` outside the web
  root in the secrets file.
* Everything the agent does is logged to `sb_activity` with a summary, status and
  duration, and appears in the panel's live feed.

## Troubleshooting

| symptom | cause |
|---|---|
| blank page or HTTP 500 | a PHP error before headers; read the host's error log, or fetch `bridge.php?op=ping` |
| `503 site_bridge is not configured` | `bridge.secrets.php` missing, or no `db` block |
| `503 … open setup.php once` | tables or admin password missing |
| `401 unauthorized` | token unknown, expired or revoked — issue a new one in the panel |
| `429` | 20 rejected tokens a minute from one address, or 120 writes a minute for one token |
| `sql: connect failed` | the host must be the `sqlXXX` name from VistaPanel, not `localhost` |
| uploads fail | the host caps uploads (10 MB on InfinityFree); check `upload_max_filesize` in `selftest` |
| the feed shows nothing | the requests never arrived; check the token's "last use" in the tokens table |

## Not included, on purpose

No `shell` op: shared hosting disables `exec`, `shell_exec` and friends, so a
shell op would be a lie. If you need real commands, put `fetch_bridge` on a
machine you control — that is what it is for.
