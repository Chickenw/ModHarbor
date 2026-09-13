<x-filament-panels::page>

<style>

        .mh-brand-banner {
            display: block !important;
            overflow: hidden;
            border-radius: 16px;
            border: 1px solid rgba(56, 189, 248, 0.18);
            background: #04111f;
            line-height: 0;
            width: 100%;
            margin: 0 0 18px;
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

body:has(.mh-provider-settings) .fi-main,
body:has(.mh-provider-settings) .fi-page,
body:has(.mh-provider-settings) .fi-page-content,
body:has(.mh-provider-settings) .fi-main-ctn {
    width: 100% !important;
    max-width: none !important;
}
body:has(.mh-provider-settings) .fi-main {
    padding-left: 16px !important;
    padding-right: 16px !important;
}
body:has(.mh-provider-settings) .fi-header {
    display: none !important;
}

.mh-provider-settings {
    width: 100%;
    max-width: none;
    margin: 0;
}

.mh-provider-settings-intro {
    margin-bottom: 18px;
    padding: 16px 18px;
    border: 1px solid rgba(148, 163, 184, .22);
    border-radius: 10px;
    background: rgba(15, 23, 42, .28);
}

.mh-provider-settings-intro h2 {
    margin: 0 0 5px;
    font-size: 17px;
    font-weight: 700;
}

.mh-provider-settings-intro p {
    margin: 0;
    opacity: .75;
    font-size: 13px;
    line-height: 1.55;
}

.mh-provider-settings-grid {
    display: grid;
    gap: 16px;
}

.mh-provider-card {
    overflow: hidden;
    border: 1px solid rgba(148, 163, 184, .22);
    border-radius: 10px;
    background: rgba(15, 23, 42, .20);
}

.mh-provider-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 15px 18px;
    border-bottom: 1px solid rgba(148, 163, 184, .16);
}

.mh-provider-name {
    font-size: 15px;
    font-weight: 700;
}

.mh-provider-status {
    font-size: 12px;
    opacity: .72;
}

.mh-provider-card-body {
    padding: 18px;
}

.mh-provider-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.mh-provider-field-full {
    grid-column: 1 / -1;
}

.mh-provider-label {
    display: block;
    margin-bottom: 6px;
    font-size: 12px;
    font-weight: 700;
}

.mh-provider-input,
.mh-provider-select {
    width: 100%;
    min-height: 40px;
    padding: 8px 10px;
    border: 1px solid rgba(148, 163, 184, .30);
    border-radius: 7px;
    background: rgba(2, 6, 23, .35);
}

.mh-provider-secret-row {
    display: flex;
    gap: 8px;
    align-items: stretch;
}

.mh-provider-secret-row .mh-provider-input {
    flex: 1;
}

.mh-provider-secret-toggle {
    min-width: 66px;
    padding: 0 12px;
    border: 1px solid rgba(148, 163, 184, .30);
    border-radius: 7px;
    font-size: 12px;
    font-weight: 700;
}

.mh-provider-help {
    margin-top: 6px;
    font-size: 11px;
    line-height: 1.45;
    opacity: .65;
}

.mh-provider-saved {
    margin-top: 5px;
    font-size: 11px;
    font-weight: 600;
    opacity: .8;
}

.mh-provider-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 17px;
}

.mh-provider-save {
    min-height: 38px;
    padding: 8px 16px;
    border-radius: 7px;
    font-weight: 700;
    background: rgb(59, 130, 246);
    color: white;
}

.mh-provider-save:disabled {
    opacity: .55;
}

@media (max-width: 720px) {
    .mh-provider-fields {
        grid-template-columns: 1fr;
    }
}
.mh-provider-settings { --mh-line:#1b3850;--mh-text:#edf6ff;--mh-muted:#8eabc3;color:var(--mh-text); }
body:has(.mh-provider-settings) { background:#050d17; }
body:has(.mh-provider-settings) .fi-main { padding-inline:clamp(14px,2.2vw,32px) !important; }
.mh-provider-settings-intro { position:relative;overflow:hidden;padding:22px;border-color:var(--mh-line);border-radius:15px;background:linear-gradient(135deg,#0d2133,#081521); }
.mh-provider-settings-intro::after { content:"";position:absolute;width:220px;height:220px;right:-90px;top:-130px;border-radius:50%;background:rgba(43,140,255,.18);filter:blur(8px); }
.mh-provider-settings-intro h2 { font-size:20px;color:#fff; }
.mh-provider-settings-intro p { max-width:780px;color:var(--mh-muted);opacity:1; }
.mh-provider-settings-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
.mh-provider-card { position:relative;border-color:var(--mh-line);border-radius:15px;background:linear-gradient(155deg,rgba(13,30,45,.98),rgba(7,18,29,.98));box-shadow:0 16px 34px rgba(0,0,0,.18); }
.mh-provider-card::before { content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:#2b8cff;opacity:.75; }
.mh-provider-card-head { min-height:64px;border-color:#162e43; }
.mh-provider-name { font-size:16px;color:#fff; }
.mh-provider-status { max-width:55%;padding:5px 9px;border:1px solid rgba(43,140,255,.25);border-radius:999px;background:rgba(43,140,255,.09);color:#a9d3ff;opacity:1; }
.mh-provider-input,.mh-provider-select { border-color:#28455e;background:#06121e;color:#eaf5ff; }
.mh-provider-input:focus,.mh-provider-select:focus { border-color:#2b8cff;box-shadow:0 0 0 3px rgba(43,140,255,.14);outline:0; }
.mh-provider-secret-toggle { border-color:#28455e;background:#0a1a29;color:#cce6ff; }
.mh-provider-save { background:linear-gradient(135deg,#2b8cff,#1569d8);box-shadow:0 8px 20px rgba(43,140,255,.2); }
.mh-provider-kicker { margin-bottom:7px;color:#66b3ff;font-size:.68rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase; }
@media (max-width: 980px) { .mh-provider-settings-grid{grid-template-columns:1fr} }
.mh-backup { padding:16px 18px;margin-bottom:16px;border:1px solid #1b3850;border-radius:12px;background:#0a1825; }
.mh-backup-toolbar { display:flex;align-items:center;flex-wrap:wrap;gap:12px 20px; }
.mh-backup-copy { flex:1 1 240px; }
.mh-backup-copy h2 { margin:0;color:#f0f6fc;font-size:15px;font-weight:700; }
.mh-backup-copy p { margin:4px 0 0;color:#b0c3d5;font-size:12px;line-height:1.5; }
.mh-backup-import { display:flex;align-items:center;flex-wrap:wrap;gap:8px;flex:0 1 490px;min-width:0; }
.mh-backup-file { flex:1 1 240px;min-width:0;max-width:100%;width:280px;height:40px;padding:4px;border:1px solid #42627c;border-radius:8px;background:#06121e;color:#e2edf7;font-size:13px; }
.mh-backup-file::file-selector-button { height:30px;padding:0 12px;margin-right:10px;border:1px solid #527795;border-radius:5px;background:#1a3c57;color:#fff;font-weight:600;cursor:pointer; }
.mh-backup-file:hover::file-selector-button { background:#245475; }
.mh-backup-file:focus-visible,.mh-backup details summary:focus-visible { outline:2px solid #70b9ff;outline-offset:3px; }
.mh-backup .mh-provider-save { min-height:40px;font-size:13px;white-space:nowrap; }
.mh-backup-error { flex-basis:100%;color:#ffafb5;font-size:13px; }
.mh-backup details { margin-top:10px;color:#afc3d6;font-size:12px;line-height:1.5; }
.mh-backup details summary { width:fit-content;color:#9bc9f0;cursor:pointer; }
.mh-backup details p { margin:6px 0 0;max-width:900px; }
@media(max-width:700px) { .mh-backup-import{flex-basis:100%}.mh-backup-file{width:100%} }
</style>


<div class="mh-provider-settings">

    <div class="mh-provider-settings-intro">
        <div class="mh-provider-kicker">ModHarbor</div>
        <h2>Provider Settings</h2>

        <p>
            Credentials saved here are shared by every configured game that
            uses the provider. Secrets are encrypted on disk and are are
            shown only when a root administrator explicitly selects Reveal. Blank secret fields keep saved credentials. Test checks the current fields without saving them.
        </p>
    </div>

    <section class="mh-backup" aria-label="Provider backup and restore">
        <div class="mh-backup-toolbar">
        <div class="mh-backup-copy">
            <h2>Backup &amp; restore</h2>
            <p>All saved providers. Exports contain readable API keys—keep them private.</p>
        </div>
        <button type="button" class="mh-provider-save"
            wire:click="exportAllSettings" wire:loading.attr="disabled"
            wire:confirm="Download all saved provider settings including readable API keys and tokens? Keep this file private.">Export all settings</button>
        <form wire:submit="importAllSettings" class="mh-backup-import">
            <input id="mh-settings-import" class="mh-backup-file" aria-label="Choose provider backup JSON file" type="file" wire:model="settingsImport" accept=".json,application/json">
            <button type="submit" class="mh-provider-save" wire:loading.attr="disabled"
                wire:target="settingsImport,importAllSettings"
                wire:confirm="Import this backup and overwrite matching saved provider settings? Export your current settings first.">Import settings</button>
            <span wire:loading wire:target="settingsImport" role="status">Uploading backup…</span>
            @error('settingsImport') <p class="mh-backup-error" role="alert">{{ $message }}</p> @enderror
        </form>
        </div>
        <details>
            <summary>Import details</summary>
            <p>Export includes saved settings only. Import overwrites matching settings. Blank secrets keep existing credentials; providers absent from the file stay unchanged. Export a backup first. Providers save individually; a storage failure may leave a partial import.</p>
        </details>
    </section>
    <div class="mh-provider-settings-grid">

        @forelse ($providers as $providerKey => $provider)

            <section
                class="mh-provider-card"
                wire:key="provider-settings-{{ $providerKey }}"
            >
                <div class="mh-provider-card-head">

                    <div class="mh-provider-name">
                        {{ $provider['label'] }}
                    </div>

                    <div class="mh-provider-status" role="status" aria-live="polite">
                        {{ $status[$providerKey] ?? '' }}
                    </div>

                </div>

                <div class="mh-provider-card-body">

                    <div class="mh-provider-fields">

                        @foreach ($provider['fields'] as $fieldKey => $field)

                            @php
                                $type = strtolower(
                                    (string) ($field['type'] ?? 'text')
                                );

                                $isSecret = $type === 'secret';

                                $isConfigured =
                                    (bool) (
                                        $configured[$providerKey][$fieldKey]
                                        ?? false
                                    );
                            @endphp

                            <div
                                class="{{ count($provider['fields']) === 1 ? 'mh-provider-field-full' : '' }}"
                                wire:key="provider-field-{{ $providerKey }}-{{ $fieldKey }}"
                            >
                                <label class="mh-provider-label">
                                    {{ $field['label'] ?? $fieldKey }}
                                </label>

                                @if ($type === 'boolean')

                                    <select
                                        class="mh-provider-select"
                                        wire:model="values.{{ $providerKey }}.{{ $fieldKey }}"
                                    >
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </select>

                                @else

                                    @if ($isSecret)

                                        @php
                                            $isRevealed =
                                                (bool) (
                                                    $revealed[$providerKey][$fieldKey]
                                                    ?? false
                                                );
                                        @endphp

                                        <div class="mh-provider-secret-row">

                                            <input
                                                class="mh-provider-input"
                                                type="{{ $isRevealed ? 'text' : 'password' }}"
                                                wire:model="values.{{ $providerKey }}.{{ $fieldKey }}"
                                                placeholder="{{
                                                    $isConfigured
                                                        ? 'Saved - click Show to view or replace'
                                                        : ($field['placeholder'] ?? '')
                                                }}"
                                                autocomplete="new-password"
                                            />

                                            @if ($isConfigured && !$isRevealed)

                                                <button
                                                    type="button"
                                                    class="mh-provider-secret-toggle"
                                                    wire:click="revealSecret('{{ $providerKey }}', '{{ $fieldKey }}')"
                                                >
                                                    Show
                                                </button>

                                            @elseif ($isRevealed)

                                                <button
                                                    type="button"
                                                    class="mh-provider-secret-toggle"
                                                    wire:click="hideSecret('{{ $providerKey }}', '{{ $fieldKey }}')"
                                                >
                                                    Hide
                                                </button>

                                            @endif

                                        </div>

                                    @else

                                        <input
                                            class="mh-provider-input"
                                            type="{{ $type === 'number' ? 'number' : 'text' }}"
                                            wire:model="values.{{ $providerKey }}.{{ $fieldKey }}"
                                            placeholder="{{ $field['placeholder'] ?? '' }}"
                                            autocomplete="off"
                                        />

                                    @endif

                                @endif

                                @if ($isSecret)
                                    <div class="mh-provider-saved">
                                        {{
                                            $isConfigured
                                                ? 'A credential is currently configured.'
                                                : 'No credential is currently configured.'
                                        }}
                                    </div>
                                @endif

                                @if (!empty($field['help']))
                                    <div class="mh-provider-help">
                                        {{ $field['help'] }}
                                    </div>
                                @endif

                            </div>

                        @endforeach

                    </div>

                    <div class="mh-provider-actions">
                        <button type="button" class="mh-provider-secret-toggle"
                            wire:click="testProvider('{{ $providerKey }}')" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="testProvider('{{ $providerKey }}')">Test Connection</span>
                            <span wire:loading wire:target="testProvider('{{ $providerKey }}')">Checking...</span>
                        </button>
                        @if (!empty($provider['fields']))

                        <button
                            type="button"
                            class="mh-provider-save"
                            wire:click="saveProvider('{{ $providerKey }}')"
                            wire:loading.attr="disabled"
                            
                        >
                            Save {{ $provider['label'] }}
                        </button>

                        @endif
                    </div>

                </div>
            </section>

        @empty

            <div class="mh-provider-settings-intro">
                No providers currently declare global settings.
            </div>

        @endforelse

    </div>

</div>

</x-filament-panels::page>
