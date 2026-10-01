<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI Branding plugin - logo files.
 *
 * Locates the logo files used across the interface and manages the ones
 * uploaded from the plugin configuration page.
 *
 * Two places are searched, in this order:
 *
 *   1. the plugin storage directory (files/_plugins/branding), filled in
 *      by the configuration page - no code change needed;
 *   2. the plugin itself: `pics/` then the plugin root, so a logo can
 *      still be shipped directly with the plugin.
 *
 * Recognised files (first found wins):
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

use RuntimeException;

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
     * Maximum size of an uploaded logo (2 MB).
     */
    public const MAX_UPLOAD_BYTES = 2097152;

    /**
     * Base names a logo can be stored/uploaded under, with their label
     * for the configuration page.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'logo'          => 'Logo principal (utilisé partout)',
        'light'         => 'Logo sur fond sombre (menu du haut)',
        'dark'          => 'Logo sur fond clair',
        'reduced'       => 'Logo réduit (barre latérale repliée)',
        'login'         => 'Logo de la page de connexion',
        'light-reduced' => 'Logo réduit sur fond sombre',
        'dark-reduced'  => 'Logo réduit sur fond clair',
        'light-login'   => 'Logo de connexion (thème sombre)',
        'dark-login'    => 'Logo de connexion (thème clair)',
        'favicon'       => 'Favicon (onglet du navigateur)',
    ];

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
     * Absolute path of the plugin directory.
     */
    public static function dir(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Directory holding the files uploaded through the configuration
     * page (outside the plugin code, in GLPI's writable data directory).
     */
    public static function storageDir(): string
    {
        if (defined('GLPI_VAR_DIR') && GLPI_VAR_DIR !== '') {
            return rtrim((string) GLPI_VAR_DIR, '/') . '/_plugins/branding';
        }

        return self::dir() . '/var';
    }

    /**
     * Whether the given base name (see LABELS) is handled.
     */
    public static function isKnownName(string $name): bool
    {
        return array_key_exists($name, self::LABELS);
    }

    /**
     * Kinds accepted by front/logo.php and the CSS stylesheet. Kinds and
     * base names are the same list.
     */
    public static function isKnownKind(string $kind): bool
    {
        return array_key_exists($kind, self::FALLBACKS);
    }

    /**
     * Whether at least one real logo is available (uploaded or bundled).
     *
     * Used to avoid injecting logo CSS (and showing a broken image) when
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
     * nothing is available.
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
     * Absolute path of an uploaded file for the given base name, or null.
     */
    public static function stored(string $name): ?string
    {
        $directory = self::storageDir();

        return self::locate($directory, $name, $directory);
    }

    /**
     * Absolute path of a file bundled with the plugin (`pics/` then the
     * plugin root), or null.
     *
     * Bundled files follow the `logo-<kind>.<ext>` naming documented in
     * pics/README.md (`logo.png`, `logo-light.png`, `logo-login.png`, …).
     * The bare `<kind>.<ext>` form is also accepted, as a convenience.
     */
    public static function bundled(string $name): ?string
    {
        $base = self::dir();
        $candidates = in_array($name, ['logo', 'favicon'], true)
            ? [$name]
            : ['logo-' . $name, $name];

        foreach ($candidates as $candidate) {
            foreach ([$base . '/pics', $base] as $directory) {
                $file = self::locate($directory, $candidate, $base);
                if ($file !== null) {
                    return $file;
                }
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
     * Accepted extensions for a given base name.
     *
     * @return string[]
     */
    public static function extensions(string $name): array
    {
        return $name === 'favicon' ? self::FAVICON_EXTENSIONS : self::EXTENSIONS;
    }

    /**
     * Store an uploaded logo in the plugin storage directory.
     *
     * @throws RuntimeException when the file is missing, too big or not a
     *                          supported image.
     */
    public static function upload(string $name, string $tmp_path, string $original_name): void
    {
        if (!self::isKnownName($name)) {
            throw new RuntimeException('Type de logo inconnu.');
        }

        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::extensions($name), true)) {
            throw new RuntimeException(sprintf(
                'Format « %s » non supporté (formats acceptés : %s).',
                $extension === '' ? '?' : $extension,
                implode(', ', self::extensions($name))
            ));
        }

        if (!is_file($tmp_path) || !is_uploaded_file($tmp_path)) {
            throw new RuntimeException('Fichier uploadé introuvable.');
        }

        $size = filesize($tmp_path);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('Le fichier reçu est vide.');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException(sprintf(
                'Fichier trop volumineux (%s Mo, maximum %s Mo).',
                round($size / 1048576, 1),
                round(self::MAX_UPLOAD_BYTES / 1048576, 1)
            ));
        }

        if (!self::looksLikeImage($tmp_path, $extension)) {
            throw new RuntimeException('Le fichier ne semble pas être une image valide.');
        }

        $directory = self::storageDir();
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Impossible de créer le dossier de stockage « %s ».', $directory));
        }

        self::remove($name);

        $target = $directory . '/' . $name . '.' . $extension;
        if (!@move_uploaded_file($tmp_path, $target)) {
            throw new RuntimeException('Impossible d\'enregistrer le logo.');
        }
        @chmod($target, 0644);
    }

    /**
     * Delete the uploaded files for a given base name.
     */
    public static function remove(string $name): void
    {
        $directory = self::storageDir();
        if (!is_dir($directory)) {
            return;
        }

        foreach (self::extensions($name) as $extension) {
            foreach ([$extension, strtoupper($extension)] as $suffix) {
                $path = $directory . '/' . $name . '.' . $suffix;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Uploaded file if any, bundled file otherwise.
     */
    private static function find(string $name): ?string
    {
        return self::stored($name) ?? self::bundled($name);
    }

    /**
     * Look for `<name>.<extension>` in a directory.
     *
     * The path is resolved with realpath() and checked to stay inside the
     * expected base directory: only bundled/uploaded files are ever
     * served, never an arbitrary path.
     */
    private static function locate(string $directory, string $name, string $base): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }

        foreach (self::extensions($name) as $extension) {
            foreach ([$extension, strtoupper($extension)] as $suffix) {
                $path = $directory . '/' . $name . '.' . $suffix;
                if (!is_file($path)) {
                    continue;
                }

                $real = realpath($path);
                if ($real !== false && str_starts_with($real, rtrim($base, '/') . DIRECTORY_SEPARATOR)) {
                    return $real;
                }
            }
        }

        return null;
    }

    /**
     * Cheap sanity check on the uploaded content.
     */
    private static function looksLikeImage(string $path, string $extension): bool
    {
        if ($extension === 'svg') {
            $head = (string) @file_get_contents($path, false, null, 0, 4096);

            return stripos($head, '<svg') !== false;
        }

        return @getimagesize($path) !== false;
    }
}
