<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge plugin - simple message page rendering.
 *
 * These helpers are used by the plugin front scripts (login/callback/logout)
 * to display a human readable page without depending on a logged-in session.
 * They print the page and return; the script then ends naturally.
 *
 * ---------------------------------------------------------------------
 */

namespace GlpiPlugin\Ssobridge;

final class Front
{
    /**
     * @param string   $title    Page title.
     * @param string[] $messages Error / information messages.
     * @param int      $status   HTTP status code.
     */
    public static function renderMessage(string $title, array $messages, int $status = 400): void
    {
        http_response_code($status);

        $glpi_base = self::glpiBasePath();
        $login_url = $glpi_base . '/index.php';
        $sso_url   = $glpi_base . '/plugins/ssobridge/front/login.php';

        $messages_html = '';
        foreach ($messages as $message) {
            $messages_html .= '<li>' . htmlescape((string) $message) . '</li>' . "\n";
        }

        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="en"><head><meta charset="utf-8">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        echo '<title>SSO Bridge - ' . htmlescape($title) . '</title>' . "\n";
        echo '<style>'
            . 'body{font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f4f4f6;color:#1d1d1f;'
            . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
            . '.card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.15);max-width:560px;padding:32px;margin:16px}'
            . 'h1{font-size:1.25rem;margin:0 0 12px}ul{margin:0 0 20px;padding-left:20px}'
            . 'a{color:#1759e6;text-decoration:none}a:hover{text-decoration:underline}'
            . '</style>' . "\n";
        echo '</head><body><div class="card">' . "\n";
        echo '<h1>' . htmlescape($title) . '</h1>' . "\n";
        if (count($messages) > 0) {
            echo '<ul>' . "\n" . $messages_html . '</ul>' . "\n";
        }
        echo '<p><a href="' . htmlescape($login_url) . '">' . htmlescape('Back to GLPI login') . '</a>'
            . ' &middot; <a href="' . htmlescape($sso_url) . '">' . htmlescape('Retry SSO login') . '</a></p>' . "\n";
        echo '</div></body></html>' . "\n";
    }

    /**
     * GLPI base path: root_doc when configured, otherwise derived from the
     * current script location.
     */
    private static function glpiBasePath(): string
    {
        global $CFG_GLPI;

        if (isset($CFG_GLPI['root_doc']) && $CFG_GLPI['root_doc'] !== '') {
            return rtrim($CFG_GLPI['root_doc'], '/');
        }

        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
        $base   = preg_replace('#/plugins/ssobridge/front/?.*$#', '', $script);
        return rtrim($base ?? '', '/');
    }
}
