# Game Builder

Root administrators can create, edit, enable, disable, import and export inert game
definitions. Stable keys cannot be renamed in place. Disable before deleting.
Concurrent edits use a catalog revision to reject stale saves. Export/import keeps
provider metadata portable and excludes global credentials.

1. Start with a disabled definition. Set its display name and exact egg ID/name or
   a narrow name fragment. Ambiguous detection fails closed.
2. Choose sources and fill provider metadata: mod.io game identity, Nexus domain,
   Steam app ID, CurseForge game/class/loader, or Modrinth loaders/game versions.
   Required identity fields and metadata types are checked before saving an enabled
   definition or enabling a draft. Field types appear below each metadata editor.
   API-specific compatibility is validated again when browsing/installing.
3. Declare relative mod and config directories. Paths cannot escape the server root.
4. Choose copy, ZIP archive or an applicable provider-managed strategy. Set the
   destination to a declared mod directory and an optional strict archive prefix.
5. Set install-while-running and restart-required policy. Prefer stopped-server
   changes unless the game's runtime explicitly supports hot replacement. Config
   edits and recovery require a stopped server regardless of install policy.
6. Test the definition on a disposable server before enabling it for other tenants.

Most games use the generic capability. Keep the existing Eco ModKit and Rust
Carbon/Oxide capabilities for their runtime-specific behavior. Definitions cannot
execute arbitrary commands, PHP classes or templates.

## Recommended game profiles

Search the Game name field to choose a recommended setup for Minecraft Java
Edition, Eco, Rust, or 7 Days to Die (also searchable as `7DTD`). Local profiles
appear before Steam results; matching Steam App IDs resolve to the same profile.
Known profiles remain available when Steam search fails. Other Steam games and
manual entry continue to work.

Selecting a profile replaces the draft's name, optional Steam App ID, sources,
provider metadata, mod/config directories, package types, deployment and runtime
settings. It clears the custom artwork override and old config rules. Values remain
editable before Save. Existing server detection and enabled state are preserved;
new drafts remain disabled by default. Selection alone never saves a definition.
Selecting another profile replaces its predecessor's recommendations; selecting
an ordinary Steam result after a profile clears the previous profile's setup.

| Profile | Sources | Packages / deployment | Runtime |
| --- | --- | --- | --- |
| Minecraft Java Edition | CurseForge, Modrinth, GitHub, Upload | JAR copied intact to `mods` | Stop to install; restart required |
| Eco | mod.io, GitHub, Upload | ZIP using existing Eco ModKit capability | Stop to install; restart required |
| Rust | uMod, GitHub, Direct, Upload | CS/ZIP using existing Carbon/Oxide capability | Running installs allowed; restart not required |
| 7 Days to Die | Nexus, 7DaysToDieMods, GitHub, Direct, Upload | ZIP extracted to `Mods` | Stop to install; restart required |

Minecraft supplies CurseForge game/class identities and Modrinth's mod project
type. Set the actual server version and loader in provider metadata; profiles do
not guess server compatibility. The JAR profile does not enable ZIP modpack
extraction. Changing package types must still satisfy the existing deployment
validator. Provider credentials stay in Provider Settings.

New drafts receive the profile's canonical stable key. If that key already exists,
Save rejects the collision; edit the existing definition. Eco and Rust capabilities
are restricted to their existing canonical keys. Applying either to a different
existing key is refused before changing the draft. No installed definition or seed
version is migrated by this feature.

Developers add profiles under `game_profiles` in `config/gamenest-mod-manager.php`.
Each entry has optional `aliases`, `notes`, `seed_key`, and `defaults`. A seed
reference reuses the shipped definition, with top-level defaults replacing seed
fields (nested arrays are replaced in full). This is shipped data, not the
administrator's saved definition. `GameProfileCatalog` exposes provider-neutral
search results; Game Builder consumes the same definition fields for every game.
No shared UI conditionals are needed for additional games.

### Panel acceptance after deployment

1. Run `php tests/check.php /var/www/pelican` to compile against the actual
   installed Pelican/Filament/Livewire registry.
2. Select each of the four profiles. Review every tab, including JSON provider
   metadata in Advanced, and confirm paths, package selections and behavior render.
3. Switch Rust → Minecraft → 7DTD → an ordinary Steam game. Confirm old provider
   metadata, Steam identity and runtime settings do not remain. Test manual entry
   and known-profile search with Steam unavailable.
4. Edit populated values, set exact server detection, save, reopen and export.
   Confirm changes persist. Verify existing-key collisions and Cancel/New Game.
5. On disposable servers, verify provider search, compatible version selection,
   install, reinstall and remove. For Minecraft set the real version/loader first
   and confirm the JAR remains intact. Check Eco dependencies, both Rust runtimes,
   7DTD ZIP layout and Nexus Free/Premium behavior using existing acceptance steps.

## Automatic Steam artwork

Set the definition's Steam App ID to obtain artwork automatically. This is the
store game's App ID, which can differ from a dedicated-server or Workshop App ID.
No Steam API key is needed. Game cards and the server header share this priority:

1. `artwork_url`: optional HTTPS URL or panel-relative custom image path.
2. Locally cached Steam header artwork resolved from `steam_app_id`.
3. The generic ModHarbor lighthouse illustration.

The custom URL is portable through import/export and is displayed by the browser;
the panel never downloads custom URLs. Clear it to restore automatic selection.
Existing definitions need no migration and receive the fallback until they have
a Steam App ID. No game-specific artwork mappings are used.

Steam images are cached in `storage/app/gamenest-mod-manager/artwork` for 30 days.
Failed lookups wait six hours before retrying and retain any last good image.
Only one cold/expired game is refreshed per page request, so a large new catalog
fills its cache progressively. Requests have short timeouts and reject redirects;
downloaded images are limited to 512 KiB, 4096 pixels per side, and PNG/JPEG/WebP.
SVG from Steam is rejected. Cached images are embedded from local bytes, so this
feature needs no public-storage link or Steam request from visitors' browsers.
Allow `data:` image sources in a custom Content Security Policy. Custom URLs must
also be permitted by that policy. Cache files can be removed to refresh artwork;
they contain public images, not credentials or installed-mod state.

The artwork header format follows [Steam's graphical asset documentation](https://partner.steamgames.com/doc/store/assets).
Steam's public asset endpoints can change; verify real artwork on the destination
panel during acceptance. Their failure never blocks mod management.

## Optional config rules

The Advanced tab accepts `config_rules` JSON. A matching file is discovered by the
config editor and preserved by the generic lifecycle driver. Leave blank to retain
existing defaults. An empty list explicitly disables generic config discovery.

```json
[
  {
    "root": "Mods",
    "patterns": ["settings.xml", "*.json"],
    "exclude": [".*", "*.bak*"],
    "depth": 4
  }
]
```

The root must be a declared config directory. Patterns match filenames, not paths;
depth is 0–8, with at most 32 rules and 32 patterns per list. Avoid broad `*.xml`
preservation in a directory containing versioned game data such as 7DTD ModInfo
files. Declare only editable user settings. Legacy native runtime drivers keep
their existing preservation behavior; custom discovery rules do not replace those
drivers' game-specific deployment rules.

JSON, YAML, TOML, INI and XML are validated before saving. XML rejects DTD/entity
declarations and disables network access. CFG/TXT are treated as bounded plain text;
the game remains the authority for their semantics. Configs are limited to 1 MiB.
Backups precede changes, stale editor content is rejected, and revisions are tied
to the server and exact path. Restore also backs up the current contents.
