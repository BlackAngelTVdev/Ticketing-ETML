<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI Branding plugin
 *
 * Replaces every GLPI logo (top menu, collapsed sidebar, login page,
 * dark theme, browser tab icon) with an image dropped directly in the
 * plugin folder. Nothing is stored in the database and there is no
 * configuration screen: the presence of the file is the configuration.
 *
 * Drop `pics/logo.png` (or .svg, .webp, .jpg, .jpeg, .gif) and the
 * plugin takes over. Optional variants are described in README.md and
 * src/Logo.php.
 *
 * ---------------------------------------------------------------------
 */

use Glpi\Http\Firewall;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Branding\Logo;

/**
 * Plugin metadata.
 */
function plugin_version_branding(): array
{
    return [
        'name'         => 'Branding',
        'version'      => '1.0.0',
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
 * When no logo file is bundled yet, nothing at all is registered: the
 * interface simply keeps GLPI's default logos instead of showing a
 * broken image.
 */
function plugin_init_branding(): void
{
    global $PLUGIN_HOOKS;

    if (!Logo::hasLogo()) {
        return;
    }

    // Override the `--glpi-logo-*` CSS variables on every page.
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['branding']                = 'css/branding.css';
    $PLUGIN_HOOKS[Hooks::ADD_CSS_ANONYMOUS_PAGE]['branding'] = 'css/branding.css';

    // Replace the favicon (of the classic and anonymous pages).
    if (Logo::hasFavicon()) {
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['branding']                = 'js/branding.js';
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_ANONYMOUS_PAGE]['branding'] = 'js/branding.js';
    }
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
