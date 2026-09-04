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
 * Optional query parameter: ?redirect=/front/central.php (validated by GLPI
 * before the final redirect, so open redirection is not possible).
 *
 * ---------------------------------------------------------------------
 */

use GlpiPlugin\Ssobridge\Config;
use GlpiPlugin\Ssobridge\Front;
use GlpiPlugin\Ssobridge\SsoClient;

// Already authenticated? Nothing to do, send the user home.
if (Session::getLoginUserID()) {
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
