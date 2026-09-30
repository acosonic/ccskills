<?php

// Organisation branding. Rebrand = set ORG_* in .env, replace the files in public/images/
// and change --app-brand in public/css/theme.css.
return [
    'org'        => env('ORG_NAME', 'Generic Corp'),
    'location'   => env('ORG_LOCATION', ''),                 // e.g. "Springfield"; shown next to the name
    'logo'       => 'images/brand-logo.svg',                 // full logo (login form)
    'mark'       => 'images/brand-mark.svg',                 // square mark on white
    'mark_white' => 'images/brand-mark-white.svg',           // square mark on the brand colour
];
