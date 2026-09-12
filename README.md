# ModHarbor

Pelican mod manager with multi-game support. Discover, install, and recover
mods for almost any game. Built-in profiles ship for Minecraft, Valheim,
7 Days to Die, Eco, Rust, and Icarus.

**1.0.0-rc.3** by ChickenWings.

- [Install, upgrade and recovery](docs/OPERATIONS.md)
- [Provider and game support](docs/SUPPORT-MATRIX.md)
- [Provider credentials and connection tests](docs/PROVIDERS.md)
- [Game Builder and config rules](docs/GAME-BUILDER.md)
- [Architecture and provider development](DEVELOPER-PROVIDERS.md)

## Requirements

The plugin manifest targets Pelican `^1.0.0-beta38`. PHP 8.2+ with ZIP, mbstring,
DOM/libxml and the panel's normal HTTP extensions is required. The archive includes
the existing isolated parser dependencies under `runtime/vendor`. It does not
replace Pelican's Composer project. Steam Workshop additionally requires a
dedicated SteamCMD installation with anonymous access to the selected game.

## Offline validation

```sh
php tests/check.php
# On the destination panel, also compile the actual registered Blade components:
php tests/check.php /var/www/pelican
```

These tests use fake HTTP, Wings and SteamCMD process implementations. They need no
live credentials and do not contact or mutate a running game server. Test fixtures
use isolated operating-system temporary directories.

## Operating model

Discover a package, resolve dependencies, validate its source and paths, stage and
inspect its contents, back up changed files, install, verify, and commit the
manifest and audit journal. Archive content validation occurs during staging;
all conflict/dependency validation completes before live file replacement.
Failed moves roll back, including ambiguous remote timeouts. An incomplete
rollback blocks further operations until recovery succeeds.

Bulk actions and Update All use the same engine. Each selected package plus its
required dependencies is a transaction. A batch stops at its first failure;
earlier completed transactions remain valid. Config edits create recoverable
revisions and reject stale editor contents. Restart reminders accumulate changes;
ModHarbor does not send unexpected power commands.

Global Provider Settings and Game Builder require a root administrator. Server
actions enforce file permissions, locked server identity and current-tenant checks.
Keep panel backups, the panel encryption key, per-server manifests and operation
journals together when restoring the system.
