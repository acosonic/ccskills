<?php

// Vodič (public/js/tour.js). 'pages': regex putanje => koraci [selektor, naslov, tekst, strana?].
// Za svaku novu stranicu aplikacije dodajte ovde korake (i u lang/en/tour.php).
return [
    'next'     => 'Sledeće →',
    'prev'     => '← Nazad',
    'done'     => 'Završi ✓',
    'progress' => '{{current}} / {{total}}',

    'common' => [
        'welcome'  => ['👋 Dobrodošli', 'Kratak vodič pokazuje kako je aplikacija organizovana. Pre početka rada potrebno je da ga prođete do kraja — traje oko minut. Kliknite <strong>Sledeće</strong>.'],
        'sidebar'  => ['🧭 Glavni meni', 'Sve celine aplikacije su u meniju, grupisane po nameni.'],
        'toggle'   => ['↔️ Sakrij meni', 'Ovim dugmetom meni sklapate na same ikonice i dobijate više prostora. Na telefonu se meni otvara ovde.'],
        'language' => ['🌐 Jezik', 'Prebacivanje između srpskog i engleskog. Izbor se pamti za vaš nalog.'],
        'theme'    => ['🌙 Svetla i tamna tema', 'Tamna tema je prijatnija za rad uveče.'],
        'help'     => ['❓ Vodič', 'Vodič možete pokrenuti ponovo u svakom trenutku — na svakoj stranici objašnjava šta se na njoj nalazi.'],
        'user'     => ['👤 Vaš nalog', 'Ovde su vaše ime i uloga, i odjava iz aplikacije.'],
    ],

    'pages' => [
        '^/(dashboard)?$' => [
            ['.kpi-card', '📊 Pokazatelji', 'Najvažnije brojke na jednom mestu. Klik na karticu otvara odgovarajući spisak.'],
        ],
        '^/users$' => [
            ['.app-content a[href$="/users/ldap-sync"]', '🏢 Uvoz iz Active Directory', 'Zaposleni se uvoze iz AD-a i prijavljuju svojim domen nalogom — lozinka se proverava u AD-u.', 'left'],
            ['.app-content a[href$="/users/create"]', '➕ Novi korisnik', 'Lokalni nalog ili AD nalog, sa ulogom administrator, supervizor ili radnik.', 'left'],
            ['.app-content form[action*="toggle-active"]', '⏯️ Aktiviraj / deaktiviraj', 'Deaktiviran korisnik ne može da se prijavi, a podaci ostaju sačuvani.', 'left'],
        ],
    ],

    'finish' => ['✅ To je to!', 'Spremni ste za rad. Vodič je uvek dostupan preko dugmeta <strong>?</strong> u gornjem desnom uglu.'],
];
