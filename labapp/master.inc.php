<?php
// custom/labapp/master.inc.php

if (php_sapi_name() === 'cli') return;
if (!defined('DOL_VERSION')) return;
if (strpos($_SERVER['PHP_SELF'] ?? '', '/install/') !== false) return;

// ── Verifica flag persistente no banco ───────────────────────────────
// Se já rodou alguma vez, a constante existe e não faz nada
if (getDolGlobalString('LABAPP_MODULES_INITIALIZED') === '1') return;

$modulesToActivate = array(
    'labapp',
    'nfse',
    'nfe'
);

foreach ($modulesToActivate as $modname) {
    $constName = 'MAIN_MODULE_' . strtoupper($modname);
    if (empty($conf->global->$constName)) continue;

    $classfile = DOL_DOCUMENT_ROOT . '/custom/' . $modname . '/core/modules/mod' . ucfirst($modname) . '.class.php';
    if (!file_exists($classfile)) continue;

    require_once $classfile;
    $classname = 'mod' . ucfirst($modname);
    if (!class_exists($classname)) continue;

    $mod = new $classname($db);
    $mod->init();

    dol_syslog('LabApp: módulo ' . $modname . ' inicializado automaticamente', LOG_INFO);
}

// ── Grava flag no banco — nunca mais roda ────────────────────────────
dolibarr_set_const($db, 'LABAPP_MODULES_INITIALIZED', '1', 'chaine', 0, '', $conf->entity);