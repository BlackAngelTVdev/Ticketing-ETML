<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge - SSO callback.
 *
 * The SSO portal redirects the browser here after the user authenticated.
 * The correlation id stored at login time is exchanged against the validated
 * identity (email + username), then the user is logged into GLPI.
 *
 * ---------------------------------------------------------------------
 */

use GlpiPlugin\Ssobridge\Front;
use GlpiPlugin\Ssobridge\GlpiLoginService;
use GlpiPlugin\Ssobridge\SsoClient;

// Correlation id set by front/login.php before the redirect to the portal.
$correlationId = $_SESSION[SsoClient::SESSION_KEY] ?? '';
unset($_SESSION[SsoClient::SESSION_KEY]);

if ($correlationId === '') {
    Front::renderMessage(
        'SSO login failed',
        ['No pending SSO login was found (the correlation id is missing). Start again from the login page.'],
        400
    );
    return;
}

// Ask the portal to validate the login and give us the user identity.
$identity = SsoClient::retrieveLoginInfo($correlationId);
if ($identity['error'] !== '') {
    Front::renderMessage(
        'SSO validation failed',
        [$identity['error']],
        401
    );
    return;
}

$errors = GlpiLoginService::login($identity['email'], $identity['username']);
if (count($errors) > 0) {
    Front::renderMessage('SSO login failed', $errors, 403);
}
