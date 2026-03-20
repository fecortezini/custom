#!/usr/bin/env php
<?php
/**
 * CLI script to activate LabApp, NFSe, and NFe modules.
 *
 * Run from Docker entrypoint AFTER the database is ready:
 *   php /var/www/html/htdocs/custom/labapp/scripts/activate_modules.php
 *
 * Safe to run multiple times (fully idempotent).
 */

// Suppress web-only requirements so main.inc.php loads cleanly in CLI
define('NOREQUIREHTML', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREUSER', '1');
define('NOREQUIRETRAN', '1');
define('NOTOKENRENEWAL', '1');
define('NOLOGIN', '1');
define('NOMULTILANG', '1');

// Locate and load Dolibarr bootstrap.
// Script lives at: htdocs/custom/labapp/scripts/activate_modules.php
// main.inc.php is at: htdocs/main.inc.php  →  three levels up
$res = false;
$candidates = array(
    __DIR__ . '/../../../main.inc.php',   // htdocs/custom/labapp/scripts → htdocs
    __DIR__ . '/../../../../main.inc.php', // one extra level (some installs)
);
foreach ($candidates as $path) {
    if (file_exists($path)) {
        $res = @include_once $path;
        if ($res !== false) {
            break;
        }
    }
}
if (!$res) {
    fwrite(STDERR, "ERROR: Could not locate main.inc.php. Run this script from the Dolibarr htdocs tree.\n");
    exit(1);
}

// Modules to activate in order (LabApp first — others may depend on its extrafields)
$modules = array(
    'LabApp' => DOL_DOCUMENT_ROOT . '/custom/labapp/core/modules/modLabApp.class.php',
    'NFSe'   => DOL_DOCUMENT_ROOT . '/custom/nfse/core/modules/modNFSe.class.php',
    'NFe'    => DOL_DOCUMENT_ROOT . '/custom/nfe/core/modules/modNFe.class.php',
);

$hasError = false;

foreach ($modules as $modname => $classfile) {
    if (!file_exists($classfile)) {
        echo "SKIP : $modname — file not found ($classfile)\n";
        continue;
    }

    require_once $classfile;

    $classname = 'mod' . $modname;
    if (!class_exists($classname)) {
        echo "SKIP : $modname — class $classname not found in $classfile\n";
        continue;
    }

    /** @var DolibarrModules $mod */
    $mod = new $classname($db);
    $result = $mod->init();

    if ($result >= 0) {
        echo "OK   : $modname activated\n";
    } else {
        $err = !empty($mod->error) ? $mod->error : 'unknown error';
        fwrite(STDERR, "ERROR: $modname — $err\n");
        $hasError = true;
    }
}

// Mark that all modules have been initialized so the index hook skips on next load
dolibarr_set_const($db, 'LABAPP_MODULES_INITIALIZED', '1', 'chaine', 0, '', 1);

echo "Done.\n";
exit($hasError ? 1 : 0);
