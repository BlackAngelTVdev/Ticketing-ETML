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
 * Menu:   Setup > Branding (entry added by plugin_branding_redefine_menus,
 *         right below "Plugins"), also linked from Setup > Plugins.
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

Html::header('Branding', $here, 'config', 'branding', '', false);
?>
<style>
    .branding-thumbs { max-width: 180px; max-height: 70px; background: #f4f4f6; border: 1px solid #ececf0; border-radius: 4px; padding: 4px; }
    .branding-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .branding-tag { display: inline-block; border-radius: 4px; padding: 1px 6px; font-size: .72rem; }
    .branding-tag-stored { background: #dcfce7; color: #166534; }
    .branding-tag-bundled { background: #e0e7ff; color: #3730a3; }
    .branding-tag-none { background: #f3f4f6; color: #6b7280; }
</style>

<div class="card">
    <h2>Logos</h2>
    <p class="text-muted">
        Téléversez un logo pour remplacer celui de GLPI. Les formats acceptés sont
        <?php echo htmlescape(implode(', ', $options)); ?> (2 Mo maximum).
        « Logo principal » suffit : les autres retombent dessus tant qu'ils ne sont pas personnalisés.
    </p>

    <?php if ($saved) { ?>
        <div class="alert alert-success">Modifications enregistrées.</div>
    <?php } ?>
    <?php foreach ($errors as $error) { ?>
        <div class="alert alert-danger"><?php echo htmlescape($error); ?></div>
    <?php } ?>

    <table class="table card-table">
        <thead>
            <tr>
                <th>Emplacement</th>
                <th>Aperçu</th>
                <th>Source</th>
                <th>Changer</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (Logo::LABELS as $name => $label) {
            $stored  = Logo::stored($name);
            $bundled = Logo::bundled($name);
            $current = $stored ?? $bundled; ?>
            <tr>
                <td>
                    <?php echo htmlescape($label); ?><br>
                    <code>logo<?php echo $name === 'logo' ? '' : '-' . htmlescape($name); ?>.<?php echo htmlescape(implode('|', Logo::extensions($name))); ?></code>
                </td>

                <td>
                <?php if ($current !== null) {
                    $preview = $root . '/plugins/branding/front/logo.php?kind=' . rawurlencode($name)
                        . '&v=' . rawurlencode((string) @filemtime($current)); ?>
                    <img class="branding-thumbs" src="<?php echo htmlescape($preview); ?>" alt="">
                <?php } else { ?>
                    <span class="text-muted">—</span>
                <?php } ?>
                </td>

                <td>
                <?php if ($stored !== null) { ?>
                    <span class="branding-tag branding-tag-stored">Téléversé</span>
                <?php } elseif ($bundled !== null) { ?>
                    <span class="branding-tag branding-tag-bundled">Fourni avec le plugin</span>
                <?php } else { ?>
                    <span class="branding-tag branding-tag-none">Logo GLPI</span>
                <?php } ?>
                </td>

                <td>
                    <div class="branding-actions">
                        <form method="post" action="<?php echo htmlescape($here); ?>" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload">
                            <input type="hidden" name="name" value="<?php echo htmlescape($name); ?>">
                            <input type="hidden" name="_glpi_csrf_token" value="<?php echo htmlescape($csrf_token); ?>">
                            <input type="file" name="logo" accept="image/*,.ico" required>
                            <button type="submit" class="btn btn-sm btn-primary">Téléverser</button>
                        </form>

                    <?php if ($stored !== null) { ?>
                        <form method="post" action="<?php echo htmlescape($here); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="name" value="<?php echo htmlescape($name); ?>">
                            <input type="hidden" name="_glpi_csrf_token" value="<?php echo htmlescape($csrf_token); ?>">
                            <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Supprimer ce logo ?');">Supprimer</button>
                        </form>
                    <?php } ?>
                    </div>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <p class="text-muted">
        Les logos téléversés sont enregistrés dans
        <code><?php echo htmlescape(Logo::storageDir()); ?></code> (hors du code du plugin).
        Un fichier fourni avec le plugin ne peut pas être supprimé depuis cette page.
    </p>
</div>

<div class="card">
    <h2>Interface</h2>
    <form method="post" action="<?php echo htmlescape($here); ?>">
        <input type="hidden" name="action" value="settings">
        <input type="hidden" name="_glpi_csrf_token" value="<?php echo htmlescape($csrf_token); ?>">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="show_map_button" value="1" id="show_map_button"
                <?php echo Settings::showMapButton() ? 'checked' : ''; ?>>
            <label class="form-check-label" for="show_map_button">
                Afficher le bouton « Afficher sur la carte » dans les listes de résultats
            </label>
        </div>
        <p class="text-muted">
            Décoché (recommandé) : le sélecteur tableau/carte est masqué.
            La vue tableau et le bouton « Afficher sous forme de tableau » restent disponibles.
        </p>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>
</div>
<?php
Html::footer();