<?php

// Active Directory login and user import (read-only; never writes to the directory).
// Service account finds the user, the user's own bind checks the password.
return [
    'enabled'      => (bool) env('LDAP_ENABLED', false),
    'host'         => env('LDAP_HOST', 'dc01.corp.example'),
    'port'         => (int) env('LDAP_PORT', 389),
    'bind_user'    => env('LDAP_BIND_USER', ''),
    'bind_pass'    => env('LDAP_BIND_PASS', ''),
    'base_dn'      => env('LDAP_BASE_DN', 'DC=corp,DC=example'),
    'search_ou'    => env('LDAP_SEARCH_OU', 'OU=Users,DC=corp,DC=example'),
    'user_filter'  => env('LDAP_USER_FILTER', '(&(objectClass=user)(objectCategory=person)(givenName=*)(sn=*))'),
    'default_role' => env('LDAP_DEFAULT_ROLE', 'worker'),
    'auto_create'  => (bool) env('LDAP_AUTO_CREATE', false),
    'domain_label' => env('LDAP_DOMAIN_LABEL', 'corp.example'),
];
