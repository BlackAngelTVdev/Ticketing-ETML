<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge - SSO logout.
 *
 * Destroys the local GLPI session, then invalidates the SSO session on the
 * portal (the portal redirects the browser back to GLPI afterwards).
 *
 * An optional ?redirect=<GLPI target> is forwarded to the portal logout URL
 * when it points to this GLPI instance (local path or same-host absolute URL).
 *
 * ---------------------------------------------------------------------
 */

use GlpiPlugin\Ssobridge\SsoClient;

global $CFG_GLPI;

$base_url = '';

// Prefer the GLPI configured base URL.
if (isset($CFG_GLPI['url_base']) && $CFG_GLPI['url_base'] !== '') {
    $base_url = $CFG_GLPI['url_base'];
} elseif (isset($CFG_GLPI['root_doc'])) {
    // Fall back to scheme://host + root_doc.
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $path   = rtrim($CFG_GLPI['root_doc'], '/');
    $base_url = $scheme . '://' . $host . $path;
}

// The portal needs an absolute redirect target.
if ($base_url === '') {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $base_url = $scheme . '://' . $host;
}

// Destroy the GLPI session (like front/logout.php).
Session::cleanOnLogout();

$sso_logout_url = SsoClient::ssoLogoutUrl($base_url);

// Forward a safe redirect target to the portal logout endpoint.
$redirect = $_GET['redirect'] ?? ($_POST['redirect'] ?? '');
if (is_string($redirect) && $redirect !== '') {
    $safe = SsoClient::sanitizeRedirect($redirect);
    if ($safe !== '') {
        $sep = (strpos($sso_logout_url, '?') === false) ? '?' : '&';
        $sso_logout_url .= $sep . 'redirect=' . rawurlencode($safe);
    }
}

// Invalidate the SSO session too.
Html::redirect($sso_logout_url);
