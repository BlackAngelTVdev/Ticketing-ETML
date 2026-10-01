<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI Branding plugin - configuration page.
 *
 * Lets an administrator upload the logos used across the interface
 * (without touching the plugin code) and toggle the interface tweaks
 * such as the "Show as map" button.
 *
 * URL:    /plugins/branding/front/config.php
 * Access: authenticated users with the right to change the setup.
 *
 * Uploaded logos are stored in files/_plugins/branding (see
 * GlpiPlugin\Branding\Logo), while the tweaks are saved in GLPI's
 * configuration (see GlpiPlugin\Branding\Settings).
 *
 * ---------------------------------------------------------------------
 */

use GlpiPlugin\Branding\Logo;
use GlpiPlugin\Branding\Settings;

global $CFG_GLPI;

// ---------------------------------------------------------------------
// Access control: setup right only.
// ---------------------------------------------------------------------
$config_right = defined('UPDATE') ? UPDATE : 2;
if (!Session::getLoginUserID() || !Session::haveRight('config', $config_right)) {
    if (class_exists(\Glpi\Exception\Http\AccessDeniedHttpException::class)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    Html::displayErrorAndDie('Access denied');
}

$root       = isset($CFG_GLPI['root_doc']) ? rtrim($CFG_GLPI['root_doc'], '/') : '';
$here       = $root . '/plugins/branding/front/config.php';
$csrf_token = method_exists('Session', 'getNewCSRFToken') ? (string) Session::getNewCSRFToken() : '';

$errors = [];

// ---------------------------------------------------------------------
// Actions.
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Session::checkCSRF($_POST);

    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $name   = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '';

    if ($action === 'settings') {
        Settings::setShowMapButton(isset($_POST['show_map_button']));
        Html::redirect($here . '?saved=1');
    } elseif ($action === 'upload') {
        try {
            if (!Logo::isKnownName($name)) {
                throw new RuntimeException('Type de logo inconnu.');
            }

            $file = $_FILES['logo'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Aucun fichier n\'a été téléversé (ou erreur pendant l\'envoi).');
            }

            Logo::upload($name, (string) $file['tmp_name'], (string) $file['name']);
            Html::redirect($here . '?saved=1');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($action === 'delete') {
        if (Logo::isKnownName($name)) {
            Logo::remove($name);
        }
        Html::redirect($here . '?saved=1');
    }
}

$saved = isset($_GET['saved']);

// ---------------------------------------------------------------------
// Rendering.
// ---------------------------------------------------------------------
$options = ['png', 'svg', 'webp', 'jpg', 'jpeg', 'gif'];

echo '<!DOCTYPE html>' . "\n";
echo '<html lang="fr"><head><meta charset="utf-8">' . "\n";
echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
echo '<title>Branding - configuration</title>' . "\n";
echo '<style>'
    . 'body{font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f4f4f6;color:#1d1d1f;margin:0;padding:24px}'
    . '.wrap{max-width:960px;margin:0 auto}'
    . 'h1{font-size:1.35rem;margin:0 0 4px}h2{font-size:1rem;margin:24px 0 8px}'
    . '.card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.15);padding:20px;margin-bottom:16px}'
    . 'table{border-collapse:collapse;width:100%;font-size:.9rem}'
    . 'td,th{padding:10px 8px;border-bottom:1px solid #ececf0;text-align:left;vertical-align:middle}'
    . 'th{font-weight:600;color:#5f5f68}'
    . '.thumbs{max-width:180px;max-height:70px;display:block;background:#f4f4f6;border:1px solid #ececf0;border-radius:4px;padding:4px}'
    . '.tag{display:inline-block;border-radius:4px;padding:1px 6px;font-size:.72rem}'
    . '.tag-stored{background:#dcfce7;color:#166534}.tag-bundled{background:#e0e7ff;color:#3730a3}.tag-none{background:#f3f4f6;color:#6b7280}'
    . 'code{background:#f4f4f6;border-radius:4px;padding:1px 5px;font-size:.8rem}'
    . 'a{color:#1759e6;text-decoration:none}a:hover{text-decoration:underline}'
    . 'button{padding:6px 12px;border:1px solid #d4d4d8;border-radius:4px;background:#fff;cursor:pointer}'
    . '.btn-primary{background:#1759e6;border-color:#1759e6;color:#fff}'
    . '.muted{color:#5f5f68;font-size:.85rem}'
    . '.alert{border-radius:6px;padding:10px 14px;margin-bottom:16px}'
    . '.alert-ok{background:#dcfce7;color:#166534}.alert-ko{background:#fee2e2;color:#991b1b}'
    . '.row-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}'
    . '.switch{display:flex;align-items:center;gap:8px}'
    . '</style>' . "\n";
echo '</head><body><div class="wrap">' . "\n";

echo '<h1>Branding</h1>' . "\n";
echo '<p class="muted"><a href="' . htmlescape($root) . '/front/plugin.php">← Retour aux plugins</a></p>' . "\n";

if ($saved) {
    echo '<div class="alert alert-ok">Modifications enregistrées.</div>' . "\n";
}
foreach ($errors as $error) {
    echo '<div class="alert alert-ko">' . htmlescape($error) . '</div>' . "\n";
}

// ---------------------------------------------------------------------
// Logos.
// ---------------------------------------------------------------------
echo '<div class="card">' . "\n";
echo '<h2 style="margin-top:0">Logos</h2>' . "\n";
echo '<p class="muted">Téléversez un logo pour remplacer celui de GLPI. Les formats acceptés sont '
    . htmlescape(implode(', ', $options)) . ' (2 Mo maximum). « Logo principal » suffit : les autres '
    . 'retombent dessus tant qu\'ils ne sont pas personnalisés.</p>' . "\n";

echo '<table>' . "\n";
echo '<tr><th>Emplacement</th><th>Aperçu</th><th>Source</th><th>Changer</th></tr>' . "\n";

foreach (Logo::LABELS as $name => $label) {
    $stored   = Logo::stored($name);
    $bundled  = Logo::bundled($name);
    $current  = $stored ?? $bundled;

    echo '<tr>' . "\n";
    echo '<td>' . htmlescape($label) . '<br><code>logo' . ($name === 'logo' ? '' : '-' . $name) . '.*</code></td>' . "\n";

    echo '<td>';
    if ($current !== null) {
        $preview = $root . '/plugins/branding/front/logo.php?kind=' . rawurlencode($name)
            . '&v=' . rawurlencode((string) @filemtime($current));
        echo '<img class="thumbs" src="' . htmlescape($preview) . '" alt="">';
    } else {
        echo '<span class="muted">—</span>';
    }
    echo '</td>' . "\n";

    echo '<td>';
    if ($stored !== null) {
        echo '<span class="tag tag-stored">Téléversé</span>';
    } elseif ($bundled !== null) {
        echo '<span class="tag tag-bundled">Fourni avec le plugin</span>';
    } else {
        echo '<span class="tag tag-none">Logo GLPI</span>';
    }
    echo '</td>' . "\n";

    echo '<td><div class="row-actions">';

    echo '<form method="post" action="' . htmlescape($here) . '" enctype="multipart/form-data">' . "\n";
    echo '<input type="hidden" name="action" value="upload">' . "\n";
    echo '<input type="hidden" name="name" value="' . htmlescape($name) . '">' . "\n";
    echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlescape($csrf_token) . '">' . "\n";
    echo '<input type="file" name="logo" accept="image/*,.ico" required>' . "\n";
    echo '<button type="submit">Téléverser</button>' . "\n";
    echo '</form>' . "\n";

    if ($stored !== null) {
        echo '<form method="post" action="' . htmlescape($here) . '">' . "\n";
        echo '<input type="hidden" name="action" value="delete">' . "\n";
        echo '<input type="hidden" name="name" value="' . htmlescape($name) . '">' . "\n";
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlescape($csrf_token) . '">' . "\n";
        echo '<button type="submit" onclick="return confirm(\'Supprimer ce logo ?\');">Supprimer</button>' . "\n";
        echo '</form>' . "\n";
    }

    echo '</div></td>' . "\n";
    echo '</tr>' . "\n";
}
echo '</table>' . "\n";

$storage = Logo::storageDir();
echo '<p class="muted">Les logos téléversés sont enregistrés dans <code>' . htmlescape($storage) . '</code> '
    . '(hors du code du plugin). Un fichier fourni avec le plugin ne peut pas être supprimé depuis cette page.</p>' . "\n";
echo '</div>' . "\n";

// ---------------------------------------------------------------------
// Interface tweaks.
// ---------------------------------------------------------------------
$show_map = Settings::showMapButton();

echo '<div class="card">' . "\n";
echo '<h2 style="margin-top:0">Interface</h2>' . "\n";
echo '<form method="post" action="' . htmlescape($here) . '">' . "\n";
echo '<input type="hidden" name="action" value="settings">' . "\n";
echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlescape($csrf_token) . '">' . "\n";
echo '<label class="switch"><input type="checkbox" name="show_map_button" value="1"'
    . ($show_map ? ' checked' : '') . '> Afficher le bouton « Afficher sur la carte » dans les listes de résultats</label>' . "\n";
echo '<p class="muted">Décoché (recommandé) : le sélecteur tableau/carte est masqué. '
    . 'La vue tableau et le bouton « Afficher sous forme de tableau » restent disponibles.</p>' . "\n";
echo '<button type="submit" class="btn-primary">Enregistrer</button>' . "\n";
echo '</form>' . "\n";
echo '</div>' . "\n";

echo '<p class="muted">Référence : <code>branding</code></p>' . "\n";
echo '</div></body></html>' . "\n";
