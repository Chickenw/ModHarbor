# ModHarbor UI Overhaul

## What changed

- Unified Game Setup, Mod Manager, and Provider Settings around a responsive dark navy/blue visual system.
- Removed the large artwork banner from all three pages and replaced it with compact contextual page headers.
- Added configured-game summary statistics, live search, and status/type filters.
- Converted game definitions into artwork-led cover tiles with a primary Manage action and overflow menu while preserving every existing action.
- Moved the game-definition editor into a responsive right-side drawer on desktop.
- Added server-level installed/update/provider/disabled summary statistics to Mod Manager.
- Modernized tabs, fields, result cards, installed rows, status treatments, and action controls.
- Reworked provider settings into responsive connection cards with clearer connection state and secret controls.
- Preserved the Pelican shell/sidebar, ModHarbor banner, Livewire bindings, page classes, provider logic, game profiles, install lifecycle, filters, version selection, configs, history, import, and export behavior.

## Deploy

1. Back up `/var/www/pelican/plugins/gamenest-mod-manager` outside Pelican's `plugins` directory.
2. Extract this archive so `plugin.json` is at `/var/www/pelican/plugins/gamenest-mod-manager/plugin.json`.
3. Restore the existing owner/group used by the Pelican installation (commonly `www-data:www-data`).
4. From `/var/www/pelican`, run `sudo -u www-data php artisan optimize:clear`.
5. Restart the installed PHP-FPM service and hard-refresh the browser.

No database migration or provider reconfiguration is required for this UI-only release.
