<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI Branding plugin - logo asset endpoint.
 *
 * Serves the logo file dropped in the plugin folder. This script is
 * referenced by `css/branding.css` (one URL per logo kind) and by the
 * favicon header tag, so it must stay reachable by anonymous users.
 *
 * URL:    /plugins/branding/front/logo.php?kind=<kind>
 * Params: kind (default `logo`), one of: logo, light, dark, reduced,
 *         login, light-reduced, dark-reduced, light-login, dark-login,
 *         favicon. Unknown kinds fall back to `logo`.
 *
 * ---------------------------------------------------------------------
 */

use GlpiPlugin\Branding\Logo;

$kind = isset($_GET['kind']) && is_string($_GET['kind'])
    ? strtolower(trim($_GET['kind']))
    : 'logo';

if (!Logo::isKnownKind($kind)) {
    $kind = 'logo';
}

$file = Logo::resolve($kind);

// No logo bundled (yet): the CSS is not injected in that case, but the
// endpoint must still answer cleanly instead of leaking a PHP warning.
if ($file === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'No logo file found in the Branding plugin.';
    return;
}

$mtime = (int) @filemtime($file);
$size  = (int) @filesize($file);
$etag  = '"' . dechex($mtime) . '-' . dechex($size) . '"';

header('Content-Type: ' . Logo::mime($file));
header('Cache-Control: public, max-age=86400');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

// Honour the browser cache: answer 304 as long as the logo did not change.
$if_none_match = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
if ($if_none_match === $etag || trim($if_none_match, '"') === trim($etag, '"')) {
    http_response_code(304);
    return;
}

$if_modified_since = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
if ($if_modified_since !== '' && strtotime($if_modified_since) >= $mtime) {
    http_response_code(304);
    return;
}

header('Content-Length: ' . $size);
readfile($file);
