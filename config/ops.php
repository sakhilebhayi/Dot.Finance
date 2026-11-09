<?php

return [
    /*
     | Where scripts/ops/backup.sh keeps its sets (a folder outside every web root). Left unset, finance:ops:backup-check does
     | nothing: it is a watchdog for a backup you have scheduled, not a backup of its own.
     */
    'backup_dir' => env('BACKUP_DIR'),

    // A set older than this many hours means the nightly backup stopped (30 = one daily run plus slack).
    'backup_max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 30),

    /*
     | The most rows one CSV import may hold through the web page. Each row is written and journalled (about 3 ms a row on a
     | fast machine, more on shared hosting), and a web request is cut off after the host's time limit. A bigger file is
     | imported from the command line: php artisan finance:transactions:import FILE --account=ID --ledger=company:ID
     */
    'import_max_rows' => (int) env('IMPORT_MAX_ROWS', 5000),
];
