# UnrealEngine-Proto-1

This repository contains the Arena local PC bridge for Windows and Linux, including Omarchy. The bridge connects an Arena agent to a worker running on your computer through a Supabase queue.

- [Linux / Omarchy quick start and security guidance](bridge/README.md#linux--omarchy-quick-start)
- [Full setup and usage](bridge/README.md)
- [Agent guide](AGENTS.md) and [reusable prompt](prompt.md)

Approval is required for every command by default. The optional `--auto` mode runs queued commands without asking and carries significant security risks; read the guide before enabling it.
