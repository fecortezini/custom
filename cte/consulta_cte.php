<?php
error_reporting(E_ALL);
ini_set('display_errors', 'On');

use NFePHP\CTe\Make;
use NFePHP\CTe\Tools;
use NFePHP\CTe\Complements;
use NFePHP\Common\Certificate;
use NFePHP\CTe\Common\Standardize;

// Carregamento do ambiente Dolibarr
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';

function carregarNfeConfig($db){
    $sql = "SELECT name, value FROM ". MAIN_DB_PREFIX ."nfe_config";
    $res = $db->query($sql);
    $config = [];
    if ($res) {
        while ($row = $db->fetch_object($res)) {
            $config[$row->name] = $row->value;
        }
    }
    return $config;
}

global $mysoc, $db;

//carrega o conteudo do certificado.
$nfe_cfg = carregarNfeConfig($db);
$certPath = $nfe_cfg['cert_pfx'];
$ambiente = isset($nfe_cfg['ambiente']) ? (int)$nfe_cfg['ambiente'] : 2;

$arr = [
    "atualizacao" => date('Y-m-d H:i:s'),
    "tpAmb" => $ambiente,
    "razaosocial" => $mysoc->name,
    "cnpj" => preg_replace('/\D/', '', $mysoc->idprof1),
    "siglaUF" => $mysoc->state_code ?? 'ES',
    "schemes" => "PL_CTe_400",
    "versao" => '4.00',
    "proxyConf" => [
        "proxyIp" => "",
        "proxyPort" => "",
        "proxyUser" => "",
        "proxyPass" => ""
    ]
];
//monta o config.json
$configJson = json_encode($arr);

try {
//intancia a classe tools
  $tools = new Tools($configJson, Certificate::readPfx($certPath, $nfe_cfg['cert_pass']));
  $tools->model('57');

  $chave = '32251240344276000101570010000000971000000098';
  $response = $tools->sefazConsultaChave($chave);
  $stdCl = new Standardize($response);
  //nesse caso o $arr irá conter uma representação em array do XML retornado
  $arr = $stdCl->toArray();
  $std = $stdCl->toStd();
  
  // Exibição formatada
  echo '<div style="font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5;">';
  
  // Status da Consulta
  $cStat = $arr['cStat'] ?? '';
  $xMotivo = $arr['xMotivo'] ?? '';
  $statusColor = ($cStat == '100' || $cStat == '101') ? '#28a745' : '#dc3545';
  
  echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 5px solid '.$statusColor.';">';
  echo '<h2 style="margin: 0 0 15px 0; color: #333;">Consulta CT-e</h2>';
  echo '<div style="display: grid; grid-template-columns: 200px 1fr; gap: 10px;">';
  echo '<strong>Status:</strong><span style="color: '.$statusColor.'; font-weight: bold;">['.$cStat.'] '.$xMotivo.'</span>';
  echo '<strong>Versão:</strong><span>'.$arr['attributes']['versao'].'</span>';
  echo '<strong>Aplicativo:</strong><span>'.$arr['verAplic'].'</span>';
  echo '<strong>UF:</strong><span>'.$arr['cUF'].'</span>';
  echo '</div></div>';
  
  // Dados do CT-e
  if (isset($arr['protCTe']['infProt'])) {
      $infProt = $arr['protCTe']['infProt'];
      echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px;">';
      echo '<h3 style="margin: 0 0 15px 0; color: #333;">Protocolo de Autorização</h3>';
      echo '<div style="display: grid; grid-template-columns: 200px 1fr; gap: 10px;">';
      echo '<strong>Chave:</strong><span style="font-family: monospace;">'.($infProt['chCTe'] ?? '').'</span>';
      echo '<strong>Protocolo:</strong><span>'.($infProt['nProt'] ?? '').'</span>';
      echo '<strong>Data/Hora:</strong><span>'.($infProt['dhRecbto'] ?? '').'</span>';
      echo '<strong>Status:</strong><span>['.$infProt['cStat'].'] '.$infProt['xMotivo'].'</span>';
      echo '<strong>Digest Value:</strong><span style="font-family: monospace; font-size: 0.9em;">'.($infProt['digVal'] ?? '').'</span>';
      echo '</div></div>';
  }
  
  // Eventos (Cancelamento, CC-e, etc)
  if (isset($arr['procEventoCTe'])) {
      $eventos = is_array($arr['procEventoCTe']) && isset($arr['procEventoCTe']['eventoCTe']) ? 
                 [$arr['procEventoCTe']] : $arr['procEventoCTe'];
      
      foreach ((array)$eventos as $procEvento) {
          if (!isset($procEvento['eventoCTe'])) continue;
          
          $evento = $procEvento['eventoCTe']['infEvento'];
          $retEvento = $procEvento['retEventoCTe']['infEvento'] ?? [];
          
          $tpEvento = $evento['tpEvento'] ?? '';
          $eventoNome = 'Evento';
          $eventoIcon = '📄';
          
          if ($tpEvento == '110111') {
              $eventoNome = 'Cancelamento';
              $eventoIcon = '❌';
          } elseif ($tpEvento == '110110') {
              $eventoNome = 'Carta de Correção';
              $eventoIcon = '✏️';
          }
          
          $statusEvento = $retEvento['cStat'] ?? '';
          $eventoColor = in_array($statusEvento, ['135', '136']) ? '#28a745' : '#ffc107';
          
          echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 5px solid '.$eventoColor.';">';
          echo '<h3 style="margin: 0 0 15px 0; color: #333;">'.$eventoIcon.' '.$eventoNome.'</h3>';
          echo '<div style="display: grid; grid-template-columns: 200px 1fr; gap: 10px;">';
          echo '<strong>Data/Hora:</strong><span>'.($evento['dhEvento'] ?? '').'</span>';
          echo '<strong>Sequência:</strong><span>'.($evento['nSeqEvento'] ?? '').'</span>';
          
          // Detalhes específicos do cancelamento
          if (isset($evento['detEvento']['evCancCTe'])) {
              $canc = $evento['detEvento']['evCancCTe'];
              echo '<strong>Protocolo CT-e:</strong><span>'.($canc['nProt'] ?? '').'</span>';
              echo '<strong>Justificativa:</strong><span>'.($canc['xJust'] ?? '').'</span>';
          }
          
          // Retorno do evento
          if (!empty($retEvento)) {
              echo '<strong style="grid-column: 1/-1; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">Retorno SEFAZ</strong>';
              echo '<strong>Status:</strong><span style="color: '.$eventoColor.'; font-weight: bold;">['.$statusEvento.'] '.$retEvento['xMotivo'].'</span>';
              echo '<strong>Protocolo Evento:</strong><span>'.($retEvento['nProt'] ?? '').'</span>';
              echo '<strong>Data Registro:</strong><span>'.($retEvento['dhRegEvento'] ?? '').'</span>';
          }
          
          echo '</div></div>';
      }
  }
  
  // Botão para ver dados completos
  echo '<details style="background: white; padding: 20px; border-radius: 8px;">';
  echo '<summary style="cursor: pointer; font-weight: bold; color: #666;">Ver dados completos (array)</summary>';
  echo '<pre style="background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; margin-top: 15px;">';
  print_r($arr);
  echo '</pre></details>';
  
  echo '</div>';
  exit();
} catch (\Exception $e) {
  echo '<div style="background: #f8d7da; color: #721c24; padding: 20px; border-radius: 8px; margin: 20px; border-left: 5px solid #dc3545;">';
  echo '<h3 style="margin: 0 0 10px 0;">❌ Erro</h3>';
  echo '<p style="margin: 0;">'.htmlspecialchars($e->getMessage()).'</p>';
  echo '</div>';
  //TRATAR
}