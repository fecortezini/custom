<?php
/**
 * Geração e Download do DACTE (Documento Auxiliar do CT-e)
 */

error_reporting(E_ALL);
ini_set('display_errors', 'On');

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

use NFePHP\DA\CTe\Dacte;
use Com\Tecnick\Barcode\Barcode;

/**
 * Classe customizada para corrigir erro de wrapper data:// em ambientes Windows/XAMPP
 * onde allow_url_fopen pode estar desabilitado ou com restrições.
 * Converte a imagem para arquivo temporário ao invés de data URI.
 */
class DacteCustom extends Dacte {
    protected function adjustImage($logo, $turn_bw = false)
    {
        if (!empty($this->logomarca)) {
            return $this->logomarca;
        }
        if (empty($logo)) {
            return null;
        }
        
        if (is_file($logo)) {
            $info = getimagesize($logo);
            $type = $info[2]; // 2 = JPG, 3 = PNG
            
            // Se for JPEG e não precisar de P&B, retorna o caminho direto
            if ($type == 2 && !$turn_bw) {
                return $logo;
            }
            
            // Se for PNG ou precisar de P&B, converte para JPEG temporário
            $image = null;
            if ($type == 3) {
                $image = imagecreatefrompng($logo);
            } elseif ($type == 2) {
                $image = imagecreatefromjpeg($logo);
            }
            
            if ($image) {
                if ($turn_bw) {
                    imagefilter($image, IMG_FILTER_GRAYSCALE);
                }
                
                $tmpDir = sys_get_temp_dir();
                $tmpFile = tempnam($tmpDir, 'dacte_logo_');
                imagejpeg($image, $tmpFile, 100);
                imagedestroy($image);
                
                // Registrar para deletar o arquivo temporário ao final
                register_shutdown_function(function() use ($tmpFile) {
                    if (file_exists($tmpFile)) {
                        @unlink($tmpFile);
                    }
                });
                
                return $tmpFile;
            }
        }
        
        return parent::adjustImage($logo, $turn_bw);
    }

    protected function qrCodeDacte($y = 0)
    {
        $margemInterna = $this->margemInterna;
        $barcode = new Barcode();
        $bobj = $barcode->getBarcodeObj(
            'QRCODE,M',
            $this->qrCodCTe,
            -4,
            -4,
            'black',
            array(-2, -2, -2, -2)
        )->setBackgroundColor('white');
        $qrcode = $bobj->getPngData();
        $wQr = 36;
        $hQr = 36;
        $yQr = ($y + $margemInterna);
        if ($this->orientacao == 'P') {
            $xQr = 170;
        } else {
            $xQr = 250;
        }
        
        // Salvar em arquivo temporário ao invés de data URI
        $tmpDir = sys_get_temp_dir();
        $tmpFile = tempnam($tmpDir, 'dacte_qrcode_');
        file_put_contents($tmpFile, $qrcode);
        
        // Registrar para deletar o arquivo temporário ao final
        register_shutdown_function(function() use ($tmpFile) {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        });
        
        $this->pdf->image($tmpFile, $xQr - 3, $yQr, $wQr, $hQr, 'PNG');
    }
}

// Parâmetros
$action = GETPOST('action', 'alpha');
$id = GETPOSTINT('id');
$mode = GETPOST('mode', 'alpha'); // 'download' ou 'view'

// Validações
if (empty($id) || $id <= 0) {
    dol_print_error($db, 'ID do CT-e não informado');
    exit;
}

// Buscar XML do CT-e no banco de dados
$sql = "SELECT rowid, chave, xml_enviado, xml_recebido, numero, serie, protocolo 
        FROM " . MAIN_DB_PREFIX . "cte_emitidos 
        WHERE rowid = " . ((int)$id);
$res = $db->query($sql);

if (!$res || $db->num_rows($res) == 0) {
    dol_print_error($db, 'CT-e não encontrado');
    exit;
}

$obj = $db->fetch_object($res);

if (empty($obj->xml_enviado)) {
    dol_print_error($db, 'XML do CT-e não encontrado no banco de dados');
    exit;
}

// Preparar XML completo com protocolo
// O xml_recebido contém a resposta da SEFAZ com o protCTe
// Precisamos criar o XML protocolado (cteProc) que junta o CT-e + protocolo
$xmlCompleto = $obj->xml_enviado;

if (!empty($obj->xml_recebido)) {
    // Montar o XML protocolado (cteProc) que é o formato correto para o DACTE
    try {
        $xmlEnviado = new \DOMDocument('1.0', 'UTF-8');
        $xmlEnviado->loadXML($obj->xml_enviado);
        
        $xmlRecebido = new \DOMDocument('1.0', 'UTF-8');
        $xmlRecebido->loadXML($obj->xml_recebido);
        
        // Extrair o nó protCTe da resposta
        $protCTe = $xmlRecebido->getElementsByTagName('protCTe')->item(0);
        
        if ($protCTe) {
            // Criar o XML protocolado (cteProc)
            $cteProc = new \DOMDocument('1.0', 'UTF-8');
            $cteProc->formatOutput = false;
            $cteProc->preserveWhiteSpace = false;
            
            // Criar nó raiz cteProc
            $cteProcNode = $cteProc->createElementNS('http://www.portalfiscal.inf.br/cte', 'cteProc');
            $cteProcNode->setAttribute('versao', '4.00');
            $cteProc->appendChild($cteProcNode);
            
            // Importar o nó CTe completo
            $cteNode = $xmlEnviado->documentElement;
            $importedCte = $cteProc->importNode($cteNode, true);
            $cteProcNode->appendChild($importedCte);
            
            // Importar o nó protCTe
            $importedProt = $cteProc->importNode($protCTe, true);
            $cteProcNode->appendChild($importedProt);
            
            $xmlCompleto = $cteProc->saveXML();
        }
    } catch (Exception $e) {
        // Se houver erro ao montar o XML protocolado, usa o xml_enviado mesmo
        // mas o DACTE vai mostrar sem validade fiscal
        error_log("Erro ao montar XML protocolado: " . $e->getMessage());
    }
}

try {
    // Buscar logo da empresa (opcional)
    $logo = null;
    $logoPath = DOL_DOCUMENT_ROOT . '/documents/mycompany/logos/thumbs/' . $conf->global->MAIN_INFO_SOCIETE_LOGO;
    if (file_exists($logoPath)) {
        $logo = $logoPath;
    } else {
        // Tentar caminho alternativo
        $logoPath = DOL_DOCUMENT_ROOT . '/documents/mycompany/logos/' . $conf->global->MAIN_INFO_SOCIETE_LOGO;
        if (file_exists($logoPath)) {
            $logo = $logoPath;
        }
    }
    
    // Instanciação da classe DACTE Customizada
    // Usar o XML completo (protocolado) para ter validade fiscal
    $da = new DacteCustom($xmlCompleto);
    
    // Configurações do PDF
    $da->debugMode(false); // true para debug, false para produção
    $da->printParameters('P', 'A4'); // Portrait, A4
    $da->setDefaultFont('times');
    $da->setDefaultDecimalPlaces(2);
    
    // Adicionar logo se existir
    // if ($logo) {
    //     $da->logoParameters($logo, 'C', false); // Centralizado
    // }
    
    // Adicionar créditos do integrador (opcional)
    $da->creditsIntegratorFooter('Sistema Dolibarr - Módulo CT-e');
    
    // Renderizar o PDF
    $pdf = $da->render();
    
    // Nome do arquivo
    $filename = 'DACTE_'. $obj->chave .'.pdf';
    
    // Definir modo de exibição
    if ($mode === 'download') {
        // Forçar download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdf;
    } else {
        // Visualizar no navegador
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdf;
    }
    
} catch (InvalidArgumentException $e) {
    dol_print_error($db, "Erro ao processar DACTE: " . $e->getMessage());
} catch (Exception $e) {
    dol_print_error($db, "Erro ao gerar DACTE: " . $e->getMessage());
}
