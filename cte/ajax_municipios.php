<?php
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

header('Content-Type: application/json; charset=utf-8');

$action = GETPOST('action', 'aZ09');

if ($action === 'getEstados') {
    $sql = "SELECT DISTINCT codigo_uf, uf, nome_estado FROM ".MAIN_DB_PREFIX."estados_municipios_ibge WHERE active=1 ORDER BY nome_estado";
    $res = $db->query($sql);
    $estados = [];
    if ($res) {
        while ($obj = $db->fetch_object($res)) {
            $estados[] = ['codigo' => $obj->codigo_uf, 'sigla' => $obj->uf, 'nome' => $obj->nome_estado];
        }
    }
    echo json_encode($estados);
    exit;
}

if ($action === 'getMunicipios') {
    $uf = GETPOST('uf', 'aZ09');
    if (empty($uf)) {
        echo json_encode([]);
        exit;
    }
    $sql = "SELECT codigo_ibge, nome FROM ".MAIN_DB_PREFIX."estados_municipios_ibge WHERE uf='".$db->escape($uf)."' AND active=1 ORDER BY nome";
    $res = $db->query($sql);
    $municipios = [];
    if ($res) {
        while ($obj = $db->fetch_object($res)) {
            $municipios[] = ['codigo' => $obj->codigo_ibge, 'nome' => $obj->nome];
        }
    }
    echo json_encode($municipios);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
