# Provider settings

Open **Provider Settings** as a Pelican root administrator. Every registered source
has a connection card. Enter or edit credentials, then Save. Stored secrets are
encrypted with Pelican's application key. Inputs are empty and masked after loading
or saving; a blank secret keeps its saved value. Reveal explicitly loads the saved
value for the administrator; Hide clears that browser value. Do not screen-share
revealed credentials. Environment configuration remains a fallback.

Test Connection checks the current draft values, reusing saved secrets for blank
fields. It does not save the draft. Statuses are Configured, Not configured,
Connected, Failed or Not applicable. A successful check proves the API probe only;
it does not prove a game's metadata, package compatibility, premium entitlement or
SteamCMD download permission. Refreshing the page resets transient test statuses.
Raw response bodies, credentials and exception chains are excluded from probe
results and notifications.

| Provider | Setup | Connection check |
|---|---|---|
| mod.io | API key and/or access token; official HTTPS API base | Read one game |
| Nexus | API key | Validate API user; returned personal information is discarded |
| CurseForge | API key | Read one game |
| Modrinth | Optional token | Current user with token; public categories without token |
| GitHub | Optional token | Current user with token; rate limit metadata without token |
| Steam Workshop | Web API key; dedicated SteamCMD root | API reachability only; does not certify the key's permissions or execute SteamCMD |
| uMod | None | Public plugin search |
| 7DaysToDieMods | None | Public catalog |
| Direct Download / Upload | No global connection | Not applicable |

mod.io bases are restricted to `https://api.mod.io/v1`,
`https://api.test.mod.io/v1`, or the official game-specific
`https://g-<game-id>.modapi.io/v1` form. Credentials cannot be sent to custom hosts.
Authenticated API requests do not follow redirects. Package downloads validate
public addresses and redirects independently.

Provider APIs impose restrictions. Nexus v1 text search filters its selected feed;
numeric IDs offer exact lookup. Premium/eligible access may be needed for downloads.
CurseForge distribution opt-outs are enforced. Workshop supports public anonymous
downloads only and cannot retrieve an old replaced revision. 7DaysToDieMods requires
clean scan status and respects its download wait; unresolved site dependencies are
rejected. Use an author-approved package upload when an API cannot supply a file.

Versions shows bounded compatible file choices with filename and release ID.
Select the correct server variant. Exact version identity is rechecked during
deployment. A historical release whose dependency graph differs from the resolved
graph is rejected safely; this is not an automatic migration between incompatible
dependency trees. Reinstall uses the manifest's original pin.

Upstream references: [mod.io](https://docs.mod.io/restapi/introduction),
[Nexus official client](https://github.com/Nexus-Mods/node-nexus-api),
[CurseForge API](https://docs.curseforge.com/rest-api/),
[Modrinth API](https://docs.modrinth.com/api/),
[Steam API](https://partner.steamgames.com/doc/webapi/IPublishedFileService).
