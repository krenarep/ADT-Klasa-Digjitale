<?php
// Kopjojeni këtë skedar si config.php dhe plotësoni të dhënat.
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'klasa_digjitale',
        'user'    => 'root',
        'pass'    => '',
    ],
    // Rruga bazë e aplikacionit në URL (p.sh. '' nëse është në rrënjë, '/klasa' nëse është në nën-dosje)
    'base_url'   => '',
    'app_name'   => 'Klasa Digjitale',
    'timezone'   => 'Europe/Belgrade',
    // Madhësia maksimale e paketës SCORM (MB). Kontrolloni edhe upload_max_filesize / post_max_size në php.ini
    'max_upload_mb' => 200,
];
