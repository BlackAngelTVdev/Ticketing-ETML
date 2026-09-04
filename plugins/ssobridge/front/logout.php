<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge - SSO logout.
 *
 * Destroys the local GLPI session, then invalidates the SSO session on the
 * portal (the portal redirects the browser back to GLPI afterwards).
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

// Invalidate the SSO session too.
Html::redirect(SsoClient::ssoLogoutUrl($base_url));
