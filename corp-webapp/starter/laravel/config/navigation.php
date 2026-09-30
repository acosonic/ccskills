<?php

// Sidebar menu. Groups are translation keys (lang/*/app.php), items:
// [route name, active pattern, bootstrap icon, translation key, allowed roles (null = everyone)].
return [
    'nav_group_operations' => [
        ['dashboard', 'dashboard', 'bi-grid-1x2', 'dashboard', null],
    ],
    'nav_group_admin' => [
        ['users.index', 'users.*', 'bi-people', 'users', ['admin']],
    ],
];
