<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge plugin
 *
 * Bridges an external SSO portal (e.g. apps.pm2etml.ch/auth) to GLPI.
 * After the portal validates the user, the plugin finds (or creates) the
 * matching GLPI user account and opens a GLPI session for it.
 *
 * Configuration is read from real environment variables first, then from
 * the plugin `.env` file (see .env.example). Nothing is stored in the
 * GLPI database.
 *
 * ---------------------------------------------------------------------
 */

use Glpi\Http\Firewall;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Ssobridge\Config;
use GlpiPlugin\Ssobridge\Logger;
use GlpiPlugin\Ssobridge\SsoClient;

/**
 * Plugin metadata.
 */
function plugin_version_ssobridge(): array
{
    return [
        'name'         => 'SSO Bridge',
        'version'      => '1.0.0',
        'author'       => 'SSO Bridge contributors',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => '10.0.10',
            ],
        ],
    ];
}

/**
 * Nothing to check at install time.
 */
function plugin_ssobridge_check_prerequisites(): bool
{
    return true;
}

/**
 * The plugin needs no table nor configuration in GLPI.
 */
function plugin_ssobridge_install(): bool
{
    return true;
}

/**
 * Nothing to clean up.
 */
function plugin_ssobridge_uninstall(): bool
{
    return true;
}

/**
 * Hook registration (runs on each request when the plugin is active).
 *
 * This function is called by Plugin::load() on every request during the
 * kernel boot (InitializePlugins listener, priority 110), which is exactly
 * the right place to intercept a pending SSO login: the session is already
 * started (SessionStart listener, priority 130 runs before), routing and the
 * access checks have not run yet, and the RedirectException thrown by
 * Html::redirect() is re-thrown as-is by the core, so the redirect reaches
 * the browser before GLPI can answer "session expired".
 */
function plugin_init_ssobridge(): void
{
    global $PLUGIN_HOOKS;

    // Display an "SSO login" button on the GLPI login page.
    $PLUGIN_HOOKS[Hooks::DISPLAY_LOGIN]['ssobridge'] = 'plugin_ssobridge_display_login';

    Logger::debug('SSO Bridge plugin initialised', ['script' => $_SERVER['SCRIPT_NAME'] ?? '']);

    // Take over the request when the browser comes back from the SSO portal,
    // whatever the callback URL configured on the portal is (this plugin
    // callback, /ServiceCatalog, /Helpdesk, the GLPI home page, ...). See
    // SsoClient::processPendingLogin(): it either completes the login, or
    // restarts the SSO round-trip when the PHP session was lost on the way
    // back, so the user never ends on the "session expired" page.
    SsoClient::processPendingLogin();
}

/**
 * Plugin boot (runs before routing, only when the plugin is active).
 *
 * The SSO entry points have to be reachable by anonymous users, because
 * login/callback/logout happen before any GLPI session exists. Register a
 * "no check" firewall strategy for those legacy front scripts.
 */
function plugin_ssobridge_boot(): void
{
    Firewall::addPluginStrategyForLegacyScripts(
        'ssobridge',
        '#^/front/(login|callback|logout)\.php$#',
        Firewall::STRATEGY_NO_CHECK
    );
}

/**
 * Output the "Login with SSO" button on the login page.
 *
 * When SSO_AUTO_REDIRECT is enabled (and the plugin is configured), the
 * login page is not shown at all: anonymous visitors are redirected straight
 * to the SSO portal. Append ?nosso=1 to the GLPI URL to keep the regular
 * login form (and the SSO button) visible.
 */
function plugin_ssobridge_display_login(): void
{
    global $CFG_GLPI;

    $base = isset($CFG_GLPI['root_doc']) && $CFG_GLPI['root_doc'] !== ''
        ? rtrim($CFG_GLPI['root_doc'], '/')
        : '';
    $url  = $base . '/plugins/ssobridge/front/login.php';

    $configured = Config::accessToken() !== '';
    $redirect   = $_GET['redirect'] ?? '';

    // Error left by the plugin init hook (failed validation, lost session,
    // ...). It is displayed instead of bouncing again to the portal, so the
    // user can read what happened (and quote the reference) before retrying.
    $error = SsoClient::consumePendingError();
    if ($error !== null) {
        echo '<div class="alert alert-danger text-start" role="alert">' . "\n";
        echo '    <h4 class="alert-title">' . htmlescape($error['title']) . '</h4>' . "\n";
        echo '    <ul class="mb-2">' . "\n";
        foreach ($error['messages'] as $message) {
            echo '        <li>' . htmlescape($message) . '</li>' . "\n";
        }
        echo '    </ul>' . "\n";
        echo '    <div class="small">' . htmlescape('Référence') . ' : <code>'
            . htmlescape((string) $error['ref']) . '</code><br>' . htmlescape('Journal SSO') . ' : <code>'
            . htmlescape(Logger::location()) . '</code></div>' . "\n";
        echo '</div>' . "\n";
    }

    if (
        $configured
        && $error === null
        && Config::autoRedirect()
        && !Session::getLoginUserID()
        && !isset($_GET['nosso'])
    ) {
        // Pass the originally requested page through, so the user comes back
        // to it after the SSO round-trip (the callback validates it).
        if (is_string($redirect) && $redirect !== '') {
            $url .= '?redirect=' . rawurlencode($redirect);
        }

        $js_url = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        echo '<noscript>' . "\n";
        echo '    <div class="text-center">' . "\n";
        echo '        <a class="btn btn-outline-secondary" href="' . htmlescape($url) . '">' . "\n";
        echo '            &nbsp;Connexion Eduvaud' . "\n";
        echo '        </a>' . "\n";
        echo '    </div>' . "\n";
        echo '</noscript>' . "\n";
        echo '<meta http-equiv="refresh" content="0; url=' . htmlescape($url) . '">' . "\n";
        echo '<script>' . "\n";
        echo '    window.location.replace(' . $js_url . ');' . "\n";
        echo '</script>' . "\n";
        return;
    }

    echo '<div class="w-100">' . "\n";
    echo '    <a class="btn btn-primary btn-lg w-100" href="' . htmlescape($url) . '">' . "\n";
    echo '        <i class="ti ti-user-shield" aria-hidden="true"></i>' . "\n";
    echo '        &nbsp;Connexion Eduvaud' . "\n";
    echo '    </a>' . "\n";
    echo '</div>' . "\n";
}
