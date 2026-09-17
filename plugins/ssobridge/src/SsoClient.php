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

    /**
     * PHP session key holding the redirect target requested for the pending
     * SSO login. It may be an absolute URL (e.g. https://host/ServiceCatalog)
     * even when the portal sends the browser to that URL directly.
     */
    public const REDIRECT_KEY = 'ssobridge_redirect';

    private const HTTP_TIMEOUT = 10;

    /**
     * Start the SSO login: store a correlation id, then redirect the browser
     * to the portal. Never returns on success (redirect is thrown).
     *
     * @param string|null $redirect Optional GLPI URL (local path or same-host
     *                              absolute URL) to come back to after login.
     */
    public static function startLogin(?string $redirect = null): void
    {
        $cid = self::getCorrelationId();

        if ($redirect !== null && $redirect !== '') {
            $_SESSION[self::REDIRECT_KEY] = self::sanitizeRedirect($redirect);
        } else {
            unset($_SESSION[self::REDIRECT_KEY]);
        }

        $callback_uri = self::buildCallbackUri();

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
     * Sanitize the "redirect" target so the browser can come back anywhere on
     * this GLPI instance after the SSO round-trip:
     *
     *   - absolute URLs are accepted when the host matches the current host
     *     (the SSO portal may send the user straight to
     *     https://glpi.example.org/ServiceCatalog) or when they only differ by
     *     scheme (http <-> https, common behind reverse proxies); other hosts
     *     are refused (open redirect protection);
     *   - scheme-relative URLs ("//host/...") and control characters
     *     (header injection attempts) are refused;
     *   - a query string is preserved, a fragment is dropped.
     *
     * @return string A safe redirect target usable after the callback.
     */
    public static function sanitizeRedirect(string $redirect): string
    {
        $redirect = trim($redirect);

        // Refuse control characters / CR-LF (header injection attempts).
        if ($redirect === '' || preg_match('/[\x00-\x1F\x7F]/', $redirect) === 1) {
            return '';
        }

        // Drop the fragment, keep an eventual query string (e.g. /Helpdesk?x=1).
        $fragment_pos = strpos($redirect, '#');
        if ($fragment_pos !== false) {
            $redirect = substr($redirect, 0, $fragment_pos);
        }

        // Scheme-relative URL ("//host/path"): keep only the path part.
        if (str_starts_with($redirect, '//')) {
            $path = parse_url($redirect, PHP_URL_PATH);
            return is_string($path) && $path !== '' ? $path : '';
        }

        // Absolute URL: accept it when it points to this GLPI host.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $redirect) === 1) {
            $parsed = parse_url($redirect);
            $host   = $parsed['host'] ?? '';
            if ($host === '' || $host !== self::currentHost()) {
                return '';
            }
            $path = $parsed['path'] ?? '/';
            if (isset($parsed['query']) && $parsed['query'] !== '') {
                $path .= '?' . $parsed['query'];
            }
            return $path !== '' ? $path : '/';
        }

        // Plain local path (may start with or without a slash).
        return '/' . ltrim($redirect, '/');
    }

    /**
     * Absolute URL pointing to front/callback.php (or the configured
     * SSO_CALLBACK_URI).
     */
    public static function buildCallbackUri(): string
    {
        $configured = Config::callbackUri();
        if ($configured !== '') {
            return $configured;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = self::currentHost();

        // This script lives in .../plugins/ssobridge/front/, callback.php is a sibling.
        $script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

        return $scheme . '://' . $host . $script_dir . '/callback.php';
    }

    /**
     * Process a pending SSO login when the browser landed on any other GLPI
     * page instead of front/callback.php (e.g. because the callback URL
     * configured on the portal points to /ServiceCatalog or /Helpdesk).
     *
     * When the current request is that other page AND a correlation id is
     * waiting in the session, the identity is exchanged, the GLPI session is
     * opened and the user is sent to the requested page. This makes the login
     * work at 100% even when the callback URL is a full GLPI page URL.
     *
     * @return bool True when a pending login was processed (the caller must
     *              stop: a redirect is always thrown on success, an error
     *              page is displayed on failure).
     */
    public static function processPendingLogin(): bool
    {
        $correlationId = (string) ($_SESSION[self::SESSION_KEY] ?? '');
        if ($correlationId === '') {
            return false;
        }

        // The callback script is the normal path and handles its own flow.
        if (self::isCallbackRequest()) {
            return false;
        }

        // Never hijack a POST / AJAX / file request that happens to arrive on
        // the page while a login is pending: let it run normally so nothing is
        // consumed or broken; the GET navigation will complete the login.
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $is_ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if ($method !== 'GET' || $is_ajax) {
            return false;
        }

        unset($_SESSION[self::SESSION_KEY]);
        $redirect = (string) ($_SESSION[self::REDIRECT_KEY] ?? '');
        unset($_SESSION[self::REDIRECT_KEY]);

        $identity = self::retrieveLoginInfo($correlationId);
        if ($identity['error'] !== '') {
            // Put the correlation id back so a simple page refresh retries the
            // validation (covers a race where the portal redirected the browser
            // before registering the validated identity).
            $_SESSION[self::SESSION_KEY] = $correlationId;
            if ($redirect !== '') {
                $_SESSION[self::REDIRECT_KEY] = $redirect;
            }
            Front::renderMessage('SSO validation failed', [$identity['error']], 401);
            return true;
        }

        $errors = GlpiLoginService::login($identity['email'], $identity['username'], $redirect);
        if (count($errors) > 0) {
            Front::renderMessage('SSO login failed', $errors, 403);
        }
        return true;
    }

    /**
     * Is the current request the plugin callback script itself?
     */
    private static function isCallbackRequest(): bool
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if (str_ends_with($script, '/plugins/ssobridge/front/callback.php')) {
            return true;
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        return str_ends_with($path, '/plugins/ssobridge/front/callback.php');
    }

    /**
     * Host of the current request (fallback on the GLPI configured URL).
     */
    private static function currentHost(): string
    {
        global $CFG_GLPI;

        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        if ($host !== '') {
            return strtolower((string) preg_replace('/:\d+$/', '', $host));
        }

        $url_base = parse_url((string) ($CFG_GLPI['url_base'] ?? ''), PHP_URL_HOST);
        return is_string($url_base) ? strtolower($url_base) : '';
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
