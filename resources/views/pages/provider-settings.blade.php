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
</style>


<section class="mh-brand-banner">
    <img class="mh-brand-banner-img" src="/modharbor/branding/banner-v3.webp" alt="ModHarbor — universal mod management for Pelican">
</section>

<div class="mh-provider-settings">

    <div class="mh-provider-settings-intro">
        <h2>Global Provider Connections</h2>

        <p>
            Credentials saved here are shared by every configured game that
            uses the provider. Secrets are encrypted on disk and are are
            shown only when a root administrator explicitly selects Reveal. Blank secret fields keep saved credentials. Test checks the current fields without saving them.
        </p>
    </div>

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
