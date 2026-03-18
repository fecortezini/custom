<?php
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

// MINHA SENHA GOOGLE: tytu ytcd srgh ncyd
// Pega o ID da NFe da requisição
$id = GETPOST('id', 'int');
if (!$id) {
    // Usa o sistema de mensagens do Dolibarr para feedback ao usuário
    setEventMessages('ID da nota fiscal não fornecido.', null, 'errors');
    header('Location: ' . $_SERVER["HTTP_REFERER"]); // Redireciona para a página anterior
    exit;
}

// Monta a query para buscar o PDF, XML, email e nome do cliente
$sql = "SELECT nfe.pdf_file, nfe.xml_completo, s.email, s.nom FROM ".MAIN_DB_PREFIX."nfe_emitidas nfe
        LEFT JOIN ".MAIN_DB_PREFIX."facture f ON nfe.fk_facture = f.rowid
        LEFT JOIN ".MAIN_DB_PREFIX."societe s ON f.fk_soc = s.rowid
        WHERE nfe.id = ".$id;

$resql = $db->query($sql);

if ($resql && $db->num_rows($resql) > 0) {
    $obj = $db->fetch_object($resql);
    $pdfContent = $obj->pdf_file;
    $xmlContent = $obj->xml_completo; // Adiciona o conteúdo do XML
    $clientEmail = trim($obj->email);
    $clientName = $obj->nom;

    // Validações importantes
    if (empty($pdfContent)) {
        setEventMessages('O conteúdo do PDF para esta nota fiscal está vazio no banco de dados.', null, 'errors');
        header('Location: ' . $_SERVER["HTTP_REFERER"]);
        exit;
    }

    if (empty($xmlContent)) {
        setEventMessages('O conteúdo do XML para esta nota fiscal está vazio no banco de dados.', null, 'errors');
        header('Location: ' . $_SERVER["HTTP_REFERER"]);
        exit;
    }

    if (!$clientEmail || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        setEventMessages('E-mail do cliente não encontrado ou inválido.', null, 'errors');
        header('Location: ' . $_SERVER["HTTP_REFERER"]);
        exit;
    }

    // Define o assunto e o corpo do e-mail
    $subject = "Nota Fiscal Eletrônica (NFe)";
    $message = "Prezado(a) $clientName,\n\nSegue em anexo a Nota Fiscal Eletrônica referente à sua compra.\n\nAtenciosamente.";

    // Usa o diretório temporário configurado no Dolibarr para maior compatibilidade
    $tempDir = !empty($conf->global->MAIN_TEMP_DIR) ? $conf->global->MAIN_TEMP_DIR : sys_get_temp_dir();
    $tempPdfPath = $tempDir . '/nfe_' . uniqid() . '.pdf';

    // Salva o conteúdo do PDF em um arquivo temporário
    if (file_put_contents($tempPdfPath, $pdfContent) === false) {
        setEventMessages('Falha ao salvar o arquivo PDF temporário.', null, 'errors');
        header('Location: ' . $_SERVER["HTTP_REFERER"]);
        exit;
    }

    // Salva o conteúdo do XML em um arquivo temporário
    $tempXmlPath = $tempDir . '/nfe_' . uniqid() . '.xml';
    if (file_put_contents($tempXmlPath, $xmlContent) === false) {
        setEventMessages('Falha ao salvar o arquivo XML temporário.', null, 'errors');
        header('Location: ' . $_SERVER["HTTP_REFERER"]);
        exit;
    }

    // Define o nome do arquivo para o anexo e seu tipo MIME
    $attachmentFilename = 'Nota_Fiscal_' . $id . '.pdf';
    $attachmentMimeType = 'application/pdf';

    // Define o nome do arquivo para o anexo XML
    $attachmentXmlFilename = 'Nota_Fiscal_' . $id . '.xml';
    $attachmentXmlMimeType = 'application/xml';

    $mail = new CMailFile(
        $subject,
        $clientEmail,
        $conf->global->MAIN_MAIL_EMAIL_FROM,
        $message,
        array($tempPdfPath, $tempXmlPath),        // 1. Array com os caminhos dos arquivos
        array($attachmentMimeType, $attachmentXmlMimeType), // 2. Array com os tipos MIME (um para cada arquivo)
        array($attachmentFilename, $attachmentXmlFilename)  // 3. Array com os nomes dos arquivos (um para cada arquivo)
    );

    // Envia o e-mail
    if ($mail->sendfile()) {
        setEventMessages('E-mail com a NFe enviado com sucesso para o cliente.', null, 'mesgs');
    } else {
        // Adiciona o erro do Dolibarr/PHPMailer para facilitar a depuração
        $errorMessage = 'Falha ao enviar o e-mail: ' . $mail->error;
        setEventMessages($errorMessage, null, 'errors');
        dol_syslog($errorMessage, LOG_ERR); // Loga o erro no log do Dolibarr
    }

    // Garante que os arquivos temporários sejam removidos após a tentativa de envio
    if (file_exists($tempPdfPath)) {
        unlink($tempPdfPath);
    }
    if (file_exists($tempXmlPath)) {
        unlink($tempXmlPath);
    }
    
    // Redireciona de volta para a página anterior para mostrar a mensagem de status
    header('Location: ' . $_SERVER["HTTP_REFERER"]);
    exit;

} else {
    setEventMessages('Nota fiscal não encontrada no banco de dados.', null, 'errors');
    header('Location: ' . $_SERVER["HTTP_REFERER"]);
    exit;
}
?>
