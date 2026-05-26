<?php

declare(strict_types=1);

namespace MediaManager\Auth;

use MediaManager\Repositories\LdapRepository;

final class LdapService
{
    public function __construct(
        private readonly LdapRepository $ldap = new LdapRepository()
    ) {
    }

    public function authenticate(string $usernameOrEmail, string $password): ?array
    {
        if (!$this->ldap->isEnabled()) {
            return null;
        }

        if (!function_exists('ldap_connect')) {
            error_log('[ldap] PHP LDAP extension is not available.');
            return null;
        }

        $config = $this->ldap->getSettings();
        $host = (string) ($config['host'] ?? '');
        if ($host === '') {
            return null;
        }

        $connection = @ldap_connect($host, (int) ($config['port'] ?? 389));
        if ($connection === false) {
            error_log('[ldap] Connection failed for host: ' . $host);
            return null;
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

        $bindDn = str_replace('{username}', $usernameOrEmail, (string) ($config['bind_dn_pattern'] ?? ''));
        if (!@ldap_bind($connection, $bindDn, $password)) {
            @ldap_unbind($connection);
            return null;
        }

        $filterTemplate = (string) ($config['user_search_filter'] ?? '(sAMAccountName={username})');
        if (!str_contains($filterTemplate, '{username}')) {
            @ldap_unbind($connection);
            return null;
        }

        $filter = str_replace(
            '{username}',
            ldap_escape($usernameOrEmail, '', LDAP_ESCAPE_FILTER),
            $filterTemplate
        );

        $result = @ldap_search(
            $connection,
            (string) ($config['search_base_dn'] ?? ''),
            $filter,
            ['mail', 'cn', 'memberOf']
        );
        if ($result === false) {
            @ldap_unbind($connection);
            return null;
        }

        $entries = ldap_get_entries($connection, $result);
        @ldap_unbind($connection);

        if (!is_array($entries) || ($entries['count'] ?? 0) < 1) {
            return null;
        }

        $entry = $entries[0];
        $email = isset($entry['mail'][0]) ? (string) $entry['mail'][0] : '';
        if ($email === '') {
            return null;
        }

        $groups = [];
        if (isset($entry['memberof']) && is_array($entry['memberof'])) {
            foreach ($entry['memberof'] as $idx => $groupValue) {
                if ($idx === 'count' || !is_string($groupValue)) {
                    continue;
                }
                $groups[] = $groupValue;
            }
        }

        return [
            'email' => $email,
            'name' => isset($entry['cn'][0]) ? (string) $entry['cn'][0] : $email,
            'role' => $this->mapRole($groups),
        ];
    }

    /** @param list<string> $groups */
    private function mapRole(array $groups): string
    {
        foreach ($this->ldap->roleMappings() as $mapping) {
            $ldapGroup = (string) ($mapping['ldap_group'] ?? '');
            $role = (string) ($mapping['role'] ?? '');
            if ($ldapGroup === '' || !in_array($role, ['admin', 'editor'], true)) {
                continue;
            }
            foreach ($groups as $group) {
                if (stripos($group, $ldapGroup) !== false) {
                    return $role;
                }
            }
        }

        return 'editor';
    }
}
