<?php
function gerarXmlRps($dados) {
    $dom = new DOMDocument('1.0','UTF-8');
    $dom->formatOutput = true;
    $ns = 'http://www.abrasf.org.br/nfse.xsd';

    $root = $dom->createElementNS($ns, 'EnviarLoteRpsSincronoEnvio');
    $root->setAttribute('xmlns:xsi','http://www.w3.org/2001/XMLSchema-instance');
    $root->setAttribute('xsi:schemaLocation','http://www.abrasf.org.br/nfse.xsd');
    $dom->appendChild($root);

    $numeroLote = str_pad($dados['numeroLote'],5,'0',STR_PAD_LEFT);
    $versao = '2.04';

    $lote = $dom->createElementNS($ns,'LoteRps');
    $lote->setAttribute('Id',$numeroLote);
    $lote->setAttribute('versao',$versao);
    $root->appendChild($lote);

    $lote->appendChild($dom->createElementNS($ns,'NumeroLote',$numeroLote));

    $prestLote = $dom->createElementNS($ns,'Prestador');
    $cpfCnpjL = $dom->createElementNS($ns,'CpfCnpj');
    $cpfCnpjL->appendChild($dom->createElementNS($ns,'Cnpj', $dados['prestadorCnpj']));
    $prestLote->appendChild($cpfCnpjL);
    $prestLote->appendChild($dom->createElementNS($ns,'InscricaoMunicipal',$dados['prestadorIM']));
    $lote->appendChild($prestLote);

    $qtd = count($dados['rps']);
    $lote->appendChild($dom->createElementNS($ns,'QuantidadeRps',$qtd));

    $lista = $dom->createElementNS($ns,'ListaRps');
    $lote->appendChild($lista);

    $r = $dados['rps'][0];
    $padNumeroRps = str_pad($r['numero'],5,'0',STR_PAD_LEFT);
    $prestCnpjDigits = preg_replace('/\D+/','',$dados['prestadorCnpj']);

    $rpsWrapper = $dom->createElementNS($ns,'Rps');
    $lista->appendChild($rpsWrapper);

    $inf = $dom->createElementNS($ns,'InfDeclaracaoPrestacaoServico');
    $rpsWrapper->appendChild($inf);

    $rps = $dom->createElementNS($ns,'Rps');
    $rps->setAttribute('Id', $padNumeroRps);
    $inf->appendChild($rps);

    $idRps = $dom->createElementNS($ns,'IdentificacaoRps');
    $idRps->appendChild($dom->createElementNS($ns,'Numero',$r['numero']));
    $idRps->appendChild($dom->createElementNS($ns,'Serie',$r['serie']));
    $idRps->appendChild($dom->createElementNS($ns,'Tipo',$r['tipo']));
    $rps->appendChild($idRps);

    $rps->appendChild($dom->createElementNS($ns,'DataEmissao',$r['dataEmissao']));
    $rps->appendChild($dom->createElementNS($ns,'Status',$r['status']));

    if (!empty($r['rpsSubstituido'])) {
        $sub = $dom->createElementNS($ns,'RpsSubstituido');
        $sub->appendChild($dom->createElementNS($ns,'Numero',$r['rpsSubstituido']['numero']));
        $sub->appendChild($dom->createElementNS($ns,'Serie',$r['rpsSubstituido']['serie']));
        $sub->appendChild($dom->createElementNS($ns,'Tipo',$r['rpsSubstituido']['tipo']));
        $rps->appendChild($sub);
    }

    $inf->appendChild($dom->createElementNS($ns,'Competencia',$r['competencia']));

    $servico = $dom->createElementNS($ns,'Servico');
    $valores = $dom->createElementNS($ns,'Valores');

    $valorServicosFmt = number_format((float)$r['valorServicos'],1,'.','');
    $valores->appendChild($dom->createElementNS($ns,'ValorServicos',$valorServicosFmt));

    $two = function($v){ return number_format((float)$v,2,'.',''); };
    $valores->appendChild($dom->createElementNS($ns,'ValorDeducoes',$two($r['valorDeducoes'])));
    $valores->appendChild($dom->createElementNS($ns,'ValorPis',$two($r['valorPis'])));
    $valores->appendChild($dom->createElementNS($ns,'ValorCofins',$two($r['valorCofins'])));
    $valores->appendChild($dom->createElementNS($ns,'ValorInss',$two($r['valorInss'])));
    $valores->appendChild($dom->createElementNS($ns,'ValorIr',$two($r['valorIr'])));
    $valores->appendChild($dom->createElementNS($ns,'ValorCsll',$two($r['valorCsll'])));
    $valores->appendChild($dom->createElementNS($ns,'OutrasRetencoes',$two($r['outrasRetencoes'] ?? 0)));
    $valores->appendChild($dom->createElementNS($ns,'ValorIss', number_format((float)$r['valorIss'],2,'.','')));
    $valores->appendChild($dom->createElementNS($ns,'Aliquota', number_format((float)$r['aliquota'],2,'.','')));
    $valores->appendChild($dom->createElementNS($ns,'DescontoIncondicionado',$two($r['descontoIncondicionado'] ?? 0)));
    $valores->appendChild($dom->createElementNS($ns,'DescontoCondicionado',$two($r['descontoCondicionado'] ?? 0)));
    $servico->appendChild($valores);

    $servico->appendChild($dom->createElementNS($ns,'IssRetido',$r['issRetido']));
    $servico->appendChild($dom->createElementNS($ns,'ItemListaServico',$r['itemListaServico']));
    if (!empty($r['codigoTributacaoMunicipio']))
        $servico->appendChild($dom->createElementNS($ns,'CodigoTributacaoMunicipio',$r['codigoTributacaoMunicipio']));
    $servico->appendChild($dom->createElementNS($ns,'Discriminacao',$r['discriminacao']));
    $servico->appendChild($dom->createElementNS($ns,'CodigoMunicipio',$r['codigoMunicipio']));
    if (!empty($r['exigibilidadeISS']))
        $servico->appendChild($dom->createElementNS($ns,'ExigibilidadeISS',$r['exigibilidadeISS']));
    if (!empty($r['municipioIncidencia']))
        $servico->appendChild($dom->createElementNS($ns,'MunicipioIncidencia',$r['municipioIncidencia']));
    $inf->appendChild($servico);

    $prest = $dom->createElementNS($ns,'Prestador');
    $cpfCnpj = $dom->createElementNS($ns,'CpfCnpj');
    $cpfCnpj->appendChild($dom->createElementNS($ns,'Cnpj',$prestCnpjDigits));
    $prest->appendChild($cpfCnpj);
    $prest->appendChild($dom->createElementNS($ns,'InscricaoMunicipal',$dados['prestadorIM']));
    $inf->appendChild($prest);

    $tom = $dom->createElementNS($ns,'TomadorServico');
    $idTom = $dom->createElementNS($ns,'IdentificacaoTomador');
    $cpfCnpjTom = $dom->createElementNS($ns,'CpfCnpj');
    if (!empty($r['tomadorCpf'])) {
        $cpfCnpjTom->appendChild($dom->createElementNS($ns,'Cpf', preg_replace('/\D+/','',$r['tomadorCpf'])));
    } else {
        $cpfCnpjTom->appendChild($dom->createElementNS($ns,'Cnpj', preg_replace('/\D+/','',$r['tomadorCnpj'])));
    }
    $idTom->appendChild($cpfCnpjTom);
    if (!empty($r['tomadorIM'])) {
        $idTom->appendChild($dom->createElementNS($ns,'InscricaoMunicipal',$r['tomadorIM']));
    }
    $tom->appendChild($idTom);
    $tom->appendChild($dom->createElementNS($ns,'RazaoSocial',$r['tomadorRazao']));
    $end = $dom->createElementNS($ns,'Endereco');
    $end->appendChild($dom->createElementNS($ns,'Endereco',$r['tomadorEndereco']));
    $end->appendChild($dom->createElementNS($ns,'Numero',$r['tomadorNumero']));
    if (!empty($r['tomadorBairro']))
        $end->appendChild($dom->createElementNS($ns,'Bairro',$r['tomadorBairro']));
    if (!empty($r['tomadorMunicipio']))
        $end->appendChild($dom->createElementNS($ns,'CodigoMunicipio',$r['tomadorMunicipio']));
    if (!empty($r['tomadorUF']))
        $end->appendChild($dom->createElementNS($ns,'Uf',$r['tomadorUF']));
    if (!empty($r['tomadorCep']))
        $end->appendChild($dom->createElementNS($ns,'Cep',$r['tomadorCep']));
    $tom->appendChild($end);
    if (!empty($r['tomadorEmail'])) {
        $cont = $dom->createElementNS($ns,'Contato');
        $cont->appendChild($dom->createElementNS($ns,'Email',$r['tomadorEmail']));
        $tom->appendChild($cont);
    }
    $inf->appendChild($tom);

    if (!empty($r['regimeEspecialTributacao']))
        $inf->appendChild($dom->createElementNS($ns,'RegimeEspecialTributacao',$r['regimeEspecialTributacao']));
    $inf->appendChild($dom->createElementNS($ns,'OptanteSimplesNacional',$r['optanteSimples']));
    $inf->appendChild($dom->createElementNS($ns,'IncentivoFiscal',$r['incentivoFiscal']));

    $xml = $dom->saveXML();

    $xml = preg_replace(
        '/<EnviarLoteRpsSincronoEnvio[^>]*>/',
        "<EnviarLoteRpsSincronoEnvio\n\txmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xsi:schemaLocation=\"http://www.abrasf.org.br/nfse.xsd\"\n\txmlns=\"http://www.abrasf.org.br/nfse.xsd\">",
        $xml,
        1
    );

    return $xml;
}
