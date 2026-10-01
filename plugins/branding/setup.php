<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI Branding plugin
 *
 * Replaces every GLPI logo (top menu, collapsed sidebar, login page,
 * dark theme, browser tab icon) with a custom image, and fixes a few
 * interface details (hiding the useless "Show as map" button).
 *
 * Everything is configured from Setup > Branding (an entry added in
 * the Configuration menu, right below "Plugins"; a link is also
 * available from Setup > Plugins): an administrator uploads the logos
 * (no code change needed) and toggles the interface tweaks. Logos can
 * also be shipped with the plugin by dropping files in `pics/` (or at
 * the plugin root); optional variants are described in README.md and
 * src/Logo.php.
 *
 * ---------------------------------------------------------------------
 */

use Glpi\Http\Firewall;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Branding\Logo;
use GlpiPlugin\Branding\Settings;

/**
 * Plugin metadata.
 */
function plugin_version_branding(): array
{
    return [
        'name'         => 'Branding',
        'version'      => '1.2.0',
        'author'       => 'BlankAngelTV for ETML',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => '10.0.18',
            ],
        ],
    ];
}

/**
 * Nothing to check at install time.
 */
function plugin_branding_check_prerequisites(): bool
{
    return true;
}

/**
 * The plugin needs no table nor configuration in GLPI.
 */
function plugin_branding_install(): bool
{
    return true;
}

/**
 * Nothing to clean up.
 */
function plugin_branding_uninstall(): bool
{
    return true;
}

/**
 * Hook registration (runs on every request when the plugin is active).
 *
 * The logo replacements are delivered as plain CSS (`add_css`) so they
 * apply to authenticated pages *and* to the anonymous login page
 * (`add_css_anonymous_page`), where `--glpi-logo-dark-login` is used.
 *
 * The browser tab icon cannot be changed with CSS, so a tiny script
 * replaces the existing `<link rel="icon">` once the page is loaded
 * (a header tag would be rendered *before* GLPI's own favicon link, so
 * the browser would keep the GLPI one).
 *
 * `css/tweaks.css` is loaded when the matching interface tweak is enabled
 * (such as hiding the useless "Show as map" button), while
 * `css/branding.css` is only registered once a logo is present: the
 * interface then keeps GLPI's default logos instead of showing a broken
 * image.
 *
 * The configuration page is linked from Setup > Plugins and from the
 * Setup menu (see plugin_branding_redefine_menus); it lets an
 * administrator upload the logos (stored outside the plugin code) and
 * toggle the interface tweaks.
 */
function plugin_init_branding(): void
{
    global $PLUGIN_HOOKS;

    // Configuration link shown in Setup > Plugins.
    if (Session::haveRight('config', defined('UPDATE') ? UPDATE : 2)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['branding'] = 'front/config.php';

        // Entry added in the Setup (Configuration) menu, under Plugins.
        $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['branding'] = 'plugin_branding_redefine_menus';
    }

    $PLUGIN_HOOKS[Hooks::ADD_CSS]['branding']                ??= [];
    $PLUGIN_HOOKS[Hooks::ADD_CSS_ANONYMOUS_PAGE]['branding'] ??= [];

    // Interface tweaks (no logo required): hide the "Show as map" button
    // unless the administrator chose to keep it.
    if (!Settings::showMapButton()) {
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['branding'][]                = 'css/tweaks.css';
        $PLUGIN_HOOKS[Hooks::ADD_CSS_ANONYMOUS_PAGE]['branding'][] = 'css/tweaks.css';
    }

    if (!Logo::hasLogo()) {
        return;
    }

    // Override the `--glpi-logo-*` CSS variables on every page.
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['branding'][]                = 'css/branding.css';
    $PLUGIN_HOOKS[Hooks::ADD_CSS_ANONYMOUS_PAGE]['branding'][] = 'css/branding.css';

    // Replace the favicon (of the classic and anonymous pages).
    if (Logo::hasFavicon()) {
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['branding']                = 'js/branding.js';
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_ANONYMOUS_PAGE]['branding'] = 'js/branding.js';
    }
}

/**
 * Add the plugin entry to the Setup (Configuration) menu.
 *
 * GLPI does not offer a hook to append an item to an existing menu: the
 * whole menu definition has to be returned, so it is copied, extended and
 * given back. The entry is appended to the `config` sector, which puts it
 * right below "Plugins" (the last built-in entry of that sector).
 *
 * @param array $menu Menu definition given by GLPI.
 *
 * @return array The extended menu definition.
 */
function plugin_branding_redefine_menus(array $menu): array
{
    if (
        !isset($menu['config']['content'])
        || !is_array($menu['config']['content'])
    ) {
        return $menu;
    }

    $menu['config']['content']['branding'] = [
        'title' => 'Branding',
        'page'  => '/plugins/branding/front/config.php',
        'icon'  => 'ti ti-photo',
    ];

    return $menu;
}

/**
 * Plugin boot (runs before routing, only when the plugin is active).
 *
 * `front/logo.php` is loaded by the login page (anonymous users), so it
 * has to be reachable without any GLPI session. It only ever serves the
 * image bundled with this plugin: a "no check" strategy is safe here.
 */
function plugin_branding_boot(): void
{
    if (!class_exists(Firewall::class)) {
        return;
    }

    Firewall::addPluginStrategyForLegacyScripts(
        'branding',
        '#^/front/logo\.php$#',
        Firewall::STRATEGY_NO_CHECK
    );
}
