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
 * Each page displays the short reference of the request (Logger::reference())
 * and the location of the SSO journal, so a user can quote a reference that
 * an administrator will find instantly in files/_log/ssobridge.log. When the
 * journal is in debug mode, the last entries are even shown on the page.
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
     * @param string   $redirect GLPI target to retry the SSO login for.
     */
    public static function renderMessage(string $title, array $messages, int $status = 400, string $redirect = ''): void
    {
        http_response_code($status);

        Logger::info('Displaying an SSO error page', ['title' => $title, 'status' => $status]);

        $glpi_base = self::glpiBasePath();
        $login_url = $glpi_base . '/index.php?nosso=1';
        $sso_url   = $glpi_base . '/plugins/ssobridge/front/login.php';

        if ($redirect !== '') {
            $safe = SsoClient::sanitizeRedirect($redirect);
            if ($safe !== '') {
                $sso_url .= '?redirect=' . rawurlencode($safe);
            }
        }

        $messages_html = '';
        foreach ($messages as $message) {
            $messages_html .= '<li>' . htmlescape((string) $message) . '</li>' . "\n";
        }

        $reference = Logger::reference();
        $journal   = Logger::location();

        $details = '';
        if (Logger::isDebug()) {
            $tail = Logger::tail(15);
            if (count($tail) > 0) {
                $details = '<h2>Journal (debug mode)</h2>' . "\n"
                    . '<pre class="log">' . htmlescape(implode("\n", $tail)) . '</pre>' . "\n";
            }
        }

        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="en"><head><meta charset="utf-8">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        echo '<title>SSO Bridge - ' . htmlescape($title) . '</title>' . "\n";
        echo '<style>'
            . 'body{font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f4f4f6;color:#1d1d1f;'
            . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
            . '.card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.15);max-width:720px;padding:32px;margin:16px}'
            . 'h1{font-size:1.25rem;margin:0 0 12px}h2{font-size:1rem;margin:24px 0 8px}'
            . 'ul{margin:0 0 20px;padding-left:20px}'
            . 'a{color:#1759e6;text-decoration:none}a:hover{text-decoration:underline}'
            . '.meta{margin-top:24px;border-top:1px solid #e4e4e7;padding-top:12px;font-size:.85rem;color:#5f5f68}'
            . '.meta code{background:#f4f4f6;border-radius:4px;padding:2px 6px}'
            . 'pre.log{background:#1d1d1f;color:#e7e7ea;border-radius:6px;padding:12px;overflow:auto;font-size:.78rem;line-height:1.5}'
            . '</style>' . "\n";
        echo '</head><body><div class="card">' . "\n";
        echo '<h1>' . htmlescape($title) . '</h1>' . "\n";
        if (count($messages) > 0) {
            echo '<ul>' . "\n" . $messages_html . '</ul>' . "\n";
        }
        echo '<p><a href="' . htmlescape($login_url) . '">' . htmlescape('Back to GLPI login') . '</a>'
            . ' &middot; <a href="' . htmlescape($sso_url) . '">' . htmlescape('Retry SSO login') . '</a></p>' . "\n";
        echo $details;
        echo '<p class="meta">' . htmlescape('Reference') . ' : <code>' . htmlescape($reference) . '</code><br>' . "\n";
        echo htmlescape('SSO journal') . ' : <code>' . htmlescape($journal) . '</code></p>' . "\n";
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
