<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 'Off');
ini_set('log_errors', '1');

require_once '../../main.inc.php';
require_once __DIR__ . '/../composerlib/vendor/autoload.php';
require_once DOL_DOCUMENT_ROOT.'/custom/labapp/lib/ibge_utils.php';
require_once DOL_DOCUMENT_ROOT.'/custom/mdfe/lib/certificate_security.lib.php';

use NFePHP\Common\Certificate;
use NFePHP\MDFe\Common\Standardize;
use NFePHP\MDFe\Tools;
// pegar ambiente
$sql = $db->query("SELECT value FROM ".MAIN_DB_PREFIX."nfe_config where name = 'ambiente';");
if($sql && $db->num_rows($sql) > 0){
    $res = $db->fetch_object($sql);
    $ambiente = (int)$res->value;
}

function carregarCertificadoA1Nacional($db) {
    $certPfx = null;
    $certPass = null;

    // Tenta tabela key/value
    $tableKv = (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : '') . 'nfe_config';
    $res = @$db->query("SELECT name, value FROM `".$tableKv."`");
    if ($res) {
        while ($row = $db->fetch_object($res)) {
            if ($row->name === 'cert_pfx') $certPfx = $row->value;
            if ($row->name === 'cert_pass') $certPass = $row->value;
        }
    }

    // Fallback para tabela com colunas diretas
    if (empty($certPfx)) {
        $tableDirect = MAIN_DB_PREFIX . 'nfe_config';
        $res2 = @$db->query("SELECT cert_pfx, cert_pass FROM `".$tableDirect."` LIMIT 1");
        if ($res2 && $obj = $db->fetch_object($res2)) {
            $certPfx = $obj->cert_pfx;
            $certPass = $obj->cert_pass;
        }
    }

    // Normaliza BLOB/stream
    if (is_resource($certPfx)) {
        $certPfx = stream_get_contents($certPfx);
    }
    
    if ($certPfx === null || $certPfx === '') {
        throw new Exception('Certificado PFX não encontrado no banco de dados.');
    }
    
    $certPass = (string)$certPass;
    //$criptografada = nfseEncryptPassword($certPass, $db);
    $pass = decryptPassword($certPass, $db);
    
    // Retorna certificado usando a biblioteca NFePHP
    try {
        $cert = \NFePHP\Common\Certificate::readPfx($certPfx, $pass);
        return $cert;
    } catch (Exception $e) {
        // Tenta decodificar base64 se falhar
        $certPfxDecoded = base64_decode($certPfx, true);
        if ($certPfxDecoded !== false) {
            $cert = \NFePHP\Common\Certificate::readPfx($certPfxDecoded, $certPass);
            return $cert;
        }
        throw new Exception('Erro ao ler certificado: ' . $e->getMessage());
    }
}

$config = [
    "atualizacao" => date('Y-m-d H:i:s'),
    "tpAmb" => $ambiente,
    "razaosocial" => 'LAB CONSULTORIA',
    "cnpj" => '48681521000188',
    "ie" => '084605049',
    "siglaUF" => 'ES',
    "versao" => '3.00'
];

try {
    $cert = carregarCertificadoA1Nacional($db);
    $tools = new Tools(json_encode($config), $cert);

    // $chave = '32260248681521000188580010000000011410207092';
    // $nProt = '932260000001382';
    // $cUF = '32';
    // $cMun = '3201209';
    // //$dtEnc = 'Y-m-d'; // Opcional, caso nao seja preenchido pegara HOJE
    // $resp = $tools->sefazEncerra($chave, $nProt, $cUF, $cMun, $dtEnc);

    // $st = new Standardize();
    // $std = $st->toStd($resp);

    // print_r($std);
    $chave = '32260248681521000188580010000000281805063259';
    $nSeqEvento = '2';
    $nProt = '932260000001488';
    $cMunCarrega = '3201209';
    $xMunCarrega = 'Cachoeiro de Itapemirim';
    $cMunDescarga = '3201209';
    $xMunDescarga = 'Cachoeiro de Itapemirim';
    $chNFe = '32260248681521000188550010000212401404081792';

    // O 5º parâmetro deve ser um array de arrays — cada item representa um
    // município de descarga com os documentos vinculados (chNFe ou chCTe)
    $infDoc = [
        [
            'cMunDescarga' => $cMunDescarga,
            'xMunDescarga' => $xMunDescarga,
            'chNFe'        => $chNFe,
        ]
    ];

    // $resp = $tools->sefazIncluiDFe(
    //     $chave,
    //     $nProt,
    //     $cMunCarrega,
    //     $xMunCarrega,
    //     $infDoc,
    //     $nSeqEvento
    // );

    $xNome = 'CLEITONS';
    $cpf = '01234567890';
    $resp = $tools->sefazIncluiCondutor($chave, $nSeqEvento, $xNome, $cpf);

    $st = new Standardize();
    $std = $st->toStd($resp);

    echo '<pre>';
    print_r($std);
    echo "</pre>";

    // RESPOSTA DA SEFAZ RETORNADA PELA VARIAVEL $std :
//     stdClass Object
// (
//     [attributes] => stdClass Object
//         (
//             [versao] => 3.00
//         )

//     [infEvento] => stdClass Object
//         (
//             [attributes] => stdClass Object
//                 (
//                     [Id] => ID932260000001404
//                 )

//             [tpAmb] => 2
//             [verAplic] => RS20240709143541
//             [cOrgao] => 32
//             [cStat] => 135
//             [xMotivo] => Evento registrado e vinculado ao MDF-e
//             [chMDFe] => 32260248681521000188580010000000011410207092
//             [tpEvento] => 110112
//             [xEvento] => Encerramento
//             [nSeqEvento] => 001
//             [dhRegEvento] => 2026-02-20T09:23:54-03:00
//             [nProt] => 932260000001404
//         )

// )
} catch (Exception $e) {
    echo $e->getMessage();
}