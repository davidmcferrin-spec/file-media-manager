<?php

declare(strict_types=1);

namespace MediaManager\Controllers;

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Repositories\AuditRepository;
use MediaManager\Repositories\ConversionRuleRepository;
use MediaManager\Repositories\IgnorePathRepository;
use MediaManager\Repositories\LdapRepository;
use MediaManager\Repositories\MediaTypeRepository;
use MediaManager\Repositories\ShowRepository;
use MediaManager\Repositories\SourceRepository;
use MediaManager\Repositories\UserRepository;
use PDOException;

Auth::requireAdmin();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($uri === '/settings') {
    header('Location: /settings/sources');
    exit;
}

$sourceRepo     = new SourceRepository();
$conversionRepo = new ConversionRuleRepository();
$mediaTypeRepo  = new MediaTypeRepository();
$showRepo       = new ShowRepository();
$ldapRepo       = new LdapRepository();
$ignoreRepo     = new IgnorePathRepository();
$userRepo       = new UserRepository();
$audit          = new AuditRepository();

function settings_audit(
    AuditRepository $audit,
    string $action,
    string $entityType,
    ?int $entityId = null,
    array $details = []
): void {
    $user = Auth::user();
    $audit->record(
        Auth::id(),
        $user['email'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        $action,
        $entityType,
        $entityId,
        null,
        null,
        $details
    );
}

if ($method === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Session::validateCsrf($csrf)) {
        Session::flash('error', 'Invalid request. Please try again.');
        header('Location: ' . $uri);
        exit;
    }

    // ── NAS sources ──────────────────────────────────────────
    if ($uri === '/settings/sources') {
        $id          = (int) ($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $mountPath   = trim($_POST['mount_path'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sourceCode  = trim($_POST['source_code'] ?? '');
        $active      = isset($_POST['active']);

        if ($id <= 0 || $name === '' || $mountPath === '') {
            Session::flash('error', 'Source name and mount path are required.');
        } elseif ($sourceRepo->update($id, $name, $mountPath, $description, $sourceCode, $active)) {
            settings_audit($audit, 'SOURCE_UPDATED', 'source', $id, [
                'name'       => $name,
                'mount_path' => $mountPath,
                'active'     => $active,
            ]);
            Session::flash('success', 'NAS source updated.');
        } else {
            Session::flash('error', 'Could not update source.');
        }
        header('Location: /settings/sources');
        exit;
    }

    // ── Conversion rules ─────────────────────────────────────
    if ($uri === '/settings/conversions/create') {
        $category    = $_POST['category'] ?? '';
        $alias       = trim($_POST['alias'] ?? '');
        $showId      = (int) ($_POST['show_id'] ?? 0);
        $mediaTypeId = (int) ($_POST['media_type_id'] ?? 0);
        $notes       = trim($_POST['notes'] ?? '');

        if ($alias === '') {
            Session::flash('error', 'Alias is required.');
        } elseif ($category === 'show' && $showId > 0) {
            try {
                $id = $conversionRepo->createShowRule($alias, $showId, $notes);
                settings_audit($audit, 'CONVERSION_CREATED', 'conversion_rule', $id, [
                    'category' => 'show',
                    'alias'    => ConversionRuleRepository::normalizeAlias($alias),
                ]);
                Session::flash('success', 'Show conversion rule added.');
            } catch (PDOException $e) {
                Session::flash('error', $conversionRepo->isUniqueViolation($e)
                    ? 'That alias already exists.'
                    : 'Could not create rule.');
            }
        } elseif ($category === 'media_type' && $mediaTypeId > 0) {
            try {
                $id = $conversionRepo->createMediaTypeRule($alias, $mediaTypeId, $notes);
                settings_audit($audit, 'CONVERSION_CREATED', 'conversion_rule', $id, [
                    'category' => 'media_type',
                    'alias'    => ConversionRuleRepository::normalizeAlias($alias),
                ]);
                Session::flash('success', 'Media type conversion rule added.');
            } catch (PDOException $e) {
                Session::flash('error', $conversionRepo->isUniqueViolation($e)
                    ? 'That alias already exists.'
                    : 'Could not create rule.');
            }
        } else {
            Session::flash('error', 'Select a valid target for the conversion rule.');
        }
        header('Location: /settings/conversions');
        exit;
    }

    if ($uri === '/settings/conversions/update') {
        $id          = (int) ($_POST['id'] ?? 0);
        $alias       = trim($_POST['alias'] ?? '');
        $showId      = (int) ($_POST['show_id'] ?? 0);
        $mediaTypeId = (int) ($_POST['media_type_id'] ?? 0);
        $notes       = trim($_POST['notes'] ?? '');
        $active      = isset($_POST['active']);

        $existing = $id > 0 ? $conversionRepo->findById($id) : null;
        if ($existing === null || $alias === '') {
            Session::flash('error', 'Invalid conversion rule.');
        } else {
            $category = (string) $existing['category'];
            $targetShowId = $category === 'show' ? ($showId > 0 ? $showId : null) : null;
            $targetMediaId = $category === 'media_type' ? ($mediaTypeId > 0 ? $mediaTypeId : null) : null;

            try {
                $conversionRepo->update($id, $alias, $targetShowId, $targetMediaId, $notes, $active);
                settings_audit($audit, 'CONVERSION_UPDATED', 'conversion_rule', $id);
                Session::flash('success', 'Conversion rule updated.');
            } catch (PDOException $e) {
                Session::flash('error', $conversionRepo->isUniqueViolation($e)
                    ? 'That alias already exists.'
                    : 'Could not update rule.');
            }
        }
        header('Location: /settings/conversions');
        exit;
    }

    if ($uri === '/settings/conversions/delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $conversionRepo->delete($id)) {
            settings_audit($audit, 'CONVERSION_DELETED', 'conversion_rule', $id);
            Session::flash('success', 'Conversion rule removed.');
        } else {
            Session::flash('error', 'Could not delete rule.');
        }
        header('Location: /settings/conversions');
        exit;
    }

    // ── Media types ──────────────────────────────────────────
    if ($uri === '/settings/media-types') {
        $id           = (int) ($_POST['id'] ?? 0);
        $name         = trim($_POST['name'] ?? '');
        $abbreviation = trim($_POST['abbreviation'] ?? '');
        $folderName   = trim($_POST['folder_name'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $active       = isset($_POST['active']);

        if ($id <= 0 || $name === '' || $abbreviation === '' || $folderName === '') {
            Session::flash('error', 'Name, abbreviation, and folder name are required.');
        } elseif ($mediaTypeRepo->update($id, $name, $abbreviation, $folderName, $description, $active)) {
            settings_audit($audit, 'MEDIA_TYPE_UPDATED', 'media_type', $id);
            Session::flash('success', 'Media type updated.');
        } else {
            Session::flash('error', 'Could not update media type.');
        }
        header('Location: /settings/media-types');
        exit;
    }

    // ── LDAP ─────────────────────────────────────────────────
    if ($uri === '/settings/ldap') {
        $ldapRepo->saveSettings([
            'enabled'            => isset($_POST['enabled']),
            'host'               => $_POST['host'] ?? '',
            'port'               => (int) ($_POST['port'] ?? 389),
            'bind_dn_pattern'    => $_POST['bind_dn_pattern'] ?? '',
            'search_base_dn'     => $_POST['search_base_dn'] ?? '',
            'user_search_filter' => $_POST['user_search_filter'] ?? '(sAMAccountName={username})',
        ]);
        settings_audit($audit, 'LDAP_SETTINGS_UPDATED', 'ldap_settings', 1);
        Session::flash('success', 'LDAP settings saved.');
        header('Location: /settings/ldap');
        exit;
    }

    if ($uri === '/settings/ldap/groups/add') {
        $group = trim($_POST['ldap_group'] ?? '');
        $role  = $_POST['role'] ?? 'editor';
        if ($group === '' || !in_array($role, ['admin', 'editor'], true)) {
            Session::flash('error', 'Valid group name and role are required.');
        } else {
            try {
                $id = $ldapRepo->addGroupRole($group, $role);
                settings_audit($audit, 'LDAP_GROUP_ADDED', 'ldap_group_role', $id, [
                    'ldap_group' => $group,
                    'role'       => $role,
                ]);
                Session::flash('success', 'LDAP group mapping added.');
            } catch (PDOException) {
                Session::flash('error', 'That group mapping already exists.');
            }
        }
        header('Location: /settings/ldap');
        exit;
    }

    if ($uri === '/settings/ldap/groups/delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $ldapRepo->deleteGroupRole($id)) {
            settings_audit($audit, 'LDAP_GROUP_DELETED', 'ldap_group_role', $id);
            Session::flash('success', 'LDAP group mapping removed.');
        } else {
            Session::flash('error', 'Could not remove group mapping.');
        }
        header('Location: /settings/ldap');
        exit;
    }

    if ($uri === '/settings/ignore-paths/create') {
        $sourceId = ($_POST['source_id'] ?? '') !== '' ? (int) $_POST['source_id'] : null;
        $path     = trim($_POST['path'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');

        if ($path === '') {
            Session::flash('error', 'Path is required.');
        } else {
            $id = $ignoreRepo->create($sourceId, $path, $notes);
            settings_audit($audit, 'IGNORE_PATH_CREATED', 'scan_ignore_path', $id, [
                'path'      => $path,
                'source_id' => $sourceId,
            ]);
            Session::flash('success', 'Ignore path added.');
        }
        header('Location: /settings/ignore-paths');
        exit;
    }

    if ($uri === '/settings/ignore-paths/update') {
        $id       = (int) ($_POST['id'] ?? 0);
        $sourceId = ($_POST['source_id'] ?? '') !== '' ? (int) $_POST['source_id'] : null;
        $path     = trim($_POST['path'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        $active   = isset($_POST['active']);

        if ($id <= 0 || $path === '') {
            Session::flash('error', 'Invalid ignore path.');
        } elseif ($ignoreRepo->update($id, $sourceId, $path, $notes, $active)) {
            settings_audit($audit, 'IGNORE_PATH_UPDATED', 'scan_ignore_path', $id);
            Session::flash('success', 'Ignore path updated.');
        } else {
            Session::flash('error', 'Could not update ignore path.');
        }
        header('Location: /settings/ignore-paths');
        exit;
    }

    if ($uri === '/settings/ignore-paths/delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $ignoreRepo->delete($id)) {
            settings_audit($audit, 'IGNORE_PATH_DELETED', 'scan_ignore_path', $id);
            Session::flash('success', 'Ignore path removed.');
        } else {
            Session::flash('error', 'Could not remove ignore path.');
        }
        header('Location: /settings/ignore-paths');
        exit;
    }

    // ── Users ────────────────────────────────────────────────
    if ($uri === '/settings/users/create') {
        $email       = trim($_POST['email'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $role        = $_POST['role'] ?? 'editor';
        $password    = $_POST['password'] ?? '';

        if ($email === '' || $displayName === '' || strlen($password) < 8) {
            Session::flash('error', 'Email, name, and password (8+ chars) are required.');
        } elseif (!in_array($role, ['admin', 'editor'], true)) {
            Session::flash('error', 'Invalid role.');
        } elseif ($userRepo->findByEmail($email) !== null) {
            Session::flash('error', 'A user with that email already exists.');
        } else {
            $id = $userRepo->createLocal($email, $password, $displayName, $role);
            settings_audit($audit, 'USER_CREATED', 'user', $id, ['email' => $email, 'role' => $role]);
            Session::flash('success', 'User created.');
        }
        header('Location: /settings/users');
        exit;
    }

    if ($uri === '/settings/users/update') {
        $id          = (int) ($_POST['id'] ?? 0);
        $displayName = trim($_POST['display_name'] ?? '');
        $role        = $_POST['role'] ?? 'editor';
        $active      = isset($_POST['active']);
        $password    = $_POST['password'] ?? '';
        $existing    = $id > 0 ? $userRepo->findById($id) : null;

        if ($existing === null || $displayName === '') {
            Session::flash('error', 'Invalid user.');
        } elseif ($id === Auth::id() && !$active) {
            Session::flash('error', 'You cannot deactivate your own account.');
        } elseif (!in_array($role, ['admin', 'editor'], true)) {
            Session::flash('error', 'Invalid role.');
        } else {
            if ($id === Auth::id()) {
                $active = true;
            }
            $userRepo->update($id, $displayName, $role, $active);
            if (($existing['auth_source'] ?? 'local') === 'local' && $password !== '' && strlen($password) >= 8) {
                $userRepo->updatePassword($id, $password);
            }
            settings_audit($audit, 'USER_UPDATED', 'user', $id, [
                'role'   => $role,
                'active' => $active,
            ]);
            Session::flash('success', 'User updated.');
        }
        header('Location: /settings/users');
        exit;
    }

    http_response_code(404);
    exit;
}

// ── GET views ─────────────────────────────────────────────────
$settingsTab = match (true) {
    str_starts_with($uri, '/settings/conversions') => 'conversions',
    str_starts_with($uri, '/settings/media-types') => 'media-types',
    str_starts_with($uri, '/settings/ignore-paths') => 'ignore-paths',
    str_starts_with($uri, '/settings/ldap') => 'ldap',
    str_starts_with($uri, '/settings/users') => 'users',
    default => 'sources',
};

$title = 'Settings — Media Manager';

require dirname(__DIR__) . '/Views/layouts/header.php';
require dirname(__DIR__) . '/Views/settings/_nav.php';

match ($settingsTab) {
    'conversions' => (function () use ($conversionRepo, $showRepo, $mediaTypeRepo): void {
        $rules      = $conversionRepo->all();
        $shows      = $showRepo->all(true);
        $mediaTypes = $mediaTypeRepo->all(true);
        require dirname(__DIR__) . '/Views/settings/conversions.php';
    })(),
    'media-types' => (function () use ($mediaTypeRepo): void {
        $mediaTypes = $mediaTypeRepo->all();
        require dirname(__DIR__) . '/Views/settings/media_types.php';
    })(),
    'ldap' => (function () use ($ldapRepo): void {
        $ldapSettings = $ldapRepo->getSettings();
        $groupRoles   = $ldapRepo->groupRoles();
        require dirname(__DIR__) . '/Views/settings/ldap.php';
    })(),
    'ignore-paths' => (function () use ($ignoreRepo, $sourceRepo): void {
        $ignorePaths = $ignoreRepo->all();
        $sources     = $sourceRepo->all();
        require dirname(__DIR__) . '/Views/settings/ignore_paths.php';
    })(),
    'users' => (function () use ($userRepo): void {
        $users      = $userRepo->all();
        $editUserId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
        $editUser   = $editUserId > 0 ? $userRepo->findById($editUserId) : null;
        require dirname(__DIR__) . '/Views/settings/users.php';
    })(),
    default => (function () use ($sourceRepo): void {
        $sources = $sourceRepo->all();
        require dirname(__DIR__) . '/Views/settings/sources.php';
    })(),
};

require dirname(__DIR__) . '/Views/layouts/footer.php';
