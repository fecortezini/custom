<?php
// ATIVA exibição de erros para diagnóstico
@ini_set('display_errors', '1');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

// Testa se main.inc.php existe
$mainIncPath = '../../main.inc.php';
if (!file_exists($mainIncPath)) {
    die('<h1>Erro Fatal</h1><p>Arquivo main.inc.php não encontrado em: ' . realpath('.') . '/../../main.inc.php</p>');
}

require $mainIncPath;

// Testa se autoload existe
$autoloadPath = DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die('<h1>Erro Fatal</h1><p>Autoload do Composer não encontrado em: ' . $autoloadPath . '</p>');
}

require_once $autoloadPath;

use Dompdf\Dompdf;
use Dompdf\Options;

// Handler para download de DANFSe em PDF
if (GETPOST('action','alpha') === 'danfse_pdf') {
    $id = (int) GETPOST('id', 'int');
    
    // Funções auxiliares para extrair dados do XML
    $getTxt = function($ctx, $tag) {
        if (!$ctx) return '';
        $nl = $ctx->getElementsByTagName($tag);
        return ($nl && $nl->length > 0) ? trim((string)$nl->item(0)->nodeValue) : '';
    };
    
    $fmtMoney = function($v) {
        if ($v === '' || !is_numeric($v)) return '0,00';
        return number_format((float)$v, 2, ',', '.');
    };
    
    $esc = function($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    // Carrega registro do banco
    $sql = "SELECT xml_recebido FROM ".MAIN_DB_PREFIX."nfse_emitidas WHERE id = ".$id." LIMIT 1";
    $res = $db->query($sql);
    if (!$res || $db->num_rows($res) === 0) {
        http_response_code(404);
        die('<h1>Erro 404</h1><p>NFSe não encontrada (ID: '.$id.').</p>');
    }
    
    $row = $db->fetch_object($res);
    $xmlStr = html_entity_decode((string)$row->xml_recebido, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    
    if (trim($xmlStr) === '') {
        http_response_code(400);
        die('<h1>Erro 400</h1><p>Não há XML recebido para esta NFSe (ID: '.$id.').</p>');
    }

    // CRÍTICO: Validação robusta do XML
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xmlStr)) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $errorMsg = 'XML inválido';
        if (!empty($errors)) {
            $errorMsg .= ': ' . $errors[0]->message;
        }
        http_response_code(400);
        die('<h1>Erro 400</h1><p>'.$errorMsg.'</p>');
    }

    // Localiza InfNfse
    $infNfse = $dom->getElementsByTagName('InfNfse')->item(0);
    if (!$infNfse) {
        http_response_code(400);
        die('<h1>Erro 400</h1><p>Nó InfNfse não encontrado no XML.</p>');
    }

    // Extrai dados principais
    $numeroNf = $esc($getTxt($infNfse, 'Numero'));
    $codAut = $esc($getTxt($infNfse, 'CodigoVerificacao'));
    $dataEmissao = $esc($getTxt($infNfse, 'DataEmissao'));

    // Prestador
    $prest = $infNfse->getElementsByTagName('PrestadorServico')->item(0);
    $prestRazao = $esc($getTxt($prest, 'RazaoSocial'));
    $prestIM = $esc($getTxt($prest, 'InscricaoMunicipal'));
    $prestCnpj = '';
    if ($prest) {
        $idPrest = $prest->getElementsByTagName('IdentificacaoPrestador')->item(0);
        if ($idPrest) {
            $cpfCnpj = $idPrest->getElementsByTagName('CpfCnpj')->item(0);
            if ($cpfCnpj) $prestCnpj = $esc($getTxt($cpfCnpj, 'Cnpj') ?: $getTxt($cpfCnpj, 'Cpf'));
        }
    }
    
    $prestEndereco = $prestCep = $prestTel = $prestEmail = '';
    if ($prest) {
        $end = $prest->getElementsByTagName('Endereco')->item(0);
        if ($end) {
            $prestEndereco = $esc(trim(($getTxt($end,'Endereco').' '.$getTxt($end,'Numero')).' '.$getTxt($end,'Complemento')));
            $bairro = $esc($getTxt($end, 'Bairro'));
            if ($bairro) $prestEndereco .= ($prestEndereco ? ' - ' : '').$bairro;
            $prestCep = $esc($getTxt($end, 'Cep'));
        }
        $contato = $prest->getElementsByTagName('Contato')->item(0);
        if ($contato) {
            $prestTel = $esc($getTxt($contato, 'Telefone'));
            $prestEmail = $esc($getTxt($contato, 'Email'));
        }
    }

    // Tomador
    $tom = $infNfse->getElementsByTagName('TomadorServico')->item(0);
    $tomCnpjCpf = $tomIM = $tomRazao = '';
    $tomEnd = $tomNum = $tomCompl = $tomBairro = $tomCep = $tomCidadeUF = $tomTel = $tomEmail = '';
    if ($tom) {
        $idTom = $tom->getElementsByTagName('IdentificacaoTomador')->item(0);
        if ($idTom) {
            $cpfCnpj = $idTom->getElementsByTagName('CpfCnpj')->item(0);
            if ($cpfCnpj) $tomCnpjCpf = $esc($getTxt($cpfCnpj, 'Cnpj') ?: $getTxt($cpfCnpj, 'Cpf'));
        }
        $tomIM = $esc($getTxt($tom, 'InscricaoMunicipal'));
        $tomRazao = $esc($getTxt($tom, 'RazaoSocial'));
        $end = $tom->getElementsByTagName('Endereco')->item(0);
        if ($end) {
            $tomEnd = $esc($getTxt($end,'Endereco'));
            $tomNum = $esc($getTxt($end,'Numero'));
            $tomCompl = $esc($getTxt($end,'Complemento'));
            $tomBairro = $esc($getTxt($end,'Bairro'));
            $tomCep = $esc($getTxt($end,'Cep'));
            $tomCidadeUF = $esc(trim($getTxt($end,'CodigoMunicipio').' / '.$getTxt($end,'Uf')));
        }
        $contato = $tom->getElementsByTagName('Contato')->item(0);
        if ($contato) {
            $tomTel = $esc($getTxt($contato, 'Telefone'));
            $tomEmail = $esc($getTxt($contato, 'Email'));
        }
    }

    // Serviço
    $serv = $infNfse->getElementsByTagName('Servico')->item(0);
    $discriminacao = $esc($getTxt($serv, 'Discriminacao'));
    $codigoMunicipio = $esc($getTxt($serv, 'CodigoMunicipio'));
    $municipioInc = $esc($getTxt($serv, 'MunicipioIncidencia'));
    $itemListaServ = $esc($getTxt($serv, 'ItemListaServico'));
    $codTribMunicipio = $esc($getTxt($serv, 'CodigoTributacaoMunicipio'));
    $codCnae = $esc($getTxt($serv, 'CodigoCnae'));
    $issRetido = $esc($getTxt($serv, 'IssRetido'));
    $aliquota = $fmtMoney($getTxt($serv, 'Aliquota'));
    
    $val = $serv ? $serv->getElementsByTagName('Valores')->item(0) : null;
    $vServ = $fmtMoney($getTxt($val, 'ValorServicos'));
    $vDeduc = $fmtMoney($getTxt($val, 'ValorDeducoes'));
    $vPis = $fmtMoney($getTxt($val, 'ValorPis'));
    $vCofins = $fmtMoney($getTxt($val, 'ValorCofins'));
    $vInss = $fmtMoney($getTxt($val, 'ValorInss'));
    $vIr = $fmtMoney($getTxt($val, 'ValorIr'));
    $vCsll = $fmtMoney($getTxt($val, 'ValorCsll'));
    $vIss = $fmtMoney($getTxt($val, 'ValorIss'));
    $vOutras = $fmtMoney($getTxt($val, 'OutrasRetencoes'));
    $baseCalc = $fmtMoney($getTxt($val,'BaseCalculo') ?: $getTxt($val,'ValorServicos'));

    // RPS
    $idRps = $infNfse->getElementsByTagName('IdentificacaoRps')->item(0);
    $rpsNumero = $esc($getTxt($idRps, 'Numero'));
    $rpsSerie = $esc($getTxt($idRps, 'Serie'));
    $rpsData = $esc($getTxt($infNfse, 'DataEmissaoRps'));
    $naturezaOper = $esc($getTxt($serv, 'NaturezaOperacao'));

    // Prestador (campos adicionais opcionais)
    $prestNomeFantasia = $esc($getTxt($prest, 'NomeFantasia'));

    // Intermediário de serviço (opcional, padrão ABRASF 2.04)
    $intCnpj = $intIM = $intRazao = '';
    if ($serv) {
        $int = $serv->getElementsByTagName('IntermediarioServico')->item(0);
        if ($int) {
            $cpfCnpj = $int->getElementsByTagName('CpfCnpj')->item(0);
            if ($cpfCnpj) $intCnpj = $esc($getTxt($cpfCnpj, 'Cnpj') ?: $getTxt($cpfCnpj, 'Cpf'));
            $intIM   = $esc($getTxt($int, 'InscricaoMunicipal'));
            $intRazao= $esc($getTxt($int, 'RazaoSocial'));
        }
    }

    // Construção civil (opcional)
    $codObra = $art = '';
    if ($serv) {
        $cc = $serv->getElementsByTagName('ConstrucaoCivil')->item(0);
        if ($cc) {
            $codObra = $esc($getTxt($cc, 'CodigoObra'));
            $art     = $esc($getTxt($cc, 'Art'));
        }
    }

    // Decide CNPJ/CPF Prestador
    $prestCpf = '';
    if ($prestCnpj && strlen(preg_replace('/\D/','',$prestCnpj)) === 11) {
        $prestCpf = $prestCnpj; $prestCnpj = '';
    }

    // Decide CNPJ/CPF Tomador
    $tomCpf = ''; $tomCnpj = '';
    if ($tomCnpjCpf && strlen(preg_replace('/\D/','',$tomCnpjCpf)) === 11) $tomCpf = $tomCnpjCpf; else $tomCnpj = $tomCnpjCpf;

    // Responsável pela retenção
    $respRet = ($issRetido === '1' ? 'Tomador' : 'Prestador');

    // Valores derivados
    $valorIssqnRetido = ($issRetido === '1' ? $vIss : '0,00');
    $valorLiquido     = $vServ; // mantém layout, sem reprocessar

    // Fallbacks de exibição
    if ($municipioInc === '') $municipioInc = $codigoMunicipio;

    // NOVO: Preparação de dados e placeholders vindos do XML
    $formatDate = function($s) {
        if (!$s) return '';
        try {
            // Aceita formatos ISO 8601 do ABRASF
            $dt = new DateTime($s);
            return $dt->format('d/m/Y H:i:s');
        } catch (Throwable $e) {
            return $s; // mantém como veio
        }
    };

    $dataEmissaoFmt = $formatDate($dataEmissao) ?: date('d/m/Y H:i:s');
    $dataRpsFmt      = $formatDate($rpsData);

    // Alíquota em percentual
    $aliqRaw = (string) $getTxt($serv, 'Aliquota');
    $aliqNum = null;
    if ($aliqRaw !== '') {
        $aliqRawNorm = str_replace(',', '.', $aliqRaw);
        if (is_numeric($aliqRawNorm)) $aliqNum = (float)$aliqRawNorm;
    }
    $aliqPerc = $aliqNum !== null ? number_format($aliqNum * 100, 2, ',', '.') . '%' : ($aliquota !== '' ? $aliquota.'%' : '');

    // Exigibilidade do ISS
    $mapExig = [
        '1' => 'Exigível',
        '2' => 'Não Incidência',
        '3' => 'Isenção',
        '4' => 'Exportação',
        '5' => 'Imunidade',
        '6' => 'Suspensa por Decisão Judicial',
        '7' => 'Suspensa por Processo Administrativo'
    ];
    $exigText = $mapExig[$getTxt($serv, 'ExigibilidadeISS')] ?? '';

    // Regime tributário (ABRASF) + Simples
    $mapReg = [
        '1' => 'Microempresa Municipal',
        '2' => 'Estimativa',
        '3' => 'Sociedade de Profissionais',
        '4' => 'Cooperativa',
        '5' => 'MEI',
        '6' => 'Simples Nacional'
    ];
    $regimeCod = $getTxt($serv, 'RegimeEspecialTributacao');
    $optSn     = $getTxt($serv, 'OptanteSimplesNacional');
    $regimeTrib = $mapReg[$regimeCod] ?? ($optSn === '1' ? 'Optante do Simples Nacional' : 'Regime Normal');

    // Tipo de recolhimento
    $tipoRecol = $respRet . ($issRetido === '1' ? ' - ISS Retido' : ' - ISS Não Retido');

    // Documento tomador + endereço formatado
    $tomadorDoc = $tomCnpj ?: $tomCpf;
    $tomadorEndereco = trim(
        ($tomEnd ? $tomEnd : '') .
        ($tomNum ? ', '.$tomNum : '') .
        ($tomCompl ? ' - '.$tomCompl : '') .
        ($tomCidadeUF ? ' - '.$tomCidadeUF : '')
    );

    // Linha(s) de serviço (quando não há itemização, usa 1 linha com a discriminação)
    $codItem = $codTribMunicipio ?: $itemListaServ;
    $linhasTr = '<tr>'.
        '<td>1</td>'.
        '<td>'.$discriminacao.'</td>'.
        '<td>'.$codItem.'</td>'.
        '<td class="right">1</td>'.
        '<td class="right">'.$vServ.'</td>'.
        '<td class="right">'.$vServ.'</td>'.
    '</tr>';

    // Observações/Informações adicionais úteis na DANFSe
    $infoAdicParts = [];
    if ($codTribMunicipio) $infoAdicParts[] = 'Cód. Tributação Mun.: '.$codTribMunicipio;
    if ($itemListaServ)   $infoAdicParts[] = 'Item Lista: '.$itemListaServ;
    if ($codCnae)         $infoAdicParts[] = 'CNAE: '.$codCnae;
    if ($naturezaOper)    $infoAdicParts[] = 'Natureza da Operação: '.$naturezaOper;
    if ($intCnpj || $intRazao) {
        $infoAdicParts[] = 'Intermediário: '.trim($intRazao.' '.$intCnpj.' '.$intIM);
    }
    if ($codObra || $art) {
        $infoAdicParts[] = 'Construção Civil: Obra '.$codObra.' • ART '.$art;
    }
    $infoAdic = implode(' • ', array_filter($infoAdicParts));

    // Logo (busca arquivo local e embute como base64)
    $logoPref = '';
    foreach ([DOL_DOCUMENT_ROOT.'/custom/nfse/assets/brasao_cachoeiro.png', DOL_DOCUMENT_ROOT.'/custom/nfse/brasao.png'] as $cand) {
        if (is_file($cand)) {
            $logoPref = 'data:image/png;base64,'.base64_encode(@file_get_contents($cand));
            break;
        }
    }
    if ($logoPref === '') {
        // pixel transparente
        $logoPref = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
    }

    // URL de verificação e QR-Code
    $verifUrl = 'http://notafse.cachoeiro.es.gov.br/?nf='.rawurlencode($numeroNf).'&cod='.rawurlencode($codAut);
    $qrUrl    = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data='.urlencode($verifUrl);

    // Placeholders usados no HTML
    $tpl = [
        '{{logo_prefeitura}}' => $logoPref,
        '{{numero_nf}}' => $numeroNf,
        '{{data_emissao}}' => $dataEmissaoFmt,
        '{{codigo_verificacao}}' => $codAut,

        '{{prestador_razao_social}}' => $prestRazao,
        '{{prestador_nome_fantasia}}' => $prestNomeFantasia,
        '{{prestador_endereco}}' => $prestEndereco,
        '{{prestador_cep}}' => $prestCep,
        '{{prestador_telefone}}' => $prestTel,
        '{{prestador_email}}' => $prestEmail,
        '{{prestador_im}}' => $prestIM,
        '{{prestador_cnpj}}' => ($prestCnpj ?: $prestCpf),

        '{{local_prestacao}}' => ($municipioInc ?: $codigoMunicipio),
        '{{regime_tributario}}' => $regimeTrib,
        '{{qrcode_img}}' => $qrUrl,

        '{{tomador_razao_social}}' => $tomRazao,
        '{{tomador_doc}}' => $tomadorDoc,
        '{{tomador_im}}' => $tomIM,
        '{{tomador_endereco}}' => $tomadorEndereco,
        '{{tomador_bairro}}' => $tomBairro,
        '{{tomador_cep}}' => $tomCep,

        '{{data_emissao_hora}}' => $dataEmissaoFmt,
        '{{numero_rps}}' => $rpsNumero,
        '{{serie_rps}}' => $rpsSerie,

        '{{exigibilidade_iss}}' => $exigText,
        '{{tipo_recolhimento}}' => $tipoRecol,

        '{{descricao_servico}}' => $discriminacao,
        '{{linhas_servico}}' => $linhasTr,

        '{{valor_servico}}' => $vServ,
        '{{base_calculo}}' => $baseCalc,
        '{{aliquota}}' => $aliqPerc,
        '{{valor_iss}}' => $vIss,
        '{{valor_liquido}}' => $valorLiquido,

        '{{pis}}' => $vPis,
        '{{cofins}}' => $vCofins,
        '{{inss}}' => $vInss,
        '{{irrf}}' => $vIr,
        '{{csll}}' => $vCsll,

        '{{informacoes_adicionais}}' => $infoAdic
    ];

    // NOVO: HTML (mantido compatível com Dompdf, com caixas e títulos no padrão DANFSe)
    $html = '<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>NFSe - Modelo DOMPDF (Cópia)</title>
<style>
  /* Page */
  @page { size: A4; margin: 0mm; }
  html,body {margin:0; padding:0; font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; color:#000; }
  .sheet {
    padding:6mm; /* outer margin to simulate white border */
    box-sizing:border-box;
  }

  /* Outer container (white area like PDF) */
  .page {
    width:198mm; margin:0 auto; padding:6mm; box-sizing:border-box;
    border:2px solid #000; /* outer frame similar to original */
  }

  /* Reset table styles for predictable rendering in dompdf */
  table { border-collapse: collapse; border-spacing: 0; width:100%; }
  td, th { vertical-align: top; padding:3px 6px; font-size:10pt; }
  .small { font-size:9pt; }
  .xs { font-size:8pt; }

  /* Header top area */
  .header-table td { border:0; }
  #logo-prefeitura { width:86px; height:86px; object-fit:contain; border:1px solid #000; display:block; }
  .center-title { text-align:center; font-weight:700; font-size:12pt; }
  .center-sub { text-align:center; font-size:10pt; font-weight:700; margin-top:2px; }
  .verify { text-align:center; font-size:9pt; margin-top:2px; }

  /* Thin grid lines used throughout */
  .grid, .grid td, .grid th { border:1px solid #000; }
  .boxed { border:1px solid #000; }

  /* Blocks (mimic original) */
  .meta-table th, .meta-table td { padding:6px; font-size:10pt; }
  .section-title { text-align:center; font-weight:700; font-size:11pt; padding:4px 0; }

  /* Bold labels */
  .label { font-weight:700; }

  /* Right aligned big number (nº da nota) */
  .nota-number { font-size:28pt; font-weight:700; text-align:center; }

  /* Tables inside sections */
  .service-code { width:90px; font-weight:700; }
  .service-desc { }

  /* bottom tax table small cells */
  .tax-table td { font-size:9pt; padding:4px; }

  /* ensure boxes for the big empty areas (discriminação / observação) */
  .big-box { height:60mm; } /* adjust to visually match PDF */
  .mid-box { height:20mm; }

  /* some utility */
  .text-center { text-align:center; }
  .text-right { text-align:right; }
  .no-border { border:0 !important; }
</style>
</head>
<body>
  <div class="sheet">
    <div class="page">

      <!-- TOP HEADER: logo | central title | QR & small metadata -->
      <table class="header-table" style="width:100%; margin-bottom:4px;">
        <tr>
          <td style="width:110px; vertical-align:top;">
            <!-- PLACEHOLDER: adicione src no img abaixo -->
            <img id="logo-prefeitura" src="" alt="Logo Prefeitura">
          </td>

          <td style="vertical-align:top;">
            <div class="center-title">NOTA FISCAL DE SERVIÇOS ELETRÔNICA - NFSe</div>
            <div class="center-sub">Prefeitura Municipal de Cachoeiro Itapemirim</div>
            <div class="verify xs">Código de Verificação para Autenticação: <strong>251012054006486</strong></div>
            <div class="xs" style="text-align:center; margin-top:6px;">
              Endereço: Cachoeiro de Itapemirim, Espírito Santo, ES, 29300-100 &nbsp; • &nbsp;
              CNPJ: 27.165.588/0001-90
            </div>
          </td>

          <td style="width:120px; vertical-align:top; text-align:right;">
            <!-- QR placeholder -->
            <img id="qr-code" src="" alt="QR Code" style="width:96px; height:96px; object-fit:contain; border:1px solid #000; display:block; margin-left:auto;">
            <div class="xs" style="margin-top:4px; text-align:right;">Emitido em: 12/10/2025 05:40:07</div>
          </td>
        </tr>
      </table>

      <!-- Top metadata grid (single-row grid like original) -->
      <table class="grid meta-table" style="width:100%; margin-top:6px; font-size:10pt;">
        <tr>
          <td style="width:18%"><div class="label">Data Fato Gerador</div><div>12/10/2025</div></td>
          <td style="width:18%"><div class="label">Exigibilidade de ISS</div><div>Exigível</div></td>
          <td style="width:18%"><div class="label">Regime Tributário</div><div>Tributação Normal</div></td>
          <td style="width:18%"><div class="label">Número RPS</div><div>251012054006486</div></td>
          <td style="width:12%"><div class="label">Serie RPS</div><div>3</div></td>
          <td style="width:16%;"><div class="label">Nº da Nota Fiscal</div><div class="nota-number">33256</div></td>
        </tr>

        <tr>
          <td style="width:18%"><div class="label">Tipo de Recolhimento</div><div>Não Retido</div></td>
          <td style="width:18%"><div class="label">Simples</div><div>Optante</div></td>
          <td style="width:18%"><div class="label">Local de Prestação</div><div>3201209 - Cachoeiro de Itapemirim - ES</div></td>
          <td style="width:18%"><div class="label">Local de Recolhimento</div><div>3201209 - Cachoeiro de Itapemirim - ES</div></td>
          <td colspan="2" class="no-border"></td>
        </tr>
      </table>

      <!-- PRESTADOR block -->
      <table class="grid" style="width:100%; margin-top:6px;">
        <tr><td colspan="2" class="section-title">PRESTADOR</td></tr>
        <tr>
          <td style="width:50%; padding:6px;">
            <div class="label">Razão Social: ANDERSON GRASSELI DE SOUZA</div>
            <div class="small">Nome Fantasia: ACADEMIA CIA DO CORPO</div>
            <div class="xs">Endereço: Avenida DOMINGOS ALCINO DADALTO, 5 - MONTE CRISTO</div>
            <div class="xs">Cachoeiro de Itapemirim - ES - CEP: 29310-750</div>
            <div class="xs">E-mail: itapemirimcontabilidade@gmail.com - Fone: (28) 3522-1305 - Celular: (28)99919-3967 - Site: -------</div>
            <div class="xs">Inscrição Municipal: 26663 - CPF/CNPJ: 04.822.448/0001-41</div>
          </td>
          <td style="width:50%; padding:6px;">
            <div class="label">TOMADOR</div>
            <div class="xs">Razão Social: luis felipe cortezini andrade</div>
            <div class="xs">Endereço: Rua Jose Morgan, S/N - Caicara</div>
            <div class="xs">Cachoeiro de Itapemirim - ES - CEP: 29310351</div>
            <div class="xs">E-mail: felipe_cortezini@hotmail.com - CPF/CNPJ: 160.508.737-80</div>
          </td>
        </tr>
      </table>

      <!-- SERVIÇO block -->
      <table class="grid" style="width:100%; margin-top:6px;">
        <tr><td class="section-title" colspan="2">SERVIÇO</td></tr>
        <tr>
          <td class="service-code">604</td>
          <td class="service-desc">GINÁSTICA, DANÇA, ESPORTES, NATAÇÃO, ARTES</td>
        </tr>
      </table>

      <!-- DADOS CONSTRUÇÃO CIVIL (empty but boxed like original) -->
      <table class="grid" style="width:100%; margin-top:6px;">
        <tr><td class="section-title">DADOS CONSTRUÇÃO CIVIL</td></tr>
        <tr><td style="height:8mm;"></td></tr>
      </table>

      <!-- DISCRIMINAÇÃO DOS SERVIÇOS (big box) -->
      <table class="grid" style="width:100%; margin-top:6px;">
        <tr><td class="section-title">DISCRIMINAÇÃO DOS SERVIÇOS</td></tr>
        <tr>
          <td class="big-box xs">
            Pagamento Referente Prestacao de Servico e Condicionamento Fisico Contrato: 18816462 - Plano: Novo 1 Mod. (Anual) - Pagamento(s): 47180917
          </td>
        </tr>
      </table>

      <!-- OBSERVAÇÃO (empty mid-size box) -->
      <table class="grid" style="width:100%; margin-top:6px;">
        <tr><td class="section-title">OBSERVAÇÃO</td></tr>
        <tr><td class="mid-box"></td></tr>
      </table>

      <!-- VALORES / TABELA resumida -->
      <table class="grid" style="width:100%; margin-top:6px; font-size:10pt;">
        <tr>
          <td style="width:14%" class="text-center label">VALOR SERVIÇO (R$)</td>
          <td style="width:12%" class="text-center label">DEDUÇÕES (R$)</td>
          <td style="width:14%" class="text-center label">DESCONTO INCONDICIONAL (R$)</td>
          <td style="width:18%" class="text-center label">BASE CÁLCULO (R$)</td>
          <td style="width:12%" class="text-center label">ALÍQUOTA (%)</td>
          <td style="width:12%" class="text-center label">ISS (R$)</td>
          <td style="width:18%" class="text-center label">VALOR LÍQUIDO (R$)</td>
        </tr>
        <tr>
          <td class="text-center">130,00</td>
          <td class="text-center">0,00</td>
          <td class="text-center">0,00</td>
          <td class="text-center">130,00</td>
          <td class="text-center">2.00</td>
          <td class="text-center">2,60</td>
          <td class="text-center">130,00</td>
        </tr>
      </table>

      <!-- DEMONSTRATIVO DOS TRIBUTOS FEDERAIS -->
      <table class="grid tax-table" style="width:100%; margin-top:6px;">
        <tr>
          <td colspan="5" class="label text-center">DEMONSTRATIVO DOS TRIBUTOS FEDERAIS</td>
          <td class="label text-center">DESCONTO CONDICIONAL (R$)</td>
          <td class="label text-center">OUTRAS RETENÇÕES (R$)</td>
          <td class="label text-center">VALOR LÍQUIDO (R$)</td>
        </tr>
        <tr>
          <td class="text-center">INSS (R$)<br>0,00</td>
          <td class="text-center">IR (R$)<br>0,00</td>
          <td class="text-center">CSLL (R$)<br>0,00</td>
          <td class="text-center">COFINS (R$)<br>0,00</td>
          <td class="text-center">PIS (R$)<br>0,00</td>
          <td class="text-center">0,00</td>
          <td class="text-center">0,00</td>
          <td class="text-center">130,00</td>
        </tr>
      </table>

      <!-- OUTRAS INFORMAÇÕES -->
      <table class="grid" style="width:100%; margin-top:6px;">
        <tr><td class="label">OUTRAS INFORMAÇÕES</td></tr>
        <tr>
          <td class="xs">
            (Valor Líquido = Valor Serviço - INSS - IR - CSLL - Outras Retenções - COFINS - PIS - Descontos Diversos - ISS Retido - Desconto Incondicional)
            <br><br>
            CONSULTE A AUTENTICIDADE DESTE DOCUMENTO NO SITE: http://notafse.cachoeiro.es.gov.br , NA OPÇÃO AUTENTICAR DOCUMENTO FISCAL. ESTE DOCUMENTO FOI EMITIDO POR EMPRESA OPTANTE DO SIMPLES NACIONAL (Art. 23 DA LC 123/2006), DEVENDO NESTA CONDIÇÃO O PRESTADOR INFORMAR A ALÍQUOTA ENTRE 2 A 5%, CONFORME TABELA DE ENQUADRAMENTO DE ACORDO COM O SEU FATURAMENTO. O RECOLHIMENTO DO ISSQN É REALIZADO VIA DAS EMITIDO PELA RECEITA FEDERAL DO BRASIL.
          </td>
        </tr>
      </table>

    </div> <!-- page -->
  </div> <!-- sheet -->
</body>
</html>
';

    // Substitui os placeholders do HTML pelos valores do XML
    $html = strtr($html, $tpl);

    // GERA PDF COM DOMPDF (compatível com PHP 8.2)
    try {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream('NFSe_'.$numeroNf.'.pdf', ['Attachment' => 1]);
        exit;

    } catch (Exception $e) {
        error_log('[NFSe PDF] Erro: ' . $e->getMessage());
        http_response_code(500);
        echo '<h1>Erro ao gerar PDF</h1>';
        echo '<p>'.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</p>';
        exit;
    }
}
?>
