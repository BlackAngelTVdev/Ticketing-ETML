<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge plugin - configuration
 *
 * Values are resolved in this order:
 *   1. real environment variables (recommended for Docker deployments);
 *   2. the plugin `.env` file (for classic / dev installs);
 *   3. the defaults below.
 *
 * ---------------------------------------------------------------------
 */

namespace GlpiPlugin\Ssobridge;

use RuntimeException;

final class Config
{
    /**
     * Default values (also documented in .env.example).
     */
    private const DEFAULTS = [
        // Base URL of the SSO portal (must end with a slash).
        'SSO_PORTAL_URL'       => 'https://apps.pm2etml.ch/auth/',
        // API / access token delivered by the SSO portal maintainer.
        'SSO_ACCESS_TOKEN'     => '',
        // Full URL of this plugin callback (optional).
        // When empty, it is built automatically from the current request
        // (https://<host>/plugins/ssobridge/front/callback.php).
        'SSO_CALLBACK_URI'     => '',
        // Auto-create a GLPI user when the SSO account has no match.
        'SSO_AUTO_CREATE_USERS' => '1',
        // Profile given to auto-created users:
        //   ''     -> GLPI "default profile" (is_default flag)
        //   'none' -> do not assign any profile
        //   other  -> profile name (e.g. 'Self-Service')
        'SSO_DEFAULT_PROFILE'  => '',
        // Redirect anonymous visitors of the GLPI login page straight to the
        // SSO portal (1) instead of only showing the "Login with SSO" button (0).
        // Append ?nosso=1 to any GLPI URL to keep the regular login form.
        'SSO_AUTO_REDIRECT'    => '0',
        // Verbosity of the SSO journal (files/_log/ssobridge.log):
        //   error | warning | info | debug | none
        'SSO_LOG_LEVEL'        => 'info',
        // Also send every logged entry to PHP's error_log() (1 = yes).
        'SSO_LOG_TO_ERROR_LOG' => '0',
    ];

    /** @var array<string, string>|null */
    private static ?array $values = null;

    /**
     * Directory containing the plugin (used to locate the .env file).
     */
    public static function pluginDir(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Get a raw configuration value.
     */
    public static function get(string $key): string
    {
        $values = self::values();
        return $values[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    public static function portalUrl(): string
    {
        return rtrim(self::get('SSO_PORTAL_URL'), '/') . '/';
    }

    public static function accessToken(): string
    {
        return trim(self::get('SSO_ACCESS_TOKEN'));
    }

    public static function callbackUri(): string
    {
        return trim(self::get('SSO_CALLBACK_URI'));
    }

    public static function autoCreateUsers(): bool
    {
        return filter_var(self::get('SSO_AUTO_CREATE_USERS'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Profile used for auto-created users (see DEFAULTS).
     */
    public static function defaultProfile(): string
    {
        return trim(self::get('SSO_DEFAULT_PROFILE'));
    }

    /**
     * Whether anonymous visitors of the GLPI login page should be redirected
     * straight to the SSO portal (see DEFAULTS).
     */
    public static function autoRedirect(): bool
    {
        return filter_var(self::get('SSO_AUTO_REDIRECT'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Verbosity of the SSO journal: error, warning, info, debug or none.
     */
    public static function logLevel(): string
    {
        return strtolower(trim(self::get('SSO_LOG_LEVEL')));
    }

    /**
     * Also forward every journal entry to PHP's error_log().
     */
    public static function logToErrorLog(): bool
    {
        return filter_var(self::get('SSO_LOG_TO_ERROR_LOG'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Load real env vars then parse the plugin .env file for the missing ones.
     *
     * @return array<string, string>
     */
    private static function values(): array
    {
        if (self::$values !== null) {
            return self::$values;
        }

        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $value = getenv($key);
            $values[$key] = ($value === false) ? '' : (string) $value;
        }

        $env_file = self::pluginDir() . '/.env';
        if (is_file($env_file)) {
            $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                throw new RuntimeException(sprintf('Cannot read environment file "%s".', $env_file));
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_starts_with($line, 'export ')) {
                    $line = substr($line, 7);
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key   = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));

                // Strip surrounding quotes.
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last  = substr($value, -1);
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }

                // A real environment variable always wins over the .env file.
                if (array_key_exists($key, $values) && $values[$key] === '' && $value !== '') {
                    $values[$key] = $value;
                }
            }
        }

        foreach (array_keys(self::DEFAULTS) as $key) {
            if (($values[$key] ?? '') === '') {
                $values[$key] = self::DEFAULTS[$key];
            }
        }

        self::$values = $values;
        return $values;
    }
}
