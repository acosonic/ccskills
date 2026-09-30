<?php

// Guided tour (public/js/tour.js). 'pages': path regex => steps [selector, title, text, side?].
// Add steps here (and in lang/sr/tour.php) for every new page of the application.
return [
    'next'     => 'Next →',
    'prev'     => '← Back',
    'done'     => 'Finish ✓',
    'progress' => '{{current}} / {{total}}',

    'common' => [
        'welcome'  => ['👋 Welcome', 'A short tour shows how the app is organised. You need to complete it before you start — it takes about a minute. Click <strong>Next</strong>.'],
        'sidebar'  => ['🧭 Main menu', 'All parts of the application are in the menu, grouped by purpose.'],
        'toggle'   => ['↔️ Collapse the menu', 'Collapse the menu to icons for more room. On a phone, the menu opens here.'],
        'language' => ['🌐 Language', 'Switch between Serbian and English. Your choice is remembered.'],
        'theme'    => ['🌙 Light and dark theme', 'The dark theme is easier on the eyes in the evening.'],
        'help'     => ['❓ Tour', 'You can restart the tour any time — on every page it explains what is there.'],
        'user'     => ['👤 Your account', 'Your name and role, and sign out.'],
    ],

    'pages' => [
        '^/(dashboard)?$' => [
            ['.kpi-card', '📊 Key figures', 'The most important numbers in one place. Click a card to open the list.'],
        ],
        '^/users$' => [
            ['.app-content a[href$="/users/ldap-sync"]', '🏢 Import from Active Directory', 'Staff are imported from AD and sign in with their domain account — the password is checked by AD.', 'left'],
            ['.app-content a[href$="/users/create"]', '➕ New user', 'A local or an AD account, with the admin, supervisor or worker role.', 'left'],
            ['.app-content form[action*="toggle-active"]', '⏯️ Activate / deactivate', 'A deactivated user cannot sign in; their data is kept.', 'left'],
        ],
    ],

    'finish' => ['✅ That’s it!', 'You are ready to go. The tour is always available from the <strong>?</strong> button in the top right corner.'],
];
