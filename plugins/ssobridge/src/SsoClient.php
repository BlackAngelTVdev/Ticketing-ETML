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
 * and the callback, and mirrored in a short lived cookie. Thanks to that
 * cookie, a login started through the portal can be recognised (and
 * transparently resumed) even when the PHP session was lost on the way back,
 * which is what used to end on GLPI's "Your session has expired" page.
 *
 * Whatever the callback URL configured on the portal is (the plugin callback
 * `front/callback.php`, `/ServiceCatalog`, `/Helpdesk`, the GLPI home page,
 * a same host URL, ...), the plugin completes the login on the page the
 * browser landed on and then sends the user back to that very page - see
 * processPendingLogin().
 *
 * ---------------------------------------------------------------------
 */

namespace GlpiPlugin\Ssobridge;

use Html;
use RuntimeException;
use Session;

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

    /**
     * PHP session key holding the timestamp of the pending SSO login.
     */
    public const STARTED_KEY = 'ssobridge_started_at';

    /**
     * PHP session key holding the last SSO error, displayed by the login page.
     */
    public const ERROR_KEY = 'ssobridge_error';

    /**
     * Cookie telling that an SSO round-trip is in flight. Its value is the
     * number of attempts made for the current round-trip, which keeps the
     * automatic re-launch bounded.
     */
    public const COOKIE_KEY = 'ssobridge_flow';

    /**
     * A pending login older than this is considered abandoned.
     */
    private const FLOW_TTL = 1800; // 30 minutes

    /**
     * How many times a lost SSO round-trip may be re-launched before the
     * plugin gives up and explains the problem on the login page.
     */
    private const MAX_RESTARTS = 2;

    /**
     * Safety net against restart loops when even the cookie is lost
     * (cookies fully blocked): at most N restarts per client per window.
     */
    private const RESTART_WINDOW     = 120; // seconds
    private const RESTART_PER_WINDOW = 5;

    private const HTTP_TIMEOUT = 10;

    /**
     * Query parameters that may carry the portal correlation id when the
     * portal appends it to the callback URL.
     */
    private const CID_PARAMS = ['correlationId', 'correlation_id', 'cid', 'ssobridge_cid'];

    /**
     * Start the SSO login: store a correlation id, then redirect the browser
     * to the portal. Never returns on success (redirect is thrown).
     *
     * @param string|null $redirect Optional GLPI URL (local path or same-host
     *                              absolute URL) to come back to after login.
     * @param int         $attempt  Attempt number for this round-trip
     *                              (internal, used by the recovery logic).
     */
    public static function startLogin(?string $redirect = null, int $attempt = 1): void
    {
        $cid = self::getCorrelationId();

        $_SESSION[self::SESSION_KEY] = $cid;
        $_SESSION[self::STARTED_KEY] = time();

        $safe_redirect = ($redirect === null || $redirect === '') ? '' : self::sanitizeRedirect($redirect);
        if ($safe_redirect !== '') {
            $_SESSION[self::REDIRECT_KEY] = $safe_redirect;
        } else {
            unset($_SESSION[self::REDIRECT_KEY]);
        }

        self::rememberFlow($attempt);

        $callback_uri = self::buildCallbackUri();

        Logger::info('SSO login started', [
            'portal'       => Config::portalUrl(),
            'callback_uri' => $callback_uri,
            'redirect'     => $safe_redirect,
            'correlation'  => $cid,
            'attempt'      => $attempt,
        ]);

        $sso_url = Config::portalUrl() . 'redirect'
            . '?correlationId=' . rawurlencode($cid)
            . '&redirectUri=' . rawurlencode($callback_uri);

        Html::redirect($sso_url);
    }

    /**
     * Complete a login started by the SSO portal, whatever page the browser
     * landed on.
     *
     * Called from the plugin init hook, hence before GLPI checks the session
     * and before it can answer "Your session has expired". Three cases:
     *
     *  1. a correlation id is waiting -> the identity is exchanged and the
     *     GLPI session is opened, then the browser is sent back to the page
     *     requested (the callback URL itself when nothing else was asked);
     *  2. the flow is detectable (cookie / portal referer) but the PHP
     *     session lost the correlation id -> the SSO round-trip is started
     *     again silently, instead of showing an error to the user;
     *  3. nothing SSO related -> returns false and GLPI handles the request
     *     normally.
     *
     * @return bool True when the SSO flow took over the request (a redirect
     *              is always thrown in that case).
     */
    public static function processPendingLogin(): bool
    {
        if (!self::canIntercept()) {
            return false;
        }

        $cid       = (string) ($_SESSION[self::SESSION_KEY] ?? '');
        $startedAt = (int) ($_SESSION[self::STARTED_KEY] ?? 0);

        if ($cid !== '' && $startedAt > 0 && (time() - $startedAt) > self::FLOW_TTL) {
            Logger::warning('Pending SSO login dropped', ['age' => time() - $startedAt, 'correlation' => $cid]);
            self::clearPending();
            $cid = '';
        }

        if (Session::getLoginUserID()) {
            // The login was already completed (by this very request earlier,
            // by a prefetch, by another tab, ...): only clean up.
            if ($cid !== '') {
                self::clearPending();
            }
            if (self::flowAttempts() > 0) {
                self::forgetFlow();
            }
            return false;
        }

        if ($cid === '') {
            $fromUrl = self::correlationIdFromRequest();
            if ($fromUrl !== '') {
                Logger::info('Correlation id picked up from the callback URL', ['correlation' => $fromUrl]);
                $cid = $fromUrl;
            }
        }

        if ($cid === '') {
            return self::recoverPendingLogin();
        }

        // GLPI already owns the flow (second factor authentication in progress).
        if (isset($_SESSION['mfa_pre_auth'])) {
            return false;
        }

        return self::completeLogin($cid);
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

        Logger::debug('Validating the SSO login against the portal', [
            'url'         => $url,
            'correlation' => $correlationId,
        ]);

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
            Logger::error('Unexpected answer from the SSO portal', ['body' => self::excerpt($body)]);
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
     * Absolute URL pointing to front/callback.php (or the configured
     * SSO_CALLBACK_URI).
     *
     * SSO_CALLBACK_URI is accepted in any reasonable form:
     *   https://domaine.ex/ServiceCatalog
     *   domaine.ex/ServiceCatalog          (scheme guessed)
     *   /ServiceCatalog                    (host and scheme guessed)
     *   "https://domaine.ex/plugins/ssobridge/front/callback.php  " (quotes / spaces)
     */
    public static function buildCallbackUri(): string
    {
        $configured = Config::callbackUri();
        if ($configured !== '') {
            $callback = self::normalizeCallbackUri($configured);
            Logger::debug('Using the configured callback URL', ['callback_uri' => $callback]);
            return $callback;
        }

        // Default: this plugin callback. It is located in the front directory
        // of the plugin, whichever script triggered the login.
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#^(.*)/(plugins|marketplace)/ssobridge/front/[^/]+$#i', $script, $matches) === 1) {
            $dir = $matches[1] . '/plugins/ssobridge/front';
        } else {
            $dir = self::rootDoc() . '/plugins/ssobridge/front';
        }

        $callback = self::baseUrl() . $dir . '/callback.php';
        Logger::debug('Callback URL built from the current request', ['callback_uri' => $callback, 'script' => $script]);

        return $callback;
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
     *   - host only URLs without scheme ("domaine.ex/ServiceCatalog") are
     *     accepted when the host matches the current one;
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
            $host   = strtolower((string) ($parsed['host'] ?? ''));
            if ($host === '' || $host !== self::currentHost()) {
                return '';
            }
            $path = $parsed['path'] ?? '/';
            if (isset($parsed['query']) && $parsed['query'] !== '') {
                $path .= '?' . $parsed['query'];
            }
            return $path !== '' ? $path : '/';
        }

        // Host only URL without scheme ("domaine.ex/ServiceCatalog").
        if (preg_match('#^([a-z0-9.-]+)/?(.*)$#i', $redirect, $matches) === 1
            && str_contains($matches[1], '.')
            && strtolower($matches[1]) === self::currentHost()
        ) {
            return '/' . ltrim($matches[2], '/');
        }

        // Plain local path (may start with or without a slash).
        return '/' . ltrim($redirect, '/');
    }

    /**
     * Redirect the browser to a GLPI target, resolving it against the GLPI
     * base path. Unlike Toolbox::manageRedirect(), a path that already starts
     * with the GLPI base path is not prefixed twice.
     */
    public static function redirectTo(string $target): void
    {
        $target = trim($target);
        $target = str_replace(["\r", "\n"], '', $target);

        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $target) === 1) {
            Html::redirect($target);
        }

        if ($target === '') {
            $target = '/index.php';
        }

        $path = '/' . ltrim($target, '/');
        $root = self::rootDoc();
        if ($root !== '' && $path !== $root && !str_starts_with($path, $root . '/')) {
            $path = $root . $path;
        }

        Logger::debug('Redirecting the browser', ['target' => $path]);
        Html::redirect($path);
    }

    /**
     * Store an SSO error, then send the user to the login page where it is
     * displayed (used when the request cannot be answered with a page of its
     * own, i.e. from the plugin init hook).
     *
     * @param string[] $messages
     */
    public static function fail(string $title, array $messages, string $redirect = ''): void
    {
        $_SESSION[self::ERROR_KEY] = [
            'title'    => $title,
            'messages' => array_values(array_map('strval', $messages)),
            'ref'      => Logger::reference(),
            'time'     => time(),
        ];

        self::forgetFlow();
        self::clearGlpiMessages();

        $params = ['nosso' => '1']; // never bounce again on the login page
        if ($redirect !== '' && !self::isPluginScript($redirect)) {
            $params['redirect'] = $redirect;
        }

        Html::redirect(self::rootDoc() . '/index.php?' . http_build_query($params));
    }

    /**
     * Last SSO error waiting to be displayed (consumed once).
     *
     * @return array{title: string, messages: string[], ref: string}|null
     */
    public static function consumePendingError(): ?array
    {
        $error = $_SESSION[self::ERROR_KEY] ?? null;
        unset($_SESSION[self::ERROR_KEY]);

        if (!is_array($error) || !isset($error['title'])) {
            return null;
        }

        return [
            'title'    => (string) $error['title'],
            'messages' => array_map('strval', (array) ($error['messages'] ?? [])),
            'ref'      => (string) ($error['ref'] ?? ''),
        ];
    }

    /**
     * Forget everything about the SSO flow in flight (pending login and the
     * cookie that keeps track of it).
     */
    public static function resetFlow(): void
    {
        self::clearPending();
        self::forgetFlow();
    }

    /**
     * Forget everything about a pending login.
     */
    public static function clearPending(): void
    {
        unset(
            $_SESSION[self::SESSION_KEY],
            $_SESSION[self::STARTED_KEY],
            $_SESSION[self::REDIRECT_KEY]
        );
    }

    /**
     * Complete the login with a validated correlation id.
     */
    private static function completeLogin(string $correlationId): bool
    {
        // The page the portal sent the browser to (its callback URL) is the
        // natural landing page once the session is opened: come back to it.
        $redirect = (string) ($_SESSION[self::REDIRECT_KEY] ?? '');
        if ($redirect === '' || self::isPluginScript($redirect)) {
            $redirect = self::currentPage();
        }

        Logger::info('Handling the SSO callback from a GLPI page', [
            'correlation' => $correlationId,
            'redirect'    => $redirect,
        ]);

        // Consume the pending state *before* opening the GLPI session, so the
        // internal redirects of GLPI (MFA, profile selection, ...) cannot
        // re-enter this handler.
        self::clearPending();
        self::clearGlpiMessages();

        $identity = self::retrieveLoginInfo($correlationId);
        if ($identity['error'] !== '') {
            Logger::error('SSO validation failed', [
                'correlation' => $correlationId,
                'error'       => $identity['error'],
            ]);
            self::fail('SSO validation failed', [$identity['error']], $redirect);
            return true;
        }

        Logger::info('SSO identity validated', [
            'username' => $identity['username'],
            'email'    => self::maskEmail($identity['email']),
            'correlation' => $correlationId,
        ]);

        $errors = GlpiLoginService::login(
            $identity['email'],
            $identity['username'],
            $redirect !== '' ? $redirect : null
        );

        if (count($errors) > 0) {
            Logger::error('GLPI session could not be opened', [
                'username' => $identity['username'],
                'errors'   => implode(' / ', $errors),
            ]);
            self::fail('SSO login failed', $errors, $redirect);
        }

        return true;
    }

    /**
     * No correlation id is available while an SSO round-trip is supposed to
     * be in flight (typically: the PHP session was lost between the portal
     * and GLPI). Rather than letting GLPI display "Your session has
     * expired", the round-trip is started again.
     */
    private static function recoverPendingLogin(): bool
    {
        if (!self::looksLikeSsoLanding() || isset($_SESSION['mfa_pre_auth'])) {
            return false;
        }

        $landing  = self::currentPage();
        $attempts = max(0, self::flowAttempts());
        $redirect = (string) ($_SESSION[self::REDIRECT_KEY] ?? '');
        if ($redirect === '' || self::isPluginScript($redirect)) {
            $redirect = $landing;
        }

        Logger::warning('SSO landing without any correlation id', [
            'attempts'   => $attempts,
            'landing'    => $landing,
            'referer'    => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            'has_cookie' => self::flowAttempts() > 0,
        ]);

        if ($attempts >= self::MAX_RESTARTS || self::restartBudgetExceeded()) {
            Logger::error('SSO round-trip cannot be resumed: giving up', ['attempts' => $attempts]);
            self::clearPending();
            self::fail(
                'SSO login could not be completed',
                [
                    'The SSO portal sent you back to GLPI, but the plugin could not find the login attempt it started.',
                    'This generally means the browser dropped the GLPI session between the portal and GLPI '
                    . '(cookies blocked, session garbage collected, or the callback URL pointing to another host than GLPI).',
                    'Use "Connexion Eduvaud" below to start a new SSO login.',
                ],
                $redirect
            );
            return true;
        }

        Logger::info('Restarting the SSO round-trip instead of showing "session expired"', [
            'attempt' => $attempts + 1,
            'landing' => $landing,
        ]);

        self::startLogin($redirect !== '' ? $redirect : $landing, $attempts + 1);

        return true;
    }

    /**
     * Could the current request be the landing page of an SSO round-trip?
     */
    private static function looksLikeSsoLanding(): bool
    {
        if (self::flowAttempts() > 0 || self::correlationIdFromRequest() !== '') {
            return true;
        }

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            $host = strtolower((string) (parse_url($referer, PHP_URL_HOST) ?: ''));
            if ($host !== '' && $host === self::portalHost()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the current request a plain HTML navigation the plugin may take over?
     */
    private static function canIntercept(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        // The plugin cannot do anything useful without its access token, and
        // must not interfere with GLPI in that case.
        if (Config::accessToken() === '') {
            return false;
        }

        // Escape hatch: ?nosso=1 always gives the request back to GLPI.
        if (isset($_GET['nosso'])) {
            return false;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            if ($method === 'POST' && (self::flowAttempts() > 0 || self::correlationIdFromRequest() !== '')) {
                Logger::warning('SSO flow pending but the incoming request is not a GET navigation', ['method' => $method]);
            }
            return false;
        }

        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return false;
        }

        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        if ($accept !== '' && !str_contains($accept, 'text/html') && !str_contains($accept, '*/*')) {
            return false;
        }

        $dest = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
        if ($dest !== '' && !in_array($dest, ['document', 'iframe', 'frame'], true)) {
            return false;
        }

        $path = self::requestPath();

        if (preg_match('#(^|/)(ajax|api|apirest|css|js|pics|lib|install)(/|$)#i', $path) === 1) {
            return false;
        }
        if (preg_match('#\.(css|js|mjs|json|xml|png|jpe?g|gif|svg|ico|webp|woff2?|ttf|eot|map|pdf|zip)$#i', $path) === 1) {
            return false;
        }
        if (preg_match('#/(cron\.php|logout\.php)$#i', $path) === 1) {
            return false;
        }
        if (preg_match('#/mfa/#i', $path) === 1) {
            return false;
        }

        // The logout entry point always belongs to GLPI: it must be able to
        // close the session even while a login is pending.
        if (self::isPluginScript($path, 'logout')) {
            return false;
        }

        return true;
    }

    /**
     * Value of a possible ?correlationId=... added by the portal to the
     * callback URL.
     */
    public static function correlationIdFromRequest(): string
    {
        foreach (self::CID_PARAMS as $param) {
            if (isset($_GET[$param]) && is_string($_GET[$param]) && trim($_GET[$param]) !== '') {
                return trim($_GET[$param]);
            }
        }

        return '';
    }

    /**
     * Number of attempts of the SSO round-trip in flight, -1 when no flow
     * cookie is present.
     */
    private static function flowAttempts(): int
    {
        $raw = $_COOKIE[self::COOKIE_KEY] ?? '';
        if (!is_string($raw) || $raw === '' || !preg_match('/^\d+$/', $raw)) {
            return -1;
        }

        return (int) $raw;
    }

    private static function rememberFlow(int $attempts): void
    {
        self::setFlowCookie((string) max(1, $attempts), time() + self::FLOW_TTL);
    }

    private static function forgetFlow(): void
    {
        self::setFlowCookie('', time() - 3600);
    }

    private static function setFlowCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        $options = [
            'expires'  => $expires,
            'path'     => self::rootDoc() !== '' ? self::rootDoc() . '/' : '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        @setcookie(self::COOKIE_KEY, $value, $options);
        $_COOKIE[self::COOKIE_KEY] = $value;
    }

    /**
     * Forget the messages GLPI queued ("Your session has expired. Please log
     * in again.", ...) when the plugin takes over the request, so the user
     * never sees a confusing error about a session he never had.
     */
    private static function clearGlpiMessages(): void
    {
        unset(
            $_SESSION['MESSAGE_AFTER_REDIRECT'],
            $_SESSION['glpi_message_after_redirect'],
            $_SESSION['glpi_messages_after_redirect']
        );
    }

    /**
     * Is the given path one of the plugin front scripts?
     */
    private static function isPluginScript(string $path, ?string $name = null): bool
    {
        $path = (string) (parse_url($path, PHP_URL_PATH) ?: $path);

        if ($name === null) {
            return preg_match('#/(?:plugins|marketplace)/ssobridge/front/#i', $path) === 1;
        }

        return preg_match(
            '#/(?:plugins|marketplace)/ssobridge/front/' . preg_quote($name, '#') . '\.php$#i',
            $path
        ) === 1;
    }

    /**
     * Path of the current request.
     */
    private static function requestPath(): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        if ($path === '') {
            $path = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        }

        return $path;
    }

    /**
     * Safe URL of the current page, used to come back to the callback URL
     * once the session is opened.
     */
    private static function currentPage(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return '';
        }

        $safe = self::sanitizeRedirect($uri);
        if ($safe === '' || self::isPluginScript($safe)) {
            return '';
        }

        return $safe;
    }

    /**
     * Host of the current request (fallback on the GLPI configured URL).
     */
    private static function currentHost(): string
    {
        $host = self::requestHost();

        return strtolower((string) preg_replace('/:\d+$/', '', $host));
    }

    /**
     * Host of the current request, port included.
     */
    private static function requestHost(): string
    {
        global $CFG_GLPI;

        $candidates = [];

        $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        if ($forwarded !== '') {
            $candidates[] = trim(explode(',', $forwarded)[0]);
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            $candidates[] = (string) $_SERVER['HTTP_HOST'];
        }
        if (!empty($_SERVER['SERVER_NAME'])) {
            $candidates[] = (string) $_SERVER['SERVER_NAME'];
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $configured = parse_url((string) ($CFG_GLPI['url_base'] ?? ''), PHP_URL_HOST);
        return is_string($configured) ? $configured : '';
    }

    /**
     * Public base URL of this GLPI instance: the configured url_base when it
     * is set, otherwise the current request.
     */
    private static function baseUrl(): string
    {
        global $CFG_GLPI;

        $configured = trim((string) ($CFG_GLPI['url_base'] ?? ''));
        if ($configured !== '' && preg_match('#^https?://#i', $configured) === 1
            && filter_var($configured, FILTER_VALIDATE_URL) !== false
        ) {
            return rtrim($configured, '/');
        }

        $scheme = self::isHttps() ? 'https' : 'http';
        $host   = self::requestHost();

        return $host === '' ? '' : $scheme . '://' . $host;
    }

    private static function isHttps(): bool
    {
        global $CFG_GLPI;

        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }

        return str_starts_with(strtolower((string) ($CFG_GLPI['url_base'] ?? '')), 'https://');
    }

    private static function rootDoc(): string
    {
        global $CFG_GLPI;

        if (isset($CFG_GLPI['root_doc']) && $CFG_GLPI['root_doc'] !== '') {
            return rtrim((string) $CFG_GLPI['root_doc'], '/');
        }

        return '';
    }

    /**
     * Accept the callback URL in any reasonable form.
     */
    private static function normalizeCallbackUri(string $uri): string
    {
        $uri = trim($uri);
        $uri = trim($uri, "\"'");
        $uri = trim($uri);

        if ($uri === '') {
            return '';
        }

        // Scheme relative ("//host/path").
        if (str_starts_with($uri, '//')) {
            return (self::isHttps() ? 'https:' : 'http:') . $uri;
        }

        // A full URL is kept as configured (the portal whitelist may compare
        // it exactly).
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $uri) === 1) {
            return $uri;
        }

        // Local path ("/ServiceCatalog") or "host/path": prefix it with the
        // public base URL of this GLPI.
        return self::baseUrl() . '/' . ltrim($uri, '/');
    }

    private static function portalHost(): string
    {
        return strtolower((string) (parse_url(Config::portalUrl(), PHP_URL_HOST) ?: ''));
    }

    private static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return $email === '' ? '' : Logger::shorten($email, 3);
        }

        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    private static function excerpt(string $body, int $length = 200): string
    {
        $body = trim((string) preg_replace('/\s+/', ' ', $body));

        return strlen($body) > $length ? substr($body, 0, $length) . '…' : $body;
    }

    /**
     * Build (and optionally store) a correlation id.
     * Prefers the id generated by the portal, falls back to a local random id.
     */
    private static function getCorrelationId(): string
    {
        $correlationId = '';

        $url  = Config::portalUrl() . 'bridge/cid?token=' . rawurlencode(Config::accessToken());
        $body = self::httpGet($url);
        if ($body !== null) {
            $data = json_decode($body, true);
            if (is_array($data) && isset($data['correlationId']) && $data['correlationId'] !== '') {
                $correlationId = (string) $data['correlationId'];
            } else {
                Logger::warning('The SSO portal did not return any correlation id', ['body' => self::excerpt($body)]);
            }
        } else {
            Logger::warning('Cannot get a correlation id from the SSO portal, using a local one');
        }

        if ($correlationId === '') {
            try {
                $correlationId = bin2hex(random_bytes(32));
            } catch (\Exception $e) {
                throw new RuntimeException('Cannot generate a valid correlation id.');
            }
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
                if ($body === false) {
                    Logger::error('HTTP request to the SSO portal failed', ['url' => $url, 'curl_error' => $error]);
                    return null;
                }
                return (string) $body;
            }
        }

        $context = stream_context_create(['http' => ['timeout' => self::HTTP_TIMEOUT]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            Logger::error('HTTP request to the SSO portal failed', ['url' => $url, 'transport' => 'file_get_contents']);
            return null;
        }

        return $body;
    }

    /**
     * Brute force protection: at most RESTART_PER_WINDOW restarts per client
     * and per window, even when cookies are lost between each request.
     */
    private static function restartBudgetExceeded(): bool
    {
        $file = self::restartCounterFile();
        if ($file === '') {
            return false; // cannot track: never block
        }

        $now     = time();
        $entries = [];
        $raw     = @file_get_contents($file);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $timestamp) {
                    if (is_int($timestamp) && ($now - $timestamp) < self::RESTART_WINDOW) {
                        $entries[] = $timestamp;
                    }
                }
            }
        }

        if (count($entries) >= self::RESTART_PER_WINDOW) {
            @unlink($file);
            return true;
        }

        $entries[] = $now;
        @file_put_contents($file, (string) json_encode(array_values($entries)), LOCK_EX);

        return false;
    }

    private static function restartCounterFile(): string
    {
        $directory = '';
        if (defined('GLPI_VAR_DIR') && GLPI_VAR_DIR !== '') {
            $candidate = rtrim((string) GLPI_VAR_DIR, '/') . '/_tmp';
            if (is_dir($candidate) && is_writable($candidate)) {
                $directory = $candidate;
            }
        }
        if ($directory === '') {
            $directory = (string) sys_get_temp_dir();
        }
        if ($directory === '' || !is_dir($directory) || !is_writable($directory)) {
            return '';
        }

        $key = md5((string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        return $directory . '/ssobridge-restart-' . $key . '.json';
    }
}
