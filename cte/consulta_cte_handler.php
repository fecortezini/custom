<?php
/**
 * Handler de consulta CT-e para uso em AJAX
 * Incluído por cte_list.php quando action=consultar
 */

if (!defined('MAIN_DB_PREFIX')) {
    die('Acesso direto não permitido');
}

use NFePHP\CTe\Tools;
use NFePHP\Common\Certificate;
use NFePHP\CTe\Common\Standardize;

function carregarNfeConfig($db) {
    $sql = "SELECT name, value FROM " . MAIN_DB_PREFIX . "nfe_config";
    $res = $db->query($sql);
    $config = [];
    if ($res) {
        while ($row = $db->fetch_object($res)) {
            $config[$row->name] = $row->value;
        }
    }
    return $config;
}

// Buscar ID do CT-e
$idCTe = GETPOSTINT('id');
if ($idCTe <= 0) {
    echo '<div class="cte-alert cte-alert-error">ID do CT-e inválido.</div>';
    exit;
}

// Buscar chave do CT-e no banco
$sql = "SELECT chave FROM " . MAIN_DB_PREFIX . "cte_emitidos WHERE rowid = " . ((int)$idCTe);
$res = $db->query($sql);

if (!$res || $db->num_rows($res) == 0) {
    echo '<div class="cte-alert cte-alert-error">CT-e não encontrado.</div>';
    exit;
}

$obj = $db->fetch_object($res);
$chave = $obj->chave;

if (empty($chave)) {
    echo '<div class="cte-alert cte-alert-error">Chave do CT-e não encontrada.</div>';
    exit;
}

global $mysoc;

// Carregar configuração NFePHP
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

$configJson = json_encode($arr);

try {
    // Instanciar Tools
    $tools = new Tools($configJson, Certificate::readPfx($certPath, $nfe_cfg['cert_pass']));
    $tools->model('57');

    // Consultar CT-e
    $response = $tools->sefazConsultaChave($chave);
    $stdCl = new Standardize($response);
    $arr = $stdCl->toArray();
    $std = $stdCl->toStd();

    // Função auxiliar para formatar data/hora
    function formatarDataHora($dhString) {
        if (empty($dhString)) return '-';
        try {
            $dt = new DateTime($dhString);
            return $dt->format('d/m/Y \à\s H:i:s');
        } catch (Exception $e) {
            return $dhString;
        }
    }
    
    // Status da Consulta
    $cStat = $arr['cStat'] ?? '';
    $xMotivo = $arr['xMotivo'] ?? '';
    
    // Definir cor e ícone baseado no status
    $statusIcon = '📋';
    $statusColor = '#6c757d';
    $statusBg = '#f8f9fa';
    
    if ($cStat == '100') {
        $statusIcon = '✅';
        $statusColor = '#28a745';
        $statusBg = '#d4edda';
    } elseif ($cStat == '101') {
        $statusIcon = '❌';
        $statusColor = '#dc3545';
        $statusBg = '#f8d7da';
    }

    // Container principal
    echo '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; background: #f8f9fa; margin: -15px; padding: 20px; line-height: 1.6;">';

    // // Card de Status Principal
    // echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
    // echo '<div style="display: flex; align-items: center; margin-bottom: 15px;">';
    // echo '<div style="width: 50px; height: 50px; border-radius: 50%; background: '.$statusBg.'; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-right: 15px;">'.$statusIcon.'</div>';
    // echo '<div style="flex: 1;">';
    // echo '<h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;">Status da Consulta</h3>';
    // echo '<p style="margin: 5px 0 0 0; font-size: 14px; color: '.$statusColor.'; font-weight: 600;">'.$xMotivo.'</p>';
    // echo '</div>';
    // echo '</div>';
    // echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; padding-top: 15px; border-top: 1px solid #e9ecef;">';
    // echo '<div><span style="font-size: 12px; color: #6c757d; display: block; margin-bottom: 4px;">Código Status</span><span style="font-size: 14px; font-weight: 600; color: #333;">'.$cStat.'</span></div>';
    // echo '<div><span style="font-size: 12px; color: #6c757d; display: block; margin-bottom: 4px;">UF</span><span style="font-size: 14px; font-weight: 600; color: #333;">'.(($arr['cUF'] ?? '') == '32' ? 'ES - Espírito Santo' : ($arr['cUF'] ?? '-')).'</span></div>';
    // echo '<div><span style="font-size: 12px; color: #6c757d; display: block; margin-bottom: 4px;">Versão</span><span style="font-size: 14px; font-weight: 600; color: #333;">'.($arr['attributes']['versao'] ?? '-').'</span></div>';
    // echo '<div><span style="font-size: 12px; color: #6c757d; display: block; margin-bottom: 4px;">Aplicativo SEFAZ</span><span style="font-size: 14px; font-weight: 600; color: #333;">'.($arr['verAplic'] ?? '-').'</span></div>';
    // echo '</div>';
    // echo '</div>';

    // Card de Protocolo de Autorização
    if (isset($arr['protCTe']['infProt'])) {
        $infProt = $arr['protCTe']['infProt'];
        $statusProt = $infProt['cStat'] ?? '';
        $protColor = ($statusProt == '100') ? '#28a745' : '#6c757d';
        
        echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        echo '<div style="display: flex; align-items: center; margin-bottom: 15px;">';
        echo '<div style="font-size: 28px; margin-right: 12px;">🔐</div>';
        echo '<h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;">Protocolo de Autorização</h3>';
        echo '</div>';
        
        echo '<div style="background: #f8f9fa; padding: 12px; border-radius: 6px; margin-bottom: 12px;">';
        echo '<div style="font-size: 11px; color: #6c757d; margin-bottom: 4px; letter-spacing: 0.5px;">CHAVE DE ACESSO</div>';
        echo '<div style="font-family: \'Courier New\', monospace; font-size: 13px; font-weight: 600; color: #333; letter-spacing: 1px;">'.($infProt['chCTe'] ?? '-').'</div>';
        echo '</div>';
        
        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">';
        echo '<div><span style="font-size: 12px; color: #6c757d; display: block; margin-bottom: 4px;">Número do Protocolo</span><span style="font-size: 14px; font-weight: 600; color: #333;">'.($infProt['nProt'] ?? '-').'</span></div>';
        echo '<div><span style="font-size: 12px; color: #6c757d; display: block; margin-bottom: 4px;">Data/Hora Autorização</span><span style="font-size: 14px; font-weight: 600; color: #333;">'.formatarDataHora($infProt['dhRecbto'] ?? '').'</span></div>';
        echo '<div style="grid-column: 1/-1;"><span style="font-size: 12px; color: #6c757d; display: block; margin-bottom: 4px;">Status da Autorização</span><span style="font-size: 14px; font-weight: 600; color: '.$protColor.';">['.$statusProt.'] '.($infProt['xMotivo'] ?? '-').'</span></div>';
        echo '</div>';
        echo '</div>';
    }

    // Card de Eventos (Cancelamento, CC-e, etc)
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
            $eventoBg = '#e7f3ff';

            if ($tpEvento == '110111') {
                $eventoNome = 'Cancelamento';
                $eventoIcon = '🚫';
                $eventoBg = '#ffe6e6';
            } elseif ($tpEvento == '110110') {
                $eventoNome = 'Carta de Correção';
                $eventoIcon = '📝';
                $eventoBg = '#fff8e6';
            }

            $statusEvento = $retEvento['cStat'] ?? '';
            $eventoColor = in_array($statusEvento, ['135', '136']) ? '#28a745' : '#dc3545';

            echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
            echo '<div style="display: flex; align-items: center; margin-bottom: 15px;">';
            echo '<div style="width: 45px; height: 45px; border-radius: 8px; background: '.$eventoBg.'; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-right: 12px;">'.$eventoIcon.'</div>';
            echo '<h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #333;">'.$eventoNome.'</h3>';
            echo '</div>';

            echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">';
            echo '<div><span style="font-size: 12px; color: #6c757d; display: block; margin-bottom: 4px;">Data/Hora do Evento</span><span style="font-size: 14px; font-weight: 600; color: #333;">'.formatarDataHora($evento['dhEvento'] ?? '').'</span></div>';
            echo '<div><span style="font-size: 12px; color: #6c757d; display: block; margin-bottom: 4px;">Sequência</span><span style="font-size: 14px; font-weight: 600; color: #333;">'.($evento['nSeqEvento'] ?? '-').'</span></div>';
            echo '</div>';

            // Detalhes específicos do cancelamento
            if (isset($evento['detEvento']['evCancCTe'])) {
                $canc = $evento['detEvento']['evCancCTe'];
                echo '<div style="background: #fff3cd; padding: 15px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #ffc107;">';
                echo '<div style="font-size: 12px; color: #856404; margin-bottom: 8px; font-weight: 600;">JUSTIFICATIVA DO CANCELAMENTO</div>';
                echo '<div style="font-size: 14px; color: #333;">'.($canc['xJust'] ?? '-').'</div>';
                echo '</div>';
            }

            // Detalhes específicos da carta de correção
            if (isset($evento['detEvento']['evCCeCTe'])) {
                $cce = $evento['detEvento']['evCCeCTe'];
                echo '<div style="background: #e7f3ff; padding: 15px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #007bff;">';
                echo '<div style="font-size: 12px; color: #004085; margin-bottom: 8px; font-weight: 600;">CORREÇÃO EFETUADA</div>';
                echo '<div style="display: grid; gap: 10px;">';
                if (!empty($cce['infCorrecao']['grupoAlterado'])) {
                    echo '<div><span style="font-size: 11px; color: #6c757d;">Grupo: </span><span style="font-size: 13px; color: #333;">'.($cce['infCorrecao']['grupoAlterado']).'</span></div>';
                }
                if (!empty($cce['infCorrecao']['campoAlterado'])) {
                    echo '<div><span style="font-size: 11px; color: #6c757d;">Campo: </span><span style="font-size: 13px; color: #333;">'.($cce['infCorrecao']['campoAlterado']).'</span></div>';
                }
                if (!empty($cce['infCorrecao']['valorAlterado'])) {
                    echo '<div><span style="font-size: 11px; color: #6c757d;">Novo Valor: </span><span style="font-size: 13px; font-weight: 600; color: #333;">'.($cce['infCorrecao']['valorAlterado']).'</span></div>';
                }
                echo '</div></div>';
            }

            // Retorno do evento (SEFAZ)
            if (!empty($retEvento)) {
                echo '<div style="border-top: 1px solid #e9ecef; padding-top: 15px; margin-top: 15px;">';
                echo '<div><span style="font-size: 12px; color: #6c757d; display: block; margin-bottom: 4px;">Protocolo do Evento</span><span style="font-size: 14px; font-weight: 600; color: #333;">'.($retEvento['nProt'] ?? '-').'</span></div>';
                echo '<div><span style="font-size: 12px; color: #6c757d; display: block; margin-bottom: 4px;">Data de Registro</span><span style="font-size: 14px; font-weight: 600; color: #333;">'.formatarDataHora($retEvento['dhRegEvento'] ?? '').'</span></div>';
                echo '</div>';
                echo '</div>';
            }

            echo '</div>';
        }
    }

    // Rodapé informativo
    echo '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 15px 20px; border-radius: 8px; color: white; text-align: center; font-size: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
    echo '<div style="font-weight: 600; margin-bottom: 4px;">✓ Consulta realizada com sucesso</div>';
    echo '<div style="opacity: 0.9;">Dados obtidos diretamente da SEFAZ em '.date('d/m/Y \à\s H:i:s').'</div>';
    echo '</div>';

    echo '</div>';

} catch (\Exception $e) {
    echo '<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; border-left: 4px solid #dc3545;">';
    echo '<h3 style="margin: 0 0 8px 0; font-size: 16px;">❌ Erro</h3>';
    echo '<p style="margin: 0; font-size: 13px;">' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}
