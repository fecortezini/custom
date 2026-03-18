<?php
/**
 * Handler AJAX para cancelamento de CT-e
 * Exibe formulário de cancelamento e processa o cancelamento
 */

// Não incluir main.inc.php aqui - já foi incluído em cte_list.php

$action = GETPOST('action', 'alpha');
$id = GETPOSTINT('id');

// Validação básica
if ($id <= 0) {
    echo '<div class="cte-alert cte-alert-error">ID do CT-e inválido.</div>';
    exit;
}

// Buscar dados do CT-e
$sql = "SELECT rowid, chave, protocolo, numero, serie, dhemi, status 
        FROM " . MAIN_DB_PREFIX . "cte_emitidos 
        WHERE rowid = " . ((int)$id);
$res = $db->query($sql);

if (!$res || $db->num_rows($res) == 0) {
    echo '<div class="cte-alert cte-alert-error">CT-e não encontrado.</div>';
    exit;
}

$cte = $db->fetch_object($res);

// Verificar se já foi cancelado
if (strtolower($cte->status) === 'cancelado') {
    echo '<div class="cte-alert cte-alert-error">Este CT-e já foi cancelado.</div>';
    exit;
}

// Verificar se está autorizado
if (strtolower($cte->status) !== '100' && strtolower($cte->status) !== 'autorizado') {
    echo '<div class="cte-alert cte-alert-error">Apenas CT-e autorizados podem ser cancelados.</div>';
    exit;
}

// Verificar prazo de 7 dias (168 horas)
$prazoExpirado = false;
$mensagemPrazo = '';
if (!empty($cte->dhemi)) {
    try {
        $emissao = new DateTime($cte->dhemi);
        $agora = new DateTime('now');
        $diffHours = ($agora->getTimestamp() - $emissao->getTimestamp()) / 3600;
        
        if ($diffHours > 168) {
            $prazoExpirado = true;
            $mensagemPrazo = 'O prazo para cancelamento (7 dias / 168 horas) já expirou para este CT-e.';
        } else {
            $horasRestantes = ceil(168 - $diffHours);
            $mensagemPrazo = "Prazo para cancelamento: {$horasRestantes} horas restantes.";
        }
    } catch (Exception $e) {
        // Se houver erro ao validar data, permite continuar
    }
}

// Ação: Mostrar formulário de cancelamento
if ($action === 'mostrar_cancelamento') {
    ?>
    <div class="cte-card">
        <div class="cte-card-header">
            Informações do CT-e
        </div>
        <div class="cte-card-body">
            <div class="cte-data-grid-3">
                <div class="cte-data-item">
                    <div class="cte-data-label">Número</div>
                    <div class="cte-data-value"><?php echo dol_escape_htmltag($cte->numero ?: '-'); ?></div>
                </div>
                <div class="cte-data-item">
                    <div class="cte-data-label">Série</div>
                    <div class="cte-data-value"><?php echo dol_escape_htmltag($cte->serie ?: '-'); ?></div>
                </div>
                <div class="cte-data-item">
                    <div class="cte-data-label">Protocolo</div>
                    <div class="cte-data-value"><?php echo dol_escape_htmltag($cte->protocolo ?: '-'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($prazoExpirado): ?>
        <div class="cte-alert cte-alert-error">
            <strong>⚠ Atenção:</strong> <?php echo dol_escape_htmltag($mensagemPrazo); ?>
        </div>
    <?php else: ?>
        <?php if ($mensagemPrazo): ?>
            <div class="cte-alert" style="background-color: #fff3cd; color: #856404; border-color: #ffeeba;">
                <strong>ℹ Info:</strong> <?php echo dol_escape_htmltag($mensagemPrazo); ?>
            </div>
        <?php endif; ?>

        <div class="cte-card">
            <div class="cte-card-header">
                Cancelamento do CT-e
            </div>
            <div class="cte-card-body">
                <form id="formCancelamentoCte">
                    <input type="hidden" name="action" value="processar_cancelamento">
                    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                    <input type="hidden" name="token" value="<?php echo newToken(); ?>">
                    
                    <div class="cte-data-item">
                        <div class="cte-data-label" style="margin-bottom: 8px;">
                            Justificativa de Cancelamento <span style="color: #dc3545;">*</span>
                        </div>
                        <textarea 
                            name="justificativa" 
                            id="justificativaCancelamento" 
                            rows="4" 
                            class="flat" 
                            style="width: 100%; padding: 10px; border: 1px solid #cfe6e2; border-radius: 6px;" 
                            placeholder="Digite a justificativa (mínimo 15 caracteres)" 
                            required
                        ></textarea>
                        <small style="color: #6c757d; display: block; margin-top: 5px;">
                            Mínimo de 15 caracteres. Informe o motivo do cancelamento de forma clara.
                        </small>
                    </div>
                </form>
            </div>
        </div>

        <div class="actions" style="text-align: center; margin-top: 20px;">
            <button type="button" class="butActionDelete" onclick="closeCteModal()">Cancelar</button>
            <button type="button" class="butAction" onclick="processarCancelamentoCte()">
                <i class="fa fa-ban" style="margin-right: 6px;"></i>Confirmar Cancelamento
            </button>
        </div>

        <script>
        function processarCancelamentoCte() {
            var justificativa = document.getElementById('justificativaCancelamento').value.trim();
            
            if (justificativa.length < 15) {
                alert('A justificativa deve conter pelo menos 15 caracteres.');
                return;
            }
            
            var form = document.getElementById('formCancelamentoCte');
            var formData = new FormData(form);
            
            var body = document.getElementById('cteModalBody');
            if (body) body.innerHTML = '<div style="text-align:center;padding:40px;"><i class="fa fa-spinner fa-spin" style="font-size:48px;color:#007bff;"></i><p style="margin-top:20px;font-size:1.1em;">Processando cancelamento...</p></div>';
            
            fetch('<?php echo dol_escape_js($_SERVER["PHP_SELF"]); ?>', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r){ return r.json(); })
            .then(function(response){
                if (response.success) {
                    var bodyEl = document.getElementById('cteModalBody');
                    if (bodyEl) bodyEl.innerHTML = '<div class="cte-alert" style="background:#d4edda;color:#155724;border-color:#c3e6cb;"><strong>✓ Sucesso!</strong><br>' + (response.message || 'CT-e cancelado com sucesso!') + '</div>';
                    setTimeout(function() {
                        closeCteModal();
                        window.location.reload();
                    }, 2000);
                } else {
                    var bodyEl = document.getElementById('cteModalBody');
                    if (bodyEl) bodyEl.innerHTML = '<div class="cte-alert cte-alert-error"><strong>✗ Erro</strong><br>' + (response.message || 'Erro ao cancelar CT-e') + '</div><div class="actions" style="text-align:center;margin-top:20px;"><button type="button" class="butAction" onclick="closeCteModal()">Fechar</button></div>';
                }
            })
            .catch(function(err){
                var bodyEl = document.getElementById('cteModalBody');
                if (bodyEl) bodyEl.innerHTML = '<div class="cte-alert cte-alert-error"><strong>✗ Erro de Comunicação</strong><br>' + (err && err.message ? err.message : 'Erro desconhecido') + '</div><div class="actions" style="text-align:center;margin-top:20px;"><button type="button" class="butAction" onclick="closeCteModal()">Fechar</button></div>';
            });
        }
        </script>
    <?php endif; ?>
    <?php
    exit;
}

// Ação: Processar cancelamento
if ($action === 'processar_cancelamento') {
    header('Content-Type: application/json; charset=UTF-8');
    
    // Validar token CSRF
    if (empty($_POST['token']) || $_POST['token'] !== $_SESSION['newtoken']) {
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido']);
        exit;
    }
    
    // Verificar prazo novamente
    if (!empty($cte->dhemi)) {
        try {
            $emissao = new DateTime($cte->dhemi);
            $agora = new DateTime('now');
            $diffHours = ($agora->getTimestamp() - $emissao->getTimestamp()) / 3600;
            
            if ($diffHours > 168) {
                echo json_encode(['success' => false, 'message' => 'O prazo para cancelamento (7 dias) já expirou.']);
                exit;
            }
        } catch (Exception $e) {
            // Continua se houver erro
        }
    }
    
    $justificativa = GETPOST('justificativa', 'restricthtml');
    
    if (mb_strlen($justificativa) < 15) {
        echo json_encode(['success' => false, 'message' => 'A justificativa deve conter pelo menos 15 caracteres.']);
        exit;
    }
    
    // Validar chave e protocolo
    if (empty($cte->chave) || strlen($cte->chave) != 44) {
        echo json_encode(['success' => false, 'message' => 'Chave de acesso do CT-e inválida ou não encontrada.']);
        exit;
    }
    
    if (empty($cte->protocolo)) {
        echo json_encode(['success' => false, 'message' => 'Protocolo de autorização não encontrado. CT-e pode não estar autorizado corretamente.']);
        exit;
    }
    
    // Chamar função de cancelamento
    require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
    
    use NFePHP\CTe\Tools;
    use NFePHP\Common\Certificate;
    use NFePHP\CTe\Common\Standardize;
    use NFePHP\CTe\Complements;
    
    try {
        // Carregar configurações do banco
        $sql = "SELECT name, value FROM " . MAIN_DB_PREFIX . "nfe_config";
        $res = $db->query($sql);
        $nfe_cfg = [];
        if ($res) {
            while ($row = $db->fetch_object($res)) {
                $nfe_cfg[$row->name] = $row->value;
            }
        }
        
        if (empty($nfe_cfg['cert_pfx']) || empty($nfe_cfg['cert_pass'])) {
            echo json_encode(['success' => false, 'message' => 'Certificado digital não configurado']);
            exit;
        }
        
        // Carregar dados do emitente
        global $mysoc;
        if (empty($mysoc->id)) {
            $mysoc->fetch(0);
        }
        
        $ambiente = isset($nfe_cfg['ambiente']) ? (int)$nfe_cfg['ambiente'] : 2;
        
        // Validar ambiente
        if (!in_array($ambiente, [1, 2])) {
            echo json_encode(['success' => false, 'message' => 'Ambiente inválido. Deve ser 1 (Produção) ou 2 (Homologação).']);
            exit;
        }
        
        // Configuração para NFePHP
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
        $certPath = $nfe_cfg['cert_pfx'];
        $certPassword = $nfe_cfg['cert_pass'];
        
        // Inicializar ferramentas NFePHP
        $tools = new Tools($configJson, Certificate::readPfx($certPath, $certPassword));
        $tools->model('57');
        
        // Log de debug (remover em produção)
        error_log('[CTE Cancelamento] Ambiente: ' . $ambiente);
        error_log('[CTE Cancelamento] UF: ' . ($mysoc->state_code ?: 'ES'));
        error_log('[CTE Cancelamento] Chave: ' . $cte->chave);
        error_log('[CTE Cancelamento] Protocolo: ' . $cte->protocolo);
        
        // Enviar cancelamento
        try {
            $response = $tools->sefazCancela($cte->chave, $justificativa, $cte->protocolo);
        } catch (Exception $e) {
            error_log('[CTE Cancelamento] Exceção ao enviar: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao comunicar com a SEFAZ: ' . $e->getMessage()
            ]);
            exit;
        }
        
        // Validar se houve resposta
        if (empty($response)) {
            $ambienteNome = ($ambiente == 1) ? 'Produção' : 'Homologação';
            echo json_encode([
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
            ]);
            exit;
        }
        
        // Padronizar resposta
        try {
            $stdCl = new Standardize($response);
            $std = $stdCl->toStd();
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao processar resposta da SEFAZ: ' . $e->getMessage() . '. XML retornado: ' . substr($response, 0, 500)
            ]);
            exit;
        }
        
        $cStat = $std->infEvento->cStat ?? '';
        $xMotivo = $std->infEvento->xEvento ?? '';
        $nProt = $std->infEvento->nProt ?? '';
        
        // Log para debug
        if (empty($cStat)) {
            echo json_encode([
                'success' => false,
                'message' => 'Resposta inválida da SEFAZ. Estrutura do retorno: ' . print_r($std, true)
            ]);
            exit;
        }
        
        // Códigos de sucesso: 101, 135, 155
        if (in_array($cStat, ['101', '135', '155'])) {
            // Protocolar o evento
            $xml = Complements::toAuthorize($tools->lastRequest, $response);
            
            $db->begin();
            
            try {
                // Atualizar status no banco
                $sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "cte_emitidos 
                             SET status = 'cancelado' 
                             WHERE rowid = " . ((int)$id);
                $resUpdate = $db->query($sqlUpdate);
                
                if (!$resUpdate) {
                    throw new Exception('Erro ao atualizar status do CT-e');
                }
                
                // Registrar evento com a estrutura correta da tabela
                // tipo: 1=Cancelamento, 2=CC-e, 3=EPEC
                $sqlEvento = "INSERT INTO " . MAIN_DB_PREFIX . "cte_eventos 
                             (fk_cte, tipo, protocolo, justificativa, xml_enviado, xml_recebido, data_evento) 
                             VALUES (
                                 " . ((int)$id) . ",
                                 1,
                                 '" . $db->escape($nProt) . "',
                                 '" . $db->escape($justificativa) . "',
                                 '" . $db->escape($tools->lastRequest) . "',
                                 '" . $db->escape($xml) . "',
                                 NOW()
                             )";
                $resEvento = $db->query($sqlEvento);
                
                if (!$resEvento) {
                    throw new Exception('Erro ao registrar evento de cancelamento');
                }
                
                $db->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => "CT-e cancelado com sucesso! Protocolo: {$nProt}",
                    'protocolo' => $nProt
                ]);
            } catch (Exception $e) {
                $db->rollback();
                echo json_encode(['success' => false, 'message' => 'Erro ao gravar no banco: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => "Erro ao cancelar CT-e: [{$cStat}] {$xMotivo}"
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro: ' . $e->getMessage()
        ]);
    }
    
    exit;
}

// Se chegou aqui, ação inválida
echo '<div class="cte-alert cte-alert-error">Ação inválida.</div>';
exit;
