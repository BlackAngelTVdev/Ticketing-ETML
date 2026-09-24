<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge plugin - SSO journal (logging).
 *
 * Every SSO step is written to a single, human readable file named
 * `ssobridge.log` located in GLPI's log directory (`files/_log` by
 * default):
 *
 *   2026-09-24 12:00:00 [INFO    ] [SSO-4F3A9C1B] SSO login completed | user=jdoe uri=/ServiceCatalog ip=10.0.0.5
 *
 * Each entry carries a short reference (`SSO-xxxxxxxx`) which is also
 * displayed on the plugin error pages: a user can quote it, and the
 * administrator greps it in the journal to get the whole story of the
 * failed login.
 *
 * Verbosity is controlled by SSO_LOG_LEVEL (error, warning, info, debug,
 * none). The journal never contains the access token: sensitive values are
 * redacted, correlation ids are shortened.
 *
 * Logging must never break a login: every failure falls back to PHP's
 * error_log() and never throws.
 *
 * ---------------------------------------------------------------------
 */

namespace GlpiPlugin\Ssobridge;

final class Logger
{
    /**
     * File name inside GLPI's log directory (also exposed by front/logs.php).
     */
    public const FILE_NAME = 'ssobridge.log';

    private const LEVELS = [
        'debug'    => 10,
        'info'     => 20,
        'warning'  => 30,
        'error'    => 40,
        'critical' => 50,
    ];

    /**
     * Rotate the journal to `<name>.1` when it grows above this size.
     */
    private const MAX_SIZE = 1048576; // 1 MB

    private const SENSITIVE_KEY = '/(token|secret|password|passwd|pwd|api[_-]?key|authorization)/i';

    private const CORRELATION_KEY = '/(correlation|cid)/i';

    /**
     * Short reference of the current request (e.g. "SSO-4F3A9C1B").
     */
    private static ?string $reference = null;

    /**
     * Cached verbosity threshold.
     */
    private static ?int $threshold = null;

    /**
     * Short reference of the current request, displayed on error pages and
     * written in front of every journal entry.
     */
    public static function reference(): string
    {
        if (self::$reference === null) {
            try {
                $suffix = strtoupper(bin2hex(random_bytes(4)));
            } catch (\Exception $e) {
                $suffix = strtoupper(str_pad((string) mt_rand(0, 0xFFFFFF), 6, '0', STR_PAD_LEFT));
            }
            self::$reference = 'SSO-' . $suffix;
        }

        return self::$reference;
    }

    public static function isDebug(): bool
    {
        return self::threshold() <= self::LEVELS['debug'];
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function critical(string $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }

    /**
     * Write one journal entry.
     *
     * @param array<string, mixed> $context Extra key/value pairs (already
     *                                      redacted when needed).
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $level  = strtolower($level);
        $weight = self::LEVELS[$level] ?? self::LEVELS['info'];

        if ($weight < self::threshold()) {
            return;
        }

        $line = sprintf(
            '%s [%-8s] [%s] %s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            self::reference(),
            self::scrub($message)
        );

        $context = self::enrich($context);
        if ($context !== []) {
            $line .= ' | ' . self::formatContext($context);
        }
        $line .= PHP_EOL;

        self::write($line);
    }

    /**
     * Absolute path of the journal (empty when no writeable directory could
     * be found).
     */
    public static function path(): string
    {
        $directory = self::directory();

        return $directory === null ? '' : $directory . '/' . self::FILE_NAME;
    }

    /**
     * Human readable location of the journal, for error pages / admin UI.
     */
    public static function location(): string
    {
        return self::path() !== '' ? self::path() : 'PHP error log (error_log)';
    }

    /**
     * Last lines of the journal, most recent last.
     *
     * @return string[]
     */
    public static function tail(int $maxLines = 200): array
    {
        $path = self::path();
        if ($path === '' || !is_file($path)) {
            return [];
        }

        $maxLines = max(1, min($maxLines, 2000));
        $size     = (int) @filesize($path);
        if ($size <= 0) {
            return [];
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $chunk   = 65536;
        $data    = '';
        $read    = 0;
        $truncated = false;

        while ($read < $size && substr_count($data, "\n") <= $maxLines) {
            $seek = max(0, $size - $read - $chunk);
            if (@fseek($handle, $seek) !== 0) {
                break;
            }
            $part = @fread($handle, min($chunk, $size - $seek));
            if ($part === false || $part === '') {
                break;
            }
            $data      = $part . $data;
            $read      = $size - $seek;
            $truncated = $seek > 0;
        }

        @fclose($handle);

        $lines = preg_split('/\r?\n/', $data) ?: [];
        if ($truncated && count($lines) > 0) {
            array_shift($lines); // first line is a partial one
        }

        $lines = array_values(array_filter($lines, static fn($line) => $line !== ''));

        return array_slice($lines, -$maxLines);
    }

    /**
     * Empty the journal (used by the admin log viewer).
     */
    public static function truncate(): bool
    {
        $path = self::path();
        if ($path === '' || !is_file($path)) {
            return false;
        }

        return @file_put_contents($path, '') !== false;
    }

    /**
     * Shorten an identifier (typically a correlation id) so the journal
     * cannot be used to hijack a pending login.
     */
    public static function shorten(string $value, int $length = 8): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return substr($value, 0, $length) . '…';
    }

    /**
     * Remove credentials that may appear in URLs/messages.
     */
    public static function scrub(string $text): string
    {
        return (string) preg_replace(
            '/((?:token|access_token|correlationId|correlation_id|api[_-]?key)=)[^&\s"\'<>]*/i',
            '$1[redacted]',
            $text
        );
    }

    /**
     * Add request informations to every entry.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function enrich(array $context): array
    {
        $request = [
            'uri' => self::scrub((string) ($_SERVER['REQUEST_URI'] ?? '')),
            'ip'  => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ];

        try {
            $userId = \Session::getLoginUserID();
        } catch (\Throwable $e) {
            $userId = 0;
        }
        if ($userId) {
            $request['user'] = (int) $userId;
        }

        return array_merge($context, array_filter($request, static fn($value) => $value !== '' && $value !== 0));
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function formatContext(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . self::formatValue((string) $key, $value);
        }

        return implode(' ', $parts);
    }

    private static function formatValue(string $key, mixed $value): string
    {
        if (preg_match(self::SENSITIVE_KEY, $key) === 1) {
            return '[redacted]';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || $value === '') {
            return '""';
        }

        if (is_scalar($value)) {
            $string = self::scrub((string) $value);
            if (preg_match(self::CORRELATION_KEY, $key) === 1) {
                $string = self::shorten($string);
            }

            return $string;
        }

        return self::scrub((string) json_encode($value));
    }

    private static function write(string $line): void
    {
        $path = self::path();

        if ($path === '') {
            error_log(rtrim($line));
            return;
        }

        self::rotate($path);

        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
            return;
        }

        if (Config::logToErrorLog()) {
            error_log(rtrim($line));
        }
    }

    private static function rotate(string $path): void
    {
        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_SIZE) {
            @rename($path, $path . '.1');
        }
    }

    /**
     * GLPI's log directory (created when missing).
     */
    private static function directory(): ?string
    {
        $candidates = [];

        if (defined('GLPI_LOG_DIR') && GLPI_LOG_DIR !== '') {
            $candidates[] = (string) GLPI_LOG_DIR;
        }

        global $CFG_GLPI;
        if (!empty($CFG_GLPI['log_dir'])) {
            $candidates[] = (string) $CFG_GLPI['log_dir'];
        }

        if (defined('GLPI_VAR_DIR') && GLPI_VAR_DIR !== '') {
            $candidates[] = rtrim((string) GLPI_VAR_DIR, '/') . '/_log';
        }

        foreach ($candidates as $directory) {
            $directory = rtrim($directory, '/');
            if ($directory === '') {
                continue;
            }
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                continue;
            }
            if (is_writable($directory)) {
                return $directory;
            }
        }

        return null;
    }

    private static function threshold(): int
    {
        if (self::$threshold !== null) {
            return self::$threshold;
        }

        try {
            $level = strtolower(trim(Config::logLevel()));
        } catch (\Throwable $e) {
            $level = 'info';
        }

        if ($level === '' || $level === 'none' || $level === 'off' || $level === 'false') {
            self::$threshold = PHP_INT_MAX;
        } elseif (isset(self::LEVELS[$level])) {
            self::$threshold = self::LEVELS[$level];
        } else {
            self::$threshold = self::LEVELS['info'];
        }

        return self::$threshold;
    }
}
