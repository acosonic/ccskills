<?php

// Leaflet base map. CARTO (Positron / Dark Matter) needs a key bound to the app's domain
// (https://dashboard.basemaps.carto.com); without it, or on another domain, maps fall back to OSM.
return [
    'carto_key' => env('CARTO'),
    // Default map centre — set to the organisation's city (MAP_CENTER="lat,lng").
    'center'    => array_map('floatval', explode(',', env('MAP_CENTER', '51.5074,-0.1278'))),
];
