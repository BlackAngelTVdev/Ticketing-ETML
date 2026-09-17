<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge - SSO login entry point.
 *
 * Generates a correlation id, stores it in the PHP session and redirects
 * the browser to the SSO portal. The portal sends the user back to the
 * callback page afterwards.
 *
 * Optional query parameter: ?redirect=<GLPI target>. Both local paths
 * (/ServiceCatalog, /front/central.php, /Helpdesk?x=1) and absolute URLs on
 * the same host are accepted: SsoClient::sanitizeRedirect() only refuses
 * external hosts (open redirect protection) and control characters. Even a
 * full page URL such as https://glpi.example.org/ServiceCatalog works: the
 * plugin completes the login on that page itself (Boot::onRequest()).
 *
 * ---------------------------------------------------------------------
 */

use GlpiPlugin\Ssobridge\Config;
use GlpiPlugin\Ssobridge\Front;
use GlpiPlugin\Ssobridge\SsoClient;

// Already authenticated? Nothing to do, send the user to the requested page.
if (Session::getLoginUserID()) {
    $redirect = $_GET['redirect'] ?? '';
    if (is_string($redirect) && $redirect !== '') {
        $safe = SsoClient::sanitizeRedirect($redirect);
        if ($safe !== '') {
            Toolbox::manageRedirect($safe);
        }
    }
    Auth::redirectIfAuthenticated();
}

// The plugin has to be configured before it can start an SSO login.
if (Config::accessToken() === '') {
    Front::renderMessage(
        'SSO Bridge is not configured',
        ['Set the SSO access token in the plugin configuration (SSO_ACCESS_TOKEN in the .env file or environment).'],
        500
    );
    return;
}

$redirect = null;
if (isset($_GET['redirect']) && is_string($_GET['redirect']) && $_GET['redirect'] !== '') {
    $redirect = $_GET['redirect'];
}

SsoClient::startLogin($redirect);
