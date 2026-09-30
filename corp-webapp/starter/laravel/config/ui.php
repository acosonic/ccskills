<?php

// public/js/app-ui.js: which URLs open in a modal (short forms) or a side sheet (record details).
// JavaScript regular expressions matched against location.pathname.
return [
    'modal' => [
        '^/users/(create|\\d+/edit)/?$',
    ],
    'sheet' => [
        // e.g. '^/(equipment|sites)/\\d+/?$'
    ],
];
