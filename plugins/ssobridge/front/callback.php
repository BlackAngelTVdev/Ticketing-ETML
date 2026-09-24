<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge - SSO callback (fallback).
 *
 * The SSO portal usually redirects the browser to this script after the user
 * authenticated. The login itself is handled before this file runs, by
 * SsoClient::processPendingLogin() (plugin init hook), which also works when
 * the portal is configured with another callback URL (/ServiceCatalog,
 * /Helpdesk, ...) or when the PHP session was lost on the way back.
 *
 * Reaching this script therefore means that no SSO login was pending, either
 * because it was already completed (the user is logged in) or because the
 * browser arrived here from somewhere else.
 *
 * ---------------------------------------------------------------------
 */

use GlpiPlugin\Ssobridge\Front;
use GlpiPlugin\Ssobridge\Logger;

if (Session::getLoginUserID()) {
    Logger::debug('SSO callback reached while already authenticated');
    Auth::redirectIfAuthenticated();
}

Logger::warning('SSO callback reached without any pending login', [
    'referer'     => $_SERVER['HTTP_REFERER'] ?? '',
    'has_session' => session_id() !== '' ? 1 : 0,
]);

Front::renderMessage(
    'No pending SSO login',
    [
        'This page is the callback used by the SSO portal. No login was waiting for it, so there is nothing to complete.',
        'Start a new login with the SSO button below, or simply open GLPI.',
    ],
    400
);
