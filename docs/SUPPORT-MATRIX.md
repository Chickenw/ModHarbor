# Support matrix

Support means an implemented integration with offline fixtures, not live acceptance
of every package. A provider must be enabled in the selected Game Builder definition
and its package format and game metadata must match. Adding a provider to a game
does not establish that its catalog contains suitable server mods.

| Source | Discovery | Install / reinstall / update / remove | Version selection | Dependencies |
|---|---|---|---|---|
| mod.io | Browse/search and tags | Yes; exact file pins | Up to 100 recent files | API dependencies and existing recipes |
| uMod | Browse/search | Yes; current upstream package | Current file only | Existing required relationships and informational suggestions |
| GitHub | Explicit repository and releases | Yes; release assets | Up to 100 assets, bounded release history | Existing declared recipes; no inferred README dependencies |
| Nexus | Recent/trending feeds; numeric ID | Eligible API access required | Up to 100 available files/variants | v1 does not provide a general dependency graph |
| CurseForge | Filtered catalog | Author distribution/access must permit | First 50 compatible returned files; exact pins supported | Required, optional, suggested, embedded/conflicting relationships |
| Modrinth | Faceted catalog | Server/loader/game-version checks | Up to 100 compatible versions | Required/optional/conflicting and version pins |
| Steam Workshop | App-specific catalog | Anonymous SteamCMD access required | Current revision only; stale pins rejected | Workshop children with complete metadata |
| 7DaysToDieMods | Public catalog and filters | Clean file plus request/wait/claim authorization | Up to 100 currently exposed clean main files | Unresolved site dependencies require manual review |
| Direct Download | Explicit public HTTPS URL | Install/reinstall/remove; no update feed | Current supplied source | Explicit recipes only |
| Upload | Private per-server upload | Install/reinstall/replace/remove | Retained source | Explicit recipes only |
| Adopted local files | Approved path scan | Enable/disable/reinstall/remove | Retained snapshot | No inferred dependencies |

All managed sources use common permissions, journaling, failure recovery, config
preservation and installed-state handling. Unsupported operations are not evidence
that a mod is current. Direct, uploaded and adopted sources report no update feed.

| Game | Existing seeded sources | Regression coverage / behavior |
|---|---|---|
| Eco | mod.io, GitHub, Upload | Required dependencies, ModKit layouts, preserved configs, stopped-server changes |
| Rust | uMod, GitHub, Direct, Upload | Carbon and Oxide; CS and ZIP; runtime paths and existing hot-reload policy |
| 7 Days to Die | GitHub, Direct, Upload | Generic ZIP layout normalization under Mods; wrapped archives and XML-containing mods |

Nexus (`domain=7daystodie`) and 7DaysToDieMods can be added through the provider
metadata fields for 7 Days to Die. Preserve the server's existing definition when
upgrading. The seeded 7 Days to Die definition starts disabled; select accurate egg
matching and enable it after review. Existing edited definitions are not replaced.

Modrinth, CurseForge and Workshop have provider fixtures using declarative example
games. They still need a compatible live game beyond the existing regression
targets. The generic archive engine accepts ZIP, not arbitrary TAR/RAR/7z packages.
Generic/Workshop managed content is limited to 32 MiB and bounded entry counts.
