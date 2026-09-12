# Install, upgrade and recover

This archive replaces only `/var/www/pelican/plugins/gamenest-mod-manager`.
It includes the complete supplied Git history and tags, additional checkpoints,
and the existing isolated parser runtime. It excludes editor backups, unrelated
plugins, panel credentials, local test runtimes and scratch files.

## Before replacing the plugin

1. Verify the archive against its `.sha256` sidecar. Extract into a new staging
   directory, never over the live panel. Inspect `plugin.json` and Git history.
2. Back up the existing plugin directory **including `.git`**, the panel database
   and encryption key, and `storage/app/gamenest-mod-manager`. Preserve the legacy
   mod.io settings location if it exists. Keep these backups private.
3. Back up the relevant game-server files, including `.gamenest-mod-manager.json`
   and `.gamenest/mod-manager`. These are on Wings servers, not necessarily on the
   panel machine. Plugin code rollback is not a rollback of installed game mods.
4. Check `git status --short` and `git diff --check` in the destination plugin. The
   received baseline was `2e2873bd66920cf965aacbdd758e0ba9d61a54c2`. If the server has
   newer work, merge/review it rather than replacing it with this older branch.
   Checkpoint any destination work before proceeding.
5. Run `php tests/check.php` in the staged repository. PHP must have ZIP, mbstring
   and DOM enabled. The bundled TOML library emits upstream deprecation notices on
   PHP 8.4; see release notes. Do not change the panel's Composer dependencies.

## Fresh install

Use the Pelican version declared in `plugin.json`. Put the staged plugin at the
exact plugin directory above, retaining its isolated `runtime/vendor` folder.
Assign its files to the normal panel owner/group with directories readable and
traversable by the panel worker. Enable the plugin through Pelican's plugin manager.
Use its root-only Provider Settings and Game Builder pages to configure it.
Do not copy test credentials or manually hard-code live secrets in the repository.

## Upgrade the existing installation

Use a maintenance window so no ModHarbor operation can run during the directory
swap. Finish/recover outstanding operations first. Retain the old plugin directory
as a backup outside the active plugins directory; move the verified staged
`gamenest-mod-manager` directory into its exact previous location. This avoids
leaving stale source files mixed with new code. Do not replace the `plugins` parent
directory or run a wildcard operation against other plugins.

From `/var/www/pelican`, clear panel caches and validate the actual registered UI:

```sh
php artisan optimize:clear
php plugins/gamenest-mod-manager/tests/check.php /var/www/pelican
```

Restart the panel's normal workers using your existing deployment procedure.
Confirm panel health, root-only navigation, server permissions, saved provider
settings and existing Game Builder definitions before allowing mod operations.
Run the live acceptance checklist in `RELEASE-1.0.md`. No automatic installer in
this archive changes permissions, restarts a service or alters live server files.

## Interrupted operation recovery

Keep the game stopped. Open History and retry recovery for the unresolved
operation. The exclusive per-server lock blocks new writes while a journal is
running or requires recovery. Recovery reverses recorded moves and verifies the
result; progress is saved so an interrupted recovery can be retried.

Do not delete operation journals or `.gamenest/mod-manager/operations` to bypass
the lock. If multiple unresolved journals, corrupt metadata or conflicting files
prevent recovery, retain all evidence and compare the exact recorded paths with
the backups. Never apply a journal from another server. Restore a consistent game
filesystem and manifest from backup before clearing a failed state manually.

Completed operations clean temporary staging/backups. They are not permanent full
server snapshots. To revert a successful update, select an available older package
version through Versions; its compatibility/dependencies must still validate.
For removed files no longer offered upstream, restore your server backup. Config
revisions retain five prior versions per path; a Restore creates a backup first.

## Limits and operational monitoring

Server quota checks use Pelican's MiB quota and Wings `disk_bytes` usage. Configured
packages reserve staging bytes plus journal headroom; unlimited server quotas
cannot reveal physical node capacity. Monitor actual node disks separately.
SteamCMD can use more space in its own cache than the final bounded package size.
Panel temporary archive space is checked before inspection.

Package checksums are verified where provided. Moves verify occupancy and size;
files up to 32 MiB also record/verify SHA-256 through deployment and recovery.
Journals, upload sources and config backups are sensitive administrator data.
Use one panel host or a shared filesystem supporting the operation locks; this
release does not implement a distributed transaction coordinator.

## Rebuild a repository archive

With a clean committed tree and isolated runtime installed:

```sh
python3 tools/build-release.py /tmp/modharbor-1.0.0-rc.2.tar.gz
```

The builder checks Git object integrity, packages committed source bytes, includes
all Git metadata and runtime files, and emits SHA-256 and per-file manifests.
It refuses an existing output filename or an output inside the repository.
