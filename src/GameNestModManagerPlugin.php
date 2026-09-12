<?php

namespace GameNest\GameNestModManager;

use App\Enums\TablerIcon;
use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use GameNest\GameNestModManager\Pages\ModManager;

class GameNestModManagerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'gamenest-mod-manager';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'server') {
            $panel->navigationItems([
                NavigationItem::make('ModHarbor')
                    ->icon(TablerIcon::BuildingLighthouse)
                    ->extraAttributes([
                        'class' => 'modharbor-sidebar-nav',
                        'x-data' => "{ modHarborOpen: true }",
                        'x-bind:class' => "{ 'modharbor-collapsed': ! modHarborOpen }",
                        'x-on:click' => "if (\$event.target.closest('.fi-sidebar-item-btn') === \$el.querySelector(':scope > .fi-sidebar-item-btn')) { modHarborOpen = ! modHarborOpen }",
                    ])
                    ->sort(18),
            ]);
        }

        if (in_array($panel->getId(), ['admin', 'server'], true)) {
            $panel->pages([
                \GameNest\GameNestModManager\Pages\GameSetup::class,
                \GameNest\GameNestModManager\Pages\ProviderSettings::class,
            ]);
        }
        if ($panel->getId() === 'server') {
            $panel->pages([
                ModManager::class,
            ]);
            if (method_exists($panel, 'defaultAvatarProvider')) {
                $panel->defaultAvatarProvider(
                    \GameNest\GameNestModManager\Services\ServerArtworkAvatarProvider::class
                );
            }
        }
    }

    public function boot(Panel $panel): void
    {
        if ($panel->getId() !== 'server') {
            return;
        }

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => <<<'HTML'
<style id="modharbor-sidebar-branding">
.fi-sidebar .fi-avatar img {
    object-fit: cover;
}
/*
 * ModHarbor server-sidebar branding.
 * Keep child navigation native; only brand the parent item.
 */
.fi-sidebar-item.modharbor-sidebar-nav > .fi-sidebar-item-btn {
    border-radius: 0.5rem;
    transition:
        background-color 160ms ease,
        box-shadow 160ms ease;
}

.fi-sidebar-item.modharbor-sidebar-nav > .fi-sidebar-item-btn > .fi-sidebar-item-icon {
    color: rgb(34 211 238) !important;
    filter: drop-shadow(0 0 5px rgb(34 211 238 / 0.35));
}

.fi-sidebar-item.modharbor-sidebar-nav > .fi-sidebar-item-btn > .fi-sidebar-item-label {
    color: rgb(103 232 249) !important;
    font-weight: 700;
}

.fi-sidebar-item.modharbor-sidebar-nav > .fi-sidebar-item-btn:hover {
    background-color: rgb(34 211 238 / 0.08) !important;
}

.fi-sidebar-item.modharbor-sidebar-nav > .fi-sidebar-item-btn:hover > .fi-sidebar-item-icon {
    color: rgb(103 232 249) !important;
    filter: drop-shadow(0 0 7px rgb(34 211 238 / 0.50));
}

/* ModHarbor parent collapse behavior */
.fi-sidebar-item.modharbor-sidebar-nav > .fi-sidebar-sub-group-items {
    overflow: hidden;
}

.fi-sidebar-item.modharbor-sidebar-nav.modharbor-collapsed > .fi-sidebar-sub-group-items {
    display: none;
}

/* Native-style collapse chevron */
.fi-sidebar-item.modharbor-sidebar-nav > .fi-sidebar-item-btn::after {
    content: '';
    width: 0.45rem;
    height: 0.45rem;
    margin-left: auto;
    border-right: 2px solid currentColor;
    border-bottom: 2px solid currentColor;
    color: rgb(103 232 249);
    opacity: 0.75;
    transform: rotate(45deg);
    transition: transform 160ms ease;
}

.fi-sidebar-item.modharbor-sidebar-nav.modharbor-collapsed > .fi-sidebar-item-btn::after {
    transform: rotate(-45deg);
}
</style>
HTML
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            static function (): string {
                try {
                    $map = app(\GameNest\GameNestModManager\Services\ServerTenantArtwork::class)->nameMap();
                } catch (\Throwable) {
                    return '';
                }
                if ($map === []) {
                    return '';
                }
                $json = json_encode(
                    $map,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );

                return <<<HTML
<script id="modharbor-tenant-artwork">
(function () {
  const map = {$json};
  const apply = () => {
    document.querySelectorAll('img').forEach((img) => {
      const src = img.getAttribute('src') || '';
      if (!src.includes('ui-avatars.com') && !src.includes('/modharbor/server-avatar/')) {
        return;
      }
      if (img.dataset.mhArt === '1') {
        return;
      }
      let el = img.parentElement;
      for (let i = 0; i < 6 && el; i++) {
        const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
        if (Object.prototype.hasOwnProperty.call(map, text)) {
          img.dataset.mhArt = '1';
          img.src = map[text];
          img.style.objectFit = 'cover';
          return;
        }
        el = el.parentElement;
      }
    });
  };
  apply();
  new MutationObserver(apply).observe(document.documentElement, { childList: true, subtree: true });
})();
</script>
HTML;
            }
        );
    }
}
