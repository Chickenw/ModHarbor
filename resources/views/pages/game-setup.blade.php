<x-filament-panels::page>
    <style>
        .mh-builder {
            --mh-bg: #07111a;
            --mh-panel: #0b1823;
            --mh-panel-2: #0e1e2b;
            --mh-panel-3: #102331;
            --mh-border: #29455b;
            --mh-border-soft: #1c3447;
            --mh-text: #f5f8fb;
            --mh-soft: #d3dfeb;
            --mh-muted: #89a5bd;
            --mh-blue: #1687ff;
            --mh-blue-2: #0b6ff0;
            --mh-green: #16c46f;
            --mh-orange: #f59e0b;
            --mh-red: #ef3340;

            width: 100%;
            color: var(--mh-text);
        }

        .mh-builder,
        .mh-builder * {
            box-sizing: border-box;
        }

        .mh-builder code {
            color: #b9dcff;
        }

        .mh-card {
            overflow: hidden;
            border: 1px solid var(--mh-border);
            border-radius: 12px;
            background:
                linear-gradient(180deg, rgba(14,30,43,.98), rgba(7,18,27,.99));
            box-shadow: 0 14px 35px rgba(0,0,0,.16);
        }

        .mh-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--mh-border-soft);
            background: linear-gradient(
                180deg,
                rgba(19,39,55,.78),
                rgba(10,24,35,.40)
            );
        }

        .mh-title {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--mh-text);
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -.015em;
        }

        .mh-card-header .mh-title {
            font-size: 1.08rem;
        }

        .mh-help {
            color: var(--mh-muted);
            font-size: .79rem;
            line-height: 1.5;
        }

        .mh-message {
            padding: 12px 14px;
            border: 1px solid rgba(22,135,255,.55);
            border-radius: 9px;
            background: rgba(22,135,255,.10);
            color: var(--mh-soft);
        }

        .mh-section {
            padding: 14px;
        }

        /* ---------------------------------------------------------
         * Configured games
         * ------------------------------------------------------ */

        .mh-games-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0,1fr));
            gap: 14px;
        }

        .mh-game-card {
            min-width: 0;
            min-height: 290px;
            display: flex;
            flex-direction: column;
            padding: 16px;
            border: 1px solid var(--mh-border-soft);
            border-radius: 10px;
            background:
                radial-gradient(
                    circle at top right,
                    rgba(22,135,255,.08),
                    transparent 38%
                ),
                linear-gradient(
                    180deg,
                    rgba(14,31,44,.94),
                    rgba(8,20,29,.94)
                );
            transition:
                transform .15s ease,
                border-color .15s ease,
                box-shadow .15s ease;
        }

        .mh-game-card:hover {
            transform: translateY(-1px);
            border-color: #3f6480;
            box-shadow: 0 10px 26px rgba(0,0,0,.15);
        }

        .mh-game-top {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .mh-game-mark {
            width: 46px;
            height: 46px;
            flex: 0 0 46px;
            display: grid;
            place-items: center;
            border: 1px solid #34526a;
            border-radius: 9px;
            background:
                linear-gradient(145deg, #14314a, #0b1d2b);
            color: #62b4ff;
            font-size: 1.05rem;
            font-weight: 900;
            letter-spacing: -.04em;
        }

        .mh-game-name {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
            color: #fff;
            font-size: 1rem;
            font-weight: 800;
            line-height: 1.3;
        }

        .mh-badge {
            display: inline-flex;
            align-items: center;
            min-height: 22px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: .66rem;
            font-weight: 800;
            line-height: 1;
        }

        .mh-badge-enabled {
            color: #d9ffeb;
            border: 1px solid rgba(22,196,111,.45);
            background: rgba(22,196,111,.17);
        }

        .mh-badge-disabled {
            color: #e1e8ef;
            border: 1px solid rgba(126,145,164,.42);
            background: rgba(126,145,164,.15);
        }

        .mh-badge-official {
            color: #d9ebff;
            border: 1px solid rgba(22,135,255,.55);
            background: rgba(22,135,255,.18);
        }

        .mh-badge-custom {
            color: #dce5ed;
            border: 1px solid rgba(117,137,155,.42);
            background: rgba(117,137,155,.13);
        }

        .mh-game-facts {
            margin-top: 13px;
            min-height: 86px;
            color: #a9bfd1;
            font-size: .76rem;
            line-height: 1.55;
        }

        .mh-game-facts strong {
            color: #dce8f2;
            font-weight: 700;
        }

        .mh-game-providers {
            margin-top: 13px;
            padding-top: 12px;
            border-top: 1px solid var(--mh-border-soft);
        }

        .mh-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 7px;
        }

        .mh-chip {
            display: inline-flex;
            align-items: center;
            min-height: 25px;
            padding: 4px 9px;
            border: 1px solid #35536a;
            border-radius: 999px;
            background: #102432;
            color: #e2edf6;
            font-size: .70rem;
            font-weight: 700;
            line-height: 1;
        }

        .mh-game-card .mh-actions {
            margin-top: auto;
            padding-top: 15px;
        }

        .mh-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .mh-builder .fi-btn {
            min-height: 36px;
            border-radius: 7px;
            font-weight: 750;
        }

        /* ---------------------------------------------------------
         * Editor
         * ------------------------------------------------------ */

        .mh-editor {
            display: grid;
            grid-template-columns: 225px minmax(0,1fr);
            min-height: 540px;
        }

        .mh-editor-nav {
            border-right: 1px solid var(--mh-border-soft);
            background:
                linear-gradient(
                    180deg,
                    rgba(12,29,42,.92),
                    rgba(7,19,28,.72)
                );
            padding: 10px;
        }

        .mh-editor-nav-item {
            display: flex;
            align-items: center;
            gap: 11px;
            min-height: 55px;
            padding: 9px 11px;
            border-radius: 8px;
            color: #d7e4ef;
        }

        .mh-editor-nav-item + .mh-editor-nav-item {
            margin-top: 3px;
        }

        .mh-editor-nav-item:first-child {
            border: 1px solid rgba(22,135,255,.35);
            background: rgba(22,135,255,.12);
            box-shadow: inset 3px 0 0 var(--mh-blue);
        }

        .mh-nav-icon {
            width: 29px;
            height: 29px;
            flex: 0 0 29px;
            display: grid;
            place-items: center;
            border-radius: 7px;
            background: rgba(22,135,255,.13);
            color: #5bb1ff;
            font-weight: 900;
        }

        .mh-editor-nav-item strong {
            display: block;
            color: #f3f7fb;
            font-size: .80rem;
        }

        .mh-editor-nav-item small {
            display: block;
            margin-top: 2px;
            color: #7f9bb3;
            font-size: .67rem;
            line-height: 1.3;
        }

        .mh-editor-content {
            min-width: 0;
            padding: 20px;
        }

        .mh-grid {
            display: grid;
            grid-template-columns: repeat(2,minmax(0,1fr));
            gap: 0;
            border: 1px solid var(--mh-border-soft);
            border-radius: 10px;
            overflow: hidden;
            background: rgba(5,16,24,.28);
        }

        .mh-grid > section {
            min-width: 0;
            min-height: 255px;
            padding: 19px;
            border-right: 1px solid var(--mh-border-soft);
            border-bottom: 1px solid var(--mh-border-soft);
            background:
                linear-gradient(
                    180deg,
                    rgba(14,31,44,.58),
                    rgba(8,20,29,.25)
                );
        }

        .mh-grid > section:nth-child(2n) {
            border-right: 0;
        }

        .mh-grid > section:nth-last-child(-n+2) {
            border-bottom: 0;
        }

        .mh-grid > section > .mh-title::before {
            width: 28px;
            height: 28px;
            flex: 0 0 28px;
            display: inline-grid;
            place-items: center;
            border: 1px solid rgba(22,135,255,.25);
            border-radius: 7px;
            background: rgba(22,135,255,.12);
            color: #5bb1ff;
            font-size: .78rem;
            font-weight: 900;
        }

        .mh-grid > section:nth-child(1) > .mh-title::before { content:"G"; }
        .mh-grid > section:nth-child(2) > .mh-title::before { content:"D"; }
        .mh-grid > section:nth-child(3) > .mh-title::before { content:"S"; }
        .mh-grid > section:nth-child(4) > .mh-title::before { content:"I"; }
        .mh-grid > section:nth-child(5) > .mh-title::before { content:"P"; }
        .mh-grid > section:nth-child(6) > .mh-title::before { content:"B"; }

        .mh-field {
            display: block;
            margin-top: 15px;
        }

        .mh-label {
            display: block;
            margin-bottom: 6px;
            color: #dce7f0;
            font-size: .77rem;
            font-weight: 750;
        }

        .mh-input {
            display: block;
            width: 100%;
            min-height: 42px;
            padding: 9px 11px;
            border: 1px solid #45627a;
            border-radius: 6px;
            outline: none;
            background: #071722;
            color: #f7fafc;
            color-scheme: dark;
            font-size: .84rem;
            transition:
                border-color .15s ease,
                box-shadow .15s ease;
        }

        .mh-input:focus {
            border-color: var(--mh-blue);
            box-shadow:
                0 0 0 1px var(--mh-blue),
                0 0 0 4px rgba(22,135,255,.09);
        }

        .mh-input::placeholder {
            color: #657f95;
        }

        textarea.mh-input {
            min-height: 68px;
            resize: vertical;
        }

        select.mh-input,
        select.mh-input option {
            background-color: #091a26 !important;
            color: #fff !important;
        }

        .mh-checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 9px;
        }

        .mh-checkbox-row input {
            margin-top: 3px;
        }

        .mh-builder input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--mh-blue);
        }

        .mh-provider-list {
            overflow: hidden;
            margin-top: 12px;
            border: 1px solid var(--mh-border-soft);
            border-radius: 8px;
            background: rgba(4,14,22,.36);
        }

        .mh-provider {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 42px;
            padding: 8px 11px;
            border-bottom: 1px solid var(--mh-border-soft);
            color: #d8e5ef;
            font-size: .80rem;
            font-weight: 700;
            cursor: pointer;
        }

        .mh-provider:last-child {
            border-bottom: 0;
        }

        .mh-provider:has(input:checked) {
            background: rgba(22,135,255,.10);
            color: #fff;
        }

        .mh-provider:has(input:checked)::before {
            content:"";
            position:absolute;
            left:0;
            top:6px;
            bottom:6px;
            width:3px;
            border-radius:0 3px 3px 0;
            background:var(--mh-blue);
        }

        .mh-packages {
            display:flex;
            flex-wrap:wrap;
            gap:6px;
        }

        .mh-packages label {
            display:inline-flex;
            align-items:center;
            gap:5px;
            min-height:29px;
            padding:4px 8px;
            border:1px solid var(--mh-border-soft);
            border-radius:999px;
            background:rgba(255,255,255,.02);
            color:#d5e1eb;
            font-size:.72rem;
            font-weight:700;
            cursor:pointer;
        }

        .mh-packages label:has(input:checked) {
            border-color:rgba(22,135,255,.55);
            background:rgba(22,135,255,.13);
            color:#fff;
        }

        .mh-packages input {
            width:13px !important;
            height:13px !important;
        }

        /* ---------------------------------------------------------
         * Advanced / actions / import
         * ------------------------------------------------------ */

        .mh-advanced {
            margin-top: 15px;
            overflow:hidden;
            border:1px solid #31526c;
            border-radius:9px;
            background:rgba(7,21,31,.62);
        }

        .mh-advanced summary {
            min-height:47px;
            display:flex;
            align-items:center;
            padding:10px 14px;
            color:#eaf2f8;
            font-size:.82rem;
            font-weight:800;
            cursor:pointer;
            background:rgba(22,135,255,.06);
        }

        .mh-advanced summary:hover {
            background:rgba(22,135,255,.10);
        }

        .mh-advanced-body {
            padding:4px 14px 16px;
            border-top:1px solid var(--mh-border-soft);
        }

        .mh-provider-meta {
            margin-top:11px;
            padding:11px;
            border:1px solid var(--mh-border-soft);
            border-radius:7px;
            background:rgba(0,0,0,.12);
        }

        .mh-actions-bar {
            display:flex;
            align-items:center;
            flex-wrap:wrap;
            gap:9px;
            padding:15px 20px 18px;
            border-top:1px solid var(--mh-border-soft);
            background:rgba(5,15,23,.32);
        }

        .mh-import-row {
            display:flex;
            align-items:center;
            justify-content:space-between;
            flex-wrap:wrap;
            gap:14px;
        }

        .mh-import-controls {
            display:flex;
            align-items:center;
            flex-wrap:wrap;
            gap:12px;
        }

        .mh-builder input[type="file"] {
            max-width:100%;
            color:var(--mh-muted);
            font-size:.78rem;
        }

        .mh-builder input[type="file"]::file-selector-button {
            min-height:34px;
            margin-right:9px;
            padding:6px 10px;
            border:1px solid #45627a;
            border-radius:6px;
            background:#102534;
            color:#fff;
            font-weight:700;
            cursor:pointer;
        }

        @media (max-width: 1280px) {
            .mh-games-grid {
                grid-template-columns: repeat(2,minmax(0,1fr));
            }

            .mh-editor {
                grid-template-columns: 190px minmax(0,1fr);
            }
        }

        @media (max-width: 900px) {
            .mh-games-grid {
                grid-template-columns:1fr;
            }

            .mh-editor {
                grid-template-columns:1fr;
            }

            .mh-editor-nav {
                display:grid;
                grid-template-columns:repeat(3,minmax(0,1fr));
                gap:5px;
                border-right:0;
                border-bottom:1px solid var(--mh-border-soft);
            }

            .mh-editor-nav-item + .mh-editor-nav-item {
                margin-top:0;
            }

            .mh-grid {
                grid-template-columns:1fr;
            }

            .mh-grid > section,
            .mh-grid > section:nth-child(2n),
            .mh-grid > section:nth-last-child(-n+2) {
                min-height:0;
                border-right:0;
                border-bottom:1px solid var(--mh-border-soft);
            }

            .mh-grid > section:last-child {
                border-bottom:0;
            }
        }

        @media (max-width: 620px) {
            .mh-card-header {
                align-items:flex-start;
                flex-direction:column;
            }

            .mh-editor-nav {
                grid-template-columns:1fr 1fr;
            }

            .mh-editor-content {
                padding:12px;
            }
        }

        /* =========================================================
         * Game Builder UI v2.1 layout corrections
         * ====================================================== */

        .mh-games-grid {
            align-items: stretch;
        }

        .mh-game-card {
            min-height: 0;
            padding: 15px;
        }

        .mh-game-heading {
            min-width: 0;
            flex: 1 1 auto;
        }

        .mh-game-top {
            min-height: 48px;
        }

        .mh-game-name {
            align-items: center;
        }

        .mh-game-facts {
            min-height: 0;
            margin-top: 13px;
        }

        .mh-game-facts > div + div {
            margin-top: 2px;
        }

        .mh-game-providers {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid var(--mh-border-soft);
        }

        .mh-package-chip-row {
            margin-top: 7px;
        }

        .mh-package-chip {
            color: #a9c2d6;
            background: rgba(255,255,255,.025);
        }

        .mh-game-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;

            margin-top: 15px;
            padding-top: 14px;

            border-top: 1px solid var(--mh-border-soft);
        }

        .mh-game-actions .fi-btn {
            width: auto !important;
            min-width: 0 !important;
            flex: 0 0 auto !important;
        }

        .mh-empty-games {
            grid-column: 1 / -1;

            padding: 28px;

            border: 1px dashed var(--mh-border);
            border-radius: 9px;

            color: var(--mh-muted);
            text-align: center;
        }

        /*
         * Make the editor feel less like six giant equal boxes.
         */
        .mh-editor-content {
            padding: 16px;
        }

        .mh-grid > section {
            min-height: 220px;
            padding: 17px;
        }

        .mh-provider-list {
            max-width: 100%;
        }

        .mh-editor-nav-item {
            min-height: 51px;
        }

        .mh-actions-bar {
            padding-top: 13px;
            padding-bottom: 14px;
        }

        /*
         * Make the currently edited definition more obvious.
         */
        .mh-editing-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;

            min-height: 26px;
            padding: 4px 9px;

            border: 1px solid rgba(22,196,111,.40);
            border-radius: 999px;

            background: rgba(22,196,111,.13);

            color: #d9ffeb;

            font-size: .69rem;
            font-weight: 800;
        }

        @media (min-width: 1281px) {
            .mh-games-grid {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 1280px) {
            .mh-games-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .mh-games-grid {
                grid-template-columns: 1fr;
            }

            .mh-game-actions {
                justify-content: flex-start;
            }
        }

        /* =========================================================
         * Game Builder UI v2.2
         * Density, editor state and visual hierarchy
         * ====================================================== */

        .mh-card {
            box-shadow:
                0 16px 38px rgba(0, 0, 0, .18),
                inset 0 1px 0 rgba(255,255,255,.018);
        }

        /* Configured game cards */
        .mh-game-card {
            min-height: 0;
        }

        .mh-game-mark {
            width: 44px;
            height: 44px;
            flex-basis: 44px;
            border-color: #365a75;
            background:
                radial-gradient(
                    circle at 30% 20%,
                    rgba(40,148,255,.20),
                    transparent 55%
                ),
                linear-gradient(
                    145deg,
                    #12314a,
                    #091b29
                );
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,.045);
        }

        .mh-game-name {
            min-height: 25px;
        }

        .mh-game-facts {
            line-height: 1.48;
            color: #9eb6c9;
        }

        .mh-game-facts strong {
            color: #dce9f3;
        }

        .mh-chip {
            min-height: 24px;
            padding: 4px 8px;
            font-size: .68rem;
        }

        .mh-game-actions {
            gap: 7px;
        }

        .mh-game-actions .fi-btn {
            min-height: 34px;
        }

        /* Editor shell */
        .mh-editor-card > .mh-card-header {
            min-height: 67px;
        }

        .mh-editor {
            grid-template-columns: 205px minmax(0,1fr);
        }

        .mh-editor-nav {
            padding: 11px 9px;
        }

        .mh-editor-nav-item {
            min-height: 50px;
            padding: 8px 10px;
        }

        .mh-editor-nav-item:first-child {
            background:
                linear-gradient(
                    90deg,
                    rgba(22,135,255,.18),
                    rgba(22,135,255,.07)
                );
        }

        .mh-nav-icon {
            width: 27px;
            height: 27px;
            flex-basis: 27px;
            font-size: .72rem;
        }

        .mh-editor-content {
            padding: 14px;
        }

        .mh-grid {
            border-color: #29485f;
        }

        .mh-grid > section {
            min-height: 214px;
            padding: 17px 18px;
        }

        .mh-grid > section > .mh-title {
            margin-bottom: 2px;
        }

        .mh-field {
            margin-top: 14px;
        }

        .mh-input {
            min-height: 40px;
        }

        textarea.mh-input {
            min-height: 64px;
        }

        .mh-help {
            font-size: .75rem;
        }

        .mh-label {
            font-size: .76rem;
        }

        /* Providers */
        .mh-provider {
            min-height: 39px;
            padding-top: 7px;
            padding-bottom: 7px;
        }

        /* Advanced */
        .mh-advanced {
            margin-top: 13px;
            border-color: #315b78;
            background:
                linear-gradient(
                    180deg,
                    rgba(11,31,45,.90),
                    rgba(6,20,29,.85)
                );
        }

        .mh-advanced summary {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 10px;

            min-height: 58px;
            padding: 10px 13px;

            list-style: none;
        }

        .mh-advanced summary::-webkit-details-marker {
            display: none;
        }

        .mh-advanced-icon {
            width: 30px;
            height: 30px;

            display: grid;
            place-items: center;

            border: 1px solid rgba(22,135,255,.32);
            border-radius: 7px;

            background: rgba(22,135,255,.13);
            color: #66b7ff;

            font-size: .75rem;
            font-weight: 900;
        }

        .mh-advanced-summary-copy {
            min-width: 0;
        }

        .mh-advanced-summary-copy strong {
            display: block;

            color: #f2f7fb;

            font-size: .81rem;
            line-height: 1.25;
        }

        .mh-advanced-summary-copy small {
            display: block;

            margin-top: 2px;

            color: #7f9eb6;

            font-size: .68rem;
            line-height: 1.35;
        }

        .mh-advanced-chevron {
            color: #74baff;
            font-size: 1rem;
            transition: transform .15s ease;
        }

        .mh-advanced[open] .mh-advanced-chevron {
            transform: rotate(180deg);
        }

        /* Save area */
        .mh-actions-bar {
            min-height: 61px;
            padding-left: 14px;
            padding-right: 14px;
        }

        .mh-actions-bar .fi-btn:first-child {
            min-width: 110px;
        }

        /* Import */
        .mh-import-row {
            min-height: 43px;
        }

        .mh-import-controls {
            width: 100%;
        }

        /*
         * Pelican gives us enough room at normal desktop sizes.
         * Keep editor content dominant rather than allowing the
         * left rail to eat too much horizontal space.
         */
        @media (min-width: 1450px) {
            .mh-editor {
                grid-template-columns: 190px minmax(0,1fr);
            }

            .mh-editor-content {
                padding: 15px;
            }
        }

        @media (max-width: 1050px) {
            .mh-editor {
                grid-template-columns: 180px minmax(0,1fr);
            }
        }

        @media (max-width: 900px) {
            .mh-editor {
                grid-template-columns: 1fr;
            }
        }

        /* =========================================================
         * Game Builder UI v2.3
         * Functional section navigation + form-state hardening
         * ====================================================== */

        html {
            scroll-behavior: smooth;
        }

        .mh-editor-nav-item {
            text-decoration: none !important;
            cursor: pointer;
            transition:
                background-color .14s ease,
                border-color .14s ease,
                transform .14s ease;
        }

        .mh-editor-nav-item:hover {
            color: #fff;
            background: rgba(22,135,255,.10);
            transform: translateX(2px);
        }

        .mh-editor-nav-item:focus-visible {
            outline: 2px solid var(--mh-blue);
            outline-offset: 2px;
        }

        .mh-editor-section,
        .mh-advanced {
            scroll-margin-top: 90px;
        }

        .mh-editor-section:target {
            position: relative;
            box-shadow:
                inset 0 0 0 1px rgba(22,135,255,.62),
                inset 4px 0 0 rgba(22,135,255,.72);
        }

        .mh-advanced:target {
            border-color: rgba(22,135,255,.75);
            box-shadow:
                0 0 0 1px rgba(22,135,255,.20);
        }

        /*
         * The first nav item had previously been styled as permanently
         * selected. Keep it subtle now that all six links are functional.
         */
        .mh-editor-nav-item:first-child {
            border: 1px solid transparent;
            background: transparent;
            box-shadow: none;
        }

        .mh-editor-nav-item:first-child:hover {
            background: rgba(22,135,255,.10);
        }

        /* =========================================================
         * Pelican Egg artwork
         * ====================================================== */

        .mh-game-mark {
            overflow: hidden;
            padding: 0;
        }

        .mh-game-icon {
            display: block;

            width: 100%;
            height: 100%;

            object-fit: cover;

            border-radius: inherit;
        }

        .mh-game-fallback {
            display: grid;
            place-items: center;

            width: 100%;
            height: 100%;

            color: #62b4ff;

            font-size: 1.02rem;
            font-weight: 900;
            letter-spacing: -.04em;
        }

        /* =========================================================
         * Game Builder UI v3
         * Real section tabs
         * ====================================================== */

        [x-cloak] {
            display: none !important;
        }

        .mh-editor-nav-item {
            width: 100%;

            border: 1px solid transparent;

            background: transparent;

            appearance: none;
            text-align: left;

            font: inherit;

            cursor: pointer;
        }

        .mh-editor-nav-item:hover {
            border-color: rgba(22,135,255,.18);

            background:
                rgba(22,135,255,.08);

            transform: none;
        }

        .mh-editor-nav-active {
            border-color:
                rgba(22,135,255,.42) !important;

            background:
                linear-gradient(
                    90deg,
                    rgba(22,135,255,.19),
                    rgba(22,135,255,.07)
                ) !important;

            box-shadow:
                inset 3px 0 0
                var(--mh-blue);
        }

        .mh-editor-nav-active .mh-nav-icon {
            border-color:
                rgba(22,135,255,.48);

            background:
                rgba(22,135,255,.22);

            color: #ffffff;
        }

        .mh-tab-workspace {
            min-height: 500px;

            border: 1px solid
                var(--mh-border-soft);

            border-radius: 10px;

            overflow: hidden;

            background:
                linear-gradient(
                    180deg,
                    rgba(12,29,42,.46),
                    rgba(6,18,27,.24)
                );
        }

        .mh-tab-panel {
            min-height: 500px;

            padding: 24px;

            border: 0 !important;

            background:
                linear-gradient(
                    180deg,
                    rgba(14,31,44,.62),
                    rgba(7,20,29,.22)
                );

            box-shadow: none !important;
        }

        .mh-tab-panel > .mh-title {
            margin-bottom: 18px;

            font-size: 1rem;
        }

        .mh-tab-panel .mh-field {
            max-width: 760px;
        }

        .mh-tab-panel .mh-provider-list {
            max-width: 760px;
        }

        /*
         * Both installation sections share the same tab.
         */
        .mh-installation-secondary {
            min-height: 0;

            padding-top: 0;

            border-top:
                1px solid
                var(--mh-border-soft)
                !important;

            background: transparent;
        }

        .mh-installation-secondary > .mh-title {
            padding-top: 21px;
        }

        /*
         * Keep installation tab visually continuous.
         */
        .mh-tab-workspace
        > #mh-installation:has(
            + #mh-package-deployment
        ) {
            min-height: 0;
            padding-bottom: 22px;
        }

        .mh-tab-heading {
            display: flex;
            align-items: center;
            gap: 12px;

            margin-bottom: 18px;
        }

        .mh-tab-heading-icon {
            width: 34px;
            height: 34px;

            flex: 0 0 34px;

            display: grid;
            place-items: center;

            border:
                1px solid
                rgba(22,135,255,.32);

            border-radius: 8px;

            background:
                rgba(22,135,255,.13);

            color: #66b7ff;

            font-size: .78rem;
            font-weight: 900;
        }

        .mh-advanced-tab {
            min-height: 500px;
        }

        .mh-advanced-tab
        .mh-advanced-body {
            padding: 0;

            border: 0;

            background: transparent;
        }

        .mh-advanced-tab
        .mh-provider-meta {
            max-width: 760px;
        }

        /*
         * Save controls remain visible regardless of active tab.
         */
        .mh-actions-bar {
            position: relative;

            z-index: 2;
        }

        @media (max-width: 900px) {
            .mh-tab-workspace,
            .mh-tab-panel,
            .mh-advanced-tab {
                min-height: 0;
            }

            .mh-tab-panel {
                padding: 18px;
            }
        }

        /* =========================================================
         * Game Builder UI v3.1
         * Tab density polish
         * ====================================================== */

        .mh-tab-workspace {
            min-height: 360px;
        }

        .mh-tab-panel,
        .mh-advanced-tab {
            min-height: 360px;
        }

        .mh-advanced-tab {
            padding-top: 22px;
        }

        .mh-advanced-tab .mh-tab-heading {
            margin-bottom: 14px;
        }

        @media (max-width: 900px) {
            .mh-tab-workspace,
            .mh-tab-panel,
            .mh-advanced-tab {
                min-height: 0;
            }
        }

        /* =========================================================
         * Game Builder UI v3.2
         * Wide workspace + ModHarbor banner
         * ====================================================== */

        /*
         * Filament normally constrains the page content quite heavily.
         * Game Builder is a dashboard/editor, so give it desktop room.
         *
         * This is scoped to pages containing .mh-builder so it does not
         * alter the rest of Pelican.
         */
        body:has(.mh-builder) .fi-main {
            width: 100% !important;
            max-width: 1720px !important;

            margin-left: auto !important;
            margin-right: auto !important;

            padding-left: 28px !important;
            padding-right: 28px !important;
        }

        body:has(.mh-builder) .fi-page {
            width: 100% !important;
            max-width: none !important;
        }

        body:has(.mh-builder) .fi-page-content {
            width: 100% !important;
            max-width: none !important;
        }

        /*
         * Hide the plain Filament title on this page. The custom
         * ModHarbor banner below becomes the visual page heading.
         */
        body:has(.mh-builder) .fi-header {
            display: none !important;
        }

        .mh-builder {
            width: 100%;
            max-width: none;
        }

        /* ---------------------------------------------------------
         * ModHarbor hero/banner
         * ------------------------------------------------------ */

        .mh-hero {
            position: relative;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;

            min-height: 116px;

            padding: 20px 24px;

            overflow: hidden;

            border: 1px solid #2e536d;
            border-radius: 14px;

            background:
                radial-gradient(
                    circle at 8% 0%,
                    rgba(22, 135, 255, .20),
                    transparent 34%
                ),
                radial-gradient(
                    circle at 88% 120%,
                    rgba(30, 92, 138, .16),
                    transparent 38%
                ),
                linear-gradient(
                    115deg,
                    #0d2231 0%,
                    #0a1924 48%,
                    #08151f 100%
                );

            box-shadow:
                0 16px 40px rgba(0, 0, 0, .18),
                inset 0 1px 0 rgba(255,255,255,.035);
        }

        .mh-hero::after {
            content: "";

            position: absolute;

            width: 340px;
            height: 340px;

            right: -130px;
            top: -210px;

            border: 1px solid rgba(81, 164, 229, .10);
            border-radius: 50%;

            pointer-events: none;
        }

        .mh-hero-brand {
            position: relative;
            z-index: 1;

            display: flex;
            align-items: center;
            gap: 17px;

            min-width: 0;
        }

        .mh-hero-icon {
            width: 62px;
            height: 62px;
            flex: 0 0 62px;

            display: grid;
            place-items: center;

            border: 1px solid rgba(60, 158, 235, .48);
            border-radius: 15px;

            background:
                radial-gradient(
                    circle at 28% 22%,
                    rgba(76, 172, 255, .28),
                    transparent 48%
                ),
                linear-gradient(
                    145deg,
                    #123e5d,
                    #0b263a
                );

            box-shadow:
                inset 0 1px 0 rgba(255,255,255,.08),
                0 8px 22px rgba(0,0,0,.18);

            color: #78c2ff;
        }

        .mh-hero-anchor {
            transform: translateY(-1px);
            font-size: 1.75rem;
            line-height: 1;
        }

        .mh-hero-copy {
            min-width: 0;
        }

        .mh-hero-eyebrow {
            margin-bottom: 3px;

            color: #62b7ff;

            font-size: .69rem;
            line-height: 1;
            font-weight: 850;
            letter-spacing: .17em;
        }

        .mh-hero-title {
            margin: 0;

            color: #ffffff;

            font-size: clamp(1.55rem, 2vw, 2.15rem);
            line-height: 1.05;
            font-weight: 850;
            letter-spacing: -.035em;
        }

        .mh-hero-description {
            max-width: 780px;

            margin: 7px 0 0;

            color: #94aec3;

            font-size: .82rem;
            line-height: 1.5;
        }

        .mh-hero-meta {
            position: relative;
            z-index: 1;

            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 7px;

            max-width: 370px;
        }

        .mh-hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;

            min-height: 30px;

            padding: 5px 10px;

            border: 1px solid #31536b;
            border-radius: 999px;

            background: rgba(7, 21, 31, .62);

            color: #bed1e0;

            font-size: .69rem;
            line-height: 1;
            font-weight: 700;

            white-space: nowrap;
        }

        .mh-hero-dot {
            width: 7px;
            height: 7px;

            border-radius: 50%;

            background: #27c67a;

            box-shadow: 0 0 0 3px rgba(39,198,122,.11);
        }

        /*
         * Use the extra desktop width intelligently.
         */
        @media (min-width: 1500px) {
            .mh-games-grid {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
            }

            .mh-editor {
                grid-template-columns:
                    205px minmax(0, 1fr);
            }

            .mh-editor-content {
                padding: 17px;
            }

            .mh-tab-panel .mh-field,
            .mh-tab-panel .mh-provider-list,
            .mh-advanced-tab .mh-provider-meta {
                max-width: 980px;
            }
        }

        @media (min-width: 1900px) {
            body:has(.mh-builder) .fi-main {
                max-width: 1840px !important;
            }

            .mh-tab-panel .mh-field,
            .mh-tab-panel .mh-provider-list,
            .mh-advanced-tab .mh-provider-meta {
                max-width: 1120px;
            }
        }

        @media (max-width: 1050px) {
            .mh-hero {
                align-items: flex-start;
                flex-direction: column;
            }

            .mh-hero-meta {
                justify-content: flex-start;
                max-width: none;
            }
        }

        @media (max-width: 700px) {
            body:has(.mh-builder) .fi-main {
                padding-left: 12px !important;
                padding-right: 12px !important;
            }

            .mh-hero {
                padding: 17px;
            }

            .mh-hero-icon {
                width: 50px;
                height: 50px;
                flex-basis: 50px;
            }

            .mh-hero-description {
                font-size: .78rem;
            }

            .mh-hero-meta {
                display: none;
            }
        }

        /* =========================================================
         * ModHarbor Game Builder UI v3.3
         * Lighthouse branding + wide workspace
         * ====================================================== */

        /*
         * Remove Filament's narrow content constraint.
         */
        body:has(.mh-builder) .fi-main,
        body:has(.mh-builder) .fi-main-ctn,
        body:has(.mh-builder) .fi-page,
        body:has(.mh-builder) .fi-page-content {
            width: 100% !important;
            max-width: none !important;
        }

        body:has(.mh-builder) .fi-main {
            margin: 0 !important;

            padding-left: 18px !important;
            padding-right: 18px !important;
        }

        body:has(.mh-builder) .fi-header {
            display: none !important;
        }

        .mh-builder {
            width: 100%;
            max-width: none !important;
        }

        /*
         * Banner
         */
        .mh-brand-banner {
            position: relative;

            display: grid;
            grid-template-columns:
                minmax(300px, .85fr)
                minmax(250px, .65fr)
                minmax(360px, 1fr);

            align-items: center;
            gap: 28px;

            min-height: 132px;

            padding: 17px 22px;

            overflow: hidden;

            border: 1px solid #238ccf;
            border-radius: 15px;

            background:
                radial-gradient(
                    circle at 78% -20%,
                    rgba(100, 82, 238, .40),
                    transparent 35%
                ),
                linear-gradient(
                    105deg,
                    #08263e 0%,
                    #0b2440 36%,
                    #18194e 73%,
                    #17184a 100%
                );

            box-shadow:
                0 12px 36px rgba(0,0,0,.24),
                inset 0 1px 0 rgba(255,255,255,.05);
        }

        .mh-brand-main,
        .mh-brand-title-block,
        .mh-brand-right {
            position: relative;
            z-index: 2;
        }

        /*
         * Lighthouse mark
         */
        .mh-brand-main {
            display: flex;
            align-items: center;
            gap: 17px;
            min-width: 0;
        }

        .mh-brand-logo {
            width: 76px;
            height: 76px;

            flex: 0 0 76px;

            filter:
                drop-shadow(
                    0 8px 15px rgba(0,0,0,.26)
                );
        }

        .mh-brand-logo svg {
            display: block;
            width: 100%;
            height: 100%;
        }

        .mh-brand-word {
            display: flex;
            align-items: baseline;

            color: #ffffff;

            font-size: clamp(1.65rem, 2vw, 2.45rem);
            line-height: .95;
            font-weight: 900;
            letter-spacing: -.055em;

            white-space: nowrap;
        }

        .mh-brand-word strong {
            color: #4397ff;
            font-weight: 900;
        }

        .mh-brand-subtitle {
            margin-top: 7px;

            color: #c6ddff;

            font-size: .90rem;
            font-weight: 800;
            letter-spacing: -.01em;
        }

        .mh-brand-support {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;

            margin-top: 9px;

            color: #c0d0e0;

            font-size: .69rem;
            font-weight: 700;
        }

        .mh-support-current {
            display: inline-flex;
            align-items: center;
            gap: 6px;

            color: #36e5b0;
        }

        .mh-support-dot {
            width: 7px;
            height: 7px;

            border-radius: 50%;
            background: #2dd4a7;

            box-shadow:
                0 0 0 4px rgba(45,212,167,.10);
        }

        .mh-support-muted {
            margin-left: -3px;
            color: #617d98;

            font-size: .60rem;
            font-weight: 650;
        }

        /*
         * Game Builder title
         */
        .mh-brand-title-block {
            padding-left: 4px;
        }

        .mh-brand-kicker {
            color: #67b9ff;

            font-size: .63rem;
            line-height: 1;
            font-weight: 900;
            letter-spacing: .22em;
        }

        .mh-builder-name {
            margin-top: 5px;

            color: #fff;

            font-size: clamp(1.35rem, 1.75vw, 2rem);
            line-height: 1;
            font-weight: 850;
            letter-spacing: -.035em;
        }

        .mh-builder-desc {
            max-width: 330px;

            margin-top: 7px;

            color: #88a8c2;

            font-size: .71rem;
            line-height: 1.45;
        }

        /*
         * Right-side product block
         */
        .mh-brand-right {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;

            min-width: 0;
        }

        .mh-multigame-card {
            display: flex;
            align-items: center;
            gap: 12px;

            min-width: 185px;
            min-height: 62px;

            padding: 10px 14px;

            border:
                1px solid
                rgba(76, 143, 234, .42);

            border-radius: 11px;

            background:
                rgba(7, 31, 55, .45);

            backdrop-filter: blur(4px);
        }

        .mh-multigame-icon {
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 4px;

            width: 29px;
            height: 31px;

            flex: 0 0 29px;
        }

        .mh-multigame-icon span {
            display: block;

            height: 5px;

            border: 2px solid #55c8ff;
            border-radius: 3px;
        }

        .mh-multigame-card strong,
        .mh-multigame-card span,
        .mh-multigame-card small {
            display: block;
        }

        .mh-multigame-card strong {
            color: #ffffff;
            font-size: .75rem;
        }

        .mh-multigame-card span {
            margin-top: 1px;

            color: #c3d7e8;
            font-size: .68rem;
        }

        .mh-multigame-card small {
            margin-top: 2px;

            color: #58b5ff;
            font-size: .57rem;
        }

        .mh-brand-providers {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 7px;
        }

        .mh-brand-providers span {
            min-height: 29px;

            display: inline-flex;
            align-items: center;

            padding: 5px 10px;

            border:
                1px solid
                rgba(77, 125, 184, .33);

            border-radius: 8px;

            background:
                rgba(5, 20, 44, .72);

            color: #f5f8fc;

            font-size: .65rem;
            font-weight: 800;
        }

        .mh-banner-art {
            position: absolute;

            right: 0;
            bottom: 0;

            width: 64%;
            height: 100%;

            z-index: 1;

            pointer-events: none;
        }

        .mh-banner-art svg {
            width: 100%;
            height: 100%;
        }

        /*
         * Make configured cards and editor use the width we just gained.
         */
        .mh-games-grid {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }

        .mh-editor {
            grid-template-columns:
                210px minmax(0, 1fr);
        }

        .mh-editor-content {
            padding: 14px;
        }

        .mh-tab-panel .mh-field,
        .mh-tab-panel .mh-provider-list {
            max-width: 1080px;
        }

        /*
         * Let Game Builder fill virtually the entire area between
         * Pelican's sidebar and the right edge.
         */
        @media (min-width: 1400px) {
            body:has(.mh-builder) .fi-main {
                padding-left: 12px !important;
                padding-right: 20px !important;
            }

            .mh-builder {
                max-width: none !important;
            }
        }

        @media (max-width: 1250px) {
            .mh-brand-banner {
                grid-template-columns:
                    minmax(270px, 1fr)
                    minmax(290px, .8fr);
            }

            .mh-brand-title-block {
                display: none;
            }

            .mh-games-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 850px) {
            .mh-brand-banner {
                grid-template-columns: 1fr;
            }

            .mh-brand-right {
                justify-content: flex-start;
            }

            .mh-banner-art {
                display: none;
            }

            .mh-games-grid {
                grid-template-columns: 1fr;
            }

            .mh-editor {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 620px) {
            body:has(.mh-builder) .fi-main {
                padding-left: 8px !important;
                padding-right: 8px !important;
            }

            .mh-brand-banner {
                padding: 14px;
            }

            .mh-brand-logo {
                width: 58px;
                height: 58px;
                flex-basis: 58px;
            }

            .mh-brand-right {
                display: none;
            }
        }

        /* =========================================================
         * ModHarbor Game Builder UI v3.4
         * Final density polish
         * ====================================================== */

        /*
         * Tabs should size to their actual content rather than
         * reserving a giant empty workspace.
         */
        .mh-tab-workspace {
            min-height: 285px;
        }

        .mh-tab-panel,
        .mh-advanced-tab {
            min-height: 285px;
        }

        /*
         * Keep form controls readable without stretching them across
         * the entire ultra-wide workspace.
         */
        .mh-tab-panel .mh-field,
        .mh-tab-panel .mh-provider-list {
            max-width: 900px;
        }

        .mh-advanced-tab .mh-provider-meta {
            max-width: 900px;
        }

        /*
         * Behavior is intentionally simple. Keep that tab compact.
         */
        #mh-server-behavior {
            min-height: 285px;
        }

        #mh-server-behavior .mh-checkbox-row {
            max-width: 760px;
        }

        /*
         * Slightly larger Pelican Egg artwork now that the cards
         * have more horizontal room.
         */
        .mh-game-mark {
            width: 72px;
            height: 72px;
            flex: 0 0 72px;
            flex-basis: 72px;
            border-radius: 10px;
        }

        .mh-game-icon {
            object-fit: cover;
            object-position: center;
        }

        .mh-game-top {
            min-height: 72px;
            align-items: center;
        }

        .mh-game-card {
            min-height: 0;
        }

        .mh-game-facts {
            min-height: 0;
        }

        /*
         * Let card content breathe a little more without making
         * the top area taller overall.
         */
        .mh-game-card {
            padding: 16px;
        }

        .mh-game-facts {
            margin-top: 14px;
        }

        .mh-game-providers {
            margin-top: 13px;
        }

        /*
         * Keep editor actions visually connected to the active tab.
         */
        .mh-actions-bar {
            padding-top: 11px;
            padding-bottom: 11px;
        }

        /*
         * Compact Import section slightly so it remains clearly
         * secondary to the Game Builder editor.
         */
        .mh-import-row {
            min-height: 36px;
        }

        /*
         * Installation may genuinely need more room because it
         * combines directories and package/deployment controls.
         */
        #mh-installation,
        #mh-package-deployment {
            min-height: 0;
        }

        /*
         * Advanced can grow naturally when provider metadata exists.
         */
        .mh-advanced-tab {
            height: auto;
        }

        @media (max-width: 900px) {
            .mh-tab-workspace,
            .mh-tab-panel,
            .mh-advanced-tab,
            #mh-server-behavior {
                min-height: 0;
            }

            .mh-game-mark {
                width: 64px;
                height: 64px;
                flex-basis: 64px;
            }
        }

        /* =========================================================
         * ModHarbor Game Builder - natural tab height
         * ====================================================== */

        .mh-tab-workspace,
        .mh-tab-panel,
        .mh-advanced-tab,
        #mh-server-behavior {
            min-height: 0 !important;
            height: auto !important;
        }

        .mh-tab-panel {
            padding-top: 22px;
            padding-bottom: 24px;
        }

        /*
         * Installation contains two sections, so keep them visually
         * connected without creating artificial empty height.
         */
        #mh-installation,
        #mh-package-deployment {
            min-height: 0 !important;
            height: auto !important;
        }

        /*
         * Prevent the editor content column itself from stretching
         * because of the navigation column.
         */
        .mh-editor-content {
            align-self: start;
        }

        .mh-tab-workspace {
            align-self: start;
        }

    
        .mh-brand-banner {
            display: block !important;
            grid-template-columns: none !important;
            align-items: stretch !important;
            gap: 0 !important;
            min-height: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
            border-radius: 16px !important;
            border: 1px solid rgba(56, 189, 248, 0.18) !important;
            background: #04111f !important;
            line-height: 0 !important;
            width: 100% !important;
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
        body:has(.mh-builder) .fi-main,
        body:has(.mh-builder) .fi-page,
        body:has(.mh-builder) .fi-page-content,
        body:has(.mh-builder) .fi-main-ctn {
            width: 100% !important;
            max-width: none !important;
        }
        .mh-games-grid {
            align-items: start !important;
            grid-auto-rows: min-content !important;
        }
        .mh-game-card {
            height: auto !important;
            min-height: 0 !important;
            align-self: start !important;
        }
        .mh-game-card .mh-actions,
        .mh-game-actions {
            margin-top: 12px !important;
        }
        .mh-game-facts,
        .mh-game-providers {
            min-height: 0 !important;
        }
        .mh-game-icon {
            object-fit: contain !important;
            object-position: center center !important;
            background: #0b1d2b;
        }
</style>

    <div class="mh-builder space-y-5">

        
<section class="mh-brand-banner">
    <img class="mh-brand-banner-img" src="/modharbor/branding/banner-v3.webp" alt="ModHarbor — universal mod management for Pelican">
</section>


        @if ($message !== '')
            <div role="status" class="mh-message">
                {{ $message }}
            </div>
        @endif

        {{-- Configured games --}}
        <section class="mh-card">
            <div class="mh-card-header">
                <div>
                    <div class="mh-title">Configured games</div>
                    <div class="mh-help">
                        Manage every game definition ModHarbor can use on this panel.
                        Official definitions use ModHarbor-tested capability profiles.
                    </div>
                </div>

                <x-filament::button wire:click="newGame">
                    + Add game
                </x-filament::button>
            </div>

            <div class="mh-section">
                <div class="mh-games-grid">
                    @forelse ($games as $key => $game)
                        @php
                            $official =
                                $this->isOfficialDefinition(
                                    (string) $key
                                );

                            $artVersion = substr(hash('sha256', json_encode([
                                $game['artwork_url'] ?? '',
                                $game['steam_app_id'] ?? '',
                                $game['steamgriddb_game_id'] ?? '',
                            ])), 0, 12);
                        @endphp

                        <article
                            wire:key="game-{{ $key }}"
                            class="mh-game-card"
                        >
                            <div class="mh-game-top">
                                <div class="mh-game-mark">
                                    <img
                                        src="/modharbor/game-artwork/{{ rawurlencode((string) $key) }}?v={{ $artVersion }}"
                                        alt=""
                                        class="mh-game-icon"
                                        loading="lazy"
                                        referrerpolicy="no-referrer"
                                    >
                                </div>

                                <div class="mh-game-heading">
                                    <div class="mh-game-name">
                                        <span>{{ $game['name'] }}</span>

                                        @if ($game['enabled'])
                                            <span class="mh-badge mh-badge-enabled">
                                                Enabled
                                            </span>
                                        @else
                                            <span class="mh-badge mh-badge-disabled">
                                                Disabled
                                            </span>
                                        @endif

                                        @if ($official)
                                            <span class="mh-badge mh-badge-official">
                                                Official
                                            </span>
                                        @else
                                            <span class="mh-badge mh-badge-custom">
                                                Custom
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="mh-game-facts">
                                @if (!empty($game['steam_app_id']))
                                    <div>
                                        <strong>Steam App ID:</strong>
                                        {{ $game['steam_app_id'] }}
                                    </div>
                                @endif

                                @if (!empty($game['detection']['egg_names']))
                                    <div>
                                        <strong>Exact egg:</strong>
                                        {{ implode(', ', $game['detection']['egg_names']) }}
                                    </div>
                                @endif

                                @if (!empty($game['detection']['egg_ids']))
                                    <div>
                                        <strong>Egg ID:</strong>
                                        {{ implode(', ', $game['detection']['egg_ids']) }}
                                    </div>
                                @endif
                            </div>

                            <div class="mh-game-providers">
                                @php
                                    $sourceKeys = array_keys($game['sources'] ?? []);
                                    $visibleSources = array_slice($sourceKeys, 0, 3);
                                    $extraSources = max(0, count($sourceKeys) - 3);
                                @endphp

                                <div class="mh-chip-row">
                                    @forelse ($visibleSources as $source)
                                        @php
                                            $provider =
                                                $this->providerOptions()[$source]
                                                ?? null;
                                        @endphp

                                        <span
                                            class="mh-chip"
                                            title="{{ $this->deploymentStatus($game, $source) }}"
                                        >
                                            {{ $provider['label'] ?? $source }}
                                        </span>
                                    @empty
                                        <span class="mh-help">
                                            No providers selected
                                        </span>
                                    @endforelse

                                    @if ($extraSources > 0)
                                        <span class="mh-chip mh-package-chip">
                                            +{{ $extraSources }} more
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mh-actions mh-game-actions">
                                <x-filament::button
                                    wire:click="editGame('{{ $key }}')"
                                >
                                    Edit
                                </x-filament::button>

                                <x-filament::button
                                    color="gray"
                                    wire:click="exportGame('{{ $key }}')"
                                >
                                    Export
                                </x-filament::button>

                                <x-filament::button
                                    color="{{ $game['enabled'] ? 'warning' : 'success' }}"
                                    wire:click="toggleGame('{{ $key }}')"
                                    wire:confirm="Change availability for every matching server? Disabled games block custom detection until re-enabled."
                                >
                                    {{ $game['enabled'] ? 'Disable' : 'Enable' }}
                                </x-filament::button>

                                @if (!$game['enabled'])
                                    <x-filament::button
                                        color="danger"
                                        wire:click="deleteGame('{{ $key }}')"
                                        wire:confirm="Export a backup first. Delete this definition? Existing mods stay on servers. Do not delete definitions still in use."
                                    >
                                        Delete
                                    </x-filament::button>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="mh-empty-games">
                            No game definitions are configured yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

        {{-- Add / edit form --}}
        @if ($editorOpen)
        <form
            wire:submit="saveGame"
            wire:key="game-definition-editor-{{ $editing === '' ? 'new' : $editing }}"
            class="mh-card mh-editor-card"
        >

            <div class="mh-card-header">
                <div>
                    <div class="mh-title">
                        {{ $editing === '' ? 'New game definition' : 'Edit ' . ($form['name'] ?? 'game') }}
                    </div>

                    <div class="mh-help">
                        {{ $editing === ''
                            ? 'Create a portable definition for a game ModHarbor should manage.'
                            : 'Update this game definition. Changes apply to every matching server on this panel.' }}
                    </div>
                </div>

                @if ($editing !== '')
                    <span class="mh-editing-badge">
                        @if ($this->isOfficialDefinition($editing))
                            Editing official definition
                        @else
                            Editing custom definition
                        @endif
                    </span>
                @endif
            </div>

            <div
                class="mh-editor"
                x-data="{ activeTab: 'game' }"
            >
                <aside
                    class="mh-editor-nav"
                    aria-label="Game definition sections"
                >
                    <button
                        type="button"
                        class="mh-editor-nav-item"
                        :class="{ 'mh-editor-nav-active': activeTab === 'game' }"
                        @click="activeTab = 'game'"
                    >
                        <span class="mh-nav-icon">G</span>
                        <span>
                            <strong>Game Information</strong>
                            <small>Name, Steam ID and status</small>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="mh-editor-nav-item"
                        :class="{ 'mh-editor-nav-active': activeTab === 'detection' }"
                        @click="activeTab = 'detection'"
                    >
                        <span class="mh-nav-icon">D</span>
                        <span>
                            <strong>Server Detection</strong>
                            <small>Egg names and IDs</small>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="mh-editor-nav-item"
                        :class="{ 'mh-editor-nav-active': activeTab === 'sources' }"
                        @click="activeTab = 'sources'"
                    >
                        <span class="mh-nav-icon">S</span>
                        <span>
                            <strong>Mod Sources</strong>
                            <small>Allowed providers</small>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="mh-editor-nav-item"
                        :class="{ 'mh-editor-nav-active': activeTab === 'installation' }"
                        @click="activeTab = 'installation'"
                    >
                        <span class="mh-nav-icon">I</span>
                        <span>
                            <strong>Installation</strong>
                            <small>Directories, packages and deployment</small>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="mh-editor-nav-item"
                        :class="{ 'mh-editor-nav-active': activeTab === 'behavior' }"
                        @click="activeTab = 'behavior'"
                    >
                        <span class="mh-nav-icon">B</span>
                        <span>
                            <strong>Server Behavior</strong>
                            <small>Runtime rules</small>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="mh-editor-nav-item"
                        :class="{ 'mh-editor-nav-active': activeTab === 'advanced' }"
                        @click="activeTab = 'advanced'"
                    >
                        <span class="mh-nav-icon">A</span>
                        <span>
                            <strong>Advanced Settings</strong>
                            <small>Developer options</small>
                        </span>
                    </button>
                </aside>

                <div
                    class="mh-editor-content"
                    wire:replace
                    wire:key="definition-editor-content-{{ $editing === '' ? 'new' : $editing }}"
                >
                    <div class="mh-tab-workspace">

                {{-- Game information --}}
                <section
                    id="mh-game-information"
                    class="mh-editor-section mh-tab-panel"
                    x-show="activeTab === 'game'"
                    x-cloak
                >
                    <div class="mh-title">Game information</div>

                    <div class="mh-field" style="position:relative;">
                        <span class="mh-label">Game name</span>

                        <input
                            class="mh-input"
                            wire:model.live.debounce.300ms="form.name"
                            value="{{ $form['name'] ?? '' }}"
                            maxlength="160"
                            required
                            autocomplete="off"
                            placeholder="Search for a game..."
                        >

                        <span class="mh-help">
                            Search known game setups and Steam identities. Selecting a recommended setup replaces
                            sources, provider metadata, paths, package types and runtime defaults in this draft.
                            Review or edit every value before saving. You can also enter a game manually.
                        </span>

                        @if (!empty($gameSearchResults))
                            <div
                                class="mh-game-search-results"
                                style="
                                    margin-top:8px;
                                    border:1px solid rgba(148,163,184,.22);
                                    border-radius:10px;
                                    overflow:hidden;
                                    background:rgba(15,23,42,.96);
                                "
                            >
                                @foreach ($gameSearchResults as $index => $result)
                                    <button
                                        type="button"
                                        wire:key="game-search-result-{{ $index }}"
                                        wire:click="selectGameSearchResult({{ $index }})"
                                        style="
                                            width:100%;
                                            display:flex;
                                            align-items:center;
                                            justify-content:space-between;
                                            gap:16px;
                                            padding:12px 14px;
                                            border:0;
                                            border-bottom:1px solid rgba(148,163,184,.14);
                                            background:transparent;
                                            color:inherit;
                                            text-align:left;
                                            cursor:pointer;
                                        "
                                    >
                                        <span>
                                            <strong>
                                                {{ $result['name'] ?? 'Unknown game' }}
                                            </strong>

                                            <span
                                                class="mh-help"
                                                style="display:block;margin-top:3px;"
                                            >
                                                {{ $result['catalog_label'] ?? 'Catalog' }}
                                                @if (!empty($result['steam_app_id']))
                                                    · App ID {{ $result['steam_app_id'] }}
                                                @endif
                                            </span>
                                        </span>

                                        <span
                                            aria-hidden="true"
                                            style="
                                                font-size:18px;
                                                opacity:.65;
                                            "
                                        >
                                            →
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @if (!empty($gameSearchMessage))
                            <div
                                class="mh-help"
                                style="margin-top:8px;"
                            >
                                {{ $gameSearchMessage }}
                            </div>
                        @endif
                    </div>

                    <label class="mh-field">
                        <span class="mh-label">Steam App ID <span class="mh-help">(optional)</span></span>
                        <input
                            class="mh-input"
                            wire:model="form.steam_app_id"
                            value="{{ $form['steam_app_id'] ?? '' }}"
                            inputmode="numeric"
                            placeholder="Optional Steam App ID"
                        >
                        <span class="mh-help">
                            Automatically supplies cached Steam artwork. Mod paths remain separately configured.
                        </span>
                    </label>

                    <label class="mh-field">
                        <span class="mh-label">Custom artwork URL (optional)</span>
                        <input class="mh-input" wire:model="form.artwork_url" maxlength="2048" placeholder="https://... or /images/game.jpg">
                        <span class="mh-help">Overrides automatic artwork on game cards and the server header. HTTPS URL, panel path, or leave blank for automatic (Steam → SteamGridDB → placeholder).</span>
                    </label>

                    <label class="mh-field">
                        <span class="mh-label">SteamGridDB game ID <span class="mh-help">(optional, for non-Steam games)</span></span>
                        <input class="mh-input" wire:model="form.steamgriddb_game_id" inputmode="numeric" placeholder="e.g. 2254">
                        <span class="mh-help">Stable SteamGridDB game ID. Avoids name guessing after you pick a match. Requires an API key under Provider Settings → SteamGridDB.</span>
                    </label>

                    <div class="mh-field" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                        <label class="mh-field" style="flex:1;min-width:220px;margin:0;">
                            <span class="mh-label">Upload artwork</span>
                            <input type="file" class="mh-input" wire:model="artworkUpload" accept="image/png,image/jpeg,image/webp">
                            <span class="mh-help">PNG, JPEG or WebP, max 512 KiB. Applied when you Save Game.</span>
                        </label>
                        <div style="display:flex;gap:8px;padding-top:18px;">
                            <button type="button" class="mh-btn" wire:click="useAutomaticArtwork" wire:loading.attr="disabled">Use automatic artwork</button>
                        </div>
                    </div>
                </section>

                {{-- Server detection --}}
                <section
                    id="mh-server-detection"
                    class="mh-editor-section mh-tab-panel"
                    x-show="activeTab === 'detection'"
                    x-cloak
                >
                    <div class="mh-title">Server detection</div>

                    <label class="mh-field">
                        <span class="mh-label">Exact egg name(s)</span>
                        <textarea
                            class="mh-input"
                            wire:model.live="eggNames"
                            rows="3"
                            placeholder="Example Game"
                        ></textarea>
                        <span class="mh-help">
                            One per line. Use the egg name only, not the number Pelican shows beside it. Example: Minecraft
                        </span>
                    </label>

                    <label class="mh-field">
                        <span class="mh-label">Egg name contains</span>
                        <textarea
                            class="mh-input"
                            wire:model.live="eggNameContains"
                            rows="3"
                            placeholder="Example versioned egg name"
                        ></textarea>
                        <span class="mh-help">
                            Optional. One per line. Use this when an egg name includes a
                            changing version or suffix.
                        </span>
                    </label>

                    <label class="mh-field">
                        <span class="mh-label">Egg IDs</span>
                        <textarea
                            class="mh-input"
                            wire:model.live="eggIds"
                            rows="3"
                            placeholder="Optional exact Pelican egg ID"
                        ></textarea>
                        <span class="mh-help">
                            Optional. The numeric ID Pelican shows next to the egg name (example: 5). Leave blank — name matching is enough for most panels.
                        </span>
                    </label>
                </section>

                {{-- Providers --}}
                <section
                    id="mh-mod-sources"
                    class="mh-editor-section mh-tab-panel"
                    x-show="activeTab === 'sources'"
                    x-cloak
                >
                    <div class="mh-title">Mod sources</div>

                    <div class="mh-help">
                        Select where ModHarbor is allowed to obtain mods for this game.
                    </div>

                    <div class="mh-provider-list">
                        @foreach ($this->providerOptions() as $key => $provider)
                            <label
                                wire:key="source-toggle-{{ $key }}"
                                class="mh-provider"
                            >
                                <input
                                    type="checkbox"
                                    wire:model.live="allowedSources"
                                    value="{{ $key }}"
                                >

                                <span>{{ $provider['label'] }}</span>
                            </label>
                        @endforeach
                    </div>

                </section>

                {{-- Installation --}}
                <section
                    id="mh-installation"
                    class="mh-editor-section mh-tab-panel"
                    x-show="activeTab === 'installation'"
                    x-cloak
                >
                    <div class="mh-title">Installation settings</div>

                    <label class="mh-field">
                        <span class="mh-label">Mod directory</span>
                        <textarea
                            class="mh-input"
                            wire:model="modDirectories"
                            placeholder="Mods"
                        >{{ $modDirectories }}</textarea>
                        <span class="mh-help">
                            Relative to the server root. One path per line.
                        </span>
                    </label>

                    <label class="mh-field">
                        <span class="mh-label">Config directory</span>
                        <textarea
                            class="mh-input"
                            wire:model="configDirectories"
                            placeholder="Configs"
                        >{{ $configDirectories }}</textarea>
                        <span class="mh-help">
                            Locations ModHarbor may inspect for mod configuration files.
                        </span>
                    </label>
                </section>

                {{-- Package/deployment --}}
                <section
                    id="mh-package-deployment"
                    class="mh-editor-section mh-tab-panel mh-installation-secondary"
                    x-show="activeTab === 'installation'"
                    x-cloak
                >
                    <div class="mh-title">Package and deployment</div>

                    <div class="mh-field">
                        <span class="mh-label">Accepted package types</span>

                        <div class="mh-packages">
                            @foreach (['zip', 'dll', 'cs', 'jar', 'pak', 'xml', 'json', 'cfg', 'txt'] as $extension)
                                <label>
                                    <input
                                        type="checkbox"
                                        wire:model="form.package_types"
                                        value="{{ $extension }}"
                                    >
                                    .{{ $extension }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <label class="mh-field">
                        <span class="mh-label">Deployment strategy</span>

                        <select
                            class="mh-input"
                            wire:model="form.deployment.strategy"
                        >
                            <option value="archive">
                                Extract ZIP contents into target
                            </option>

                            <option value="copy">
                                Copy a single file into target
                            </option>

                            <option value="provider-managed">
                                Provider-managed
                            </option>
                        </select>
                    </label>

                    <label class="mh-field">
                        <span class="mh-label">Target mod directory</span>
                        <input
                            class="mh-input"
                            wire:model="form.deployment.target"
                            required
                            placeholder="Mods"
                        >
                    </label>
                </section>

                {{-- Behavior --}}
                <section
                    id="mh-server-behavior"
                    class="mh-editor-section mh-tab-panel"
                    x-show="activeTab === 'behavior'"
                    x-cloak
                >
                    <div class="mh-title">Server behavior</div>

                    <label class="mh-field mh-checkbox-row">
                        <input
                            type="checkbox"
                            wire:model="form.behavior.install_while_running"
                        >

                        <span>
                            <strong>Allow mod changes while running</strong><br>
                            <span class="mh-help">
                                Leave disabled unless the game safely supports live mod changes.
                            </span>
                        </span>
                    </label>

                    <label class="mh-field mh-checkbox-row">
                        <input
                            type="checkbox"
                            wire:model="form.behavior.restart_required"
                        >

                        <span>
                            <strong>Restart required after mod changes</strong><br>
                            <span class="mh-help">
                                Shows that changes require a server restart to take effect.
                            </span>
                        </span>
                    </label>

                    <div class="mh-help" style="margin-top:14px;">
                        ModHarbor never automatically stops or restarts the server.
                    </div>
                </section>

            {{-- Advanced --}}
            <section
                id="mh-advanced-settings"
                class="mh-editor-section mh-tab-panel mh-advanced-tab"
                x-show="activeTab === 'advanced'"
                x-cloak
            >
                <div class="mh-tab-heading">
                    <span class="mh-tab-heading-icon">A</span>

                    <div>
                        <div class="mh-title">
                            Advanced settings
                        </div>

                        <div class="mh-help">
                            Stable key, exact egg IDs, archive layout and provider metadata.
                        </div>
                    </div>
                </div>

<div class="mh-advanced-body">
    <label class="mh-label">Config discovery and preservation rules (optional JSON)</label>
    <textarea class="mh-input" wire:model="configRulesJson" rows="5" aria-label="Config rules JSON" placeholder='[{"root":"Configs","patterns":["*.xml","*.json"],"exclude":["*.bak*"],"depth":4}]'></textarea>
    <p>Leave blank for the existing game defaults. Matching config files are editable and preserved during generic package updates. Roots must be declared config directories.</p>

                    <div
                        style="
                            display:grid;
                            grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
                            gap:16px;
                            margin-top:14px;
                        "
                    >
                        <label class="mh-field">
                            <span class="mh-label">Stable key</span>

                            @if ($editing === '')
                                <input
                                    class="mh-input"
                                    wire:model="form.key"
                                    placeholder="{{ $this->suggestedStableKey() ?: 'example-game' }}"
                                >

                                <span class="mh-help">
                                    Optional. Leave blank and ModHarbor creates
                                    <strong>{{ $this->suggestedStableKey() ?: 'a key from the game name' }}</strong>.
                                </span>
                            @else
                                <input
                                    class="mh-input"
                                    value="{{ $editing }}"
                                    disabled
                                >

                                <span class="mh-help">
                                    Stable keys cannot be changed after creation.
                                </span>
                            @endif
                        </label>

                        <label class="mh-field">
                            <span class="mh-label">Archive prefix to remove</span>
                            <input
                                class="mh-input"
                                wire:model="form.deployment.archive_prefix"
                                placeholder="Leave blank for normal ZIP extraction"
                            >
                            <span class="mh-help">
                                Advanced ZIP layouts only. When set, every archive file must exist beneath this prefix.
                            </span>
                        </label>
                    </div>

                    @if (count($allowedSources) > 0)
                        <div style="margin-top:20px;">
                            <div class="mh-title">Provider metadata</div>

                            <div class="mh-help">
                                Developer/provider-specific IDs and settings.
                                Do not store passwords or API tokens in portable definitions.
                            </div>

                            @foreach ($this->providerOptions() as $key => $provider)
                                @if (in_array($key, $allowedSources, true))
                                    <div
                                        wire:key="source-meta-{{ $key }}"
                                        class="mh-provider-meta"
                                    >
                                        <label>
                                            <span class="mh-label">
                                                {{ $provider['label'] }} metadata
                                            </span>

                                            <textarea
                                                class="mh-input"
                                                placeholder="{{ json_encode($provider['metadata_example'] ?? (object) [], JSON_UNESCAPED_SLASHES) }}"
                                                wire:model="sourceMetadata.{{ $key }}"
                                            ></textarea>
                                            @foreach ($provider['metadata_fields'] ?? [] as $field => $schema)
                                                <span class="mh-help" style="display:block;">
                                                    {{ $field }}: {{ $schema['type'] ?? 'text' }}{{ !empty($schema['required']) ? ' (required)' : '' }}
                                                </span>
                                            @endforeach
                                            @if (!empty($provider['metadata_require_any']))
                                                <span class="mh-help">Provide at least one: {{ implode(', ', $provider['metadata_require_any']) }}.</span>
                                            @endif
                                        </label>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                </div>
            </section>

                    </div>
                </div>
            </div>

            <div class="mh-actions-bar">
                <x-filament::button
                    type="submit"
                    wire:loading.attr="disabled"
                >
                    {{ $editing === '' ? 'Add game' : 'Save changes' }}
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    wire:click="cancelEdit"
                >
                    Cancel
                </x-filament::button>
            </div>
        </form>
        @endif

        {{-- Import --}}
        <section class="mh-card">
            <div class="mh-card-header">
                <div>
                    <div class="mh-title">Import a Game Definition</div>

                    <div class="mh-help">
                        Choose a <code>.modharbor.json</code> definition.
                        Imports open as disabled drafts for review.
                    </div>
                </div>
            </div>

            <div class="mh-section">
                <div class="mh-import-row">
                    <div class="mh-import-controls">
                    <input
                        type="file"
                        wire:model="importFile"
                        accept=".modharbor.json,application/json"
                    >

                    <x-filament::button
                        wire:click="importGame"
                        wire:loading.attr="disabled"
                    >
                        Load for review
                    </x-filament::button>
                    </div>
                </div>
            </div>
        </section>

    </div>
</x-filament-panels::page>
