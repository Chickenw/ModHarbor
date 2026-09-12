<x-filament-panels::page>
    @if ($versionKey !== '')
        <section class="gnmm-notice" role="region" aria-label="Package versions" tabindex="-1" x-data x-on:modharbor-versions-opened.window="$nextTick(() => { $el.scrollIntoView({behavior: 'smooth', block: 'center'}); $el.focus(); })">
            <strong>Choose a package version</strong>
            <button type="button" class="gnmm-btn gnmm-btn-secondary" wire:click="closePackageVersions">Close</button>
            <p>
                Choose an available package file. Check the filename for the correct
                server variant. Dependencies and compatibility are validated again
                before deployment.
            </p>

            <div class="gnmm-version-picker">
                <select
                    wire:model.change="selectedPackageVersion"
                    aria-label="Package version"
                    class="gnmm-input"
                >
                    <option value="">Select a file</option>

                    @foreach ($packageVersions as $packageVersion)
                        <option value="{{ $packageVersion['id'] }}">
                            {{ $packageVersion['version'] }} - {{ $packageVersion['filename'] }}
                        </option>
                    @endforeach
                </select>

                <button
                    type="button"
                    class="gnmm-btn gnmm-btn-primary"
                    wire:click="applyPackageVersion"
                    wire:confirm="Install this exact release? Existing configs will be preserved. Review compatibility before continuing."
                    wire:loading.attr="disabled"

                >
                    Install selected version
                </button>
            </div>
        </section>
    @endif
    @php
        try { $restartChanges = $this->pendingRestarts(); } catch (\Throwable) { $restartChanges = null; }
    @endphp
    @if ($restartChanges === null)
        <div role="alert">Restart status is unavailable. Check History before starting the server.</div>
    @elseif (count($restartChanges))
        <div class="gnmm-card" role="status" style="padding:16px; margin-bottom:16px; border:1px solid #d97706; border-radius:12px">
            <strong>Start required · {{ count($restartChanges) }} pending change(s)</strong>
            <p>Finish your mod and config changes, then start the server once. Confirm after it is running.</p>
            <button type="button" wire:click="confirmServerStarted" wire:confirm="Confirm that you started the server after these changes?" wire:loading.attr="disabled">Confirm server started</button>
        </div>
    @endif

    @php
        $configuredGame = app(\GameNest\GameNestModManager\Services\AdapterRegistry::class)->forServer($server);
    @endphp
    @if ($configuredGame instanceof \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter)
        <p class="rounded-xl border p-3">Game definition: {{ $configuredGame->name() }}.
            {{ $configuredGame->definition()['behavior']['install_while_running'] ? 'Mod changes while running are allowed by the administrator.' : 'Stop the server before changing mods.' }}
            {{ $configuredGame->definition()['behavior']['restart_required'] ? 'Start or restart the server after mod changes.' : 'This definition does not require a restart.' }}
        </p>
    @endif

<style>
.gnmm {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.gnmm-version-picker {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
    flex-wrap: wrap;
}

.gnmm-version-picker select {
    flex: 1 1 420px;
    min-width: 240px;
    max-width: 760px;
}

.gnmm-version-picker .gnmm-btn {
    flex: 0 0 auto;
}

.gnmm-card {
    border: 1px solid rgba(148, 163, 184, .28);
    border-radius: 12px;
    background: var(--gray-50, #fff);
    overflow: hidden;
}

.dark .gnmm-card {
    background: rgba(17, 24, 39, .55);
    border-color: rgba(255, 255, 255, .10);
}

.gnmm-header {
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.gnmm-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
}

.gnmm-icon-box {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(99, 102, 241, .10);
    flex: 0 0 auto;
}

.gnmm-icon-box svg {
    width: 22px !important;
    height: 22px !important;
}

.gnmm-title {
    font-size: 17px;
    line-height: 1.3;
    font-weight: 700;
    margin: 0;
}

.gnmm-subtitle {
    margin-top: 3px;
    font-size: 13px;
    color: #6b7280;
}

.dark .gnmm-subtitle {
    color: #9ca3af;
}

.gnmm-providers {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.gnmm-badge {
    display: inline-flex;
    align-items: center;
    min-height: 25px;
    padding: 4px 9px;
    border-radius: 7px;
    background: rgba(148, 163, 184, .13);
    font-size: 11px;
    font-weight: 600;
}

.gnmm-tabs {
    display: flex;
    overflow-x: auto;
    border-bottom: 1px solid rgba(148, 163, 184, .25);
}

.gnmm-tab {
    border: 0;
    border-bottom: 2px solid transparent;
    background: transparent;
    padding: 13px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    color: #6b7280;
}

.dark .gnmm-tab {
    color: #9ca3af;
}

.gnmm-tab:hover {
    color: #111827;
}

.dark .gnmm-tab:hover {
    color: #fff;
}

.gnmm-tab-active {
    color: rgb(79, 70, 229);
    border-bottom-color: rgb(79, 70, 229);
}

.dark .gnmm-tab-active {
    color: rgb(129, 140, 248);
}

.gnmm-content {
    padding: 20px;
}

.gnmm-section-title {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
}

.gnmm-section-subtitle {
    margin: 4px 0 0;
    font-size: 13px;
    color: #6b7280;
}

.dark .gnmm-section-subtitle {
    color: #9ca3af;
}

.gnmm-search {
    display: flex;
    gap: 10px;
    margin-top: 18px;
    flex-wrap: wrap;
}

.gnmm-search-input {
    flex: 1 1 320px;
    min-width: 220px;
    height: 40px;
    border-radius: 9px;
    border: 1px solid #d1d5db;
    padding: 0 12px;
    font-size: 13px;
    background: #fff;
    color: #111827;
}

.dark .gnmm-search-input {
    background: rgba(255,255,255,.05);
    border-color: rgba(255,255,255,.12);
    color: #fff;
}

.gnmm-btn {
    min-height: 40px;
    border-radius: 9px;
    border: 1px solid transparent;
    padding: 8px 15px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
}

.gnmm-btn svg {
    width: 16px !important;
    height: 16px !important;
}

.gnmm-btn-primary {
    background: rgb(79, 70, 229);
    color: #fff;
}

.gnmm-btn-primary:hover {
    background: rgb(67, 56, 202);
}

.gnmm-btn-secondary {
    border-color: #d1d5db;
    background: transparent;
    color: inherit;
}

.dark .gnmm-btn-secondary {
    border-color: rgba(255,255,255,.14);
}

.gnmm-btn-disabled {
    background: rgba(148, 163, 184, .14);
    color: #9ca3af;
    cursor: default;
}

.gnmm-empty {
    margin-top: 18px;
    min-height: 170px;
    border: 1px dashed rgba(148, 163, 184, .45);
    border-radius: 11px;
    padding: 30px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.gnmm-empty svg {
    width: 34px !important;
    height: 34px !important;
    color: #9ca3af;
}

.gnmm-empty-title {
    margin-top: 10px;
    font-size: 13px;
    font-weight: 700;
}

.gnmm-empty-text {
    margin-top: 4px;
    font-size: 12px;
    color: #6b7280;
    max-width: 560px;
}

.dark .gnmm-empty-text {
    color: #9ca3af;
}

.gnmm-grid {
    margin-top: 18px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.gnmm-mod-card {
    border: 1px solid rgba(148, 163, 184, .28);
    border-radius: 11px;
    overflow: hidden;
    background: rgba(255,255,255,.55);
}

.dark .gnmm-mod-card {
    background: rgba(0,0,0,.12);
    border-color: rgba(255,255,255,.10);
}

.gnmm-mod-image {
    width: 100%;
    height: 145px;
    object-fit: cover;
    display: block;
}

.gnmm-mod-body {
    padding: 16px;
}

.gnmm-mod-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.gnmm-mod-name {
    font-size: 14px;
    font-weight: 700;
}

.gnmm-mod-author {
    margin-top: 2px;
    color: #6b7280;
    font-size: 11px;
}

.dark .gnmm-mod-author {
    color: #9ca3af;
}

.gnmm-installed-badge {
    display: inline-flex;
    padding: 4px 8px;
    border-radius: 7px;
    background: rgba(34,197,94,.12);
    color: rgb(22,163,74);
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}

.gnmm-summary {
    margin-top: 10px;
    font-size: 12px;
    line-height: 1.45;
    color: #4b5563;
}

.dark .gnmm-summary {
    color: #d1d5db;
}

.gnmm-meta {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 5px 14px;
    color: #6b7280;
    font-size: 10px;
}

.dark .gnmm-meta {
    color: #9ca3af;
}

.gnmm-actions {
    margin-top: 15px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.gnmm-link {
    font-size: 12px;
    color: #6b7280;
    text-decoration: none;
}

.gnmm-link:hover {
    color: rgb(79,70,229);
}

.gnmm-installed-list {
    margin-top: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.gnmm-installed-row {
    border: 1px solid rgba(148,163,184,.28);
    border-radius: 10px;
    padding: 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.gnmm-installed-name {
    font-size: 13px;
    font-weight: 700;
}


.gnmm-health-grid {
    margin-top: 16px;
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 8px;
}

.gnmm-health-stat {
    border: 1px solid rgba(148,163,184,.26);
    border-radius: 9px;
    padding: 10px 11px;
    background: rgba(148,163,184,.06);
}

.gnmm-health-label {
    font-size: 10px;
    color: #6b7280;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
}

.dark .gnmm-health-label {
    color: #9ca3af;
}

.gnmm-health-value {
    margin-top: 3px;
    font-size: 14px;
    font-weight: 800;
}

.gnmm-health-ok {
    color: rgb(22,163,74);
}

.gnmm-health-warn {
    color: rgb(217,119,6);
}

.gnmm-health-bad {
    color: rgb(220,38,38);
}

.gnmm-health-actions {
    margin-top: 12px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.gnmm-details-panel {
    margin-top: 14px;
    padding: 14px;
    border: 1px solid rgba(99,102,241,.28);
    border-radius: 10px;
    background: rgba(99,102,241,.045);
}

.gnmm-details-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 10px;
    margin-top: 12px;
}

.gnmm-details-item {
    border: 1px solid rgba(148,163,184,.22);
    border-radius: 8px;
    padding: 9px 10px;
}

.gnmm-details-list {
    margin-top: 10px;
    font-size: 11px;
    line-height: 1.55;
    color: #6b7280;
}

.dark .gnmm-details-list {
    color: #9ca3af;
}

.gnmm-history-filters {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 14px;
}

.gnmm-history-filters select {
    min-width: 150px;
}

@media (max-width: 1000px) {
    .gnmm-health-grid {
        grid-template-columns: repeat(3, minmax(0,1fr));
    }

    .gnmm-details-grid {
        grid-template-columns: repeat(2, minmax(0,1fr));
    }
}

@media (max-width: 640px) {
    .gnmm-health-grid,
    .gnmm-details-grid {
        grid-template-columns: 1fr 1fr;
    }
}

.gnmm-footer {
    text-align: center;
    font-size: 11px;
    color: #9ca3af;
}


.gnmm-settings {
    margin-top: 16px;
    border: 1px solid rgba(148,163,184,.28);
    border-radius: 10px;
    overflow: hidden;
}

.gnmm-settings-summary {
    cursor: pointer;
    list-style: none;
    padding: 12px 14px;
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.gnmm-settings-summary::-webkit-details-marker {
    display: none;
}

.gnmm-settings-body {
    border-top: 1px solid rgba(148,163,184,.22);
    padding: 14px;
}

.gnmm-settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.gnmm-field-full {
    grid-column: 1 / -1;
}

.gnmm-label {
    display: block;
    margin-bottom: 5px;
    font-size: 11px;
    font-weight: 700;
}

.gnmm-input {
    width: 100%;
    height: 39px;
    border-radius: 8px;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #111827;
    padding: 0 11px;
    font-size: 12px;
}

.dark .gnmm-input {
    background: rgba(255,255,255,.05);
    border-color: rgba(255,255,255,.12);
    color: #fff;
}


/*
 * Native select dropdowns need explicit option colors.
 * Windows/Chrome may otherwise render white option text
 * against the browser's white dropdown background.
 */
.gnmm-input option {
    background: #ffffff;
    color: #111827;
}

.dark .gnmm-input option {
    background: #111827;
    color: #ffffff;
}

.dark select.gnmm-input {
    color-scheme: dark;
}

.gnmm-field-help {
    margin-top: 5px;
    font-size: 10px;
    color: #6b7280;
}

.dark .gnmm-field-help {
    color: #9ca3af;
}

.gnmm-settings-actions {
    margin-top: 14px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 9px;
}

.gnmm-status {
    margin-left: auto;
    font-size: 11px;
    font-weight: 700;
    color: #6b7280;
}

.dark .gnmm-status {
    color: #9ca3af;
}

@media (max-width: 850px) {
    .gnmm-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {
    .gnmm-content {
        padding: 15px;
    }

    .gnmm-header {
        padding: 15px;
    }

    .gnmm-tabs {
        width: 100%;
    }
}
/* Compact cards retain the plugin's self-contained Pelican styling. */
.gnmm-mod-card { display:flex; align-items:flex-start; }
.gnmm-mod-image { width:64px; height:64px; margin:12px 0 12px 12px; border-radius:7px; flex:0 0 auto; }
.gnmm-mod-body { padding:12px; flex:1; min-width:0; }
.gnmm-grid { gap:10px; margin-top:14px; }
.gnmm-summary { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin-top:6px; }
.gnmm-meta { margin-top:7px; gap:4px 10px; }
.gnmm-actions { margin-top:10px; flex-wrap:wrap; }
.gnmm-mod-card .gnmm-btn, .gnmm-row-actions .gnmm-btn { min-height:30px; padding:5px 9px; font-size:12px; }
.gnmm-row-actions { display:flex; flex-wrap:wrap; gap:7px; align-items:center; }
.gnmm-installed-row { flex-wrap:wrap; }
.gnmm-btn:disabled { opacity:.5; cursor:wait; }
.gnmm-btn:focus-visible, .gnmm-tab:focus-visible { outline:2px solid #818cf8; outline-offset:3px; }
.gnmm-btn-danger { color:#dc2626; border-color:rgba(220,38,38,.4); background:transparent; }
.gnmm-disabled-badge { color:inherit; background:rgba(148,163,184,.16); }
.gnmm-notice { padding:14px; margin-bottom:16px; border:1px solid #a78bfa; border-radius:10px; }
.gnmm-notice p { margin:6px 0 12px; font-size:13px; }
.gnmm-history-message { font-size:12px; margin-top:6px; }

.gnmm-history-timings {
    margin: 10px 0;
    padding: 10px 12px;
    border: 1px solid rgba(148, 163, 184, .22);
    border-radius: 8px;
    font-size: 12px;
}

.gnmm-history-timing {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding-top: 5px;
}

.gnmm-history-timing span:last-child {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
@media(max-width:600px) { .gnmm-settings-grid { grid-template-columns:1fr; } .gnmm-mod-image { width:48px; height:48px; } }

.gnmm-config-layout {
    display:grid;
    grid-template-columns:minmax(240px,320px) minmax(0,1fr);
    gap:14px;
    align-items:start;
}

.gnmm-config-list,
.gnmm-config-editor {
    min-width:0;
    padding:12px;
    border:1px solid rgba(148,163,184,.25);
    border-radius:10px;
    background:rgba(255,255,255,.02);
}

.gnmm-section-heading {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    margin-bottom:10px;
}

.gnmm-config-item {
    display:block;
    width:100%;
    margin-top:7px;
    padding:9px 10px;
    text-align:left;
    cursor:pointer;
    border:1px solid rgba(148,163,184,.22);
    border-radius:8px;
    background:transparent;
}

.gnmm-config-item:hover,
.gnmm-config-item-active {
    border-color:rgba(99,102,241,.6);
    background:rgba(99,102,241,.08);
}

.gnmm-config-name {
    display:block;
    font-size:13px;
    font-weight:600;
}

.gnmm-config-path {
    display:block;
    margin-top:2px;
    overflow:hidden;
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
    font-size:11px;
    white-space:nowrap;
    text-overflow:ellipsis;
    opacity:.7;
}

.gnmm-config-size {
    display:block;
    margin-top:5px;
    font-size:11px;
    opacity:.6;
}

.gnmm-config-heading-text {
    min-width:0;
}

.gnmm-config-textarea {
    display:block;
    width:100%;
    min-height:520px;
    resize:vertical;
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
    font-size:12px;
    line-height:1.55;
    white-space:pre;
    tab-size:4;
}

.gnmm-config-empty {
    min-height:180px;
}

@media (max-width:900px) {
    .gnmm-config-layout {
        grid-template-columns:1fr;
    }

    .gnmm-config-textarea {
        min-height:380px;
    }
}


/* ==========================================================
   GameNest Installed Mod Manager - compact dark table
   ========================================================== */

.gnmm-installed-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.gnmm-installed-count {
    flex: 0 0 auto;
    padding: .4rem .7rem;
    border: 1px solid rgba(148, 163, 184, .18);
    border-radius: .55rem;
    color: rgb(148, 163, 184);
    font-size: .78rem;
    background: rgba(15, 23, 42, .35);
}

.gnmm-update-summary {
    color: rgb(248, 113, 113);
    font-weight: 600;
}

.gnmm-installed-toolbar {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) 190px auto;
    gap: .75rem;
    align-items: center;
    margin-bottom: 1rem;
}

.gnmm-installed-search {
    position: relative;
    display: flex;
    align-items: center;
}

.gnmm-installed-search > svg {
    position: absolute;
    left: .9rem;
    width: 1.15rem;
    height: 1.15rem;
    color: rgb(148, 163, 184);
    pointer-events: none;
}

.gnmm-installed-search input,
.gnmm-installed-sort {
    width: 100%;
    min-height: 2.75rem;
    border: 1px solid rgba(96, 165, 250, .26);
    border-radius: .6rem;
    background: rgba(15, 23, 42, .55);
    color: rgb(226, 232, 240);
    outline: none;
}

.gnmm-installed-search input {
    padding: .65rem .9rem .65rem 2.75rem;
}

.gnmm-installed-sort {
    padding: .65rem .8rem;
}

.gnmm-installed-search input:focus,
.gnmm-installed-sort:focus {
    border-color: rgba(59, 130, 246, .7);
    box-shadow: 0 0 0 1px rgba(59, 130, 246, .25);
}

.gnmm-check-btn {
    min-height: 2.75rem;
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    white-space: nowrap;
}

.gnmm-check-btn svg {
    width: 1rem;
    height: 1rem;
}

.gnmm-mod-table {
    overflow: hidden;
    border: 1px solid rgba(148, 163, 184, .12);
    border-radius: .7rem;
    background: rgba(2, 6, 23, .16);
}

.gnmm-mod-table-head,
.gnmm-mod-row {
    display: grid;
    grid-template-columns:
        minmax(270px, 2.2fr)
        minmax(115px, .85fr)
        minmax(85px, .65fr)
        minmax(85px, .65fr)
        minmax(145px, 1fr)
        minmax(150px, auto);
    gap: .9rem;
    align-items: center;
}

.gnmm-mod-table-head {
    padding: .75rem 1rem;
    background: rgba(30, 41, 59, .48);
    color: rgb(203, 213, 225);
    font-size: .76rem;
    font-weight: 700;
    border-bottom: 1px solid rgba(148, 163, 184, .12);
}

.gnmm-actions-heading {
    text-align: right;
}

.gnmm-mod-row {
    position: relative;
    padding: .78rem 1rem;
    border-bottom: 1px solid rgba(148, 163, 184, .1);
    transition:
        background-color .15s ease,
        border-color .15s ease;
}

.gnmm-mod-row:last-child {
    border-bottom: 0;
}

.gnmm-mod-row:hover {
    background: rgba(30, 41, 59, .28);
}

.gnmm-mod-row-update {
    background:
        linear-gradient(
            90deg,
            rgba(127, 29, 29, .17),
            rgba(30, 41, 59, .07) 48%
        );
}

.gnmm-mod-row-update:hover {
    background:
        linear-gradient(
            90deg,
            rgba(127, 29, 29, .23),
            rgba(30, 41, 59, .15) 48%
        );
}

.gnmm-mod-main {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: .75rem;
}

.gnmm-mod-avatar {
    width: 2.75rem;
    height: 2.75rem;
    flex: 0 0 2.75rem;
    display: grid;
    place-items: center;
    border: 1px solid rgba(96, 165, 250, .25);
    border-radius: .55rem;
    background:
        linear-gradient(
            145deg,
            rgba(37, 99, 235, .38),
            rgba(15, 23, 42, .9)
        );
    color: rgb(219, 234, 254);
    font-weight: 800;
    font-size: 1rem;
}

.gnmm-mod-copy {
    min-width: 0;
}

.gnmm-mod-name-line {
    display: flex;
    align-items: center;
    gap: .45rem;
    min-width: 0;
    color: rgb(241, 245, 249);
    font-size: .88rem;
}

.gnmm-mod-name-line strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.gnmm-mod-description {
    margin-top: .2rem;
    color: rgb(148, 163, 184);
    font-size: .74rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.gnmm-provider-cell {
    min-width: 0;
    display: flex;
    flex-direction: column;
    color: rgb(203, 213, 225);
    font-size: .78rem;
}

.gnmm-provider-cell span {
    margin-top: .1rem;
    color: rgb(100, 116, 139);
    font-size: .7rem;
}

.gnmm-version-cell {
    color: rgb(203, 213, 225);
    font-size: .8rem;
}

.gnmm-version-new {
    color: rgb(248, 113, 113);
    font-weight: 700;
}

.gnmm-status-pill {
    width: fit-content;
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    padding: .3rem .55rem;
    border-radius: 999px;
    border: 1px solid transparent;
    font-size: .7rem;
    font-weight: 700;
    white-space: nowrap;
}

.gnmm-status-pill svg {
    width: .85rem;
    height: .85rem;
}

.gnmm-status-current {
    color: rgb(52, 211, 153);
    background: rgba(6, 78, 59, .24);
    border-color: rgba(52, 211, 153, .2);
}

.gnmm-status-update {
    color: rgb(248, 113, 113);
    background: rgba(127, 29, 29, .25);
    border-color: rgba(248, 113, 113, .24);
}

.gnmm-status-disabled {
    color: rgb(96, 165, 250);
    background: rgba(30, 64, 175, .22);
    border-color: rgba(96, 165, 250, .2);
}

.gnmm-status-dependency {
    color: rgb(203, 213, 225);
    background: rgba(71, 85, 105, .3);
    border-color: rgba(148, 163, 184, .2);
    font-size: .62rem;
}

.gnmm-compact-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: .42rem;
}

.gnmm-icon-btn {
    width: 2.25rem;
    height: 2.25rem;
    display: inline-grid;
    place-items: center;
    border: 1px solid rgba(148, 163, 184, .2);
    border-radius: .5rem;
    background: rgba(51, 65, 85, .54);
    color: rgb(203, 213, 225);
    cursor: pointer;
    transition:
        transform .12s ease,
        background-color .12s ease,
        border-color .12s ease;
}

.gnmm-icon-btn:hover {
    transform: translateY(-1px);
    background: rgba(71, 85, 105, .68);
    border-color: rgba(148, 163, 184, .34);
}

.gnmm-icon-btn svg {
    width: 1.05rem;
    height: 1.05rem;
}

.gnmm-icon-btn-update {
    color: white;
    background: rgba(37, 99, 235, .92);
    border-color: rgba(96, 165, 250, .5);
}

.gnmm-icon-btn-update:hover {
    background: rgba(37, 99, 235, 1);
}

.gnmm-icon-btn-danger {
    color: rgb(254, 202, 202);
    background: rgba(185, 28, 28, .7);
    border-color: rgba(248, 113, 113, .35);
}

.gnmm-icon-btn-danger:hover {
    background: rgba(220, 38, 38, .86);
}

.gnmm-installed-footer {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-top: .75rem;
    color: rgb(148, 163, 184);
    font-size: .72rem;
}

.gnmm-status-legend {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .75rem;
    margin-top: 1rem;
    padding: .9rem 1rem;
    border: 1px solid rgba(148, 163, 184, .12);
    border-radius: .7rem;
    background: rgba(15, 23, 42, .35);
}

.gnmm-status-legend > div {
    display: flex;
    align-items: flex-start;
    gap: .55rem;
    min-width: 0;
}

.gnmm-status-legend span:last-child {
    display: flex;
    flex-direction: column;
    color: rgb(148, 163, 184);
    font-size: .66rem;
}

.gnmm-status-legend strong {
    margin-bottom: .12rem;
    color: rgb(226, 232, 240);
    font-size: .72rem;
}

.gnmm-legend-dot {
    width: .7rem;
    height: .7rem;
    margin-top: .2rem;
    flex: 0 0 .7rem;
    border-radius: 999px;
}

.gnmm-legend-current {
    background: rgb(52, 211, 153);
}

.gnmm-legend-update {
    background: rgb(248, 113, 113);
}

.gnmm-legend-disabled {
    background: rgb(59, 130, 246);
}

.gnmm-legend-dependency {
    background: rgb(148, 163, 184);
}

@media (max-width: 1100px) {
    .gnmm-mod-table {
        overflow-x: auto;
    }

    .gnmm-mod-table-head,
    .gnmm-mod-row {
        min-width: 920px;
    }

    .gnmm-status-legend {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .gnmm-installed-toolbar {
        grid-template-columns: 1fr;
    }

    .gnmm-installed-header,
    .gnmm-installed-footer {
        flex-direction: column;
    }

    .gnmm-status-legend {
        grid-template-columns: 1fr;
    }
}


/* GameNest visual pop pass */

/* Main installed area gets a little more depth */
.gnmm-installed-header {
    position: relative;
    padding: .15rem 0 .55rem 1rem;
}

.gnmm-installed-header::before {
    content: "";
    position: absolute;
    left: 0;
    top: .15rem;
    bottom: .55rem;
    width: 3px;
    border-radius: 999px;
    background: linear-gradient(
        180deg,
        rgb(59, 130, 246),
        rgb(99, 102, 241)
    );
    box-shadow: 0 0 16px rgba(59, 130, 246, .45);
}

.gnmm-section-title {
    letter-spacing: -.015em;
}

.gnmm-installed-count {
    color: rgb(191, 219, 254);
    border-color: rgba(59, 130, 246, .28);
    background:
        linear-gradient(
            145deg,
            rgba(37, 99, 235, .13),
            rgba(15, 23, 42, .55)
        );
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.03),
        0 6px 20px rgba(0,0,0,.12);
}


/* Toolbar */
.gnmm-installed-toolbar {
    padding: .8rem;
    border: 1px solid rgba(59, 130, 246, .13);
    border-radius: .75rem;
    background:
        linear-gradient(
            135deg,
            rgba(30, 41, 59, .38),
            rgba(15, 23, 42, .18)
        );
}

.gnmm-installed-search input,
.gnmm-installed-sort {
    background:
        linear-gradient(
            180deg,
            rgba(15, 23, 42, .88),
            rgba(15, 23, 42, .58)
        );
    border-color: rgba(96, 165, 250, .24);
}

.gnmm-installed-search input:hover,
.gnmm-installed-sort:hover {
    border-color: rgba(96, 165, 250, .42);
}

.gnmm-installed-search input:focus,
.gnmm-installed-sort:focus {
    border-color: rgb(59, 130, 246);
    box-shadow:
        0 0 0 1px rgba(59, 130, 246, .25),
        0 0 18px rgba(37, 99, 235, .12);
}


/* Main table */
.gnmm-mod-table {
    border-color: rgba(59, 130, 246, .13);
    background:
        linear-gradient(
            160deg,
            rgba(15, 23, 42, .44),
            rgba(2, 6, 23, .22)
        );
    box-shadow:
        0 14px 35px rgba(0, 0, 0, .18),
        inset 0 1px 0 rgba(255,255,255,.02);
}

.gnmm-mod-table-head {
    background:
        linear-gradient(
            90deg,
            rgba(30, 58, 95, .48),
            rgba(30, 41, 59, .58)
        );
    color: rgb(219, 234, 254);
    border-bottom-color: rgba(96, 165, 250, .18);
}


/* Rows */
.gnmm-mod-row {
    position: relative;
    transition:
        background .16s ease,
        transform .16s ease,
        box-shadow .16s ease;
}

.gnmm-mod-row::before {
    content: "";
    position: absolute;
    left: 0;
    top: 10px;
    bottom: 10px;
    width: 2px;
    border-radius: 999px;
    background: transparent;
    transition: background .16s ease, box-shadow .16s ease;
}

.gnmm-mod-row:hover {
    background:
        linear-gradient(
            90deg,
            rgba(37, 99, 235, .10),
            rgba(30, 41, 59, .19) 55%,
            transparent
        );
}

.gnmm-mod-row:hover::before {
    background: rgb(59, 130, 246);
    box-shadow: 0 0 11px rgba(59, 130, 246, .7);
}


/* Update rows get their own attention treatment */
.gnmm-mod-row-update {
    background:
        linear-gradient(
            90deg,
            rgba(127, 29, 29, .19),
            rgba(59, 130, 246, .035) 58%,
            transparent
        );
}

.gnmm-mod-row-update::before {
    background: rgb(248, 113, 113);
    box-shadow: 0 0 11px rgba(248, 113, 113, .45);
}


/* Mod avatar tile */
.gnmm-mod-avatar {
    position: relative;
    overflow: hidden;
    border-color: rgba(96, 165, 250, .42);
    background:
        radial-gradient(
            circle at 30% 20%,
            rgba(96, 165, 250, .48),
            transparent 48%
        ),
        linear-gradient(
            145deg,
            rgba(37, 99, 235, .65),
            rgba(30, 64, 175, .33) 55%,
            rgba(15, 23, 42, .96)
        );
    box-shadow:
        0 5px 14px rgba(0, 0, 0, .22),
        inset 0 1px 0 rgba(255,255,255,.09);
    text-shadow: 0 1px 4px rgba(0,0,0,.5);
}

.gnmm-mod-avatar::after {
    content: "";
    position: absolute;
    inset: 0;
    background:
        linear-gradient(
            135deg,
            rgba(255,255,255,.10),
            transparent 38%
        );
    pointer-events: none;
}


/* Names/provider */
.gnmm-mod-name-line strong {
    color: rgb(248, 250, 252);
}

.gnmm-mod-row:hover .gnmm-mod-name-line strong {
    color: rgb(219, 234, 254);
}

.gnmm-provider-cell strong {
    color: rgb(147, 197, 253);
}

.gnmm-provider-cell span {
    color: rgb(96, 165, 250);
    opacity: .72;
}


/* Version columns */
.gnmm-version-cell {
    font-variant-numeric: tabular-nums;
    font-weight: 600;
}

.gnmm-version-new {
    color: rgb(252, 165, 165);
    text-shadow: 0 0 10px rgba(248, 113, 113, .15);
}


/* Status pills */
.gnmm-status-pill {
    box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
}

.gnmm-status-current {
    background:
        linear-gradient(
            180deg,
            rgba(6, 95, 70, .34),
            rgba(6, 78, 59, .20)
        );
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.035),
        0 0 12px rgba(52, 211, 153, .06);
}

.gnmm-status-update {
    background:
        linear-gradient(
            180deg,
            rgba(153, 27, 27, .38),
            rgba(127, 29, 29, .22)
        );
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.04),
        0 0 15px rgba(248, 113, 113, .10);
}

.gnmm-status-dependency {
    background:
        linear-gradient(
            180deg,
            rgba(71, 85, 105, .46),
            rgba(51, 65, 85, .24)
        );
}


/* Action buttons feel more like real controls */
.gnmm-icon-btn {
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.04),
        0 3px 8px rgba(0,0,0,.12);
}

.gnmm-icon-btn:hover {
    color: white;
    border-color: rgba(96, 165, 250, .48);
    background:
        linear-gradient(
            180deg,
            rgba(71, 85, 105, .84),
            rgba(51, 65, 85, .64)
        );
    box-shadow:
        0 5px 12px rgba(0,0,0,.18),
        0 0 10px rgba(59, 130, 246, .07);
}

.gnmm-icon-btn-update {
    background:
        linear-gradient(
            180deg,
            rgb(59, 130, 246),
            rgb(37, 99, 235)
        );
    box-shadow:
        0 4px 12px rgba(37, 99, 235, .28),
        inset 0 1px 0 rgba(255,255,255,.15);
}

.gnmm-icon-btn-danger {
    background:
        linear-gradient(
            180deg,
            rgba(220, 38, 38, .90),
            rgba(153, 27, 27, .82)
        );
}


/* Footer */
.gnmm-installed-footer {
    padding: 0 .15rem;
}


/* Legend becomes four actual mini-cards */
.gnmm-status-legend {
    padding: .75rem;
    border-color: rgba(59, 130, 246, .12);
    background:
        linear-gradient(
            145deg,
            rgba(15, 23, 42, .45),
            rgba(2, 6, 23, .17)
        );
}

.gnmm-status-legend > div {
    padding: .7rem .75rem;
    border: 1px solid rgba(148, 163, 184, .09);
    border-radius: .55rem;
    background: rgba(30, 41, 59, .20);
    transition:
        background .15s ease,
        border-color .15s ease,
        transform .15s ease;
}

.gnmm-status-legend > div:hover {
    transform: translateY(-1px);
    border-color: rgba(96, 165, 250, .18);
    background: rgba(30, 41, 59, .34);
}

.gnmm-legend-dot {
    box-shadow: 0 0 9px currentColor;
}

.gnmm-legend-current {
    color: rgb(52, 211, 153);
}

.gnmm-legend-update {
    color: rgb(248, 113, 113);
}

.gnmm-legend-disabled {
    color: rgb(59, 130, 246);
}

.gnmm-legend-dependency {
    color: rgb(148, 163, 184);
}


/* Check Updates gets a bit more GameNest identity */
.gnmm-check-btn {
    border-color: rgba(59, 130, 246, .24);
    background:
        linear-gradient(
            180deg,
            rgba(30, 64, 175, .25),
            rgba(30, 41, 59, .42)
        );
}

.gnmm-check-btn:hover {
    border-color: rgba(96, 165, 250, .48);
    background:
        linear-gradient(
            180deg,
            rgba(37, 99, 235, .34),
            rgba(30, 41, 59, .52)
        );
}


/* GameNest installed artwork */

.gnmm-mod-avatar {
    padding: 0;
}

.gnmm-mod-avatar img {
    position: relative;
    z-index: 1;
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
    border-radius: inherit;
}

.gnmm-mod-avatar span {
    position: relative;
    z-index: 1;
}

.gnmm-mod-avatar:has(img) {
    background: rgb(15, 23, 42);
    border-color: rgba(148, 163, 184, .26);
}

.gnmm-mod-avatar:has(img)::after {
    background:
        linear-gradient(
            145deg,
            rgba(255,255,255,.08),
            transparent 42%
        );
}

.gnmm-mod-profile-link {
    min-width: 0;
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    overflow: hidden;
    color: rgb(248, 250, 252);
    font-weight: 700;
    text-decoration: none;
    transition: color .14s ease;
}

.gnmm-mod-profile-link:hover {
    color: rgb(96, 165, 250);
}

.gnmm-mod-profile-link svg {
    width: .78rem;
    height: .78rem;
    flex: 0 0 .78rem;
    color: rgb(96, 165, 250);
}

.gnmm-mod-description {
    max-width: 34rem;
}


/* GameNest premium brand header */

.gnmm-brand-header {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    min-height: 5.4rem;
    padding: 1.05rem 1.25rem !important;

    border-color: rgba(59, 130, 246, .52) !important;

    background:
        radial-gradient(
            circle at 14% 12%,
            rgba(14, 165, 233, .19),
            transparent 28%
        ),
        radial-gradient(
            circle at 76% -25%,
            rgba(79, 70, 229, .31),
            transparent 46%
        ),
        linear-gradient(
            110deg,
            rgba(8, 47, 73, .90),
            rgba(15, 23, 42, .96) 36%,
            rgba(30, 27, 75, .92) 72%,
            rgba(15, 23, 42, .96)
        ) !important;

    box-shadow:
        0 0 0 1px rgba(37, 99, 235, .12),
        0 10px 35px rgba(0, 0, 0, .22),
        0 0 24px rgba(37, 99, 235, .12),
        inset 0 1px 0 rgba(255, 255, 255, .045);

    transition:
        border-color .18s ease,
        box-shadow .18s ease;
}


/* Blue glow line across the top */
.gnmm-brand-header::before {
    content: "";
    position: absolute;
    z-index: -1;
    left: 0;
    right: 0;
    top: 0;
    height: 2px;

    background:
        linear-gradient(
            90deg,
            transparent,
            rgb(14, 165, 233) 12%,
            rgb(59, 130, 246) 38%,
            rgb(99, 102, 241) 66%,
            transparent
        );

    box-shadow:
        0 0 16px rgba(59, 130, 246, .75);
}


/* Decorative sweeping wave */
.gnmm-brand-header::after {
    content: "";
    position: absolute;
    z-index: -1;
    pointer-events: none;

    width: 56%;
    height: 240%;
    right: -7%;
    top: -105%;

    border-radius: 48%;

    background:
        linear-gradient(
            135deg,
            transparent 14%,
            rgba(37, 99, 235, .05) 34%,
            rgba(79, 70, 229, .25) 52%,
            rgba(99, 102, 241, .10) 60%,
            transparent 72%
        );

    transform: rotate(-15deg);
    filter: blur(.15px);
}


/* Make the existing left icon feel branded */
.gnmm-brand-header svg {
    position: relative;
    z-index: 1;
}


/*
 * The first compact square inside the header is the current puzzle icon
 * container. These selectors intentionally stay scoped to the header.
 */
.gnmm-brand-header > div:first-child > div:first-child,
.gnmm-brand-header > div:first-child > span:first-child {
    position: relative;
}


/* Enhance common icon wrappers without affecting provider badge */
.gnmm-brand-header > div:first-child [class*="icon"],
.gnmm-brand-header > div:first-child > div:first-child {
    border-color: rgba(56, 189, 248, .52) !important;

    background:
        radial-gradient(
            circle at 35% 25%,
            rgba(56, 189, 248, .30),
            transparent 45%
        ),
        linear-gradient(
            145deg,
            rgba(37, 99, 235, .46),
            rgba(30, 64, 175, .24)
        ) !important;

    box-shadow:
        0 0 18px rgba(37, 99, 235, .17),
        inset 0 1px 0 rgba(255, 255, 255, .08);
}


/* Stronger application title */
.gnmm-brand-title {
    color: rgb(248, 250, 252) !important;
    font-size: 1.05rem !important;
    font-weight: 800 !important;
    letter-spacing: -.018em;

    text-shadow:
        0 0 12px rgba(59, 130, 246, .18);
}


/* Subtitle */
.gnmm-brand-subtitle {
    color: rgb(96, 165, 250) !important;
    font-weight: 600;
}


/*
 * Fallback styling for the current title/subtitle structure if the subtitle
 * class could not be injected because Blade uses nested markup.
 */
.gnmm-brand-header > div:first-child {
    position: relative;
    z-index: 2;
}

.gnmm-brand-header > div:first-child p,
.gnmm-brand-header > div:first-child small {
    color: rgb(96, 165, 250) !important;
}


/* Provider badge on right */
.gnmm-brand-header > :last-child {
    position: relative;
    z-index: 2;
}

.gnmm-brand-header > :last-child:not(:first-child) {
    border-color: rgba(96, 165, 250, .20);
}


/* Give small badges/labels inside the header a polished glass effect */
.gnmm-brand-header [class*="badge"],
.gnmm-brand-header [class*="pill"] {
    border: 1px solid rgba(96, 165, 250, .18) !important;

    background:
        linear-gradient(
            180deg,
            rgba(30, 41, 59, .82),
            rgba(15, 23, 42, .68)
        ) !important;

    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, .04),
        0 4px 12px rgba(0, 0, 0, .14);
}


/* Gentle interaction */
.gnmm-brand-header:hover {
    border-color: rgba(96, 165, 250, .62) !important;

    box-shadow:
        0 0 0 1px rgba(37, 99, 235, .16),
        0 12px 38px rgba(0, 0, 0, .24),
        0 0 30px rgba(37, 99, 235, .15),
        inset 0 1px 0 rgba(255, 255, 255, .05);
}


@media (max-width: 720px) {
    .gnmm-brand-header {
        min-height: 4.8rem;
        padding: .9rem 1rem !important;
    }

    .gnmm-brand-title {
        font-size: .95rem !important;
    }

    .gnmm-brand-header::after {
        width: 85%;
        right: -28%;
    }
}


/* MODHARBOR FINAL HERO */


        .mh-brand-banner {
            overflow: hidden;
            border-radius: 16px;
            border: 1px solid rgba(56, 189, 248, 0.18);
            background: #04111f;
            line-height: 0;
            width: 100%;
        }
        .mh-brand-banner {
            position: relative !important;
            display: block !important;
            height: auto !important;
            overflow: hidden !important;
            width: 100% !important;
            padding: 0 !important;
            line-height: 0 !important;
        }
        .mh-brand-banner-bg { display: none !important; }
        .mh-brand-banner-img {
            display: block !important;
            width: 100% !important;
            height: 180px !important;
            max-height: 180px !important;
            max-width: 100% !important;
            object-fit: contain !important;
            object-position: center center !important;
            background: #04111f !important;
        }
        .mh-hero { display: none !important; }

        body:has(.gnmm) .fi-main,
        body:has(.gnmm) .fi-page,
        body:has(.gnmm) .fi-page-content,
        body:has(.gnmm) .fi-main-ctn {
            width: 100% !important;
            max-width: none !important;
        }
        body:has(.gnmm) .fi-main {
            padding-left: 16px !important;
            padding-right: 16px !important;
        }

.mh-hero {
    position: relative;
    isolation: isolate;
    overflow: hidden;

    min-height: 8.25rem;

    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 2rem;

    margin-bottom: 1rem;
    padding: 1rem 1.35rem;

    border: 1px solid rgba(59, 130, 246, .72);
    border-radius: .85rem;

    background:
        radial-gradient(
            circle at 11% 10%,
            rgba(14, 165, 233, .24),
            transparent 28%
        ),
        radial-gradient(
            circle at 76% -20%,
            rgba(79, 70, 229, .45),
            transparent 49%
        ),
        linear-gradient(
            112deg,
            rgba(8, 47, 73, .98),
            rgba(8, 19, 39, .98) 36%,
            rgba(30, 27, 75, .96) 72%,
            rgba(12, 22, 43, .98)
        );

    box-shadow:
        0 0 0 1px rgba(14, 165, 233, .12),
        0 16px 42px rgba(0, 0, 0, .27),
        0 0 30px rgba(37, 99, 235, .18),
        inset 0 1px 0 rgba(255, 255, 255, .06);
}

.mh-hero::before {
    content: "";
    position: absolute;
    z-index: 0;
    pointer-events: none;

    top: 0;
    left: 2%;
    right: 2%;

    height: 2px;

    background:
        linear-gradient(
            90deg,
            transparent,
            rgb(34, 211, 238) 13%,
            rgb(59, 130, 246) 43%,
            rgb(99, 102, 241) 71%,
            transparent
        );

    box-shadow:
        0 0 18px rgba(59, 130, 246, .85);
}

.mh-hero::after {
    content: "";
    position: absolute;
    z-index: 0;
    pointer-events: none;

    left: 0;
    right: 0;
    bottom: 0;

    height: 31%;

    opacity: .58;

    background:
        linear-gradient(
            180deg,
            transparent,
            rgba(2, 6, 23, .52)
        );
}

.mh-hero-glow {
    position: absolute;
    z-index: 0;
    pointer-events: none;

    width: 34rem;
    height: 13rem;

    right: 13%;
    top: -5.5rem;

    border-radius: 50%;

    background:
        radial-gradient(
            ellipse,
            rgba(129, 140, 248, .23),
            rgba(59, 130, 246, .08) 43%,
            transparent 72%
        );

    filter: blur(4px);
}

.mh-hero-wave {
    position: absolute;
    z-index: 0;
    pointer-events: none;

    right: -8%;

    border-radius: 50%;

    transform: rotate(-8deg);
}

.mh-hero-wave-one {
    width: 57%;
    height: 125%;

    top: 47%;

    border-top: 1px solid rgba(96, 165, 250, .28);

    box-shadow:
        inset 0 12px 26px rgba(37, 99, 235, .08);
}

.mh-hero-wave-two {
    width: 48%;
    height: 105%;

    top: 58%;
    right: 4%;

    border-top: 1px solid rgba(129, 140, 248, .22);
}

.mh-brand {
    position: relative;
    z-index: 2;

    display: flex;
    align-items: center;
    gap: 1rem;

    min-width: 0;
}

.mh-logo {
    position: relative;

    width: 5.65rem;
    height: 5.65rem;

    flex: 0 0 5.65rem;

    display: grid;
    place-items: center;

    padding: .42rem;

    border: 1px solid rgba(56, 189, 248, .72);
    border-radius: .82rem;

    background:
        radial-gradient(
            circle at 30% 20%,
            rgba(56, 189, 248, .24),
            transparent 38%
        ),
        linear-gradient(
            145deg,
            rgba(37, 99, 235, .46),
            rgba(30, 64, 175, .18) 50%,
            rgba(15, 23, 42, .82)
        );

    box-shadow:
        0 0 22px rgba(37, 99, 235, .25),
        inset 0 1px 0 rgba(255, 255, 255, .10);
}

.mh-logo::before {
    content: "";
    position: absolute;
    inset: -1px;

    z-index: -1;

    border-radius: inherit;

    background:
        linear-gradient(
            135deg,
            rgba(34, 211, 238, .55),
            rgba(37, 99, 235, .20),
            rgba(99, 102, 241, .52)
        );

    filter: blur(7px);
    opacity: .52;
}

.mh-logo svg {
    width: 100%;
    height: 100%;
    display: block;
}

.mh-brand-copy {
    min-width: 0;
}

.mh-wordmark {
    line-height: .95;

    font-size: clamp(1.85rem, 3vw, 2.75rem);
    font-weight: 850;

    letter-spacing: -.055em;

    white-space: nowrap;
}

.mh-wordmark-mod {
    color: rgb(248, 250, 252);

    text-shadow:
        0 0 16px rgba(255, 255, 255, .10);
}

.mh-wordmark-harbor {
    color: transparent;

    background:
        linear-gradient(
            90deg,
            rgb(56, 189, 248),
            rgb(59, 130, 246),
            rgb(129, 140, 248)
        );

    -webkit-background-clip: text;
    background-clip: text;
}

.mh-tagline {
    margin-top: .42rem;

    color: rgb(191, 219, 254);

    font-size: .94rem;
    font-weight: 650;

    letter-spacing: .015em;
}

.mh-game-line {
    margin-top: .72rem;

    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .44rem;

    color: rgb(148, 163, 184);

    font-size: .72rem;
}

.mh-game {
    display: inline-flex;
    align-items: center;
    gap: .32rem;

    color: rgb(191, 219, 254);
}

.mh-game small {
    color: rgb(100, 116, 139);

    font-size: .58rem;
}

.mh-game-active {
    color: rgb(94, 234, 212);
    font-weight: 700;
}

.mh-game-dot {
    width: .45rem;
    height: .45rem;

    border-radius: 999px;

    background: rgb(45, 212, 191);

    box-shadow:
        0 0 9px rgba(45, 212, 191, .7);
}

.mh-game-separator {
    color: rgb(71, 85, 105);
}

.mh-game-more {
    color: rgb(148, 163, 184);
}

.mh-hero-right {
    position: relative;
    z-index: 2;

    display: flex;
    align-items: center;
    gap: .8rem;

    flex: 0 0 auto;
}

.mh-multigame-card {
    display: flex;
    align-items: center;
    gap: .65rem;

    padding: .72rem .9rem;

    min-width: 12rem;

    border: 1px solid rgba(96, 165, 250, .25);
    border-radius: .7rem;

    background:
        linear-gradient(
            145deg,
            rgba(30, 64, 175, .20),
            rgba(15, 23, 42, .60)
        );

    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, .04),
        0 8px 22px rgba(0, 0, 0, .16);
}

.mh-server-icon {
    width: 2rem;
    height: 2rem;

    flex: 0 0 2rem;

    color: rgb(125, 211, 252);
}

.mh-server-icon svg {
    width: 100%;
    height: 100%;
}

.mh-multigame-card > div:last-child {
    display: flex;
    flex-direction: column;

    line-height: 1.18;
}

.mh-multigame-card strong {
    color: rgb(219, 234, 254);

    font-size: .76rem;
}

.mh-multigame-card span {
    margin-top: .12rem;

    color: rgb(191, 219, 254);

    font-size: .70rem;
}

.mh-multigame-card small {
    margin-top: .15rem;

    color: rgb(96, 165, 250);

    font-size: .62rem;
}

.mh-provider-stack {
    display: flex;
    align-items: center;
    gap: .35rem;
}

.mh-provider-badge {
    display: inline-flex;
    align-items: center;

    padding: .38rem .58rem;

    border: 1px solid rgba(148, 163, 184, .15);
    border-radius: .48rem;

    background:
        linear-gradient(
            180deg,
            rgba(30, 41, 59, .78),
            rgba(15, 23, 42, .62)
        );

    color: rgb(248, 250, 252);

    font-size: .68rem;
    font-weight: 800;

    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, .04),
        0 5px 12px rgba(0, 0, 0, .14);
}

.mh-footer-divider {
    margin: 0 .38rem;

    color: rgb(71, 85, 105);
}

.mh-footer-tagline {
    color: rgb(147, 197, 253);
}

.mh-footer-pelican {
    color: rgb(148, 163, 184);
}

@media (max-width: 900px) {
    .mh-hero {
        align-items: flex-start;
        flex-direction: column;

        min-height: 0;
    }

    .mh-hero-right {
        width: 100%;

        justify-content: space-between;
    }
}

@media (max-width: 620px) {
    .mh-logo {
        width: 4.6rem;
        height: 4.6rem;

        flex-basis: 4.6rem;
    }

    .mh-game-line {
        display: none;
    }

    .mh-multigame-card {
        min-width: 0;
    }
}


/* MODHARBOR GITHUB PROVIDER */

.mh-browse-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.mh-provider-tabs {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .25rem;
    border: 1px solid rgba(148,163,184,.12);
    border-radius: .65rem;
    background: rgba(15,23,42,.42);
}

.mh-provider-tab {
    min-height: 2.2rem;
    padding: .45rem .8rem;
    border: 1px solid transparent;
    border-radius: .48rem;
    background: transparent;
    color: rgb(148,163,184);
    font-size: .76rem;
    font-weight: 700;
    cursor: pointer;
}

.mh-provider-tab:hover {
    color: rgb(219,234,254);
    background: rgba(30,41,59,.55);
}

.mh-provider-tab-active {
    color: white;
    border-color: rgba(96,165,250,.36);
    background:
        linear-gradient(
            180deg,
            rgba(37,99,235,.68),
            rgba(30,64,175,.48)
        );
    box-shadow:
        0 4px 12px rgba(37,99,235,.16),
        inset 0 1px 0 rgba(255,255,255,.08);
}

.mh-github-panel {
    border: 1px solid rgba(148,163,184,.12);
    border-radius: .75rem;
    padding: 1rem;
    background:
        linear-gradient(
            145deg,
            rgba(15,23,42,.48),
            rgba(2,6,23,.16)
        );
}

.mh-github-intro {
    display: flex;
    align-items: center;
    gap: .8rem;
    margin-bottom: 1rem;
}

.mh-github-intro > div:last-child {
    display: flex;
    flex-direction: column;
    gap: .15rem;
}

.mh-github-intro strong {
    color: rgb(241,245,249);
    font-size: .9rem;
}

.mh-github-intro span {
    color: rgb(148,163,184);
    font-size: .74rem;
}

.mh-github-mark {
    width: 2.8rem;
    height: 2.8rem;
    flex: 0 0 2.8rem;
    display: grid;
    place-items: center;
    border: 1px solid rgba(148,163,184,.22);
    border-radius: .65rem;
    background: rgba(30,41,59,.65);
    color: rgb(226,232,240);
}

.mh-github-mark svg {
    width: 1.65rem;
    height: 1.65rem;
}

.mh-github-search {
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: end;
    gap: .75rem;
    margin-bottom: 1rem;
}

.mh-github-repo {
    overflow: hidden;
    border: 1px solid rgba(96,165,250,.16);
    border-radius: .7rem;
    background: rgba(15,23,42,.34);
}

.mh-github-repo-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    padding: 1rem;
    border-bottom: 1px solid rgba(148,163,184,.10);
    background:
        linear-gradient(
            90deg,
            rgba(30,64,175,.12),
            rgba(30,41,59,.18)
        );
}

.mh-github-repo-main {
    min-width: 0;
    display: flex;
    gap: .8rem;
    align-items: flex-start;
}

.mh-github-avatar {
    width: 3.2rem;
    height: 3.2rem;
    flex: 0 0 3.2rem;
    object-fit: cover;
    border: 1px solid rgba(96,165,250,.28);
    border-radius: .6rem;
}

.mh-github-repo-name {
    display: flex;
    align-items: center;
    gap: .45rem;
    color: rgb(241,245,249);
    font-size: .92rem;
    font-weight: 750;
}

.mh-github-description {
    max-width: 52rem;
    margin: .28rem 0 .4rem;
    color: rgb(148,163,184);
    font-size: .75rem;
}

.mh-github-assets {
    padding: .75rem;
}

.mh-github-assets-title {
    margin: .1rem .25rem .65rem;
    color: rgb(203,213,225);
    font-size: .73rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .035em;
}

.mh-github-asset {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .8rem;
    padding: .72rem .8rem;
    margin-top: .45rem;
    border: 1px solid rgba(148,163,184,.10);
    border-radius: .55rem;
    background: rgba(30,41,59,.25);
}

.mh-github-asset:hover {
    border-color: rgba(96,165,250,.23);
    background: rgba(30,41,59,.38);
}

.mh-github-asset strong {
    color: rgb(226,232,240);
    font-size: .78rem;
}

.mh-github-empty {
    margin-top: .75rem;
}

@media (max-width: 800px) {
    .mh-browse-heading,
    .mh-github-repo-top {
        flex-direction: column;
    }

    .mh-provider-tabs {
        width: 100%;
    }

    .mh-provider-tab {
        flex: 1;
    }

    .mh-github-search {
        grid-template-columns: 1fr;
    }

    .mh-github-asset {
        align-items: flex-start;
        flex-direction: column;
    }
}


/* =========================================================
   ModHarbor - Installed page alignment polish
   ========================================================= */

.gnmm-installed-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 20px;
}

.gnmm-installed-header > div:first-child {
    flex: 1 1 auto;
    min-width: 0;
}

.gnmm-installed-count {
    flex: 0 0 auto;
    white-space: nowrap;
    align-self: flex-start;
}

/* ---------- Health summary ---------- */

.gnmm-health-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(118px, 1fr));
    gap: 10px;
    margin-top: 16px;
    max-width: 900px;
}

.gnmm-health-stat {
    min-height: 68px;
    padding: 12px 14px;
    border: 1px solid rgba(116, 145, 190, .26);
    border-radius: 9px;
    background: rgba(19, 27, 40, .72);
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.gnmm-health-label {
    margin-bottom: 5px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #8fa6c5;
}

.gnmm-health-value {
    font-size: 15px;
    font-weight: 700;
    line-height: 1.2;
}

.gnmm-health-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
    margin-bottom: 2px;
}

.gnmm-health-actions .gnmm-btn {
    min-width: 112px;
    justify-content: center;
}

/* ---------- Installed toolbar ---------- */

.gnmm-installed-toolbar {
    display: grid;
    grid-template-columns:
        minmax(320px, 1fr)
        minmax(170px, 190px)
        minmax(155px, 175px);
    gap: 10px;
    align-items: stretch;
    padding: 12px;
}

.gnmm-installed-search {
    width: 100%;
    min-width: 0;
}

.gnmm-installed-search input {
    width: 100%;
}

.gnmm-installed-sort,
.gnmm-check-btn {
    width: 100%;
    min-height: 42px;
}

.gnmm-installed-toolbar > button:last-child:nth-child(4) {
    grid-column: 1 / -1;
    width: 100%;
    justify-content: center;
    margin-top: 0;
}

/* ---------- Installed table ---------- */

.gnmm-mod-table {
    width: 100%;
    overflow-x: auto;
}

.gnmm-mod-table-head,
.gnmm-mod-row {
    display: grid;
    grid-template-columns:
        minmax(300px, 1.65fr)
        minmax(145px, .78fr)
        minmax(88px, .48fr)
        minmax(105px, .54fr)
        minmax(135px, .72fr)
        minmax(420px, 1.65fr);
    column-gap: 16px;
    align-items: center;
}

.gnmm-mod-table-head {
    min-width: 1240px;
}

.gnmm-mod-row {
    min-width: 1240px;
    min-height: 78px;
}

.gnmm-mod-table-head > div,
.gnmm-mod-row > div {
    min-width: 0;
}

.gnmm-actions-heading {
    text-align: right;
    padding-right: 4px;
}

/* ---------- Mod identity ---------- */

.gnmm-mod-main {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.gnmm-mod-copy {
    min-width: 0;
}

.gnmm-mod-name-line {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
}

.gnmm-mod-profile-link,
.gnmm-mod-copy strong {
    line-height: 1.25;
}

/* ---------- Status ---------- */

.gnmm-mod-row .gnmm-status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
}

/* ---------- Actions ---------- */

.gnmm-mod-row > div:last-child {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: nowrap;
    gap: 7px;
    min-width: 0;
}

.gnmm-mod-row > div:last-child button {
    min-height: 36px;
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border-radius: 8px;
    white-space: nowrap;
}

.gnmm-mod-row > div:last-child button:not(.gnmm-btn-danger):not(.gnmm-danger):not([class*="danger"]) {
    padding: 0 11px;
    border: 1px solid rgba(116, 145, 190, .24);
    background: rgba(42, 55, 76, .68);
    color: #f4f7fb;
    font-size: 12px;
    font-weight: 600;
    transition:
        background .15s ease,
        border-color .15s ease,
        transform .15s ease;
}

.gnmm-mod-row > div:last-child button:not(.gnmm-btn-danger):not(.gnmm-danger):not([class*="danger"]):hover {
    background: rgba(55, 73, 101, .92);
    border-color: rgba(105, 156, 255, .45);
}

/* icon-only action buttons stay compact */
.gnmm-mod-row > div:last-child button:has(svg):not(:has(span)) {
    min-width: 36px;
}

/* ---------- Detail panel ---------- */

.gnmm-details-panel {
    margin-top: 14px;
    margin-bottom: 4px;
}

.gnmm-details-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(130px, 1fr));
    gap: 10px;
}

/* ---------- Small screens ---------- */

@media (max-width: 1100px) {
    .gnmm-health-grid {
        grid-template-columns: repeat(3, minmax(130px, 1fr));
    }

    .gnmm-installed-toolbar {
        grid-template-columns: 1fr 180px;
    }

    .gnmm-installed-toolbar .gnmm-check-btn {
        grid-column: auto;
    }

    .gnmm-installed-toolbar > button:last-child:nth-child(4) {
        grid-column: 1 / -1;
    }
}

@media (max-width: 720px) {
    .gnmm-installed-header {
        flex-direction: column;
    }

    .gnmm-health-grid {
        grid-template-columns: repeat(2, minmax(120px, 1fr));
        width: 100%;
        max-width: none;
    }

    .gnmm-installed-toolbar {
        grid-template-columns: 1fr;
    }

    .gnmm-installed-toolbar > * {
        grid-column: 1 !important;
    }

    .gnmm-details-grid {
        grid-template-columns: repeat(2, minmax(120px, 1fr));
    }
}

</style>


<div class="gnmm">


<section class="mh-brand-banner">
    <img class="mh-brand-banner-img" src="/modharbor/branding/banner-v3.webp" alt="ModHarbor — universal mod management for Pelican">
</section>

    <div class="mh-hero">

        <div class="mh-hero-glow"></div>
        <div class="mh-hero-wave mh-hero-wave-one"></div>
        <div class="mh-hero-wave mh-hero-wave-two"></div>

        <div class="mh-brand">

            <div class="mh-logo" aria-hidden="true">
                <svg viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <linearGradient id="mhBeacon" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#ffffff"/>
                            <stop offset="52%" stop-color="#dbeafe"/>
                            <stop offset="100%" stop-color="#60a5fa"/>
                        </linearGradient>

                        <linearGradient id="mhWater" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#22d3ee"/>
                            <stop offset="45%" stop-color="#3b82f6"/>
                            <stop offset="100%" stop-color="#6366f1"/>
                        </linearGradient>
                    </defs>

                    <path d="M42 20h12l3 13H39l3-13Z" fill="url(#mhBeacon)"/>
                    <rect x="41" y="33" width="14" height="25" rx="2" fill="url(#mhBeacon)"/>
                    <rect x="44" y="39" width="8" height="7" rx="1" fill="#172554"/>
                    <path d="M37 58h22l4 9H33l4-9Z" fill="url(#mhBeacon)"/>
                    <path d="M39 20 48 12 57 20Z" fill="#ffffff"/>
                    <rect x="45.5" y="7" width="5" height="7" rx="1.5" fill="#ffffff"/>

                    <path d="M58 28 84 20 60 34Z" fill="#60a5fa" opacity=".55"/>
                    <path d="M38 28 12 20 36 34Z" fill="#38bdf8" opacity=".32"/>

                    <path
                        d="M13 66
                           C23 58 31 61 40 68
                           C49 75 58 75 83 60
                           C76 76 66 84 48 88
                           C31 84 20 77 13 66Z"
                        fill="url(#mhWater)"
                    />

                    <path
                        d="M18 70
                           C27 64 35 65 43 71
                           C52 77 61 76 77 67"
                        fill="none"
                        stroke="#ffffff"
                        stroke-width="3"
                        stroke-linecap="round"
                        opacity=".75"
                    />
                </svg>
            </div>

            <div class="mh-brand-copy">
                <div class="mh-wordmark">
                    <span class="mh-wordmark-mod">Mod</span><span class="mh-wordmark-harbor">Harbor</span>
                </div>

                <div class="mh-tagline">
                    Mods. Managed.
                </div>

                <div class="mh-game-line">
                    <span class="mh-game mh-game-active">
                        <span class="mh-game-dot"></span>
                        {{ $game }}
                    </span>

                    <span class="mh-game-separator">•</span>

                    @if ($game !== 'Rust')
                        <span class="mh-game">
                            Rust
                            <small>supported</small>
                        </span>
                    @endif

                    <span class="mh-game-separator">•</span>

                    <span class="mh-game">
                        7 Days
                    </span>

                    <span class="mh-game-separator">•</span>

                    <span class="mh-game">
                        Minecraft
                    </span>

                    <span class="mh-game-separator">•</span>

                    <span class="mh-game mh-game-more">
                        and more
                    </span>
                </div>
            </div>

        </div>

        <div class="mh-hero-right">
            <img src="{{ $this->gameArtwork() }}" alt="{{ $game }} artwork" width="230" height="108"
                style="width:230px;max-width:100%;height:108px;object-fit:cover;border-radius:12px;margin-bottom:12px;" loading="lazy" referrerpolicy="no-referrer">


            <div class="mh-multigame-card">

                <div class="mh-server-icon" aria-hidden="true">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                    >
                        <rect x="4" y="4" width="16" height="5" rx="2"/>
                        <rect x="4" y="10" width="16" height="5" rx="2"/>
                        <rect x="4" y="16" width="16" height="4" rx="2"/>
                        <path d="M7 6.5h.01M7 12.5h.01M7 18h.01"/>
                    </svg>
                </div>

                <div>
                    <strong>Multi-Game</strong>
                    <span>Mod Management</span>
                    <small>Built for Pelican</small>
                </div>

            </div>

            @if ($game === 'Rust')
                <div class="mh-provider-stack" style="margin-bottom: 8px;">
                    <span class="mh-provider-badge">
                        Runtime: {{ $rustRuntime }}
                    </span>
                </div>
            @endif

            <div class="mh-provider-stack">
                @foreach ($providers as $provider)
                    <span class="mh-provider-badge">
                        {{ $this->providerLabel($provider) }}
                    </span>
                @endforeach
            </div>

        </div>

    </div>


    <div class="gnmm-card">

        <div class="gnmm-tabs">

            @php
                $tabs = [
                    'browse' => 'Browse',
                    'installed' => 'Installed',

                    'configs' => 'Configs',
                    'history' => 'History',
                ];
            @endphp

            @foreach ($tabs as $key => $label)
                <button
                    type="button"
                    wire:click="setTab('{{ $key }}')"
                    class="gnmm-tab {{ $activeTab === $key ? 'gnmm-tab-active' : '' }}"
                >
                    {{ $label }}
                </button>
            @endforeach

        </div>


        <div class="gnmm-content">
            @if ($manifestError)
                <div class="gnmm-notice" role="alert">{{ $manifestError }}</div>
            @endif
            @php
                $unresolvedOperations = $this->recoveryState();
            @endphp
            @if (count($unresolvedOperations))
                <div class="gnmm-notice" role="alert">
                    <strong>Recovery required — changes are locked</strong>
                    <p>Keep the game server stopped. Review the interrupted operation before retrying recovery.</p>
                    @foreach ($unresolvedOperations as $operation)
                        <details>
                            <summary>{{ $operation['name'] }} · {{ $operation['status'] }}</summary>
                            <p>{{ $operation['message'] ?? '' }}</p>
                            <p>Reference: {{ $operation['id'] }} · Rollback: {{ $operation['rollback'] ?? 'Unverified' }} · Recorded moves: {{ $operation['move_count'] ?? 0 }}</p>
                            <p>{{ $operation['action'] ?? '' }} · {{ $operation['started_at'] ?? '' }} · Actor {{ $operation['actor'] ?? 'Unknown' }}</p>
                            @foreach ($operation['affected_files'] ?? [] as $affectedPath)<div>{{ $affectedPath }}</div>@endforeach
                            @foreach ($operation['journal_moves'] ?? [] as $move)
                                <div>{{ $move['from'] }} → {{ $move['to'] }} · {{ $move['restored'] ? 'Restored' : 'Awaiting verification' }}</div>
                            @endforeach
                            @if (!empty($operation['id']))
                                <button type="button" class="gnmm-btn gnmm-btn-danger" wire:click="retryOperationRecovery(@js($operation['id']))"
                                    wire:confirm="Keep the server stopped. Retry rollback of this interrupted operation?" wire:loading.attr="disabled">Retry Recovery</button>
                            @endif
                        </details>
                    @endforeach
                </div>
            @endif
            @if ($pendingAction && $pendingKey)
                @php
                    $actionDetails =
                        $this->pendingActionDetails();
                @endphp

                @if ($actionDetails)
                    <div
                        class="gnmm-notice"
                        role="alert"
                        aria-live="polite"
                        wire:key="confirm-{{ $pendingAction }}-{{ $pendingKey }}"
                        x-data
                        x-init="$el.scrollIntoView({block: 'center', behavior: 'smooth'})"
                    >
                        <strong>
                            {{ $actionDetails['title'] }}
                        </strong>

                        <p>
                            {{ $actionDetails['body'] }}
                        </p>
                        @foreach ($dependencyPreview as $dependency)
                            <div class="gnmm-field-help">{{ ucfirst(str_replace('_', ' ', $dependency['type'])) }}:
                                {{ $dependency['key'] }} {{ $dependency['constraint'] ?? '' }}
                                @if (!empty($dependency['file_id'])) · Release {{ $dependency['file_id'] }} @endif
                                @if (isset($dependency['installed'])) · {{ $dependency['installed'] ? 'Installed' : 'Not installed' }} @endif
                            </div>
                        @endforeach

                        @if (!empty($actionDetails['version']))
                            <div class="gnmm-field-help">
                                Installed:
                                <strong>
                                    {{ $actionDetails['version'] }}
                                </strong>

                                @if (
                                    $pendingAction === 'update'
                                    && !empty($actionDetails['latest'])
                                )
                                    · Target:
                                    <strong>
                                        {{ $actionDetails['latest'] }}
                                    </strong>
                                @endif

                                · Provider:
                                <strong>
                                    {{ $actionDetails['provider'] }}
                                </strong>
                            </div>
                        @endif

                        <div class="gnmm-field-help">
                            ModHarbor will verify the resulting managed files,
                            retired paths, dependencies, and manifest before
                            the operation is considered complete.
                        </div>

                        @if (!empty($actionDetails['destructive']))
                            <div class="gnmm-field-help">
                                <strong>
                                    This is an uninstall operation.
                                </strong>
                                Only files owned by the ModHarbor manifest are
                                eligible for removal.
                            </div>
                        @endif

                        <div class="gnmm-row-actions">
                            <button
                                type="button"
                                class="gnmm-btn gnmm-btn-primary"
                                wire:click="confirmAction" @disabled(count($unresolvedOperations) > 0)
                                wire:loading.attr="disabled"
                                wire:target="confirmAction"
                            >
                                {{ $actionDetails['button'] }}
                            </button>

                            <button
                                type="button"
                                class="gnmm-btn gnmm-btn-secondary"
                                wire:click="cancelAction"
                                wire:loading.attr="disabled"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            @endif

            <div wire:loading wire:target="confirmAction,checkUpdates,searchMods" role="status" class="gnmm-section-subtitle">Working… Please keep this page open.</div>

            @if ($activeTab === 'browse')

                <div class="mh-browse-heading">
                    <div>
                        <h3 class="gnmm-section-title">
                            Browse {{ $game }} Mods
                        </h3>

                        <p class="gnmm-section-subtitle">
                            Choose a provider, find a mod, and let ModHarbor handle the server deployment rules.
                        </p>
                    </div>

                    <div class="mh-provider-tabs">
                        @foreach ($providers as $provider)
                            @if ($this->sourceCapability($provider, 'browse'))
                                <button
                                    type="button"
                                    wire:click="setBrowseProvider('{{ $provider }}')"
                                    class="mh-provider-tab {{ $browseProvider === $provider ? 'mh-provider-tab-active' : '' }}"
                                >
                                    {{ $this->providerLabel($provider) }}
                                </button>
                            @endif
                        @endforeach
                    </div>
                </div>


                @if ($browseProvider === 'umod')

                    <div class="mh-github-panel">

                        <div class="mh-github-intro">
                            <div>
                                <strong>uMod Rust Plugins</strong>
                                <span>
                                    Browse the full uMod Rust plugin catalog. ModHarbor will handle Carbon or Oxide deployment automatically.
                                </span>
                            </div>
                        </div>

                        <form
                            wire:submit="searchMods"
                            class="gnmm-search"
                            style="display:grid;grid-template-columns:minmax(260px,1fr) 210px auto;gap:10px;align-items:end;"
                        >
                            <div>
                                <label class="gnmm-label">
                                    Search
                                </label>

                                <input
                                    type="text"
                                    wire:model="search"
                                    placeholder="Search 1,400+ Rust plugins..."
                                    class="gnmm-search-input"
                                    autocomplete="off"
                                />
                            </div>

                            <div>
                                <label class="gnmm-label">
                                    Sort
                                </label>

                                <select
                                    wire:change="setUModSort($event.target.value)"
                                    class="gnmm-input"
                                >
                                    <option
                                        value="updated"
                                        @selected($umodSort === 'updated')
                                    >
                                        Recently Updated
                                    </option>

                                    <option
                                        value="downloads"
                                        @selected($umodSort === 'downloads')
                                    >
                                        Most Downloaded
                                    </option>

                                    <option
                                        value="watchers"
                                        @selected($umodSort === 'watchers')
                                    >
                                        Most Watched
                                    </option>

                                    <option
                                        value="newest"
                                        @selected($umodSort === 'newest')
                                    >
                                        Newest
                                    </option>

                                    <option
                                        value="name"
                                        @selected($umodSort === 'name')
                                    >
                                        A–Z
                                    </option>
                                </select>
                            </div>

                            <div style="display:flex;gap:8px;">
                                <button
                                    type="submit"
                                    class="gnmm-btn gnmm-btn-primary"
                                    wire:loading.attr="disabled"
                                    wire:target="searchMods"
                                >
                                    <x-heroicon-o-magnifying-glass/>
                                    Search
                                </button>

                                @if (trim($search) !== '')
                                    <button
                                        type="button"
                                        wire:click="clearSearch"
                                        class="gnmm-btn gnmm-btn-secondary"
                                    >
                                        Clear
                                    </button>
                                @endif
                            </div>
                        </form>

                        <div
                            class="gnmm-meta"
                            style="display:flex;justify-content:space-between;align-items:center;margin:12px 0 14px;"
                        >
                            <span>
                                {{ number_format($umodTotal) }}
                                {{ $umodTotal === 1 ? 'plugin' : 'plugins' }}
                            </span>

                            @if ($umodTotal > 0)
                                <span>
                                    Page {{ $umodPage }}
                                    of {{ $umodLastPage }}
                                </span>
                            @endif
                        </div>

                        @if ($searched && count($mods) === 0)

                            <div class="gnmm-empty">
                                <x-heroicon-o-magnifying-glass/>

                                <div class="gnmm-empty-title">
                                    No plugins found
                                </div>
                            </div>

                        @elseif (count($mods) > 0)

                            <div class="gnmm-grid">

                                @foreach ($mods as $mod)

                                    @php
                                        $umodId =
                                            (string) (
                                                $mod['id']
                                                ?? ''
                                            );

                                        $installed =
                                            isset(
                                                $installedMods[
                                                    'umod:' . $umodId
                                                ]
                                            );

                                        $file =
                                            $mod['latest_file']
                                            ?? [];

                                        $umodUpdatedRelative = null;
                                        $umodUpdatedExact = null;

                                        if (!empty($mod['released_at'])) {
                                            try {
                                                $umodUpdatedDate =
                                                    \Carbon\Carbon::parse(
                                                        $mod['released_at']
                                                    );

                                                $umodUpdatedRelative =
                                                    $umodUpdatedDate->diffForHumans();

                                                $umodUpdatedExact =
                                                    $umodUpdatedDate->format(
                                                        'M j, Y'
                                                    );
                                            } catch (\Throwable $e) {
                                                // Ignore invalid provider dates.
                                            }
                                        }
                                    @endphp

                                    <div
                                        wire:key="umod-{{ $umodId }}"
                                        class="gnmm-mod-card"
                                    >

                                        @if (!empty($mod['logo']))
                                            <img
                                                src="{{ $mod['logo'] }}"
                                                alt=""
                                                class="gnmm-mod-image"
                                                loading="lazy"
                                            />
                                        @endif

                                        <div class="gnmm-mod-body">

                                            <div class="gnmm-mod-top">

                                                <div>
                                                    <div class="gnmm-mod-name">
                                                        {{ $mod['title'] ?? $mod['name'] ?? 'Unknown Plugin' }}
                                                    </div>

                                                    <div class="gnmm-mod-author">
                                                        by {{ $mod['author'] ?? 'Unknown' }}
                                                    </div>
                                                </div>

                                                @if ($installed)
                                                    <span class="gnmm-installed-badge">
                                                        Installed
                                                    </span>
                                                @endif

                                            </div>

                                            @if (!empty($mod['summary']))
                                                <div class="gnmm-summary">
                                                    {{ $mod['summary'] }}
                                                </div>
                                            @endif

                                            <div class="gnmm-meta">

                                                @if (!empty($mod['version']))
                                                    <span>
                                                        Version {{ $mod['version'] }}
                                                    </span>
                                                @endif

                                                @if (!empty($mod['downloads']))
                                                    <span>
                                                        {{ number_format((int) $mod['downloads']) }}
                                                        downloads
                                                    </span>
                                                @endif

                                                @if (!empty($mod['watchers']))
                                                    <span>
                                                        {{ number_format((int) $mod['watchers']) }}
                                                        watchers
                                                    </span>
                                                @endif

                                                @if ($umodUpdatedRelative)
                                                    <span title="Updated {{ $umodUpdatedExact }}">
                                                        Updated {{ $umodUpdatedRelative }}
                                                    </span>
                                                @endif

                                            </div>

                                            @if (!empty($mod['tags']))
                                                <div class="gnmm-meta">
                                                    <span>
                                                        {{ implode(' • ', array_slice($mod['tags'], 0, 5)) }}
                                                    </span>
                                                </div>
                                            @endif

                                            @php
                                              $umodSuggestions = $this->uModSuggestionsFor($mod);
                                            @endphp

                                            @if ($umodSuggestions)
                                                <div class="gnmm-field-help">
                                                    <strong>Optional integrations:</strong>
                                                    @foreach ($umodSuggestions as $suggestion)
                                                        {{ $suggestion['name'] ?? $suggestion['id'] }}
                                                        @if (!empty($suggestion['installed'])) ✓ installed @endif
                                                      @if (!$loop->last) · @endif
                                                @endforeach
                                                </div>
                                            @endif

                                            <div class="gnmm-actions">

                                                @if (!empty($mod['profile_url']))
                                                    <a
                                                        href="{{ $mod['profile_url'] }}"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="gnmm-link"
                                                    >
                                                        View on uMod
                                                    </a>
                                                @else
                                                    <span></span>
                                                @endif

                                                @if ($installed)

                                                    <button
                                                        type="button"
                                                        disabled
                                                        class="gnmm-btn gnmm-btn-disabled"
                                                    >
                                                        Installed
                                                    </button>

                                                @else

                                                    <button
                                                        type="button"
                                                        wire:click="installUMod('{{ $umodId }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="installUMod('{{ $umodId }}')"
                                                        class="gnmm-btn gnmm-btn-primary"
                                                    >
                                                        <x-heroicon-o-arrow-down-tray/>
                                                        Install
                                                    </button>

                                                @endif

                                            </div>

                                        </div>
                                    </div>

                                @endforeach

                            </div>

                            @if ($umodLastPage > 1)

                                <div
                                    style="
                                        display:flex;
                                        justify-content:center;
                                        align-items:center;
                                        gap:12px;
                                        margin-top:20px;
                                    "
                                >
                                    <button
                                        type="button"
                                        wire:click="previousUModPage"
                                        wire:loading.attr="disabled"
                                        wire:target="previousUModPage,nextUModPage"
                                        @disabled($umodPage <= 1)
                                        class="gnmm-btn {{ $umodPage <= 1 ? 'gnmm-btn-disabled' : 'gnmm-btn-secondary' }}"
                                    >
                                        Previous
                                    </button>

                                    <span class="gnmm-meta">
                                        Page {{ $umodPage }}
                                        of {{ $umodLastPage }}
                                    </span>

                                    <button
                                        type="button"
                                        wire:click="nextUModPage"
                                        wire:loading.attr="disabled"
                                        wire:target="previousUModPage,nextUModPage"
                                        @disabled(
                                            $umodPage >=
                                            $umodLastPage
                                        )
                                        class="gnmm-btn {{
                                            $umodPage >= $umodLastPage
                                                ? 'gnmm-btn-disabled'
                                                : 'gnmm-btn-secondary'
                                        }}"
                                    >
                                        Next
                                    </button>
                                </div>

                            @endif

                        @endif

                    </div>

                @elseif ($browseProvider === 'github')

                    <div class="mh-github-panel">

                        <div class="mh-github-intro">
                            <div class="mh-github-mark">
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M12 .7a11.3 11.3 0 0 0-3.57 22c.57.1.77-.24.77-.54v-2.1c-3.14.68-3.8-1.33-3.8-1.33-.51-1.3-1.25-1.65-1.25-1.65-1.02-.7.08-.69.08-.69 1.13.08 1.72 1.16 1.72 1.16 1 1.72 2.63 1.22 3.27.94.1-.73.39-1.22.71-1.5-2.5-.28-5.14-1.25-5.14-5.58 0-1.23.44-2.24 1.16-3.03-.12-.28-.5-1.43.11-2.99 0 0 .95-.3 3.1 1.16A10.8 10.8 0 0 1 12 6.18c.96 0 1.92.13 2.82.38 2.16-1.46 3.1-1.16 3.1-1.16.62 1.56.23 2.71.12 2.99.72.79 1.16 1.8 1.16 3.03 0 4.34-2.64 5.29-5.16 5.57.4.35.77 1.04.77 2.1v3.08c0 .3.2.65.78.54A11.3 11.3 0 0 0 12 .7Z"/>
                                </svg>
                            </div>

                            <div>
                                <strong>GitHub Releases</strong>
                                <span>
                                    Works across ModHarbor game adapters. Use a public repository containing packaged game-server mods.
                                </span>
                            </div>
                        </div>


                        <form
                            wire:submit="inspectGithubRepository"
                            class="mh-github-search"
                        >
                            <div>
                                <label class="gnmm-label">
                                    GitHub Repository
                                </label>

                                <input
                                    type="text"
                                    wire:model="githubRepository"
                                    class="gnmm-input"
                                    placeholder="owner/repository or https://github.com/owner/repository"
                                    autocomplete="off"
                                />

                                <div class="gnmm-field-help">
                                    ModHarbor installs GitHub Release ZIP assets, not arbitrary source branches.
                                </div>
                            </div>

                            <button
                                type="submit"
                                class="gnmm-btn gnmm-btn-primary"
                                wire:loading.attr="disabled"
                                wire:target="inspectGithubRepository"
                            >
                                <x-heroicon-o-magnifying-glass/>
                                Find Releases
                            </button>
                        </form>


                        @if ($githubResult)

                            @php
                                $ghRepo = $githubResult['repository'] ?? [];
                                $ghRelease = $githubResult['release'] ?? [];
                                $ghRepoId = (int) ($ghRepo['id'] ?? 0);
                                $ghInstalled = isset($installedMods['github:' . $ghRepoId]);
                            @endphp

                            <div class="mh-github-repo">

                                <div class="mh-github-repo-top">

                                    <div class="mh-github-repo-main">

                                        @if (!empty($ghRepo['logo']))
                                            <img
                                                src="{{ $ghRepo['logo'] }}"
                                                alt=""
                                                class="mh-github-avatar"
                                                loading="lazy"
                                            />
                                        @endif

                                        <div>
                                            <div class="mh-github-repo-name">
                                                {{ $ghRepo['full_name'] ?? $ghRepo['name'] ?? 'GitHub Repository' }}

                                                @if ($ghInstalled)
                                                    <span class="gnmm-installed-badge">
                                                        Installed
                                                    </span>
                                                @endif
                                            </div>

                                            @if (!empty($ghRepo['summary']))
                                                <div class="mh-github-description">
                                                    {{ $ghRepo['summary'] }}
                                                </div>
                                            @endif

                                            <div class="gnmm-meta">
                                                @if (!empty($ghRelease['tag']))
                                                    <span>
                                                        Latest {{ $ghRelease['tag'] }}
                                                    </span>
                                                @endif

                                                @if (!empty($ghRepo['stars']))
                                                    <span>
                                                        {{ number_format((int) $ghRepo['stars']) }} stars
                                                    </span>
                                                @endif

                                                @if (!empty($ghRelease['published_at']))
                                                    <span
                                                        title="Released {{ \Carbon\Carbon::parse($ghRelease['published_at'])->format('M j, Y') }}"
                                                    >
                                                        Updated {{ \Carbon\Carbon::parse($ghRelease['published_at'])->diffForHumans() }}
                                                    </span>
                                                @endif

                                                <span>
                                                    Repository #{{ $ghRepoId }}
                                                </span>
                                            </div>
                                        </div>

                                    </div>

                                    @if (!empty($ghRepo['profile_url']))
                                        <a
                                            href="{{ $ghRepo['profile_url'] }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="gnmm-link"
                                        >
                                            View on GitHub
                                        </a>
                                    @endif

                                </div>


                                @if (count($githubAssets) > 0)

                                    <div class="mh-github-assets">

                                        <div class="mh-github-assets-title">
                                            Latest Release Assets
                                        </div>

                                        @foreach ($githubAssets as $asset)

                                            @php
                                                $assetId =
                                                    (int) (
                                                        $asset['id']
                                                        ?? 0
                                                    );

                                                $compatibility =
                                                    $asset['compatibility']
                                                    ?? [];

                                                $assetCompatible =
                                                    (bool) (
                                                        $compatibility['compatible']
                                                        ?? false
                                                    );

                                                $compatibilityReason =
                                                    (string) (
                                                        $compatibility['reason']
                                                        ?? 'Compatibility was not checked.'
                                                    );
                                            @endphp

                                            <div
                                                class="mh-github-asset"
                                                wire:key="github-asset-{{ $assetId }}"
                                            >
                                                <div>
                                                    <strong>
                                                        {{ $asset['name'] ?? 'Release asset' }}
                                                    </strong>

                                                    <div class="gnmm-meta">
                                                        @if (!empty($asset['version']))
                                                            <span>
                                                                {{ $asset['version'] }}
                                                            </span>
                                                        @endif

                                                        @if (!empty($asset['filesize']))
                                                            <span>
                                                                {{ $this->formatBytes((int) $asset['filesize']) }}
                                                            </span>
                                                        @endif

                                                        @if (!empty($asset['downloads']))
                                                            <span>
                                                                {{ number_format((int) $asset['downloads']) }}
                                                                downloads
                                                            </span>
                                                        @endif
                                                    </div>

                                                    <div
                                                        style="
                                                            margin-top: 7px;
                                                            font-size: 12px;
                                                            font-weight: 600;
                                                        "
                                                    >
                                                        @if ($assetCompatible)
                                                            <span
                                                                style="color: #4ade80;"
                                                            >
                                                                ✓ Compatible with this game
                                                            </span>
                                                        @else
                                                            <span
                                                                style="color: #f87171;"
                                                            >
                                                                ✕ Not compatible with this game
                                                            </span>
                                                        @endif
                                                    </div>

                                                    <div
                                                        class="gnmm-meta"
                                                        style="margin-top: 3px;"
                                                    >
                                                        <span>
                                                            {{ $compatibilityReason }}
                                                        </span>
                                                    </div>
                                                </div>

                                                @if ($ghInstalled)

                                                    <button
                                                        type="button"
                                                        disabled
                                                        class="gnmm-btn gnmm-btn-disabled"
                                                    >
                                                        Installed
                                                    </button>

                                                @elseif (!$assetCompatible)

                                                    <button
                                                        type="button"
                                                        disabled
                                                        class="gnmm-btn gnmm-btn-disabled"
                                                    >
                                                        Not Compatible
                                                    </button>

                                                @else

                                                    <button
                                                        type="button"
                                                        class="gnmm-btn gnmm-btn-primary"
                                                        wire:click="installGithubAsset({{ $ghRepoId }}, {{ $assetId }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="installGithubAsset({{ $ghRepoId }}, {{ $assetId }})"
                                                    >
                                                        <x-heroicon-o-arrow-down-tray/>
                                                        Install
                                                    </button>

                                                @endif
                                            </div>

                                        @endforeach

                                    </div>

                                @else

                                    <div class="gnmm-empty mh-github-empty">
                                        <div class="gnmm-empty-title">
                                            No downloadable assets in the latest release
                                        </div>

                                        <div class="gnmm-empty-text">
                                            ModHarbor checks each GitHub Release asset against the current game's supported package types.
                                        </div>
                                    </div>

                                @endif

                            </div>


                        @elseif ($githubInspected)

                            <div class="gnmm-empty">
                                <div class="gnmm-empty-title">
                                    Repository could not be loaded
                                </div>
                            </div>


                        @else

                            <div class="gnmm-empty mh-github-empty">
                                <div class="gnmm-empty-title">
                                    Add a GitHub repository
                                </div>

                                <div class="gnmm-empty-text">
                                    Enter owner/repository above. Public repositories require no GitHub token.
                                </div>
                            </div>

                        @endif


                    </div>


                @elseif ($browseProvider === 'upload')

                    <div class="mh-github-panel">

                        <div class="mh-github-intro">
                            <div>
                                <strong>Upload File</strong>
                                <span>
                                    Install a private, paid, or local mod directly from your PC. The source remains private on this Pelican installation.
                                </span>
                            </div>
                        </div>

                        <form
                            wire:submit="installUploadedFile"
                            class="gnmm-search"
                        >
                            <div
                                x-data="{ uploading: false, progress: 0 }"
                                x-on:livewire-upload-start="uploading = true"
                                x-on:livewire-upload-finish="uploading = false; progress = 100"
                                x-on:livewire-upload-error="uploading = false"
                                x-on:livewire-upload-cancel="uploading = false"
                                x-on:livewire-upload-progress="progress = $event.detail.progress"
                                style="display:grid;gap:14px;"
                            >
                                <div
                                    style="
                                        border:1px dashed rgba(148,163,184,.45);
                                        border-radius:14px;
                                        padding:26px;
                                        text-align:center;
                                    "
                                >
                                    <x-heroicon-o-arrow-up-tray
                                        style="width:34px;height:34px;margin:0 auto 10px;"
                                    />

                                    <div
                                        class="gnmm-section-title"
                                        style="font-size:15px;"
                                    >
                                        Drop a file here or choose one from your PC
                                    </div>

                                    <p
                                        class="gnmm-section-subtitle"
                                        style="margin:6px 0 16px;"
                                    >
                                        {{ $this->uploadFormatHelp() }}
                                    </p>

                                    <input
                                        type="file"
                                        wire:model="uploadFile"
                                        accept="{{ $this->uploadAccept() }}"
                                        class="gnmm-input"
                                        style="max-width:540px;margin:0 auto;"
                                    />
                                </div>

                            <div style="margin-top:12px;">
                                <label class="gnmm-label">
                                    Version / build label
                                </label>

                                <input
                                    type="text"
                                    wire:model="uploadVersionLabel"
                                    class="gnmm-input"
                                    maxlength="64"
                                    placeholder="Optional — e.g. 1.4.2, Custom Build 3"
                                >

                                <div class="gnmm-field-help">
                                    Optional. Defaults to Local.
                                </div>
                            </div>


                                <div
                                    x-show="uploading"
                                    x-cloak
                                >
                                    <div
                                        class="gnmm-meta"
                                        style="display:flex;justify-content:space-between;margin-bottom:6px;"
                                    >
                                        <span>Uploading…</span>
                                        <span x-text="progress + '%'"></span>
                                    </div>

                                    <div
                                        style="
                                            height:7px;
                                            border-radius:999px;
                                            overflow:hidden;
                                            background:rgba(148,163,184,.18);
                                        "
                                    >
                                        <div
                                            x-bind:style="'width:' + progress + '%;height:100%;background:currentColor;transition:width .15s ease;'"
                                        ></div>
                                    </div>
                                </div>

                                @if ($uploadFile)
                                    <div class="gnmm-card" style="padding:14px;">
                                        <div class="gnmm-label">
                                            Ready to install
                                        </div>

                                        <div
                                            class="gnmm-section-title"
                                            style="font-size:15px;margin-top:4px;"
                                        >
                                            {{ $uploadFile->getClientOriginalName() }}
                                        </div>

                                        <div class="gnmm-meta" style="margin-top:4px;">
                                            {{ number_format($uploadFile->getSize() / 1024 / 1024, 2) }} MB
                                            • {{ $game }}

                                            @if ($game === 'Rust')
                                                • {{ $rustRuntime }}
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                <div style="display:flex;justify-content:flex-end;">
                                    <button
                                        type="submit"
                                        class="gnmm-btn gnmm-btn-primary"
                                        wire:loading.attr="disabled"
                                        wire:target="uploadFile,installUploadedFile"
                                        @disabled(!$uploadFile)
                                    >
                                        <x-heroicon-o-arrow-up-tray/>
                                        Install Uploaded File
                                    </button>
                                </div>

                                <div class="gnmm-meta">
                                    ModHarbor keeps a private retained copy so reinstall works later. It is not uploaded to GitHub, mod.io, uMod, or another external service.
                                </div>
                            </div>
                        </form>
                    </div>

                @elseif ($browseProvider === 'direct')

                    <div class="mh-github-panel">

                        <div class="mh-github-intro">
                            <div>
                                <strong>Direct Download</strong>
                                <span>
                                    Install a Rust plugin directly from an HTTPS .cs file or .zip plugin bundle.
                                </span>
                            </div>
                        </div>

                        <form
                            wire:submit="installDirectDownload"
                            class="mh-github-search"
                        >
                            <div>
                                <label class="gnmm-label">
                                    Plugin Download URL
                                </label>

                                <input
                                    type="url"
                                    wire:model="directUrl"
                                    class="gnmm-input"
                                    placeholder="https://example.com/MyPlugin.cs"
                                    autocomplete="off"
                                />

                                <div class="gnmm-field-help">
                                    Supports .cs plugins and ZIP bundles containing Rust .cs plugins.
                                    ModHarbor installs them into the detected Carbon or Oxide runtime.
                                </div>
                            </div>

                            <button
                                type="submit"
                                class="gnmm-btn gnmm-btn-primary"
                                wire:loading.attr="disabled"
                                wire:target="installDirectDownload"
                            >
                                <x-heroicon-o-arrow-down-tray/>
                                Install
                            </button>
                        </form>

                        <div class="gnmm-empty mh-github-empty">
                            <div class="gnmm-empty-title">
                                Manually managed source
                            </div>

                            <div class="gnmm-empty-text">
                                Direct URLs do not provide reliable release metadata, so ModHarbor will track installation, reinstall, configuration, history, and removal without pretending an automatic latest-version check exists.
                            </div>
                        </div>

                    </div>

                @elseif ($browseProvider === 'modio')

                    <form
                        wire:submit="searchMods"
                        class="gnmm-search"
                        style="display:grid;grid-template-columns:minmax(260px,1fr) 190px 190px auto;gap:10px;align-items:end;"
                    >
                        <div>
                            <label class="gnmm-label">Search</label>

                            <input
                                type="text"
                                wire:model="search"
                                placeholder="Search Eco mods..."
                                class="gnmm-search-input"
                            />
                        </div>

                        <div>
                            <label class="gnmm-label">Sort</label>

                            <select
                                wire:change="setModioSort($event.target.value)"
                                class="gnmm-input"
                            >
                                <option value="hot" @selected($modioSort === 'hot')>Hot Today</option>
                                <option value="downloads" @selected($modioSort === 'downloads')>Most Downloaded</option>
                                <option value="subscribers" @selected($modioSort === 'subscribers')>Most Subscribers</option>
                                <option value="rating" @selected($modioSort === 'rating')>Highest Rated</option>
                                <option value="newest" @selected($modioSort === 'newest')>Newest</option>
                                <option value="updated" @selected($modioSort === 'updated')>Recently Updated</option>
                                <option value="name" @selected($modioSort === 'name')>A–Z</option>
                            </select>
                        </div>

                        <div>
                            <label class="gnmm-label">Released</label>

                            <select
                                wire:change="setModioPeriod($event.target.value)"
                                class="gnmm-input"
                            >
                                <option value="all" @selected($modioPeriod === 'all')>All Time</option>
                                <option value="7d" @selected($modioPeriod === '7d')>Last 7 Days</option>
                                <option value="30d" @selected($modioPeriod === '30d')>Last 30 Days</option>
                                <option value="3m" @selected($modioPeriod === '3m')>Last 3 Months</option>
                                <option value="6m" @selected($modioPeriod === '6m')>Last 6 Months</option>
                                <option value="1y" @selected($modioPeriod === '1y')>Last Year</option>
                            </select>
                        </div>

                        @foreach ($modioTagGroups as $tagGroup)
                            @php
                                $tagGroupName =
                                    (string) ($tagGroup['name'] ?? 'Tags');

                                $selectedTag =
                                    (string) (
                                        $modioSelectedTags[$tagGroupName]
                                        ?? ''
                                    );
                            @endphp

                            <div>
                                <label class="gnmm-label">
                                    {{ $tagGroupName }}
                                </label>

                                <select
                                    wire:change="setModioTag(
                                        @js($tagGroupName),
                                        $event.target.value
                                    )"
                                    class="gnmm-input"
                                >
                                    <option
                                        value=""
                                        @selected($selectedTag === '')
                                    >
                                        All
                                    </option>

                                    @foreach (($tagGroup['tags'] ?? []) as $tag)
                                        @php
                                            $tagName =
                                                (string) (
                                                    $tag['name']
                                                    ?? ''
                                                );

                                            $tagCount =
                                                (int) (
                                                    $tag['count']
                                                    ?? 0
                                                );
                                        @endphp

                                        @if ($tagName !== '')
                                            <option
                                                value="{{ $tagName }}"
                                                @selected(
                                                    $selectedTag === $tagName
                                                )
                                            >
                                                {{ $tagName }}
                                                @if ($tagCount > 0)
                                                    ({{ number_format($tagCount) }})
                                                @endif
                                            </option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        @endforeach


                        <div style="display:flex;gap:8px;">
                            <button
                                type="submit"
                                class="gnmm-btn gnmm-btn-primary"
                                wire:loading.attr="disabled"
                                wire:target="searchMods"
                            >
                                <x-heroicon-o-magnifying-glass/>
                                Search
                            </button>

                            @if (trim($search) !== '')
                                <button
                                    type="button"
                                    wire:click="clearSearch"
                                    class="gnmm-btn gnmm-btn-secondary"
                                >
                                    Clear
                                </button>
                            @endif
                        </div>
                    </form>

                    <div
                        class="gnmm-meta"
                        style="display:flex;justify-content:space-between;align-items:center;margin:12px 0 14px;"
                    >
                        <span>
                            {{ number_format($modioTotal) }}
                            {{ $modioTotal === 1 ? 'mod' : 'mods' }}
                        </span>

                        @if ($modioTotal > 0)
                            <span>
                                Page {{ $modioPage }} of
                                {{ max(1, (int) ceil($modioTotal / $modioPerPage)) }}
                            </span>
                        @endif
                    </div>


                    @if ($searched && count($mods) === 0)

                        <div class="gnmm-empty">
                            <x-heroicon-o-magnifying-glass/>

                            <div class="gnmm-empty-title">
                                No mods found
                            </div>
                        </div>


                    @elseif (count($mods) > 0)

                        <div class="gnmm-grid">

                            @foreach ($mods as $mod)

                                @php
                                    $modId = (int) ($mod['id'] ?? 0);
                                    $installed = $this->isInstalled($modId);
                                    $file = $mod['latest_file'] ?? [];
                                @endphp

                                <div
                                    wire:key="modio-{{ $modId }}"
                                    class="gnmm-mod-card"
                                >

                                    @if (!empty($mod['logo']))
                                        <img
                                            src="{{ $mod['logo'] }}"
                                            alt=""
                                            class="gnmm-mod-image"
                                            loading="lazy"
                                        />
                                    @endif

                                    <div class="gnmm-mod-body">

                                        <div class="gnmm-mod-top">

                                            <div>
                                                <div class="gnmm-mod-name">
                                                    {{ $mod['name'] ?? 'Unknown Mod' }}
                                                </div>

                                                <div class="gnmm-mod-author">
                                                    by {{ $mod['author'] ?? 'Unknown' }}
                                                </div>
                                            </div>

                                            @if ($installed)
                                                <span class="gnmm-installed-badge">
                                                    Installed
                                                </span>
                                            @endif

                                        </div>

                                        @if (!empty($mod['summary']))
                                            <div class="gnmm-summary">
                                                {{ $mod['summary'] }}
                                            </div>
                                        @endif

                                        <div class="gnmm-meta">

                                            @if (!empty($file['version']))
                                                <span>
                                                    Version {{ $file['version'] }}
                                                </span>
                                            @endif

                                            @if (!empty($file['filesize']))
                                                <span>
                                                    {{ $this->formatBytes((int) $file['filesize']) }}
                                                </span>
                                            @endif

                                            @if (!empty($mod['downloads']))
                                                <span>
                                                    {{ number_format((int) $mod['downloads']) }} downloads
                                                </span>
                                            @endif

                                            @if (!empty($mod['date_updated']))
                                                <span title="Updated {{ \Carbon\Carbon::createFromTimestamp((int) $mod['date_updated'])->format('M j, Y') }}">
                                                    Updated {{ \Carbon\Carbon::createFromTimestamp((int) $mod['date_updated'])->diffForHumans() }}
                                                </span>
                                            @endif


                                        </div>

                                        <div class="gnmm-actions">

                                            @if (!empty($mod['profile_url']))
                                                <a
                                                    href="{{ $mod['profile_url'] }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="gnmm-link"
                                                >
                                                    View on mod.io
                                                </a>
                                            @else
                                                <span></span>
                                            @endif

                                            @if ($installed)

                                                <button
                                                    type="button"
                                                    disabled
                                                    class="gnmm-btn gnmm-btn-disabled"
                                                >
                                                    Installed
                                                </button>

                                            @else

                                                <button
                                                    type="button"
                                                    wire:click="installMod({{ $modId }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="installMod({{ $modId }})"
                                                    class="gnmm-btn gnmm-btn-primary"
                                                >
                                                    <x-heroicon-o-arrow-down-tray/>
                                                    Install
                                                </button>

                                            @endif

                                        </div>

                                    </div>

                                </div>

                            @endforeach

                        </div>



                        @if ($modioTotal > $modioPerPage)
                            <div
                                style="
                                    display:flex;
                                    justify-content:center;
                                    align-items:center;
                                    gap:12px;
                                    margin-top:20px;
                                "
                            >
                                <button
                                    type="button"
                                    wire:click="previousModioPage"
                                    wire:loading.attr="disabled"
                                    wire:target="previousModioPage,nextModioPage"
                                    @disabled($modioPage <= 1)
                                    class="gnmm-btn {{ $modioPage <= 1 ? 'gnmm-btn-disabled' : 'gnmm-btn-secondary' }}"
                                >
                                    Previous
                                </button>

                                <span class="gnmm-meta">
                                    Page {{ $modioPage }}
                                    of
                                    {{ max(1, (int) ceil($modioTotal / $modioPerPage)) }}
                                </span>

                                <button
                                    type="button"
                                    wire:click="nextModioPage"
                                    wire:loading.attr="disabled"
                                    wire:target="previousModioPage,nextModioPage"
                                    @disabled(
                                        $modioPage >=
                                        max(
                                            1,
                                            (int) ceil(
                                                $modioTotal / $modioPerPage
                                            )
                                        )
                                    )
                                    class="gnmm-btn {{
                                        $modioPage >=
                                        max(
                                            1,
                                            (int) ceil(
                                                $modioTotal / $modioPerPage
                                            )
                                        )
                                            ? 'gnmm-btn-disabled'
                                            : 'gnmm-btn-secondary'
                                    }}"
                                >
                                    Next
                                </button>
                            </div>
                        @endif


@elseif (!$searched)

                        <div class="gnmm-empty">
                            <x-heroicon-o-magnifying-glass/>

                            <div class="gnmm-empty-title">
                                Search mod.io
                            </div>

                            <div class="gnmm-empty-text">
                                Find Eco mods by name or keyword and install them directly to this server.
                            </div>
                        </div>

                    @endif

                @elseif ($this->sourceCapability($browseProvider, 'discover'))

                    <div class="mh-github-panel">
                        <div class="mh-github-intro">
                            <div>
                                <strong>{{ $this->discoveryTitle() }}</strong>
                                <span>{{ $this->discoveryDescription() }}</span>
                            </div>
                        </div>

                        <form
                            wire:submit="searchMods"
                            class="gnmm-search"
                            style="display:grid;grid-template-columns:minmax(260px,1fr) 210px auto;gap:10px;align-items:end;"
                        >
                            <div>
                                <label class="gnmm-label">Search</label>
                                <input
                                    type="text"
                                    wire:model="search"
                                    placeholder="{{ $this->discoverySearchPlaceholder() }}"
                                    class="gnmm-search-input"
                                    autocomplete="off"
                                />
                            </div>

                            @if (!empty($discoverySchema['sorts']))
                                <div>
                                    <label class="gnmm-label">Sort</label>
                                    <select
                                        wire:change="setDiscoverySort($event.target.value)"
                                        class="gnmm-input"
                                    >
                                        @foreach ($discoverySchema['sorts'] as $sortKey => $sortLabel)
                                            <option value="{{ $sortKey }}" @selected($discoverySort === $sortKey)>
                                                {{ $sortLabel }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            <button type="submit" class="gnmm-btn gnmm-btn-primary" wire:loading.attr="disabled">
                                <x-heroicon-o-magnifying-glass/> Search
                            </button>
                        </form>

                        @foreach (($discoverySchema['filters'] ?? []) as $filterKey => $filterDefinition)
                            @if (!empty($filterDefinition['options']))
                                <div style="margin-top:12px;max-width:240px;">
                                    <label class="gnmm-label">{{ $filterDefinition['label'] ?? ucfirst($filterKey) }}</label>
                                    <select
                                        wire:change="setDiscoveryFilter(@js($filterKey), $event.target.value)"
                                        class="gnmm-input"
                                    >
                                        @foreach ($filterDefinition['options'] as $filterValue => $filterLabel)
                                            <option value="{{ $filterValue }}" @selected(($discoveryFilters[$filterKey] ?? '') === $filterValue)>
                                                {{ $filterLabel }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    <div class="gnmm-section-subtitle" style="margin:14px 0;">
                        {{ number_format($discoveryTotal) }} packages
                        @if ($discoveryTotal > 0)
                            · Page {{ $discoveryPage }} of {{ $discoveryLastPage }}
                        @endif
                    </div>

                    @if (count($mods) > 0)
                        <div class="gnmm-grid">
                            @foreach ($mods as $mod)
                                @php
                                    $genericId = (string) ($mod['provider_id'] ?? $mod['id'] ?? '');
                                    $genericKey = $browseProvider . ':' . $genericId;
                                    $genericInstalled = isset($installedMods[$genericKey]);
                                    $genericFile = is_array($mod['latest_file'] ?? null) ? $mod['latest_file'] : [];
                                    $genericVersion = (string) ($genericFile['version'] ?? $mod['version'] ?? '');
                                    $genericSourceVersion = (string) ($genericFile['id'] ?? '');
                                @endphp

                                <div class="gnmm-mod-card" wire:key="discover-{{ $browseProvider }}-{{ sha1($genericId) }}">
                                    @if (!empty($mod['logo']))
                                        <img src="{{ $mod['logo'] }}" alt="" class="gnmm-mod-image" loading="lazy" referrerpolicy="no-referrer" />
                                    @endif

                                    <div class="gnmm-mod-body">
                                        <div class="gnmm-mod-top">
                                            <div>
                                                <div class="gnmm-mod-name">{{ $mod['title'] ?? $mod['name'] ?? $genericId }}</div>
                                                @if (!empty($mod['author']))
                                                    <div class="gnmm-mod-author">by {{ $mod['author'] }}</div>
                                                @endif
                                            </div>
                                            @if ($genericInstalled)
                                                <span class="gnmm-installed-badge">Installed</span>
                                            @endif
                                        </div>

                                        @if (!empty($mod['summary']))
                                            <div class="gnmm-summary">{{ $mod['summary'] }}</div>
                                        @endif

                                        <div class="gnmm-meta">
                                            @if ($genericVersion !== '')<span>Version {{ $genericVersion }}</span>@endif
                                            @if (!empty($mod['downloads']))<span>{{ number_format((int) $mod['downloads']) }} downloads</span>@endif
                                        </div>

                                        <div class="gnmm-actions">
                                            @if (!empty($mod['profile_url']))
                                                <a href="{{ $mod['profile_url'] }}" target="_blank" rel="noopener noreferrer" class="gnmm-link">
                                                    View on {{ $this->providerLabel($browseProvider) }}
                                                </a>
                                            @else
                                                <span></span>
                                            @endif

                                            @if ($genericInstalled)
                                                <button type="button" disabled class="gnmm-btn gnmm-btn-disabled">Installed</button>
                                            @else
                                                <button
                                                    type="button"
                                                    class="gnmm-btn gnmm-btn-secondary"
                                                    wire:click="inspectPackageVersions(@js($genericKey))"
                                                    wire:loading.attr="disabled"
                                                >
                                                    Versions
                                                </button>

                                                @if (
                                                    $browseProvider !== 'upload'
                                                    && in_array('upload', $providers, true)
                                                )
                                                    <button
                                                        type="button"
                                                        class="gnmm-btn gnmm-btn-secondary"
                                                        wire:click="setBrowseProvider('upload')"
                                                        wire:loading.attr="disabled"
                                                    >
                                                        <x-heroicon-o-arrow-up-tray/>
                                                        Upload File
                                                    </button>
                                                @endif

                                                <button
                                                    type="button"
                                                    class="gnmm-btn gnmm-btn-primary"
                                                    wire:click="requestAction('install', @js($genericKey), @js((string) ($mod['title'] ?? $mod['name'] ?? $genericId)), @js($browseProvider), @js($genericVersion), @js($genericSourceVersion))"
                                                    wire:loading.attr="disabled"
                                                >
                                                    <x-heroicon-o-arrow-down-tray/> Install
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if ($discoveryLastPage > 1)
                            <div style="display:flex;justify-content:center;align-items:center;gap:12px;margin-top:20px;">
                                <button type="button" wire:click="previousDiscoveryPage" @disabled($discoveryPage <= 1) class="gnmm-btn {{ $discoveryPage <= 1 ? 'gnmm-btn-disabled' : 'gnmm-btn-secondary' }}">Previous</button>
                                <span class="gnmm-meta">Page {{ $discoveryPage }} of {{ $discoveryLastPage }}</span>
                                <button type="button" wire:click="nextDiscoveryPage" @disabled($discoveryPage >= $discoveryLastPage) class="gnmm-btn {{ $discoveryPage >= $discoveryLastPage ? 'gnmm-btn-disabled' : 'gnmm-btn-secondary' }}">Next</button>
                            </div>
                        @endif
                    @else
                        <div class="gnmm-empty">
                            <x-heroicon-o-magnifying-glass/>
                            <div class="gnmm-empty-title">No packages found</div>
                            <div class="gnmm-empty-text">Try a different search or filter.</div>
                        </div>
                    @endif

                @else

                    <div class="gnmm-empty">
                        <x-heroicon-o-wrench-screwdriver/>

                        <div class="gnmm-empty-title">
                            Select an available provider
                        </div>
                    </div>

                @endif

        @elseif ($activeTab === 'installed')
                @php
                    $displayMods = $this->installedDisplayMods();
                    $updateCount = collect($this->installedMods)
                        ->filter(function ($mod, $key) {
                            $update = $this->updateResults[$key] ?? [];

                            if (
                                !empty($update['update_available'])
                                || !empty($update['has_update'])
                                || !empty($update['available'])
                            ) {
                                return true;
                            }

                            return !empty($update['latest'])
                                && !empty($mod['version'])
                                && (string) $update['latest'] !== (string) $mod['version'];
                        })
                        ->count();
                    $installedStats = $this->installedStats();
                    $installedProviderOptions = $this->installedProviderOptions();
                    $updateAllCount = $this->updateAllCount();
                @endphp

                <div class="gnmm-installed-header">
                    <div>

                    <h3 class="gnmm-section-title">Installed Mods</h3>

                    @php
                        $healthStatus = $health['status'] ?? 'unknown';
                        $selectedDetails = $this->selectedModDetails();
                    @endphp

                    <div class="gnmm-health-grid">
                        <div class="gnmm-health-stat">
                            <div class="gnmm-health-label">Managed</div>
                            <div class="gnmm-health-value">
                                {{ $health['managed'] ?? count($installedMods) }}
                            </div>
                        </div>

                        <div class="gnmm-health-stat">
                            <div class="gnmm-health-label">Updates</div>
                            <div class="gnmm-health-value">
                                {{ collect($updateResults)->filter(fn ($row) => !empty($row['available']) || !empty($row['has_update']) || !empty($row['update_available']))->count() }}
                            </div>
                        </div>

                        <div class="gnmm-health-stat">
                            <div class="gnmm-health-label">Issues</div>
                            <div class="gnmm-health-value {{ ($health['issues'] ?? 0) > 0 ? 'gnmm-health-warn' : 'gnmm-health-ok' }}">
                                {{ $health['issues'] ?? 0 }}
                            </div>
                        </div>

                        <div class="gnmm-health-stat">
                            <div class="gnmm-health-label">Unmanaged</div>
                            <div class="gnmm-health-value {{ ($health['unmanaged'] ?? 0) > 0 ? 'gnmm-health-warn' : '' }}">
                                {{ $health['unmanaged'] ?? 0 }}
                            </div>
                        </div>

                        <div class="gnmm-health-stat">
                            <div class="gnmm-health-label">Runtime</div>
                            <div class="gnmm-health-value">
                                {{ $health['runtime'] ?? ($game === 'Rust' ? $rustRuntime : $game) }}
                            </div>
                        </div>

                        <div class="gnmm-health-stat">
                            <div class="gnmm-health-label">Manifest</div>
                            <div class="gnmm-health-value {{ ($health['manifest'] ?? 'unknown') === 'healthy' ? 'gnmm-health-ok' : 'gnmm-health-bad' }}">
                                {{ ucfirst($health['manifest'] ?? 'Unknown') }}
                            </div>
                        </div>
                    </div>

                    <div class="gnmm-health-actions">
                        <button
                            type="button"
                            class="gnmm-btn gnmm-btn-secondary"
                            wire:click="verifyAll"
                            wire:loading.attr="disabled"
                            wire:target="verifyAll"
                        >
                            Verify All
                        </button>

                        <button
                            type="button"
                            class="gnmm-btn gnmm-btn-secondary"
                            wire:click="repairAllBroken" @disabled(count($unresolvedOperations) > 0)
                            wire:loading.attr="disabled"
                            wire:target="repairAllBroken"
                        >
                            Repair Broken
                        </button>
                    </div>

                    @if (($health['stale_runtime'] ?? 0) > 0)
                        <div class="gnmm-details-panel">
                            <strong>Stale Rust runtime files detected</strong>
                            <div class="gnmm-details-list">
                                ModHarbor did not delete these automatically:
                                {{ implode(', ', $health['stale_runtime_files'] ?? []) }}
                            </div>
                        </div>
                    @endif

                    @if (($health['interrupted'] ?? 0) > 0)
                        <div class="gnmm-details-panel">
                            <strong class="gnmm-health-bad">
                                Recovery required
                            </strong>

                            <div class="gnmm-details-list">
                                Mod changes remain locked until the interrupted operation is safely restored.
                                Keep the game server stopped while recovery is attempted.
                            </div>

                            @foreach (($health['operation_issues'] ?? []) as $operationIssue)
                                @if (in_array(($operationIssue['status'] ?? ''), ['running', 'recovery_required'], true))
                                    <div
                                        class="gnmm-details-item"
                                        wire:key="recovery-{{ $operationIssue['id'] }}"
                                        style="margin-top:12px;"
                                    >
                                        <div class="gnmm-health-label">
                                            {{ $operationIssue['name'] ?? 'Interrupted operation' }}
                                        </div>

                                        <div class="gnmm-field-help">
                                            {{ $operationIssue['message'] ?? 'Administrator recovery is required.' }}
                                        </div>

                                        <div class="gnmm-field-help">
                                            Operation reference:
                                            {{ $operationIssue['id'] }}
                                        </div>

                                        @if (!empty($operationIssue['id']))
                                            <div style="margin-top:10px;">
                                                <button
                                                    type="button"
                                                    class="gnmm-btn gnmm-btn-secondary"
                                                    wire:click="retryOperationRecovery(@js($operationIssue['id']))"
                                                    wire:loading.attr="disabled"
                                                    wire:target="retryOperationRecovery"
                                                >
                                                    Retry Recovery
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    @if ($selectedDetails)
                        @php
                            $detailMod = $selectedDetails['mod'];
                            $detailHealth = $selectedDetails['health'] ?? [];
                        @endphp

                        <div class="gnmm-details-panel">
                            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
                                <strong>{{ $detailMod['name'] ?? $selectedDetails['key'] }}</strong>

                                <button
                                    type="button"
                                    class="gnmm-btn gnmm-btn-secondary"
                                    wire:click="toggleModDetails(@js($selectedDetails['key']))"
                                >
                                    Close
                                </button>
                            </div>

                            <div class="gnmm-details-grid">
                                <div class="gnmm-details-item">
                                    <div class="gnmm-health-label">Provider</div>
                                    <div class="gnmm-health-value">
                                        {{ $this->providerLabel($detailMod['provider'] ?? '') }}
                                    </div>
                                </div>

                                <div class="gnmm-details-item">
                                    <div class="gnmm-health-label">Version</div>
                                    <div class="gnmm-health-value">
                                        {{ $detailMod['version'] ?? 'Unknown' }}
                                    </div>
                                </div>

                                <div class="gnmm-details-item">
                                    <div class="gnmm-health-label">State</div>
                                    <div class="gnmm-health-value">
                                        {{ !empty($detailMod['enabled']) ? 'Enabled' : 'Disabled' }}
                                    </div>
                                </div>

                                <div class="gnmm-details-item">
                                    <div class="gnmm-health-label">Health</div>
                                    <div class="gnmm-health-value {{ ($detailHealth['status'] ?? '') === 'healthy' ? 'gnmm-health-ok' : 'gnmm-health-warn' }}">
                                        {{ ucfirst($detailHealth['status'] ?? 'Unknown') }}
                                    </div>
                                </div>
                            </div>

                            <div class="gnmm-details-list">
                                <strong>Managed files:</strong>
                                {{ count($detailMod['paths'] ?? []) }}

                                @if (!empty($detailHealth['missing']))
                                    <br>
                                    <strong class="gnmm-health-bad">Missing:</strong>
                                    {{ implode(', ', $detailHealth['missing']) }}
                                @endif

                                @if (!empty($detailHealth['issues']))
                                    <br>
                                    <strong>Health:</strong>
                                    {{ implode(' ', $detailHealth['issues']) }}
                                @endif

                                @if (!empty($selectedDetails['configs']))
                                    <br>
                                    @foreach ($this->installedDependencies($detailsKey) as $edge)
                                        <div>{{ ucfirst(str_replace('_', ' ', $edge['type'])) }}: {{ $edge['key'] }} {{ $edge['constraint'] ?? '' }}
                                            @if (!empty($edge['file_id'])) · Release {{ $edge['file_id'] }} @endif</div>
                                    @endforeach
                                    <strong>Configs:</strong>
                                    {{ implode(', ', array_column($selectedDetails['configs'], 'path')) }}
                                @endif

                                @if (!empty($selectedDetails['history']))
                                    <br>
                                    <strong>Recent activity:</strong>
                                    @foreach ($selectedDetails['history'] as $detailEvent)
                                        {{ ucfirst($detailEvent['action'] ?? 'operation') }}
                                        {{ $detailEvent['status'] ?? '' }}
                                        @if (!$loop->last) • @endif
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    @endif


                        <p class="gnmm-section-subtitle">
                            Manage mods installed on this server.
                            @if ($updateCount)
                                <span class="gnmm-update-summary">
                                    {{ $updateCount }} update{{ $updateCount === 1 ? '' : 's' }} available
                                </span>
                            @else
                                Use Check Updates to refresh provider versions.
                            @endif
                        </p>
                    </div>

                    <div class="gnmm-installed-count">
                        {{ $installedStats['total'] }} managed · {{ $installedStats['providers'] }} source{{ $installedStats['providers'] === 1 ? '' : 's' }}
                    </div>
                </div>

                <details class="gnmm-notice">
                    <summary>Bulk actions</summary>
                    <p>Each mod and its dependencies is verified as one transaction. The batch stops on failure; completed changes remain. Start the server once after all changes.</p>
                    @foreach ($displayMods as $bulkMod)
                        <label style="display:block" wire:key="bulk-{{ hash('sha256', $bulkMod['_key']) }}">
                            <input type="checkbox" wire:model="selectedInstalled" value="{{ $bulkMod['_key'] }}">
                            {{ $bulkMod['name'] }} · {{ $this->providerLabel($bulkMod['provider']) }}
                        </label>
                    @endforeach
                    <select wire:model="bulkAction" aria-label="Bulk action">
                        <option value="update">Update</option><option value="reinstall">Reinstall</option>
                        <option value="enable">Enable</option><option value="disable">Disable</option><option value="remove">Remove</option>
                    </select>
                    <button type="button" class="gnmm-btn gnmm-btn-secondary" wire:click="runSelectedMods"
                        wire:confirm="Apply this action to all selected mods? Removal preserves user configs. Completed operations remain if the batch stops."
                        wire:loading.attr="disabled">Apply to selected</button>
                    @if ($bulkResult)
                        <p role="status">Completed: {{ count($bulkResult['completed']) }} · Skipped: {{ count($bulkResult['skipped']) }} · Remaining: {{ count($bulkResult['remaining']) }}.
                        {{ $bulkResult['failed'] ? 'Batch stopped. Open History to review the failed operation before retrying.' : 'Batch finished.' }}</p>
                    @endif
                </details>

                <div class="gnmm-installed-toolbar">
                    <div class="gnmm-installed-search">
                        <x-heroicon-o-magnifying-glass/>

                        <input
                            type="search"
                            wire:model.live.debounce.250ms="installedSearch"
                            placeholder="Search installed mods..."
                            autocomplete="off"
                        >
                    </div>

                    <select
                        class="gnmm-installed-sort"
                        wire:model.live="installedProviderFilter"
                        aria-label="Filter installed mods by provider"
                    >
                        <option value="all">All Providers</option>
                        @foreach ($installedProviderOptions as $providerKey => $providerLabel)
                            <option value="{{ $providerKey }}">{{ $providerLabel }}</option>
                        @endforeach
                    </select>

                    <select
                        class="gnmm-installed-sort"
                        wire:model.live="installedStatusFilter"
                        aria-label="Filter installed mods by status"
                    >
                        <option value="all">All Statuses</option>
                        <option value="updates">Updates Available</option>
                        <option value="current">Current & Enabled</option>
                        <option value="disabled">Disabled</option>
                        <option value="dependencies">Dependencies</option>
                        <option value="adopted">Adopted</option>
                    </select>

                    <select
                        class="gnmm-installed-sort"
                        wire:model.live="installedSort"
                        aria-label="Sort installed mods"
                    >
                        <option value="updates">Updates First</option>
                        <option value="name">Name</option>
                        <option value="recent">Recently Changed</option>
                    </select>

                    <button
                        type="button"
                        class="gnmm-btn gnmm-btn-secondary gnmm-check-btn"
                        wire:click="checkUpdates"
                        wire:loading.attr="disabled"
                        wire:target="checkUpdates"
                    >
                        <x-heroicon-o-arrow-path/>
                        Check Updates
                    </button>

                    @if ($updateAllCount > 0)
                        <button
                            type="button"
                            class="gnmm-btn gnmm-btn-primary gnmm-check-btn"
                            wire:click="requestUpdateAll"
                            wire:loading.attr="disabled"
                            wire:target="requestUpdateAll,confirmAction"
                        >
                            <x-heroicon-o-arrow-down-tray/>
                            Update All ({{ $updateAllCount }})
                        </button>
                    @endif


                </div>


                @if (!empty($health['config_issues']) || !empty($health['discovery_error']))
                    <div class="gnmm-notice">
                        <strong>Configuration health</strong>
                        <p>{{ $health['discovery_error'] ?? 'Some configurations need review. Open Configs to validate or restore a revision. Health validation checks up to 100 files.' }}</p>
                        @foreach ($health['config_health'] ?? [] as $configHealth)
                            @if ($configHealth['validation'] !== 'valid')<div>{{ $configHealth['path'] }} · {{ $configHealth['validation'] }}</div>@endif
                        @endforeach
                    </div>
                @endif
                <div class="gnmm-notice">
                    <strong>Existing mod files</strong>
                    <p>Scan adapter-approved locations. Each selected file becomes one managed item with a retained reinstall copy. Select all files belonging to a multi-file mod.</p>
                    <button type="button" class="gnmm-btn gnmm-btn-secondary" wire:click="scanExistingMods" wire:loading.attr="disabled">Scan Existing Mods</button>
                    @if ($scanError)<p role="alert">{{ $scanError }}</p>@endif
                    @foreach ($scanCandidates as $candidate)
                        <label class="gnmm-installed-row" wire:key="candidate-{{ $candidate['id'] }}">
                            <input type="checkbox" wire:model="selectedCandidates" value="{{ $candidate['path'] }}" @disabled(count($candidate['owners']) > 0 || count($unresolvedOperations) > 0)>
                            <span>{{ $candidate['path'] }} · {{ $candidate['status'] }}</span>
                        </label>
                    @endforeach
                    @if (count($scanCandidates))
                        <input class="gnmm-input" wire:model="adoptVersionLabel" aria-label="Adopted version label" maxlength="120">
                        <button type="button" class="gnmm-btn gnmm-btn-primary" wire:click="adoptSelectedMods"
                            wire:confirm="Adopt the selected existing files into ModHarbor? Keep the server stopped."
                            wire:loading.attr="disabled" @disabled(count($unresolvedOperations) > 0)>Adopt Selected</button>
                    @endif
                </div>

                @if ($replaceUploadKey)
                    @php
                        $replaceEntry =
                            $installedMods[$replaceUploadKey]
                            ?? null;
                    @endphp

                    <div
                        class="mh-github-panel"
                        style="margin-bottom:18px;"
                    >
                        <div class="mh-github-intro">
                            <div>
                                <strong>
                                    Replace File
                                    @if ($replaceEntry)
                                        — {{ $replaceEntry['name'] ?? $replaceUploadKey }}
                                    @endif
                                </strong>

                                <span>
                                    Upload a newer private/local package. ModHarbor will validate and replace only files owned by this managed mod.
                                </span>
                            </div>
                        </div>

                        <form
                            wire:submit="replaceUploadedFile"
                            style="display:grid;gap:14px;"
                        >
                            <div>
                                <input
                                    type="file"
                                    wire:model="replaceUploadFile"
                                    accept="{{ $this->uploadAccept() }}"
                                    class="gnmm-input"
                                >
                            </div>

                            <div>
                                <label class="gnmm-label">
                                    Version / build label
                                </label>

                                <input
                                    type="text"
                                    wire:model="replaceUploadVersionLabel"
                                    class="gnmm-input"
                                    maxlength="64"
                                    placeholder="Optional — e.g. 1.5.0, Custom Build 4"
                                >

                                <div class="gnmm-field-help">
                                    Optional. The replacement becomes the retained source used by Reinstall.
                                </div>
                            </div>


                            @if ($replaceUploadFile)
                                <div class="gnmm-card" style="padding:14px;">
                                    <div class="gnmm-label">
                                        Replacement ready
                                    </div>

                                    <div
                                        class="gnmm-section-title"
                                        style="font-size:15px;margin-top:4px;"
                                    >
                                        {{ $replaceUploadFile->getClientOriginalName() }}
                                    </div>

                                    <div
                                        class="gnmm-meta"
                                        style="margin-top:4px;"
                                    >
                                        {{ number_format($replaceUploadFile->getSize() / 1024 / 1024, 2) }} MB
                                        • {{ $game }}

                                        @if ($game === 'Rust')
                                            • {{ $rustRuntime }}
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <div
                                style="
                                    display:flex;
                                    justify-content:flex-end;
                                    gap:8px;
                                "
                            >
                                <button
                                    type="button"
                                    class="gnmm-btn gnmm-btn-secondary"
                                    wire:click="cancelReplaceUpload"
                                    wire:loading.attr="disabled"
                                >
                                    Cancel
                                </button>

                                <button
                                    type="submit"
                                    class="gnmm-btn gnmm-btn-primary"
                                    wire:loading.attr="disabled"
                                    wire:target="replaceUploadFile,replaceUploadedFile"
                                    @disabled(!$replaceUploadFile)
                                >
                                    <x-heroicon-o-document-arrow-up/>
                                    Replace File
                                </button>
                            </div>

                            <div class="gnmm-meta">
                                The old managed files are backed up transactionally during replacement. If deployment fails, ModHarbor rolls the file changes back.
                            </div>
                        </form>
                    </div>
                @endif

                @if (count($displayMods) > 0)
                    <div class="gnmm-mod-table">
                        <div class="gnmm-mod-table-head">
                            <div>Mod</div>
                            <div>Provider</div>
                            <div>Installed</div>
                            <div>Latest</div>
                            <div>Status</div>
                            <div class="gnmm-actions-heading">Actions</div>
                        </div>

                        @foreach ($displayMods as $key => $installed)
                            @php
                                $requiredNames = array_map(
                                    fn ($parent) => $installedMods[$parent]['name'] ?? $parent,
                                    $installed['required_by'] ?? []
                                );

                                $hasUpdate = !empty($installed['_has_update']);
                                $latest = $installed['_latest'] ?? null;
                                $enabled = $installed['enabled'] ?? true;

                                $uploadDetails =
                                    ($installed['provider'] ?? '') === 'upload'
                                        ? $this->uploadPackageDetails($installed)
                                        : null;

                                $managedFileCount =
                                    $this->managedFileCount($installed);
                            @endphp

                            <div
                                class="gnmm-mod-row {{ $hasUpdate ? 'gnmm-mod-row-update' : '' }}"
                                wire:key="installed-{{ $key }}"
                            >
                                <div class="gnmm-mod-main">
                                    <div class="gnmm-mod-avatar">
                                        @if (!empty($installed['_logo']))
                                            <img
                                                src="{{ $installed['_logo'] }}"
                                                alt=""
                                                loading="lazy"
                                                referrerpolicy="no-referrer"
                                            >
                                        @else
                                            <span>
                                                {{ strtoupper(substr($installed['name'] ?? 'M', 0, 1)) }}
                                            </span>
                                        @endif
                                    </div>

                                    <div class="gnmm-mod-copy">
                                        <div class="gnmm-mod-name-line">
                                            @if (!empty($installed['_profile_url']))
                                                <a
                                                    href="{{ $installed['_profile_url'] }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="gnmm-mod-profile-link"
                                                    title="Open on {{ $this->providerLabel($installed['provider'] ?? 'unknown') }}"
                                                >
                                                    {{ $installed['name'] ?? $key }}
                                                    <x-heroicon-o-arrow-top-right-on-square/>
                                                </a>
                                            @else
                                                <strong>{{ $installed['name'] ?? $key }}</strong>
                                            @endif

                                            @if (!empty($installed['dependency']))
                                                <span class="gnmm-status-pill gnmm-status-dependency">
                                                    Dependency
                                                </span>
                                            @endif
                                        </div>

                                        @if ($requiredNames)
                                            @php
                                                $dependencyRequirements =
                                                    $installed['_requirements']
                                                    ?? [];

                                                $dependencyConstraints =
                                                    collect($dependencyRequirements)
                                                        ->pluck('constraint')
                                                        ->filter()
                                                        ->unique()
                                                        ->values()
                                                        ->all();

                                                $requiredConstraint =
                                                    implode(
                                                        ', ',
                                                        $dependencyConstraints
                                                    );

                                                $providerLatest =
                                                    $installed['_provider_latest']
                                                    ?? '';

                                                $compatibleLatest =
                                                    $installed['_latest']
                                                    ?? '';
                                            @endphp

                                            <div class="gnmm-mod-description">
                                                Required by {{ implode(', ', $requiredNames) }}
                                            </div>

                                            @if ($requiredConstraint !== '')
                                                <div class="gnmm-field-help">
                                                    Required version:
                                                    <strong>
                                                        {{ $requiredConstraint }}
                                                    </strong>

                                                    @if ($compatibleLatest !== '')
                                                        · Newest compatible:
                                                        <strong>
                                                            {{ $compatibleLatest }}
                                                        </strong>
                                                    @endif
                                                </div>
                                            @endif

                                            @if (
                                                $providerLatest !== ''
                                                && $compatibleLatest !== ''
                                                && $providerLatest !== $compatibleLatest
                                            )
                                                <div class="gnmm-field-help">
                                                    Latest
                                                    {{ $this->providerLabel(
                                                        $installed['provider']
                                                        ?? 'unknown'
                                                    ) }}
                                                    release:
                                                    {{ $providerLatest }}
                                                </div>
                                            @endif
                                        @elseif (!empty($installed['_summary']))
                                            <div
                                                class="gnmm-mod-description"
                                                title="{{ $installed['_summary'] }}"
                                            >
                                                {{ $installed['_summary'] }}
                                            </div>
                                        @elseif (!empty($installed['_author']))
                                            <div class="gnmm-mod-description">
                                                By {{ $installed['_author'] }}
                                            </div>
                                        @else
                                            <div class="gnmm-mod-description">
                                                Managed by ModHarbor
                                            </div>
                                        @endif

                                            @if (($installed['provider'] ?? '') === 'umod')
                                                @php
                                                    $installedSuggestions =
                                                        $this->uModSuggestionsFor($installed);
                                                @endphp

                                                @if ($installedSuggestions)
                                                    <div class="gnmm-field-help">
                                                        <strong>Suggested integrations:</strong>

                                                        @foreach ($installedSuggestions as $suggestion)
                                                            <span>
                                                                {{ $suggestion['name'] ?? $suggestion['id'] }}

                                                                @if (!empty($suggestion['constraint']))
                                                                    ({{ $suggestion['constraint'] }})
                                                                @endif

                                                                @if (!empty($suggestion['installed']))
                                                                    ✓ installed
                                                                @endif

                                                                @if (!$loop->last)
                                                                    ·
                                                                @endif
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            @endif

                                    </div>
                                </div>

                                <div class="gnmm-provider-cell">
                                    <strong>
                                        {{ $this->providerLabel($installed['provider'] ?? 'unknown') }}
                                    </strong>

                                    @if (
                                        ($installed['provider'] ?? '') === 'upload'
                                        && $uploadDetails
                                    )
                                        <span>
                                            {{ $uploadDetails['filename'] ?? 'Private upload' }}
                                        </span>

                                        <span>
                                            {{ $managedFileCount }}
                                            managed
                                            {{ $managedFileCount === 1 ? 'file' : 'files' }}
                                        </span>

                                        @if (!empty($uploadDetails['created_at']))
                                            <span>
                                                {{
                                                    ($uploadDetails['source_type'] ?? 'upload') === 'adopted'
                                                        ? 'Adopted'
                                                        : 'Uploaded'
                                                }}
                                                {{
                                                    \Illuminate\Support\Carbon::parse(
                                                        $uploadDetails['created_at']
                                                    )->diffForHumans()
                                                }}
                                            </span>
                                        @endif
                                    @elseif (!empty($installed['provider_id']))
                                        <span>
                                            @if (($installed['provider'] ?? '') === 'modio')
                                                #{{ $installed['provider_id'] }}
                                            @else
                                                {{ $installed['provider_id'] }}
                                            @endif
                                        </span>
                                    @endif
                                </div>

                                <div class="gnmm-version-cell">
                                    {{ $installed['version'] ?? 'Unknown' }}
                                </div>

                                <div
                                    class="gnmm-version-cell {{ $hasUpdate ? 'gnmm-version-new' : '' }}"
                                    @if (!empty($installed['_constrained']))
                                        title="Newest version compatible with installed dependency requirements"
                                    @endif
                                >
                                    {{ $latest ?: 'Unknown' }}

                                    @if (!empty($installed['_constrained']))
                                        <span class="gnmm-field-help">
                                            compatible
                                        </span>
                                    @endif
                                </div>

                                <div>
                                    @if ($hasUpdate)
                                        <span class="gnmm-status-pill gnmm-status-update">
                                            <x-heroicon-o-arrow-up/>
                                            Update Available
                                        </span>
                                    @elseif (!$enabled)
                                        <span class="gnmm-status-pill gnmm-status-disabled">
                                            Disabled
                                        </span>
                                    @else
                                        <span
                                            class="gnmm-status-pill gnmm-status-current"
                                            @if (!empty($installed['_constrained']))
                                                title="Newest version allowed by dependency requirements is installed"
                                            @endif
                                        >
                                            <x-heroicon-o-check/>

                                            {{ !empty($installed['_constrained'])
                                                ? 'Compatible'
                                                : ($installed['_update_status'] ?? 'Not checked') }}
                                        </span>
                                    @endif
                                </div>

                                <div class="gnmm-compact-actions">
                                    @if ($this->lifecycleSupported($installed['provider'] ?? ''))
                                        @if ($hasUpdate)
                                            <button
                                                type="button"
                                                class="gnmm-icon-btn gnmm-icon-btn-update"
                                                title="Update"
                                                wire:click="requestAction('update', @js($key))"
                                                wire:loading.attr="disabled"
                                            >
                                                <x-heroicon-o-arrow-down-tray/>
                                            </button>
                                        @endif

                                        @if (empty($installed['enabled']) || !$requiredNames)
                                            <button
                                                type="button"
                                                class="gnmm-icon-btn"
                                                title="{{ empty($installed['enabled']) ? 'Enable' : 'Disable' }}"
                                                wire:click="requestAction('{{ empty($installed['enabled']) ? 'enable' : 'disable' }}', @js($key))"
                                                wire:loading.attr="disabled"
                                            >
                                                @if (empty($installed['enabled']))
                                                    <x-heroicon-o-play/>
                                                @else
                                                    <x-heroicon-o-pause/>
                                                @endif
                                            </button>
                                        @endif

                                        @if (($installed['provider'] ?? '') === 'upload')
                                            <button
                                                type="button"
                                                class="gnmm-icon-btn"
                                                title="Replace File"
                                                wire:click="startReplaceUpload(@js($key))"
                                                wire:loading.attr="disabled"
                                            >
                                                <x-heroicon-o-document-arrow-up/>
                                            </button>
                                        @endif


                                        <button
                                            type="button"
                                            class="gnmm-icon-action"
                                            wire:click="verifyMod(@js($key))"
                                            title="Verify"
                                        >
                                            Verify
                                        </button>

                                        <button
                                            type="button"
                                            class="gnmm-icon-action"
                                            wire:click="repairMod(@js($key))"
                                            title="Repair missing managed files"
                                        >
                                            Repair
                                        </button>

                                        <button
                                            type="button"
                                            class="gnmm-icon-action"
                                            wire:click="toggleModDetails(@js($key))"
                                            title="Details"
                                        >
                                            Details
                                        </button>

<button type="button" class="gnmm-btn gnmm-btn-secondary" wire:click="inspectPackageVersions(@js($key))" wire:loading.attr="disabled">Versions</button>
                                        <button
                                            type="button"
                                            class="gnmm-icon-btn"
                                            title="Reinstall"
                                            wire:click="requestAction('reinstall', @js($key))"
                                            wire:loading.attr="disabled"
                                        >
                                            <x-heroicon-o-arrow-path/>
                                        </button>

                                        @if (!$requiredNames)
                                            <button
                                                type="button"
                                                class="gnmm-icon-btn gnmm-icon-btn-danger"
                                                title="Remove"
                                                wire:click="requestAction('remove', @js($key))"
                                                wire:loading.attr="disabled"
                                            >
                                                <x-heroicon-o-trash/>
                                            </button>
                                        @endif
                                    @else
                                        <span class="gnmm-field-help">
                                            Unavailable for this source
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="gnmm-installed-footer">
                        Showing {{ count($displayMods) }} of {{ count($installedMods) }} installed mods

                        @if ($updatesCheckedAt)
                            <span>Updates checked {{ $updatesCheckedAt }}</span>
                        @endif
                    </div>

                    <div class="gnmm-status-legend">
                        <div>
                            <span class="gnmm-legend-dot gnmm-legend-current"></span>
                            <span>
                                <strong>Up to date</strong>
                                Latest provider release installed
                            </span>
                        </div>

                        <div>
                            <span class="gnmm-legend-dot gnmm-legend-update"></span>
                            <span>
                                <strong>Update available</strong>
                                A newer release is available
                            </span>
                        </div>

                        <div>
                            <span class="gnmm-legend-dot gnmm-legend-disabled"></span>
                            <span>
                                <strong>Disabled</strong>
                                Installed but currently disabled
                            </span>
                        </div>

                        <div>
                            <span class="gnmm-legend-dot gnmm-legend-dependency"></span>
                            <span>
                                <strong>Dependency</strong>
                                Required by another installed mod
                            </span>
                        </div>
                    </div>
                @else
                    <div class="gnmm-empty">
                        <x-heroicon-o-archive-box/>

                        <div class="gnmm-empty-title">
                            {{ $installedSearch !== '' ? 'No matching mods' : 'No managed mods yet' }}
                        </div>

                        <div class="gnmm-empty-text">
                            {{ $installedSearch !== ''
                                ? 'Try another search.'
                                : 'Mods installed through ModHarbor will appear here.' }}
                        </div>
                    </div>
                @endif




            @elseif ($activeTab === 'configs')

                @if ($configError)<div class="gnmm-notice" role="alert">{{ $configError }}</div>@endif
                <button type="button" class="gnmm-btn gnmm-btn-secondary" wire:click="refreshConfigs">Refresh Configs</button>
                <div class="gnmm-config-layout">

                    <div class="gnmm-config-list">
                        <div class="gnmm-section-heading">
                            <div>
                                <div class="gnmm-section-title">Mod Configurations</div>
                                <div class="gnmm-field-help">
                                    {{ count($modConfigs) }} managed configuration file(s)
                                </div>
                            </div>
                        </div>

                        @forelse ($modConfigs as $configIndex => $config)
                            <button
                                type="button"
                                class="gnmm-config-item {{ $editingConfigPath === ($config['path'] ?? null) ? 'gnmm-config-item-active' : '' }}"
                                wire:click="editModConfigByIndex({{ $configIndex }})"
                                wire:loading.attr="disabled"
                            >
                                <span class="gnmm-config-name">
                                    {{ $config['name'] }}
                                </span>

                                <span class="gnmm-config-path">
                                    {{ $config['path'] }}
                                </span>

                                <span class="gnmm-config-size">
                                    {{ number_format(($config['size'] ?? 0) / 1024, 1) }} KB
                                </span>
                            </button>
                        @empty
                            <div class="gnmm-empty gnmm-config-empty">
                                <x-heroicon-o-wrench-screwdriver/>

                                <div class="gnmm-empty-title">
                                    No managed mod configs found
                                </div>

                                <div class="gnmm-field-help">
                                    Active configuration files created by ModHarbor-managed mods will appear here automatically.
                                </div>
                            </div>
                        @endforelse
                    </div>

                    <div class="gnmm-config-editor">

                        @if ($editingConfigPath !== null)

                            <div class="gnmm-section-heading">
                                <div class="gnmm-config-heading-text">
                                    <div class="gnmm-section-title">
                                        {{ basename($editingConfigPath) }}
                                    </div>

                                    <div class="gnmm-config-path">
                                        {{ $editingConfigPath }}
                                    </div>
                                </div>

                                <div class="gnmm-row-actions">
                                    <button
                                        type="button"
                                        class="gnmm-btn gnmm-btn-secondary"
                                        wire:click="reloadModConfig"
                                        wire:loading.attr="disabled"
                                    >
                                        Reload
                                    </button>

                                    <button
                                        type="button"
                                        class="gnmm-btn gnmm-btn-secondary"
                                        wire:click="closeModConfigEditor"
                                    >
                                        Close
                                    </button>


                                    @if (!empty($configRevisions))
                                        <button
                                            type="button"
                                            class="gnmm-btn gnmm-btn-secondary"
                                            wire:click="restoreConfigRevision(@js($configRevisions[0]['id']))"
                                            wire:confirm="Restore the most recent previous version of this config?"
                                        >
                                            Restore Previous
                                        </button>
                                    @endif

<button
                                        type="button"
                                        class="gnmm-btn gnmm-btn-primary"
                                        wire:click="saveModConfig" @disabled(count($unresolvedOperations) > 0)
                                        wire:loading.attr="disabled"
                                    >
                                        Save
                                    </button>
                                </div>
                            </div>

                            <div class="gnmm-field-help">JSON and INI validation is built in. YAML and TOML require their configured parser. Other adapter-approved text files use adapter validation.</div>
                            @foreach ($configRevisions as $revision)
                                <button type="button" class="gnmm-btn gnmm-btn-secondary" wire:click="restoreConfigRevision(@js($revision['id']))"
                                    wire:confirm="Restore this revision?" @disabled(count($unresolvedOperations) > 0)>Restore {{ $revision['created_at'] }}</button>
                            @endforeach
                            <textarea
                                class="gnmm-input gnmm-config-textarea"
                                wire:model.defer="editingConfigContent"
                                spellcheck="false"
                            ></textarea>

                            @if (str_ends_with(strtolower($editingConfigPath), '.json'))
                                <div class="gnmm-field-help">
                                    JSON is validated before saving. Saves and restores require a stopped server and retain the previous revision.
                                </div>
                            @endif

                        @else

                            <div class="gnmm-empty gnmm-config-empty">
                                <x-heroicon-o-document-text/>

                                <div class="gnmm-empty-title">
                                    Select a configuration file
                                </div>

                                <div class="gnmm-field-help">
                                    Choose a managed mod configuration from the list to edit it.
                                </div>
                            </div>

                        @endif

                    </div>

                </div>


            @elseif ($activeTab === 'history')
                <button type="button" class="gnmm-btn gnmm-btn-secondary" wire:click="downloadSupportBundle" wire:loading.attr="disabled" wire:target="downloadSupportBundle">Download support bundle</button>
                <p class="gnmm-section-subtitle">For public bug reports: versions, definition settings, provider configuration status, manifest counts, recovery state and recent operation timings. Names, paths, provider metadata, credentials, URLs and raw error text are omitted.</p>
                <h3 class="gnmm-section-title">Mod Activity</h3>
                <p class="gnmm-section-subtitle">
                    The latest 100 matching operations. Search includes provider, version, actor and affected files.
                </p>

                <div class="gnmm-history-filters">
                    <input class="gnmm-input" wire:model.live.debounce.300ms="historySearch" placeholder="Search activity…" aria-label="Search activity">
                    <select
                        class="gnmm-input"
                        wire:model.live="historyActionFilter"
                    >
                        <option value="all">All actions</option>
                        <option value="install">Install</option>
                        <option value="update">Update</option>
                        <option value="repair">Repair</option>
                        <option value="reinstall">Reinstall</option>
                        <option value="replace">Replace</option>
                        <option value="enable">Enable</option>
                        <option value="disable">Disable</option>
                        <option value="remove">Remove</option>
                        <option value="adopt">Adopt</option>
                        <option value="recovery">Recovery</option>
                        <option value="config-save">Config save</option><option value="config-restore">Config restore</option>
                    </select>

                    <select
                        class="gnmm-input"
                        wire:model.live="historyStatusFilter"
                    >
                        <option value="all">All statuses</option>
                        <option value="completed">Completed</option>
                        <option value="failed">Failed</option>
                        <option value="running">Running</option>
                        <option value="recovery_required">Recovery Required</option>
                        <option value="recovered">Recovered</option>
                    </select>
                </div>
                <div class="gnmm-installed-list">
                    @forelse ($this->historyDisplay() as $event)
                        <div class="gnmm-installed-row" wire:key="history-{{ $event['id'] }}">
                            <div>
                                <div class="gnmm-installed-name">{{ ucfirst($event['action']) }} · {{ $event['name'] }}</div>
                                <div class="gnmm-meta"><span>{{ $event['started_at'] }}</span><span>User #{{ $event['actor'] }}</span></div>
                                <div class="gnmm-history-message">
                                    {{ $event['message'] }}
                                    @if (isset($event['duration_ms']))
                                        · {{ $event['duration_ms'] >= 1000
                                            ? number_format($event['duration_ms'] / 1000, 2) . 's'
                                            : number_format($event['duration_ms'], 0) . 'ms' }}
                                    @endif
                                </div>
                                <details>
                                    <summary>
                                        Operation details · {{ $event['rollback'] }}
                                        @if (isset($event['duration_ms']))
                                            · {{ $event['duration_ms'] >= 1000
                                                ? number_format($event['duration_ms'] / 1000, 2) . 's'
                                                : number_format($event['duration_ms'], 0) . 'ms' }}
                                        @endif
                                    </summary>

                                    @if (!empty($event['phases']))
                                        <div class="gnmm-history-timings">
                                            <strong>Performance</strong>

                                            @foreach ($event['phases'] as $phase)
                                                <div class="gnmm-history-timing">
                                                    <span>{{ $phase['name'] ?? 'Unknown' }}</span>

                                                    <span>
                                                        @if (isset($phase['duration_ms']))
                                                            {{ $phase['duration_ms'] >= 1000
                                                                ? number_format($phase['duration_ms'] / 1000, 2) . 's'
                                                                : number_format($phase['duration_ms'], 0) . 'ms' }}
                                                        @else
                                                            —
                                                        @endif
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                    <p>Actor: {{ $event['actor'] ?? 'Unknown' }} · Reference: {{ $event['id'] }}</p>
                                    @foreach ($event['transitions'] ?? [] as $transition)
                                        <div>{{ $transition['name'] }} · {{ $transition['before']['provider'] ?? '—' }} → {{ $transition['after']['provider'] ?? '—' }} ·
                                            {{ $transition['before']['version'] ?? '—' }} → {{ $transition['after']['version'] ?? '—' }}</div>
                                    @endforeach
                                    @foreach ($event['affected_files'] ?? [] as $affectedPath)<div>{{ $affectedPath }}</div>@endforeach
                                </details>
                                @if (in_array($event['status'], ['running', 'recovery_required'], true))<div class="gnmm-field-help">Operation reference: {{ $event['id'] }}</div>@endif
                                @if (!empty($event['changes']))<div class="gnmm-field-help">Affected: {{ implode(', ', $event['changes']) }}</div>@endif
                                @if (!empty($event['verification']))
                                    <div class="gnmm-field-help">
                                        Verified:
                                        {{ $event['verification']['managed_files'] ?? 0 }} managed file(s)
                                        · {{ $event['verification']['retired_files'] ?? 0 }} retired path(s)
                                        · {{ $event['verification']['dependency_links'] ?? 0 }} dependency link(s)
                                    </div>
                                @endif
                            </div>
                            <span class="gnmm-badge">{{ ucfirst(str_replace('_', ' ', $event['status'])) }}</span>
                        </div>
                    @empty
                        <div class="gnmm-empty"><div class="gnmm-empty-title">No recorded activity yet</div><div class="gnmm-empty-text">New mod operations will appear here.</div></div>
                    @endforelse
                </div>

            @endif

        </div>

    </div>


    <div class="gnmm-footer">
        ModHarbor 1.0.0-rc.3
        <span class="mh-footer-divider">|</span>
        <span class="mh-footer-tagline">Mods. Managed.</span>
        <span class="mh-footer-divider">|</span>
        <span class="mh-footer-pelican">Built for Pelican</span>
    </div>

</div>

</x-filament-panels::page>

