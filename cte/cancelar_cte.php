<?php
/**
 * Função de cancelamento de CT-e
 * Este arquivo é incluído por cte_list.php, não deve carregar main.inc.php novamente
 */

// Não incluir main.inc.php aqui - já foi incluído em cte_list.php

use NFePHP\CTe\Tools;
use NFePHP\CTe\Complements;
use NFePHP\Common\Certificate;
use NFePHP\CTe\Common\Standardize;

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
function cancelarCte($db, $nProt, $chave, $xJust, $idCTe) {
    // Implementar a lógica de cancelamento do CT-e
    global $mysoc;
    
    try{    
        $nfe_cfg = carregarNfeConfig($db);
        $certPath = $nfe_cfg['cert_pfx'];
        $certPassword = $nfe_cfg['cert_pass'];
        $ambiente = isset($nfe_cfg['ambiente']) ? (int)$nfe_cfg['ambiente'] : 2;
    }catch(Exception $e){
        throw new Exception('Erro ao carregar configurações de ambiente: '.$e->getMessage());
    }
    
    $config = [
        "atualizacao" => date('Y-m-d H:i:s'),
        "tpAmb" => $ambiente,
        "razaosocial" => $mysoc->name,
        "cnpj" => preg_replace('/\D/', '', $mysoc->idprof1),
        "siglaUF" => $mysoc->state_code ?: 'ES',
        "schemes" => "PL_CTe_400",
        "versao" => '4.00',
        "proxyConf" => [
            "proxyIp" => "",
            "proxyPort" => "",
            "proxyUser" => "",
            "proxyPass" => ""
        ]
    ];
        
    $configJson = json_encode($config);

    try {
        $tools = new Tools($configJson, Certificate::readPfx($certPath, $certPassword));
        $tools->model('57');
        
        // Enviar cancelamento para SEFAZ
        try {
            $response = $tools->sefazCancela($chave, $xJust, $nProt);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao comunicar com a SEFAZ: ' . $e->getMessage()
            ];
        }
        
        // Validar se houve resposta
        if (empty($response)) {
            $ambienteNome = ($ambiente == 1) ? 'Produção' : 'Homologação';
            return [
                'success' => false,
                'message' => "Erro de comunicação com a SEFAZ:\n\n" .
                            "• Resposta vazia do servidor\n" .
                            "• Ambiente: {$ambienteNome}\n" .
                            "• UF: " . ($mysoc->state_code ?: 'ES') . "\n\n" .
                            "Possíveis causas:\n" .
                            "1. Serviço da SEFAZ indisponível no momento\n" .
                            "2. Ambiente incorreto (verifique se o CT-e foi emitido em produção ou homologação)\n" .
                            "3. Problemas de conectividade\n" .
                            "4. Firewall bloqueando a conexão"
            ];
        }
        
        // Padronizar resposta
        try {
            $stdCl = new Standardize($response);
            $std = $stdCl->toStd();
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao processar resposta da SEFAZ: ' . $e->getMessage()
            ];
        }
        
        $cStat = $std->infEvento->cStat ?? '';
        $xMotivo = $std->infEvento->xEvento ?? '';
        $nProtEvento = $std->infEvento->nProt ?? '';
        
        // Validar estrutura da resposta
        if (empty($cStat)) {
            return [
                'success' => false,
                'message' => 'Resposta inválida da SEFAZ (estrutura inesperada)'
            ];
        }
        
        // Códigos de sucesso: 101, 135, 155
        if (in_array($cStat, ['101', '135', '155'])) {
            //SUCESSO PROTOCOLAR A SOLICITAÇÂO ANTES DE GUARDAR
            $xml = Complements::toAuthorize($tools->lastRequest, $response);
            
            // Iniciar transação
            $db->begin();
            
            try {
                // Atualizar status no banco cte_emitidos
                $sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "cte_emitidos 
                             SET status = 'cancelado' 
                             WHERE rowid = " . ((int)$idCTe);
                $resUpdate = $db->query($sqlUpdate);
                
                if (!$resUpdate) {
                    throw new Exception('Erro ao atualizar status do CT-e');
                }
                
                // Inserir evento em cte_eventos
                // tipo: 1=Cancelamento, 2=CC-e, 3=EPEC
                $sqlEvento = "INSERT INTO " . MAIN_DB_PREFIX . "cte_eventos 
                             (fk_cte, tipo, protocolo, justificativa, xml_enviado, xml_recebido, data_evento) 
                             VALUES (
                                 " . ((int)$idCTe) . ",
                                 1,
                                 '" . $db->escape($nProtEvento) . "',
                                 '" . $db->escape($xJust) . "',
                                 '" . $db->escape($tools->lastRequest) . "',
                                 '" . $db->escape($xml) . "',
                                 NOW()
                             )";
                $resEvento = $db->query($sqlEvento);
                
                if (!$resEvento) {
                    throw new Exception('Erro ao registrar evento de cancelamento');
                }
                
                // Confirmar transação
                $db->commit();
                
                return [
                    'success' => true,
                    'message' => "CT-e cancelado com sucesso!",
                    'protocolo' => $nProtEvento,
                    'xml' => $xml
                ];
                
            } catch (Exception $e) {
                // Reverter transação em caso de erro
                $db->rollback();
                throw $e;
            }
            
        } else {
            //houve alguma falha no evento
            return [
                'success' => false,
                'message' => "Erro ao cancelar CT-e: [{$cStat}] {$xMotivo}"
            ];
        }
        
    } catch (\Exception $e) {
        return [
            'success' => false,
            'message' => 'Erro: ' . $e->getMessage()
        ];
    }
}

