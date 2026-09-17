<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge plugin - SSO portal client
 *
 * Talks to the SSO portal ("bridge" API) using the access token stored in
 * the configuration:
 *
 *   GET {portal}bridge/cid?token=...            -> new correlation id
 *   GET {portal}bridge/check?token=...&correlationId=...
 *                                               -> validated identity (email/username)
 *   GET {portal}bridge/logout?redirectUri=...   -> invalidate the portal SSO session
 *
 * The correlation id is kept in the PHP session between the login redirect
 * and the callback, exactly like the original standalone "sso bridge".
 *
 * ---------------------------------------------------------------------
 */

namespace GlpiPlugin\Ssobridge;

use Html;
use RuntimeException;

final class SsoClient
{
    /**
     * PHP session key holding the correlation id.
     */
    public const SESSION_KEY = 'ssobridge_correlation_id';

    private const HTTP_TIMEOUT = 10;

    /**
     * Start the SSO login: store a correlation id, then redirect the browser
     * to the portal. Never returns on success (redirect is thrown).
     *
     * @param string|null $redirect Optional GLPI relative URL to come back to after login.
     *                              Only local paths are accepted: absolute URLs
     *                              (http://host/...) would bypass the callback
     *                              entirely and cause a "session expired" loop.
     */
    public static function startLogin(?string $redirect = null): void
    {
        $cid = self::getCorrelationId();

        $callback_uri = self::buildCallbackUri();
        if ($redirect !== null && $redirect !== '') {
            $sep = (strpos($callback_uri, '?') === false) ? '?' : '&';
            $callback_uri .= $sep . 'redirect=' . rawurlencode(self::sanitizeRedirect($redirect));
        }

        $sso_url = Config::portalUrl() . 'redirect'
            . '?correlationId=' . rawurlencode($cid)
            . '&redirectUri=' . rawurlencode($callback_uri);

        Html::redirect($sso_url);
    }

    /**
     * Retrieve the validated identity from the SSO portal.
     *
     * @return array{email: string, username: string, error: string}
     */
    public static function retrieveLoginInfo(string $correlationId): array
    {
        $token = Config::accessToken();
        $url   = Config::portalUrl() . 'bridge/check'
            . '?token=' . rawurlencode($token)
            . '&correlationId=' . rawurlencode($correlationId);

        $body = self::httpGet($url);
        if ($body === null) {
            return [
                'email'    => '',
                'username' => '',
                'error'    => sprintf('Cannot reach the SSO portal at "%s".', Config::portalUrl()),
            ];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [
                'email'    => '',
                'username' => '',
                'error'    => 'Invalid response received from the SSO portal.',
            ];
        }

        if (isset($data['error'])) {
            return [
                'email'    => '',
                'username' => '',
                'error'    => (string) $data['error'],
            ];
        }

        return [
            'email'    => (string) ($data['email'] ?? ''),
            'username' => (string) ($data['username'] ?? ''),
            'error'    => '',
        ];
    }

    /**
     * URL used to invalidate the SSO session on the portal.
     */
    public static function ssoLogoutUrl(string $redirectUri): string
    {
        return Config::portalUrl() . 'bridge/logout?redirectUri=' . rawurlencode($redirectUri);
    }

    /**
     * Sanitize the "redirect" parameter so the browser always comes back to
     * the plugin callback first (the only place where the GLPI session can be
     * opened). Only local relative paths are allowed:
     *
     *   - absolute URLs (http://ip/Helpdesk), scheme-relative (//host/...)
     *     and URLs with a host are refused -> fall back to /front/central.php;
     *   - a query string is preserved inside the final redirect (forwarded
     *     through /front/central.php?redirect=...), a fragment is dropped;
     *   - control characters / CR-LF (header injection attempts) are refused.
     *
     * @return string A safe relative redirect usable after the callback.
     */
    private static function sanitizeRedirect(string $redirect): string
    {
        $redirect = trim($redirect);

        // Refuse anything that is not a plain local path (no scheme, no host,
        // no control characters).
        $unsafe = $redirect === ''
            || preg_match('/[\x00-\x1F\x7F]/', $redirect) === 1
            || preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $redirect) === 1
            || str_contains($redirect, '//');

        if ($unsafe) {
            return '/front/central.php';
        }

        // Drop the fragment, keep an eventual query string (e.g. /Helpdesk?x=1).
        $fragment_pos = strpos($redirect, '#');
        if ($fragment_pos !== false) {
            $redirect = substr($redirect, 0, $fragment_pos);
        }
        $redirect = '/' . ltrim($redirect, '/');

        // Route through GLPI's post-login controller: after Session::init(),
        // /front/central.php forwards to the requested page once authenticated.
        return '/front/central.php?redirect=' . rawurlencode($redirect);
    }

    /**
     * Build (and optionally store) a correlation id.
     * Prefers the id generated by the portal, falls back to a local random id.
     */
    private static function getCorrelationId(bool $storeInSession = true): string
    {
        $correlationId = '';

        $url = Config::portalUrl() . 'bridge/cid?token=' . rawurlencode(Config::accessToken());
        $body = self::httpGet($url);
        if ($body !== null) {
            $data = json_decode($body, true);
            if (is_array($data) && isset($data['correlationId']) && $data['correlationId'] !== '') {
                $correlationId = (string) $data['correlationId'];
            }
        }

        if ($correlationId === '') {
            try {
                $correlationId = bin2hex(random_bytes(32));
            } catch (\Exception $e) {
                throw new RuntimeException('Cannot generate a valid correlation id.');
            }
        }

        if ($storeInSession && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $correlationId;
        }

        return $correlationId;
    }

    /**
     * Callback URI used in the SSO redirect.
     * Either the configured SSO_CALLBACK_URI or a value built from the request.
     */
    private static function buildCallbackUri(): string
    {
        $configured = Config::callbackUri();
        if ($configured !== '') {
            return $configured;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        // This script lives in .../plugins/ssobridge/front/, callback.php is a sibling.
        $script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

        return $scheme . '://' . $host . $script_dir . '/callback.php';
    }

    /**
     * Lightweight HTTP GET helper (curl first, file_get_contents fallback).
     */
    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                    CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                ]);
                $body  = curl_exec($ch);
                $error = curl_error($ch);
                curl_close($ch);
                return ($body === false) ? null : (string) $body;
            }
        }

        $context = stream_context_create(['http' => ['timeout' => self::HTTP_TIMEOUT]]);
        $body = @file_get_contents($url, false, $context);
        return ($body === false) ? null : $body;
    }
}
