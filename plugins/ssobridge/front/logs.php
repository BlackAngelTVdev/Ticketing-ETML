<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge - SSO journal viewer.
 *
 * Displays the configuration actually used by the plugin and the last
 * entries of the SSO journal (files/_log/ssobridge.log), so an
 * administrator can debug a login without opening a shell.
 *
 * URL: /plugins/ssobridge/front/logs.php
 * Access: authenticated users with the right to change the setup.
 *
 * ---------------------------------------------------------------------
 */

use GlpiPlugin\Ssobridge\Config;
use GlpiPlugin\Ssobridge\Logger;
use GlpiPlugin\Ssobridge\SsoClient;

global $CFG_GLPI;

// ---------------------------------------------------------------------
// Access control: setup right only.
// ---------------------------------------------------------------------
$config_right = defined('UPDATE') ? UPDATE : 2;
if (!Session::getLoginUserID() || !Session::haveRight('config', $config_right)) {
    if (class_exists(\Glpi\Exception\Http\AccessDeniedHttpException::class)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    Html::displayErrorAndDie('Access denied');
}

$root = isset($CFG_GLPI['root_doc']) ? rtrim($CFG_GLPI['root_doc'], '/') : '';
$here = $root . '/plugins/ssobridge/front/logs.php';

// ---------------------------------------------------------------------
// Optional actions.
// ---------------------------------------------------------------------
$csrf_token = method_exists('Session', 'getNewCSRFToken') ? (string) Session::getNewCSRFToken() : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['clear_journal']) && $csrf_token !== '') {
    Session::checkCSRF($_POST);
    Logger::info('SSO journal cleared', ['by' => Session::getLoginUserID()]);
    Logger::truncate();
    Html::redirect($here . '?cleared=1');
}

$search    = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$min_level = isset($_GET['level']) && is_string($_GET['level']) ? strtolower(trim($_GET['level'])) : '';

$lines = Logger::tail(500);

$weights = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40, 'critical' => 50];
$min     = $weights[$min_level] ?? 0;

$entries = [];
foreach ($lines as $line) {
    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[(\w+)\s*\] \[([^\]]*)\] (.*)$/', $line, $matches) === 1) {
        $entry = [
            'time'    => $matches[1],
            'level'   => strtolower($matches[2]),
            'ref'     => $matches[3],
            'message' => $matches[4],
        ];
    } else {
        $entry = ['time' => '', 'level' => 'info', 'ref' => '', 'message' => $line];
    }

    $weight = $weights[$entry['level']] ?? 20;
    if ($min > 0 && $weight < $min) {
        continue;
    }
    if ($search !== '' && stripos($line, $search) === false) {
        continue;
    }

    $entries[] = $entry;
}

$entries = array_reverse(array_slice($entries, -400)); // most recent first

// ---------------------------------------------------------------------
// Configuration summary.
// ---------------------------------------------------------------------
$configured_callback = Config::callbackUri();
$effective_callback  = SsoClient::buildCallbackUri();

$summary = [
    'SSO portal URL'              => Config::portalUrl(),
    'SSO access token'            => Config::accessToken() !== '' ? 'set' : 'MISSING',
    'SSO_CALLBACK_URI'            => $configured_callback !== '' ? $configured_callback : '(not set)',
    'Callback URL sent to portal' => $effective_callback,
    'SSO_AUTO_CREATE_USERS'       => Config::autoCreateUsers() ? '1' : '0',
    'SSO_DEFAULT_PROFILE'         => Config::defaultProfile() !== '' ? Config::defaultProfile() : '(GLPI default)',
    'SSO_AUTO_REDIRECT'           => Config::autoRedirect() ? '1' : '0',
    'SSO_LOG_LEVEL'               => Config::logLevel(),
    'Journal'                     => Logger::location(),
];

$levels = ['', 'debug', 'info', 'warning', 'error', 'critical'];

echo '<!DOCTYPE html>' . "\n";
echo '<html lang="en"><head><meta charset="utf-8">' . "\n";
echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
echo '<title>SSO Bridge - journal</title>' . "\n";
echo '<style>'
    . 'body{font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f4f4f6;color:#1d1d1f;margin:0;padding:24px}'
    . '.wrap{max-width:1100px;margin:0 auto}'
    . 'h1{font-size:1.35rem;margin:0 0 4px}h2{font-size:1rem;margin:24px 0 8px}'
    . '.card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.15);padding:20px;margin-bottom:16px}'
    . 'table{border-collapse:collapse;width:100%;font-size:.85rem}'
    . 'td,th{padding:6px 8px;border-bottom:1px solid #ececf0;text-align:left;vertical-align:top}'
    . 'th{font-weight:600;color:#5f5f68}'
    . 'code{background:#f4f4f6;border-radius:4px;padding:1px 5px}'
    . '.log{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.78rem}'
    . '.lvl{display:inline-block;border-radius:4px;padding:1px 6px;color:#fff;font-size:.7rem;text-transform:uppercase}'
    . '.debug{background:#8a8a95}.info{background:#1759e6}.warning{background:#d97706}.error{background:#dc2626}.critical{background:#7f1d1d}'
    . 'a{color:#1759e6;text-decoration:none}a:hover{text-decoration:underline}'
    . 'form{display:inline}input[type=text],select{padding:4px 6px;border:1px solid #d4d4d8;border-radius:4px}'
    . 'button{padding:5px 10px;border:1px solid #d4d4d8;border-radius:4px;background:#fff;cursor:pointer}'
    . '.muted{color:#5f5f68;font-size:.85rem}'
    . '</style>' . "\n";
echo '</head><body><div class="wrap">' . "\n";

echo '<h1>SSO Bridge - journal</h1>' . "\n";
echo '<p class="muted">Reference of this page: <code>' . htmlescape(Logger::reference()) . '</code></p>' . "\n";

if (isset($_GET['cleared'])) {
    echo '<p class="muted">The journal has been emptied.</p>' . "\n";
}

echo '<div class="card"><h2 style="margin-top:0">Configuration</h2><table>' . "\n";
foreach ($summary as $label => $value) {
    echo '<tr><th style="width:260px">' . htmlescape($label) . '</th><td><code>'
        . htmlescape((string) $value) . '</code></td></tr>' . "\n";
}
echo '</table></div>' . "\n";

echo '<div class="card">' . "\n";
echo '<h2 style="margin-top:0">Journal (' . count($entries) . ' lines shown)</h2>' . "\n";
echo '<form method="get" action="' . htmlescape($here) . '">' . "\n";
echo '    <input type="text" name="q" placeholder="search..." value="' . htmlescape($search) . '">' . "\n";
echo '    <select name="level">';
foreach ($levels as $level) {
    $label = $level === '' ? 'all levels' : $level;
    $selected = ($level === $min_level) ? ' selected' : '';
    echo '<option value="' . htmlescape($level) . '"' . $selected . '>' . htmlescape($label) . '</option>';
}
echo '</select>' . "\n";
echo '    <button type="submit">Filter</button>' . "\n";
echo '    <a href="' . htmlescape($here) . '">reset</a>' . "\n";
echo '</form>' . "\n";

if ($csrf_token !== '') {
    echo '<form method="post" action="' . htmlescape($here) . '" style="float:right;margin-top:-30px">' . "\n";
    echo '    <input type="hidden" name="_glpi_csrf_token" value="' . htmlescape($csrf_token) . '">' . "\n";
    echo '    <button type="submit" name="clear_journal" value="1">Empty the journal</button>' . "\n";
    echo '</form>' . "\n";
}

echo '<table class="log">' . "\n";
if (count($entries) === 0) {
    echo '<tr><td>No entry yet. Reproduce the problem, then reload this page.</td></tr>' . "\n";
} else {
    foreach ($entries as $entry) {
        echo '<tr>';
        echo '<td style="white-space:nowrap">' . htmlescape($entry['time']) . '</td>';
        echo '<td><span class="lvl ' . htmlescape($entry['level']) . '">' . htmlescape($entry['level']) . '</span></td>';
        echo '<td style="white-space:nowrap"><code>' . htmlescape($entry['ref']) . '</code></td>';
        echo '<td>' . htmlescape($entry['message']) . '</td>';
        echo '</tr>' . "\n";
    }
}
echo '</table>' . "\n";
echo '</div>' . "\n";

echo '<p class="muted"><a href="' . htmlescape($here) . '">Refresh</a> &middot; '
    . '<a href="' . htmlescape($root . '/index.php?nosso=1') . '">GLPI login page</a> &middot; '
    . '<a href="' . htmlescape($root . '/plugins/ssobridge/front/login.php') . '">Start an SSO login</a></p>' . "\n";

echo '</div></body></html>' . "\n";
