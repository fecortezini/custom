<?php
/**
 * Visualização da DANFSe (Documento Auxiliar da NFS-e Nacional) em HTML
 */
require '../../main.inc.php';

/** @var DoliDB $db */
/** @var User $user */

$id = GETPOST('id', 'int');

if ($id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    echo 'ID inválido';
    exit;
}

$sql = "SELECT * FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas WHERE id = ".(int)$id;
$res = $db->query($sql);

if (!$res || $db->num_rows($res) === 0) {
    header('HTTP/1.1 404 Not Found');
    echo 'NFS-e não encontrada';
    exit;
}

$nfse = $db->fetch_object($res);

if (empty($nfse->xml_nfse)) {
    header('HTTP/1.1 404 Not Found');
    echo 'XML da NFS-e não disponível';
    exit;
}

// Parse do XML para extrair dados
$xml = simplexml_load_string($nfse->xml_nfse);
if (!$xml) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Erro ao processar XML';
    exit;
}

// Registra namespaces (ajuste conforme padrão nacional)
$xml->registerXPathNamespace('nfse', 'http://www.abrasf.org.br/nfse.xsd');

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DANFSe - NFS-e <?php echo htmlspecialchars($nfse->numero_nfse ?: $nfse->numero_dps); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; padding: 20px; background: #f5f5f5; }
        .danfse-container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 15px; }
        .header h1 { font-size: 18px; margin-bottom: 5px; }
        .header h2 { font-size: 14px; color: #555; }
        .section { margin-bottom: 15px; border: 1px solid #ccc; padding: 10px; }
        .section-title { font-weight: bold; background: #e9ecef; padding: 5px; margin: -10px -10px 10px -10px; }
        .row { display: flex; gap: 10px; margin-bottom: 8px; }
        .field { flex: 1; }
        .field-label { font-weight: bold; font-size: 10px; color: #666; display: block; margin-bottom: 2px; }
        .field-value { font-size: 12px; }
        .field-full { flex: 1 1 100%; }
        .chave-acesso { font-family: monospace; font-size: 11px; word-break: break-all; }
        .qrcode { text-align: center; margin: 15px 0; }
        .footer { text-align: center; margin-top: 20px; padding-top: 10px; border-top: 1px solid #ccc; font-size: 10px; color: #666; }
        @media print {
            body { background: white; padding: 0; }
            .danfse-container { box-shadow: none; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="danfse-container">
    <!-- Cabeçalho -->
    <div class="header">
        <h1>DANFSE - Documento Auxiliar da Nota Fiscal de Serviços Eletrônica</h1>
        <h2>Padrão Nacional ABRASF</h2>
    </div>

    <!-- Dados da NFS-e -->
    <div class="section">
        <div class="section-title">Dados da NFS-e</div>
        <div class="row">
            <div class="field">
                <span class="field-label">Número da NFS-e</span>
                <span class="field-value"><?php echo htmlspecialchars($nfse->numero_nfse ?: '-'); ?></span>
            </div>
            <div class="field">
                <span class="field-label">Número da DPS</span>
                <span class="field-value"><?php echo htmlspecialchars($nfse->numero_dps); ?></span>
            </div>
            <div class="field">
                <span class="field-label">Série</span>
                <span class="field-value"><?php echo htmlspecialchars($nfse->serie); ?></span>
            </div>
        </div>
        <div class="row">
            <div class="field">
                <span class="field-label">Data de Emissão</span>
                <span class="field-value"><?php echo date('d/m/Y', strtotime($nfse->data_emissao)); ?></span>
            </div>
            <div class="field">
                <span class="field-label">Ambiente</span>
                <span class="field-value"><?php echo $nfse->ambiente == 1 ? 'Produção' : 'Homologação'; ?></span>
            </div>
            <div class="field">
                <span class="field-label">Status</span>
                <span class="field-value"><?php echo strtoupper($nfse->status); ?></span>
            </div>
        </div>
        <?php if (!empty($nfse->chave_acesso)): ?>
        <div class="row">
            <div class="field-full">
                <span class="field-label">Chave de Acesso</span>
                <span class="field-value chave-acesso"><?php echo htmlspecialchars($nfse->chave_acesso); ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Prestador -->
    <div class="section">
        <div class="section-title">Prestador de Serviços</div>
        <div class="row">
            <div class="field">
                <span class="field-label">CNPJ</span>
                <span class="field-value"><?php echo formatCnpjCpf($nfse->prestador_cnpj); ?></span>
            </div>
            <div class="field" style="flex: 2;">
                <span class="field-label">Razão Social</span>
                <span class="field-value"><?php echo htmlspecialchars($nfse->prestador_nome); ?></span>
            </div>
        </div>
    </div>

    <!-- Tomador -->
    <div class="section">
        <div class="section-title">Tomador de Serviços</div>
        <div class="row">
            <div class="field">
                <span class="field-label">CNPJ/CPF</span>
                <span class="field-value"><?php echo formatCnpjCpf($nfse->tomador_cnpjcpf); ?></span>
            </div>
            <div class="field" style="flex: 2;">
                <span class="field-label">Nome/Razão Social</span>
                <span class="field-value"><?php echo htmlspecialchars($nfse->tomador_nome); ?></span>
            </div>
        </div>
    </div>

    <!-- Serviço -->
    <div class="section">
        <div class="section-title">Discriminação do Serviço</div>
        <div class="row">
            <div class="field-full">
                <span class="field-label">Descrição</span>
                <span class="field-value"><?php echo nl2br(htmlspecialchars($nfse->descricao_servico)); ?></span>
            </div>
        </div>
        <div class="row">
            <div class="field">
                <span class="field-label">Código do Serviço</span>
                <span class="field-value"><?php echo htmlspecialchars($nfse->cod_servico); ?></span>
            </div>
        </div>
    </div>

    <!-- Valores -->
    <div class="section">
        <div class="section-title">Valores</div>
        <div class="row">
            <div class="field">
                <span class="field-label">Valor dos Serviços</span>
                <span class="field-value">R$ <?php echo number_format($nfse->valor_servicos, 2, ',', '.'); ?></span>
            </div>
            <div class="field">
                <span class="field-label">Valor do ISS</span>
                <span class="field-value">R$ <?php echo number_format($nfse->valor_iss, 2, ',', '.'); ?></span>
            </div>
        </div>
    </div>

    <!-- QR Code (se disponível) -->
    <?php if (!empty($nfse->chave_acesso)): ?>
    <div class="qrcode">
        <img src="https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=<?php echo urlencode($nfse->chave_acesso); ?>" alt="QR Code">
        <p style="font-size: 10px; margin-top: 5px;">Consulte pela Chave de Acesso</p>
    </div>
    <?php endif; ?>

    <!-- Rodapé -->
    <div class="footer">
        <p>Este documento não possui validade fiscal. A NFS-e válida é o arquivo XML assinado digitalmente.</p>
        <p>Emitido em <?php echo date('d/m/Y \à\s H:i:s'); ?></p>
    </div>

    <!-- Botões de ação -->
    <div style="text-align: center; margin-top: 20px;" class="no-print">
        <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; margin-right: 10px;">Imprimir</button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Fechar</button>
    </div>
</div>

</body>
</html>

<?php

function formatCnpjCpf($value) {
    $value = preg_replace('/\D/', '', $value);
    
    if (strlen($value) === 14) {
        // CNPJ: 00.000.000/0000-00
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $value);
    } elseif (strlen($value) === 11) {
        // CPF: 000.000.000-00
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $value);
    }
    
    return $value;
}
