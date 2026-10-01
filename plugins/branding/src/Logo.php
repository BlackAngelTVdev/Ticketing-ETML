<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI Branding plugin - logo discovery.
 *
 * The whole point of this plugin is to let you drop a logo file directly
 * in the plugin folder and have every GLPI logo replaced by it. This class
 * locates those files once and serves them through front/logo.php.
 *
 * Recognised files (first found wins), placed either in `pics/` or at the
 * root of the plugin:
 *
 *   logo.*                 logo used everywhere (fallback for all kinds)
 *   logo-light.*           logo drawn on a dark background (top menu)
 *   logo-dark.*            logo drawn on a light background
 *   logo-reduced.*         small/square logo (collapsed sidebar)
 *   logo-login.*           logo shown on the login page
 *   logo-light-reduced.*   dark-background reduced logo
 *   logo-dark-reduced.*    light-background reduced logo
 *   logo-light-login.*     login logo for the dark theme
 *   logo-dark-login.*      login logo for the light theme
 *   favicon.*              browser tab icon
 *
 * `*` is any of png, svg, webp, jpg, jpeg, gif (plus ico for the favicon).
 * Only `logo.*` is required: every other kind falls back to it.
 *
 * ---------------------------------------------------------------------
 */

namespace GlpiPlugin\Branding;

final class Logo
{
    /**
     * Image extensions accepted for a logo file (in preference order).
     */
    public const EXTENSIONS = ['png', 'svg', 'webp', 'jpg', 'jpeg', 'gif'];

    /**
     * Extensions accepted for the favicon (in preference order).
     */
    public const FAVICON_EXTENSIONS = ['ico', 'png', 'svg', 'webp', 'gif'];

    /**
     * Content type sent by front/logo.php, keyed by extension.
     */
    private const MIME = [
        'png'  => 'image/png',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'ico'  => 'image/x-icon',
    ];

    /**
     * Fallback chain used when a specific file is missing. The first
     * matching file "wins", so dropping a single `logo.*` covers every
     * spot of the interface.
     *
     * @var array<string, string[]>
     */
    private const FALLBACKS = [
        'logo'          => ['logo'],
        'light'         => ['light', 'logo'],
        'dark'          => ['dark', 'logo'],
        'reduced'       => ['reduced', 'logo'],
        'login'         => ['login', 'logo'],
        'light-reduced' => ['light-reduced', 'reduced', 'light', 'logo'],
        'dark-reduced'  => ['dark-reduced', 'reduced', 'dark', 'logo'],
        'light-login'   => ['light-login', 'login', 'light', 'logo'],
        'dark-login'    => ['dark-login', 'login', 'dark', 'logo'],
        'favicon'       => ['favicon', 'logo'],
    ];

    /**
     * Absolute path of the plugin directory (its parent is the GLPI
     * `plugins/` directory).
     */
    public static function dir(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Kinds accepted by front/logo.php and the CSS stylesheet.
     */
    public static function isKnownKind(string $kind): bool
    {
        return array_key_exists($kind, self::FALLBACKS);
    }

    /**
     * Whether at least one real logo has been dropped in the plugin.
     *
     * Used to avoid injecting anything (and showing a broken image) when
     * the plugin is installed but not customised yet.
     */
    public static function hasLogo(): bool
    {
        foreach (self::FALLBACKS as $kind => $names) {
            if ($kind === 'favicon') {
                continue;
            }
            foreach ($names as $name) {
                if (self::find($name) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the browser tab icon can be replaced (a dedicated favicon or
     * any logo to fall back to).
     */
    public static function hasFavicon(): bool
    {
        return self::resolve('favicon') !== null;
    }

    /**
     * Absolute path of the file to use for the given kind, or null when
     * nothing was dropped.
     */
    public static function resolve(string $kind): ?string
    {
        foreach (self::FALLBACKS[$kind] ?? [] as $name) {
            $file = self::find($name);
            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Content type of a logo file, based on its extension.
     */
    public static function mime(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::MIME[$extension] ?? 'application/octet-stream';
    }

    /**
     * Look for `<name>.<extension>` in `pics/` then at the plugin root.
     *
     * The path is resolved with realpath() and checked to stay inside the
     * plugin directory: only files bundled with the plugin are ever
     * served, never an arbitrary path.
     */
    private static function find(string $name): ?string
    {
        $base        = self::dir();
        $directories = [$base . '/pics', $base];
        $extensions  = $name === 'favicon' ? self::FAVICON_EXTENSIONS : self::EXTENSIONS;

        foreach ($directories as $directory) {
            foreach ($extensions as $extension) {
                foreach ([$extension, strtoupper($extension)] as $suffix) {
                    $path = $directory . '/' . $name . '.' . $suffix;
                    if (!is_file($path)) {
                        continue;
                    }

                    $real = realpath($path);
                    if ($real !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                        return $real;
                    }
                }
            }
        }

        return null;
    }
}
