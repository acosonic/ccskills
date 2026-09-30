<?php

namespace App\Services;

/**
 * Active Directory helper — READ-ONLY, never modifies the directory.
 * Flow:
 *  1. bind with the service account and look up the user's DN + attributes,
 *  2. bind again as that DN with the user's password to verify it.
 */
class LdapService
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('ldap');
    }

    public function enabled(): bool
    {
        return $this->cfg['enabled'] && function_exists('ldap_connect') && $this->cfg['host'];
    }

    /** @return \LDAP\Connection|null */
    private function connect()
    {
        $conn = @ldap_connect('ldap://' . $this->cfg['host'] . ':' . ($this->cfg['port'] ?: 389));
        if (!$conn) return null;
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
        return $conn;
    }

    /** Connect and bind with the service account (read-only). */
    private function serviceBind()
    {
        $conn = $this->connect();
        if (!$conn || !@ldap_bind($conn, $this->cfg['bind_user'], $this->cfg['bind_pass'])) {
            return null;
        }
        return $conn;
    }

    /** @return array{ok: bool, msg: string, users: int} */
    public function testConnection(): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'msg' => __('app.ldap_not_configured'), 'users' => 0];
        }
        $conn = $this->serviceBind();
        if (!$conn) {
            return ['ok' => false, 'msg' => __('app.ldap_bind_failed'), 'users' => 0];
        }
        $s = @ldap_search($conn, $this->cfg['search_ou'] ?: $this->cfg['base_dn'], $this->cfg['user_filter'], ['sAMAccountName']);
        $count = $s ? ldap_count_entries($conn, $s) : 0;
        ldap_unbind($conn);
        return ['ok' => true, 'msg' => __('app.ldap_connection_ok', ['count' => $count]), 'users' => $count];
    }

    /**
     * Authenticate against AD. Returns ['username','ime','prezime','mail'] on success, null otherwise.
     */
    public function authenticate(string $username, string $password): ?array
    {
        if (!$this->enabled() || $username === '' || $password === '') return null;

        // "CORP\user" or "user@corp.example" → "user"
        $username = preg_replace('/^.*\\\\/', '', $username);
        $username = preg_replace('/@.*$/', '', $username);

        $conn = $this->serviceBind();
        if (!$conn) return null;

        $safe = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
        $s = @ldap_search($conn, $this->cfg['base_dn'], "(&(objectClass=user)(sAMAccountName={$safe}))",
            ['distinguishedName', 'sAMAccountName', 'givenName', 'sn', 'mail', 'userAccountControl'], 0, 1);
        $entries = $s ? ldap_get_entries($conn, $s) : ['count' => 0];
        ldap_unbind($conn);

        if (($entries['count'] ?? 0) < 1) return null;
        $e   = $entries[0];
        $dn  = $e['distinguishedname'][0] ?? $e['dn'];
        $uac = (int) ($e['useraccountcontrol'][0] ?? 0);
        if ($uac & 2) return null; // disabled in AD

        // Verify the password with the user's own bind (standard AD authentication, read-only).
        $userConn = $this->connect();
        if (!$userConn) return null;
        $ok = @ldap_bind($userConn, $dn, $password);
        ldap_unbind($userConn);
        if (!$ok) return null;

        return [
            'username' => $e['samaccountname'][0] ?? $username,
            'ime'      => $e['givenname'][0] ?? '',
            'prezime'  => $e['sn'][0] ?? '',
            'mail'     => $e['mail'][0] ?? '',
        ];
    }

    /**
     * All users in the configured OU, for the import screen. Never writes to AD.
     *
     * @return array<int, array{username: string, ime: string, prezime: string, mail: string, department: string, disabled: bool}>
     */
    public function users(): array
    {
        $conn = $this->enabled() ? $this->serviceBind() : null;
        if (!$conn) return [];

        $s = @ldap_search($conn, $this->cfg['search_ou'] ?: $this->cfg['base_dn'], $this->cfg['user_filter'],
            ['sAMAccountName', 'givenName', 'sn', 'mail', 'userAccountControl', 'department'], 0, 2000);
        $entries = $s ? ldap_get_entries($conn, $s) : ['count' => 0];
        ldap_unbind($conn);

        $users = [];
        for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
            $e = $entries[$i];
            $users[] = [
                'username'   => $e['samaccountname'][0] ?? '',
                'ime'        => $e['givenname'][0] ?? '',
                'prezime'    => $e['sn'][0] ?? '',
                'mail'       => $e['mail'][0] ?? '',
                'department' => $e['department'][0] ?? '',
                'disabled'   => (bool) (((int) ($e['useraccountcontrol'][0] ?? 0)) & 2),
            ];
        }

        usort($users, fn($a, $b) => strcmp(
            mb_strtolower($a['prezime'] . $a['ime']),
            mb_strtolower($b['prezime'] . $b['ime'])
        ));

        return $users;
    }
}
