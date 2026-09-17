<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI SSO Bridge plugin - GLPI login service
 *
 * After the SSO portal validated the user, this service:
 *   1. finds the matching GLPI user (email first, then username);
 *   2. creates the user automatically when no match exists (optional);
 *   3. attaches the SSO email to the account when missing;
 *   4. starts a real GLPI session (same machinery as Auth::login(),
 *      including the 2FA / MFA flow) and redirects to GLPI.
 *
 * On failure it returns a list of human-readable error messages; the caller
 * renders them. On success it never returns: a redirect is always thrown
 * (directly, or through the GLPI MFA pages).
 *
 * ---------------------------------------------------------------------
 */

namespace GlpiPlugin\Ssobridge;

use Auth;
use Glpi\Security\TOTPManager;
use Html;
use Profile;
use Session;
use Toolbox;
use User;

final class GlpiLoginService
{
    /**
     * @param string      $email    Email provided by the SSO portal (may be empty).
     * @param string      $username Login provided by the SSO portal (may be empty).
     * @param string|null $redirect GLPI target after login (local path like
     *                              /ServiceCatalog or /Helpdesk, or a same-host
     *                              absolute URL). Kept in session so it survives
     *                              the GLPI MFA pages round-trip.
     *
     * @return string[] Error messages (empty only when this method cannot
     *                  finish: the success path always throws a redirect).
     */
    public static function login(string $email, string $username, ?string $redirect = null): array
    {
        global $DB;

        $email    = trim($email);
        $username = trim($username);

        if ($email === '' && $username === '') {
            return ['The SSO portal did not provide any usable identity (no email and no username).'];
        }

        // ------------------------------------------------------------------
        // 1. Find the GLPI user (email first, then username).
        // ------------------------------------------------------------------
        $user      = new User();
        $userFound = false;

        if ($email !== '') {
            $ids = User::getUsersIdByEmails($email);
            if (count($ids) === 1) {
                $userFound = $user->getFromDB((int) $ids[0]);
            } elseif (count($ids) > 1) {
                // Ambiguous email: several GLPI accounts share it, fall back to username.
                $userFound = false;
            }
        }
        if (!$userFound && $username !== '') {
            $userFound = $user->getFromDBByCrit([
                'name'       => $username,
                'is_deleted' => 0,
            ]);
        }

        $userCreated = false;
        if ($userFound) {
            if ((int) $user->fields['is_active'] !== 1) {
                return [sprintf(
                    'The GLPI account "%s" is deactivated.',
                    $user->fields['name']
                )];
            }
            if ((int) $user->fields['is_deleted'] === 1) {
                return [sprintf(
                    'The GLPI account "%s" is deleted.',
                    $user->fields['name']
                )];
            }
        } else {
            if (!Config::autoCreateUsers()) {
                return [
                    sprintf('No GLPI user matches "%s" and user auto-creation is disabled.', $username !== '' ? $username : $email),
                ];
            }

            $userId = self::createUser($email, $username);
            if ($userId === null || !$user->getFromDB($userId)) {
                return ['The GLPI user could not be created automatically. Contact your administrator.'];
            }
            $userCreated = true;
        }

        $usersId = (int) $user->fields['id'];

        // ------------------------------------------------------------------
        // 2. Make sure the SSO email is attached to the account.
        // ------------------------------------------------------------------
        if ($email !== '') {
            self::attachEmail($usersId, $email);
        }

        // ------------------------------------------------------------------
        // 3. Update "last login" (like Auth::login() does for known users).
        // ------------------------------------------------------------------
        if (!$userCreated) {
            (new User())->update([
                'id'              => $usersId,
                'last_login'      => date('Y-m-d H:i:s'),
                'is_deleted_ldap' => 0,
            ]);
        }

        // ------------------------------------------------------------------
        // 4. Build the Auth object and let Session::init() do the rest
        //    (profiles, entities, language, plugins hooks, ...).
        // ------------------------------------------------------------------
        $auth = new Auth();
        $auth->user          = $user;
        $auth->user_present  = true;
        $auth->extauth       = 1;
        $auth->auth_succeded = true;

        // Respect the GLPI MFA flow exactly like Auth::login(): when the user
        // has (or must set up) a second factor, defer the session creation to
        // the GLPI MFA pages. When MFA succeeds there, GLPI finishes the login.
        $check_mfa = !isAPI() && !isCommandLine();
        if ($check_mfa) {
            $totp = new TOTPManager();

            $mfa_pre_auth = [
                'user_id'     => $usersId,
                'username'    => $user->fields['name'],
                'remember_me' => false,
                'noauto'      => false,
                'redirect'    => $redirect,
            ];

            if ($totp->is2FAEnabled($usersId)) {
                $_SESSION['mfa_pre_auth'] = $mfa_pre_auth;
                Html::redirect(self::rootDoc() . '/MFA/Prompt');
            }

            if ($totp->get2FAEnforcement($usersId) !== TOTPManager::ENFORCEMENT_OPTIONAL) {
                $_SESSION['mfa_pre_auth'] = $mfa_pre_auth;
                Html::redirect(self::rootDoc() . '/MFA/Setup');
            }
        }

        Session::init($auth);

        if (!$auth->auth_succeded) {
            // Typically: the account has no profile / no right to connect.
            $errors = $auth->getErrors();
            return array_map('strval', count($errors) > 0 ? $errors : ['You are not allowed to connect to GLPI.']);
        }

        if ($redirect !== null && $redirect !== '') {
            unset($_SESSION[SsoClient::REDIRECT_KEY]);
            Toolbox::manageRedirect($redirect);
        }

        Auth::redirectIfAuthenticated();

        // Should never be reached (redirectIfAuthenticated throws when logged in).
        return ['SSO login succeeded but the redirect failed. Please retry.'];
    }

    /**
     * Create a GLPI user from the SSO identity.
     */
    private static function createUser(string $email, string $username): ?int
    {
        global $DB;

        if ($username === '' || !Auth::isValidLogin($username)) {
            // GLPI logins must respect a strict format; the email address can
            // be used as a login fallback when it is itself a valid login.
            if ($email !== '' && Auth::isValidLogin($email)) {
                $username = $email;
            } else {
                return null;
            }
        }

        // Guard against name collisions (also check deleted users, since the
        // unique key is on name + authtype + auths_id).
        $iterator = $DB->request([
            'FROM'  => User::getTable(),
            'WHERE' => ['name' => $username],
            'LIMIT' => 1,
        ]);
        if (count($iterator) > 0) {
            return null;
        }

        $user = new User();
        $userId = $user->add([
            'name'            => $username,
            'authtype'        => Auth::EXTERNAL,
            'auths_id'        => 0,
            'entities_id'     => 0,
            'is_active'       => 1,
            'is_deleted'      => 0,
            'is_deleted_ldap' => 0,
        ]);

        if ($userId === false || $user->isNewItem()) {
            return null;
        }

        $userId = (int) $userId;

        // Give the new account a profile (otherwise GLPI refuses the session).
        self::assignDefaultProfile($userId, (int) $user->fields['entities_id']);

        return $userId;
    }

    /**
     * Assign the configured profile to an auto-created user.
     * SSO_DEFAULT_PROFILE: '' => GLPI default profile, 'none' => no profile,
     * otherwise a profile name.
     */
    private static function assignDefaultProfile(int $usersId, int $entitiesId): void
    {
        global $DB;

        $setting = strtolower(Config::defaultProfile());
        if ($setting === 'none') {
            return;
        }

        $profilesId = 0;
        if ($setting === '') {
            $profilesId = (int) Profile::getDefault();
        } else {
            $iterator = $DB->request([
                'SELECT' => 'id',
                'FROM'   => Profile::getTable(),
                'WHERE'  => ['name' => $setting],
                'LIMIT'  => 1,
            ]);
            foreach ($iterator as $row) {
                $profilesId = (int) $row['id'];
            }
        }

        if ($profilesId <= 0) {
            return;
        }

        // Idempotent: repeated SSO logins must not stack duplicate profile rows.
        $iterator = $DB->request([
            'FROM'  => 'glpi_profiles_users',
            'WHERE' => [
                'users_id'     => $usersId,
                'profiles_id'  => $profilesId,
                'entities_id'  => $entitiesId,
                'is_recursive' => 0,
            ],
            'LIMIT' => 1,
        ]);
        if (count($iterator) > 0) {
            return;
        }

        $DB->insert('glpi_profiles_users', [
            'users_id'     => $usersId,
            'profiles_id'  => $profilesId,
            'entities_id'  => $entitiesId,
            'is_recursive' => 0,
            'is_dynamic'   => 0,
        ]);
    }

    /**
     * Attach an email to a user when it is not yet present anywhere.
     */
    private static function attachEmail(int $usersId, string $email): void
    {
        global $DB;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $iterator = $DB->request([
            'FROM'  => 'glpi_useremails',
            'WHERE' => ['email' => $email],
            'LIMIT' => 1,
        ]);
        if (count($iterator) > 0) {
            // Email already exists (either for this user, or for another one:
            // GLPI emails are unique, keep the existing binding).
            return;
        }

        $default = 0;
        $count = $DB->request([
            'FROM'  => 'glpi_useremails',
            'WHERE' => ['users_id' => $usersId],
        ]);
        if (count($count) === 0) {
            // First email of the user -> make it the default one.
            $default = 1;
        }

        $DB->insert('glpi_useremails', [
            'users_id'   => $usersId,
            'email'      => $email,
            'is_default' => $default,
        ]);
    }

    /**
     * GLPI base path (root_doc), for building absolute-ish redirect URLs.
     */
    private static function rootDoc(): string
    {
        global $CFG_GLPI;

        if (isset($CFG_GLPI['root_doc']) && $CFG_GLPI['root_doc'] !== '') {
            return rtrim($CFG_GLPI['root_doc'], '/');
        }

        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
        return rtrim(preg_replace('#/plugins/ssobridge/front/?.*$#', '', $script), '/');
    }
}
