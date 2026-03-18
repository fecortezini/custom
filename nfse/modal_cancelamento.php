<?php
/**
 * Handler para formulário de cancelamento de NFS-e Nacional
 */

// Não incluir main.inc.php aqui - já foi incluído no arquivo chamador

$action = GETPOST('action', 'alpha');
$id = GETPOST('id', 'int');

if (!$id) {
    echo '<div class="nfse-alert nfse-alert-error">ID da NFS-e não informado.</div>';
    exit;
}

// Busca dados da NFS-e
$sql = "SELECT * FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas WHERE id = ".((int)$id);
$res = $db->query($sql);

if (!$res || $db->num_rows($res) == 0) {
    echo '<div class="nfse-alert nfse-alert-error">NFS-e não encontrada.</div>';
    exit;
}

$nfse = $db->fetch_object($res);

// Verifica se pode ser cancelada
if (strtolower($nfse->status) === 'cancelada') {
    echo '<div class="nfse-alert nfse-alert-error">Esta NFS-e já está cancelada.</div>';
    exit;
}

if (strtolower($nfse->status) !== 'autorizada') {
    echo '<div class="nfse-alert nfse-alert-error">Apenas NFS-e autorizadas podem ser canceladas.</div>';
    exit;
}

if (empty($nfse->chave_acesso)) {
    echo '<div class="nfse-alert nfse-alert-error">Esta NFS-e não possui chave de acesso e não pode ser cancelada.</div>';
    exit;
}

// Renderiza formulário
echo '<form id="formCancelamento">';
echo '<style>#formCancelamento .nfse-btn{padding:10px 20px;border-radius:4px;font-weight:500;cursor:pointer;font-size:1.05em;border:1px solid #ddd;background:#fff;color:#666}#formCancelamento .nfse-btn-primary{background:#d32f2f;border:none;color:#fff;padding:10px 24px}#formCancelamento .nfse-btn-primary:hover{background:#b22a2a;color:#fff}#formCancelamento .nfse-btn-secondary{background:#fff;border:1px solid #ddd;color:#666}#formCancelamento .nfse-btn-secondary:hover{background:#f5f5f5;color:#666}</style>';
echo '<input type="hidden" name="token" value="'.newToken().'">';
echo '<input type="hidden" name="action" value="cancelar">';
echo '<input type="hidden" name="id" value="'.((int)$id).'">';

// Header horizontal com fontes maiores
echo '<div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; background: #f8f8f8; border-radius: 4px; margin-bottom: 18px; gap: 20px;">';
    
    // Número
    echo '<div style="flex: 0 0 auto;">';
        echo '<div style="font-size: 0.8em; color: #888; margin-bottom: 3px;">NFS-e</div>';
        echo '<div style="font-size: 1.8em; font-weight: 700; color: #333; line-height: 1;">'.dol_escape_htmltag($nfse->numero_nfse).'</div>';
    echo '</div>';
    
    // Data
    echo '<div style="flex: 1; text-align: center; padding: 0 15px; border-left: 1px solid #ddd; border-right: 1px solid #ddd;">';
        echo '<div style="font-size: 0.8em; color: #888; margin-bottom: 3px;">Emissão</div>';
        echo '<div style="font-size: 1.15em; color: #555; font-weight: 600;">'.date('d/m/Y', strtotime($nfse->data_emissao)).'</div>';
    echo '</div>';
    
    // Valor
    echo '<div style="flex: 0 0 auto; text-align: right;">';
        echo '<div style="font-size: 0.8em; color: #888; margin-bottom: 3px;">Valor</div>';
        echo '<div style="font-size: 1.6em; font-weight: 700; color: #2c7a7b; line-height: 1;">R$ '.number_format((float)$nfse->valor_servicos, 2, ',', '.').'</div>';
    echo '</div>';
    
echo '</div>';

// Cliente
echo '<div style="margin-bottom: 16px;">';
    echo '<label style="display: block; font-size: 0.9em; color: #666; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Cliente</label>';
    echo '<div style="font-size: 1.1em; color: #333; font-weight: 500;">'.dol_escape_htmltag($nfse->tomador_nome).'</div>';
echo '</div>';

// Serviço
echo '<div style="margin-bottom: 20px;">';
    echo '<label style="display: block; font-size: 0.9em; color: #666; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px;">Serviço</label>';
    echo '<div style="font-size: 1.05em; color: #555; line-height: 1.4; padding: 10px; background: #fafafa; border-radius: 4px; border: 1px solid #e8e8e8;">'.dol_escape_htmltag($nfse->descricao_servico).'</div>';
echo '</div>';

// Chave
echo '<div style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid #e8e8e8;">';
    echo '<label style="display: block; font-size: 0.9em; color: #999; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px;">Chave de Acesso</label>';
    echo '<div style="font-family: monospace; font-size: 1.1em; color: #555; font-weight: 500;">'.dol_escape_htmltag($nfse->chave_acesso).'</div>';
echo '</div>';

// Formulário de cancelamento
echo '<div style="margin-bottom: 16px;">';
    echo '<label style="display: block; font-size: 1.05em; color: #333; margin-bottom: 6px; font-weight: 500;">Motivo do Cancelamento <span style="color: #d32f2f;">*</span></label>';
    echo '<select name="codigo_motivo" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 1.05em; background: white;">';
        echo '<option value="">Selecione...</option>';
        echo '<option value="1">1 - Erro na Emissão</option>';
        echo '<option value="2">2 - Serviço não Prestado</option>';
        echo '<option value="9">9 - Outros</option>';
    echo '</select>';
echo '</div>';

echo '<div style="margin-bottom: 20px;">';
    echo '<label style="display: block; font-size: 1.05em; color: #333; margin-bottom: 6px; font-weight: 500;">Justificativa <span style="color: #d32f2f;">*</span></label>';
    echo '<textarea name="descricao_motivo" required placeholder="Descreva o motivo do cancelamento..." style="width: 100%; max-width:640px; box-sizing:border-box; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 1.05em; font-family: inherit; resize: vertical; min-height: 80px;" rows="3"></textarea>';
    echo '<div style="font-size: 0.9em; color: #999; margin-top: 4px;">Mínimo de 15 caracteres</div>';
echo '</div>';

// Alerta discreto
echo '<div style="background: #fff3e0; border-left: 3px solid #ff9800; padding: 12px 14px; margin-bottom: 20px; border-radius: 4px;">';
    echo '<div style="font-size: 1.0em; color: #e65100; line-height: 1.5;"><strong>Atenção:</strong> Esta ação é irreversível. A nota será cancelada permanentemente no sistema.</div>';
echo '</div>';

// Botões
echo '<div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 16px; border-top: 1px solid #e8e8e8;">';
    echo '<button type="button" onclick="closeNfseModal()" class="nfse-btn nfse-btn-secondary">Sair</button>';
    echo '<button type="button" onclick="processarCancelamento()" class="nfse-btn nfse-btn-primary">Confirmar Cancelamento</button>';
echo '</div>';

echo '</form>';
