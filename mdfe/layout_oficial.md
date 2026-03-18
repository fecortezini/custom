#, Nome Campo, Nível, Descrição, Elemento, Tipo, Ocorrencia., Tamanho, Domínio, Exp.Reg., Observações.
1 infMDFe 0 Informações do MDF-e G 1 - 1
2 versao 1 Versão do leiaute A N 1 - 1 ER51 Ex: "3.00"
3 Id 1 Identificador da tag a ser assinada A C 1 - 1 48 ER46 Informar a chave de acesso do MDF-e e
precedida do literal "MDFe"
4 ide 1 Identificação do MDF-e G 1 - 1
5 cUF 2 Código da UF do emitente do MDF-e E N 1 - 1 2 D1 Código da UF do emitente do Documento
Fiscal. Utilizar a
Tabela do IBGE de código de unidades
da federação.
6 tpAmb 2 Tipo do Ambiente E N 1 - 1 1 D6 1 - Produção
2 - Homologação
7 tpEmit 2 Tipo do Emitente E N 1 - 1 1 D7 1 - Prestador de serviço de transporte
2 - Transportador de Carga Própria
3 - Prestador de serviço de transporte
que emitirá CT-e Globalizado
OBS: Deve ser preenchido com 2 para
emitentes de NF-e e pelas
transportadoras quando estiverem
fazendo transporte de carga própria.
Deve ser preenchido com 3 para
transportador de carga que emitirá à
posteriori CT-e Globalizado relacionando
as NF-e.
8 tpTransp 2 Tipo do Transportador E N 0 - 1 1 D7 1 - ETC
2 - TAC
3 - CTC
9 mod 2 Modelo do Manifesto Eletrônico E N 1 - 1 2 D4 Utilizar o código 58 para identificação do
MDF-e 
10 serie 2 Série do Manifesto E N 1 - 1 1 - 3 ER32 Informar a série do documento fiscal
(informar zero se inexistente).
Série na faixa [920-969]: Reservada para
emissão por contribuinte pessoa física
com inscrição estadual.
11 nMDF 2 Número do Manifesto E N 1 - 1 1 - 9 ER31 Número que identifica o Manifesto. 1 a
999999999.
12 cMDF 2 Código numérico que compõe a Chave
de Acesso.
E N 1 - 1 8 ER41 Código aleatório gerado pelo emitente,
com o objetivo de evitar acessos
indevidos ao documento.
13 cDV 2 Digito verificador da chave de acesso do
Manifesto
E N 1 - 1 1 ER42 Informar o dígito de controle da chave de
acesso do MDF-e, que deve ser
calculado com a aplicação do algoritmo
módulo 11 (base 2,9) da chave de
acesso.
14 modal 2 Modalidade de transporte E N 1 - 1 1 D9 1 - Rodoviário;
2 - Aéreo;
3 - Aquaviário;
4 - Ferroviário.
15 dhEmi 2 Data e hora de emissão do Manifesto E C 1 - 1 21 ER1 Formato AAAA-MM-DDTHH:MM:DD TZD
16 tpEmis 2 Forma de emissão do Manifesto (Normal
ou Contingência)
E N 1 - 1 1 D6 1 - Normal
2 - Contingência
17 procEmi 2 Identificação do processo de emissão do
Manifesto
E N 1 - 1 1 D12 0 - emissão de MDF-e com aplicativo do
contribuinte;
18 verProc 2 Versão do processo de emissão E C 1 - 1 1 - 20 ER35 Informar a versão do aplicativo emissor
de MDF-e.
19 UFIni 2 Sigla da UF do Carregamento E C 1 - 1 2 D5 Utilizar a Tabela do IBGE de código de
unidades da federação.
Informar 'EX' para operações com o
exterior.
20 UFFim 2 Sigla da UF do Descarregamento E C 1 - 1 2 D5 Utilizar a Tabela do IBGE de código de
unidades da federação.
Informar 'EX' para operações com o
exterior.
21 infMunCarrega 2 Informações dos Municípios de
Carregamento
G 1 - 50
22 cMunCarrega 3 Código do Município de Carregamento E N 1 - 1 7 ER2
23 xMunCarrega 3 Nome do Município de Carregamento E C 1 - 1 2 - 60 ER35
24 infPercurso 2 Informações do Percurso do MDF-e G 0 - 25
25 UFPer 3 Sigla das Unidades da Federação do
percurso do veículo.
E C 1 - 1 2 D5 Não é necessário repetir as UF de Início
e Fim
26 dhIniViagem 2 Data e hora previstos de início da viagem E C 0 - 1 21 ER1 Formato AAAA-MM-DDTHH:MM:DD TZD
27 indCanalVerde 2 Indicador de participação do Canal Verde E N 0 - 1 1 D10
28 indCarregaPosterior 2 Indicador de MDF-e com inclusão da
Carga posterior a emissão por evento de
inclusão de DF-e
E N 0 - 1 1 D10
29 emit 1 Identificação do Emitente do Manifesto G 1 - 1
30 CNPJ 2 CNPJ do emitente CE N 1 - 1 14 ER7 Informar zeros não significativos
31 CPF 2 CPF do emitente CE N 1 - 1 11 ER10 Informar zeros não significativos
Usar com série específica 920-969 para
emitente pessoa física com inscrição
estadual
32 IE 2 Inscrição Estadual do emitente E N 1 - 1 2 - 14 ER30
33 xNome 2 Razão social ou Nome do emitente E C 1 - 1 2 - 60 ER35
34 xFant 2 Nome fantasia do emitente E C 0 - 1 1 - 60 ER35
35 enderEmit 2 Endereço do emitente G 1 - 1
36 xLgr 3 Logradouro E C 1 - 1 2 - 60 ER35
37 nro 3 Número E C 1 - 1 1 - 60 ER35
38 xCpl 3 Complemento E C 0 - 1 1 - 60 ER35
39 xBairro 3 Bairro E C 1 - 1 2 - 60 ER35
40 cMun 3 Código do município (utilizar a tabela do
IBGE), informar 9999999 para operações
com o exterior.
E N 1 - 1 7 ER2 
41 xMun 3 Nome do município, , informar
EXTERIOR para operações com o
exterior.
E C 1 - 1 2 - 60 ER35
42 CEP 3 CEP E N 0 - 1 8 ER41 Informar zeros não significativos
43 UF 3 Sigla da UF, , informar EX para
operações com o exterior.
E C 1 - 1 2 D5
44 fone 3 Telefone E N 0 - 1 7 - 12 ER48
45 email 3 Endereço de E-mail E C 0 - 1 6 - 60 ER55
46 infModal 1 Informações do modal G 1 - 1
47 versaoModal 2 Versão do leiaute específico para o
Modal
A N 1 - 1 4 ER43
48 xs:any 2 XML do modal E XML 1 - 1 Insira neste local o XML específico do
modal (rodoviário, aéreo, ferroviário ou
Aquaviário).
49 infDoc 1 Informações dos Documentos fiscais
vinculados ao manifesto
G 1 - 1
50 infMunDescarga 2 Informações dos Municípios de
descarregamento
G 1 -
100
51 cMunDescarga 3 Código do Município de
Descarregamento
E N 1 - 1 7 ER2
52 xMunDescarga 3 Nome do Município de Descarregamento E C 1 - 1 2 - 60 ER35
53 infCTe 3 Conhecimentos de Transporte - usar este
grupo quando for prestador de serviço de
transporte
G 0 -
10000
54 chCTe 4 Conhecimento Eletrônico - Chave de
Acesso
E N 1 - 1 44 ER3
55 SegCodBarra 4 Segundo código de barras E N 0 - 1 36 ER4
56 indReentrega 4 Indicador de Reentrega E N 0 - 1 1 D10
57 infUnidTransp 4 Informações das Unidades de Transporte
(Carreta/Reboque/Vagão)
G 0 - n Deve ser preenchido com as informações
das unidades de transporte utilizadas. 
58 tpUnidTransp 5 Tipo da Unidade de Transporte E N 1 - 1 1 D8 1 - Rodoviário Tração;
2 - Rodoviário Reboque;
3 - Navio;
4 - Balsa;
5 - Aeronave;
6 - Vagão;
7 - Outros
59 idUnidTransp 5 Identificação da Unidade de Transporte E C 1 - 1 1 - 20 ER54 Informar a identificação conforme o tipo
de unidade de transporte.
Por exemplo: para rodoviário tração ou
reboque deverá preencher com a placa
do veículo.
60 lacUnidTransp 5 Lacres das Unidades de Transporte G 0 - n
61 nLacre 6 Número do lacre E C 1 - 1 1 - 20 ER35
62 infUnidCarga 5 Informações das Unidades de Carga
(Containeres/ULD/Outros)
G 0 - n Dispositivo de carga utilizada (Unit Load
Device - ULD) significa todo tipo de
contêiner de carga, vagão, contêiner de
avião, palete de aeronave com rede ou
palete de aeronave com rede sobre um
iglu.
63 tpUnidCarga 6 Tipo da Unidade de Carga E N 1 - 1 1 D9 1 - Container;
2 - ULD;
3 - Pallet;
4 - Outros;
64 idUnidCarga 6 Identificação da Unidade de Carga E C 1 - 1 1 - 20 ER54 Informar a identificação da unidade de
carga, por exemplo: número do container.
65 lacUnidCarga 6 Lacres das Unidades de Carga G 0 - n
66 nLacre 7 Número do lacre E C 1 - 1 1 - 20 ER35
67 qtdRat 6 Quantidade rateada (Peso,Volume) E N 0 - 1 3, 2 ER15 5 posições, sendo 3 inteiras e 2 decimais.
68 qtdRat 5 Quantidade rateada (Peso,Volume) E N 0 - 1 3, 2 ER15 5 posições, sendo 3 inteiras e 2 decimais.
69 peri 4 Preenchido quando for transporte de produtos classificados pela ONU como perigosos. G 0 - n
70 nONU 5 Número ONU/UN E C 1 - 1 4 ER44 Ver a legislação de transporte de produtos perigosos aplicadas ao modal
71 xNomeAE 5 Nome apropriado para embarque do produto E C 0 - 1 1 - 150 ER35 Ver a legislação de transporte de produtos perigosos aplicada ao modo de transporte
72 xClaRisco 5 Classe ou subclasse/divisão, e risco subsidiário/risco secundário E C 0 - 1 1 - 40 ER35 Ver a legislação de transporte de produtos perigosos aplicadas ao modal
73 grEmb 5 Grupo de Embalagem E C 0 - 1 1 - 6 ER35 Ver a legislação de transporte de produtos perigosos aplicadas ao modal Preenchimento obrigatório para o modal aéreo.
A legislação para o modal rodoviário e ferroviário não atribui grupo de embalagem para todos os produtos, portanto haverá casos de nãopreenchimento desse campo.
74 qTotProd 5 Quantidade total por produto E C 1 - 1 1 - 20 ER35 Preencher conforme a legislação de transporte de produtos perigosos aplicada ao modal
75 qVolTipo 5 Quantidade e Tipo de volumes E C 0 - 1 1 - 60 ER35 Preencher conforme a legislação de transporte de produtos perigosos aplicada ao modal
76 infEntregaParcial 4 Grupo de informações da Entrega Parcial (Corte de Voo) G 0 - 1
77 qtdTotal 5 Quantidade total de volumes E N 1 - 1 11, 4 ER21 15 posições, sendo 11 inteiras e 4 decimais.
78 qtdParcial 5 Quantidade de volumes enviados no MDF-e E N 1 - 1 11, 4 ER21 15 posições, sendo 11 inteiras e 4 decimais.
79 infNFe 3 Nota Fiscal Eletronica G 0 - 10000
80 chNFe 4 Nota Fiscal Eletrônica E N 1 - 1 44 ER3
81 SegCodBarra 4 Segundo código de barras E N 0 - 1 36 ER4
82 indReentrega 4 Indicador de Reentrega E N 0 - 1 1 D10
83 infUnidTransp 4 Informações das Unidades de Transporte (Carreta/Reboque/Vagão) G 0 - n Deve ser preenchido com as informações das unidades de transporte utilizadas.
84 tpUnidTransp 5 Tipo da Unidade de Transporte E N 1 - 1 1 D8 1 - Rodoviário Tração;
2 - Rodoviário Reboque;
3 - Navio;
4 - Balsa;
5 - Aeronave;
6 - Vagão;
7 - Outros
85 idUnidTransp 5 Identificação da Unidade de Transporte E C 1 - 1 1 - 20 ER54 Informar a identificação conforme o tipo de unidade de transporte.
Por exemplo: para rodoviário tração ou reboque deverá preencher com a placa do veículo.
86 lacUnidTransp 5 Lacres das Unidades de Transporte G 0 - n
87 nLacre 6 Número do lacre E C 1 - 1 1 - 20 ER35
88 infUnidCarga 5 Informações das Unidades de Carga (Containeres/ULD/Outros) G 0 - n Dispositivo de carga utilizada (Unit Load Device - ULD) significa todo tipo de contêiner de carga, vagão, contêiner de avião, palete de aeronave com rede ou palete de aeronave com rede sobre um iglu.
89 tpUnidCarga 6 Tipo da Unidade de Carga E N 1 - 1 1 D9 1 - Container;
2 - ULD;
3 - Pallet;
4 - Outros;
90 idUnidCarga 6 Identificação da Unidade de Carga E C 1 - 1 1 - 20 ER54 Informar a identificação da unidade de carga, por exemplo: número do container.
91 lacUnidCarga 6 Lacres das Unidades de Carga G 0 - n
92 nLacre 7 Número do lacre E C 1 - 1 1 - 20 ER35
93 qtdRat 6 Quantidade rateada (Peso,Volume) E N 0 - 1 3, 2 ER15 5 posições, sendo 3 inteiras e 2 decimais.
94 qtdRat 5 Quantidade rateada (Peso,Volume) E N 0 - 1 3, 2 ER15 5 posições, sendo 3 inteiras e 2 decimais.
95 peri 4 Preenchido quando for transporte de produtos classificados pela ONU como perigosos. G 0 - n
96 nONU 5 Número ONU/UN E C 1 - 1 4 ER44 Ver a legislação de transporte de produtos perigosos aplicadas ao modal
97 xNomeAE 5 Nome apropriado para embarque do produto E C 0 - 1 1 - 150 ER35 Ver a legislação de transporte de produtos perigosos aplicada ao modo de transporte
98 xClaRisco 5 Classe ou subclasse/divisão, e risco subsidiário/risco secundário E C 0 - 1 1 - 40 ER35 Ver a legislação de transporte de produtos perigosos aplicadas ao modal
99 grEmb 5 Grupo de Embalagem E C 0 - 1 1 - 6 ER35 Ver a legislação de transporte de produtos perigosos aplicadas ao modal Preenchimento obrigatório para o modal aéreo.
A legislação para o modal rodoviário e ferroviário não atribui grupo de embalagem para todos os produtos, portanto haverá casos de não preenchimento desse campo.
100 qTotProd 5 Quantidade total por produto E C 1 - 1 1 - 20 ER35 Preencher conforme a legislação de transporte de produtos perigosos aplicada ao modal
101 qVolTipo 5 Quantidade e Tipo de volumes E C 0 - 1 1 - 60 ER35 Preencher conforme a legislação de transporte de produtos perigosos aplicada ao modal
102 infMDFeTransp 3 Manifesto Eletrônico de Documentos Fiscais. Somente para modal Aquaviário (vide regras MOC) G 0 - 10000
103 chMDFe 4 Manifesto Eletrônico de Documentos Fiscais E N 1 - 1 44 ER3 
104 indReentrega 4 Indicador de Reentrega E N 0 - 1 1 D10
105 infUnidTransp 4 Informações das Unidades de Transporte (Carreta/Reboque/Vagão) G 0 - n Dispositivo de carga utilizada (Unit Load Device - ULD) significa todo tipo de
contêiner de carga, vagão, contêiner de
avião, palete de aeronave com rede ou palete de aeronave com rede sobre um
iglu
106 tpUnidTransp 5 Tipo da Unidade de Transporte E N 1 - 1 1 D8 1 - Rodoviário Tração;
2 - Rodoviário Reboque;
3 - Navio;
4 - Balsa;
5 - Aeronave;
6 - Vagão;
7 - Outros
107 idUnidTransp 5 Identificação da Unidade de Transporte E C 1 - 1 1 - 20 ER54 Informar a identificação conforme o tipo de unidade de transporte.
Por exemplo: para rodoviário tração ou reboque deverá preencher com a placa do veículo.
108 lacUnidTransp 5 Lacres das Unidades de Transporte G 0 - n
109 nLacre 6 Número do lacre E C 1 - 1 1 - 20 ER35
110 infUnidCarga 5 Informações das Unidades de Carga (Containeres/ULD/Outros) G 0 - n Dispositivo de carga utilizada (Unit Load Device - ULD) significa todo tipo de
contêiner de carga, vagão, contêiner de
avião, palete de aeronave com rede ou
palete de aeronave com rede sobre um
iglu.
111 tpUnidCarga 6 Tipo da Unidade de Carga E N 1 - 1 1 D9 1 - Container;
2 - ULD;
3 - Pallet;
4 - Outros;
112 idUnidCarga 6 Identificação da Unidade de Carga E C 1 - 1 1 - 20 ER54 Informar a identificação da unidade de carga, por exemplo: número do container.
113 lacUnidCarga 6 Lacres das Unidades de Carga G 0 - n
114 nLacre 7 Número do lacre E C 1 - 1 1 - 20 ER35
115 qtdRat 6 Quantidade rateada (Peso,Volume) E N 0 - 1 3, 2 ER15 5 posições, sendo 3 inteiras e 2 decimais.
116 qtdRat 5 Quantidade rateada (Peso,Volume) E N 0 - 1 3, 2 ER15 5 posições, sendo 3 inteiras e 2 decimais
117 peri 4 Preenchido quando for transporte de produtos classificados pela ONU como perigosos. G 0 - n
118 nONU 5 Número ONU/UN E C 1 - 1 4 ER44 Ver a legislação de transporte de produtos perigosos aplicadas ao modal
119 xNomeAE 5 Nome apropriado para embarque do produto E C 0 - 1 1 - 150 ER35 Ver a legislação de transporte de produtos perigosos aplicada ao modo de transporte
120 xClaRisco 5 Classe ou subclasse/divisão, e risco subsidiário/risco secundário E C 0 - 1 1 - 40 ER35 Ver a legislação de transporte de produtos perigosos aplicadas ao modal
121 grEmb 5 Grupo de Embalagem E C 0 - 1 1 - 6 ER35 Ver a legislação de transporte de produtos perigosos aplicadas ao modal Preenchimento obrigatório para o modal aéreo.
A legislação para o modal rodoviário e
ferroviário não atribui grupo de
embalagem para todos os produtos,
portanto haverá casos de não
preenchimento desse campo.
122 qTotProd 5 Quantidade total por produto E C 1 - 1 1 - 20 ER35 Preencher conforme a legislação de transporte de produtos perigosos aplicada ao modal
123 qVolTipo 5 Quantidade e Tipo de volumes E C 0 - 1 1 - 60 ER35 Preencher conforme a legislação de transporte de produtos perigosos aplicada ao modal
124 seg 1 Informações de Seguro da Carga G 0 - n
125 infResp 2 Informações do responsável pelo seguro da carga G 1 - 1
126 respSeg 3 Responsável pelo seguro E N 1 - 1 1 - 1 D6 Preencher com:
1- Emitente do MDF-e;
2 - Responsável pela contratação do
serviço de transporte (contratante)
Dados obrigatórios apenas no modal
Rodoviário, depois da lei 11.442/07. Paraos demais modais esta informação é
opcional.
# -- X -- 3 Sequencia XML - - 0 - 1
127 CNPJ 3 Número do CNPJ do responsável pelo seguro CE N 1 - 1 14 ER7 Obrigatório apenas se responsável pelo seguro for (2) responsável pela contratação do transporte - pessoa jurídica
128 CPF 3 Número do CPF do responsável pelo seguro CE N 1 - 1 11 ER10 Obrigatório apenas se responsável pelo seguro for (2) responsável pela contratação do transporte - pessoa física
129 infSeg 2 Informações da seguradora G 0 - 1
130 xSeg 3 Nome da Seguradora E C 1 - 1 1 - 30 ER35
131 CNPJ 3 Número do CNPJ da seguradora E N 1 - 1 14 ER9 Obrigatório apenas se responsável pelo seguro for (2) responsável pela contratação do transporte - pessoa jurídica
132 nApol 2 Número da Apólice E C 0 - 1 1 - 20 ER35 Obrigatório pela lei 11.442/07 (RCTRC)
133 nAver 2 Número da Averbação E C 0 - n 1 - 40 ER35 Informar as averbações do seguro
134 tot 1 Totalizadores da carga transportada e seus documentos fiscais G 1 - 1
135 qCTe 2 Quantidade total de CT-e relacionados no Manifesto E N 0 - 1 1 - 6 ER45
136 qNFe 2 Quantidade total de NF-e relacionadas no Manifesto E N 0 - 1 1 - 6 ER45
137 qMDFe 2 Quantidade total de MDF-e relacionados no Manifesto Aquaviário E N 0 - 1 1 - 6 ER45
138 vCarga 2 Valor total da carga / mercadorias transportadas E N 1 - 1 13, 2 ER27 15 posições, sendo 13 inteiras e 2 decimais.
139 cUnid 2 Código da unidade de medida do Peso Bruto da Carga / Mercadorias transportadas E N 1 - 1 2 D11 01 – KG; 02 - TON
140 qCarga 2 Peso Bruto Total da Carga / Mercadorias transportadas E N 1 - 1 11, 4 ER21 15 posições, sendo 11 inteiras e 4 decimais.
141 lacres 1 Lacres do MDF-e G 0 - n Preenchimento opcional para os modais Rodoviário e Ferroviário
142 nLacre 2 número do lacre E C 1 - 1 1 - 60 ER35
143 autXML 1 Autorizados para download do XML do DF-e G 0 - 10 Informar CNPJ ou CPF. Preencher os zeros não significativos.
144 CNPJ 2 CNPJ do autorizado CE N 1 - 1 14 ER7 Informar zeros não significativos
145 CPF 2 CPF do autorizado CE N 1 - 1 11 ER10 Informar zeros não significativos
146 infAdic 1 Informações Adicionais G 0 - 1
147 infAdFisco 2 Informações adicionais de interesse do Fisco E C 0 - 1 1 - 2000 ER35 Norma referenciada, informações complementares, etc
148 infCpl 2 Informações complementares de interesse do Contribuinte E C 0 - 1 1 - 5000 ER35
149 infRespTec 1 Informações do Responsável Técnico pela emissão do DF-e G 0 - 1
150 CNPJ 2 CNPJ da pessoa jurídica responsável técnica pelo sistema utilizado na emissão do documento fiscal eletrônico E N 1 - 1 14 ER7 Informar o CNPJ da pessoa jurídica
desenvolvedora do sistema utilizado na emissão do documento fiscal eletrônico.
151 xContato 2 Nome da pessoa a ser contatada E C 1 - 1 2 - 60 ER35 Informar o nome da pessoa a ser
contatada na empresa desenvolvedora do
sistema utilizado na emissão do
documento fiscal eletrônico. No caso de
pessoa física, informar o respectivo nome.
152 email 2 E-mail da pessoa jurídica a ser contatada E C 1 - 1 1 - 60 ER55
153 fone 2 Telefone da pessoa jurídica a ser contatada E N 1 – 1 7 - 12 ER48 Preencher com o Código DDD + número do telefone.
# --- X --- 2 Sequencia XML - - 0 – 1
154 idCSRT 2 Identificador do código de segurança do
responsável técnico
E N 1 - 1 3 ER6 Identificador do CSRT utilizado para
geração do hash 
155 hashCSRT 2 Hash do token do código de segurança
do responsável técnico
E C 1 - 1 28 O hashCSRT é o resultado das funções
SHA-1 e base64 do token CSRT
fornecido pelo fisco + chave de acesso
do DF-e. (Implementação em futura NT)
Observação: 28 caracteres são
representados no schema como 20 bytes
do tipo base64Binary
156 infMDFeSupl 0 Informações suplementares do MDF-e G 0 - 1
157 qrCodMDFe 1 Texto com o QR-Code para consulta do
MDF-e
E C 1 - 1 50 -
1000
ER62
158 0 ds:Signature E C 1 - 1 

# Modal rodoviário:
#, Campo, Nível, Descriçã, Ele, Tipo, Ocorr., Tamanho, Domínio, Exp.Reg., Observações
1 rodo 0 Informações do modal Rodoviário G 1 - 1
2 infANTT 1 Grupo de informações para Agência
Reguladora
G 0 - 1
3 RNTRC 2 Registro Nacional de Transportadores
Rodoviários de Carga
E N 0 - 1 8 ER41 Registro obrigatório do emitente do MDFe junto à ANTT para exercer a atividade
de transportador rodoviário de cargas por
conta de terceiros e mediante
remuneração.
4 infCIOT 2 Dados do CIOT G 0 - n
5 CIOT 3 Código Identificador da Operação de
Transporte
E N 1 - 1 12 ER56 Também Conhecido como conta frete
6 CPF 3 Número do CPF responsável pela
geração do CIOT
CE N 1 - 1 11 ER10 Informar os zeros não significativos.
7 CNPJ 3 Número do CNPJ responsável pela
geração do CIOT
CE N 1 - 1 14 ER9 Informar os zeros não significativos.
8 valePed 2 Informações de Vale Pedágio G 0 - 1 Outras informações sobre Vale-Pedágio
obrigatório que não tenham campos
específicos devem ser informadas no
campo de observações gerais de uso
livre pelo contribuinte, visando atender as
determinações legais vigentes.
9 disp 3 Informações dos dispositivos do Vale
Pedágio
G 1 - n
10 CNPJForn 4 CNPJ da empresa fornecedora do ValePedágio
E N 1 - 1 14 ER7 - CNPJ da Empresa Fornecedora do
Vale-Pedágio, ou seja, empresa que
fornece ao Responsável pelo Pagamento
do Vale-Pedágio os dispositivos do ValePedágio.
- Informar os zeros não significativos.
# -- X -- 4 Sequencia XML - - 0 – 1









2.1 Leiaute do Modal Rodoviário
# Campo Nível Descrição Ele Tipo Ocorr. Tamanho Domínio Exp.Reg. Observações
1 rodo 0 Informações do modal Rodoviário G 1 - 1
2 infANTT 1 Grupo de informações para Agência
Reguladora
G 0 - 1
3 RNTRC 2 Registro Nacional de Transportadores
Rodoviários de Carga
E N 0 - 1 8 ER41 Registro obrigatório do emitente do MDFe junto à ANTT para exercer a atividade
de transportador rodoviário de cargas por
conta de terceiros e mediante
remuneração.
4 infCIOT 2 Dados do CIOT G 0 - n
5 CIOT 3 Código Identificador da Operação de
Transporte
E N 1 - 1 12 ER56 Também Conhecido como conta frete
6 CPF 3 Número do CPF responsável pela
geração do CIOT
CE N 1 - 1 11 ER10 Informar os zeros não significativos.
7 CNPJ 3 Número do CNPJ responsável pela
geração do CIOT
CE N 1 - 1 14 ER9 Informar os zeros não significativos.
8 valePed 2 Informações de Vale Pedágio G 0 - 1 Outras informações sobre Vale-Pedágio
obrigatório que não tenham campos
específicos devem ser informadas no
campo de observações gerais de uso
livre pelo contribuinte, visando atender as
determinações legais vigentes.
9 disp 3 Informações dos dispositivos do Vale
Pedágio
G 1 - n
10 CNPJForn 4 CNPJ da empresa fornecedora do ValePedágio
E N 1 - 1 14 ER7 - CNPJ da Empresa Fornecedora do
Vale-Pedágio, ou seja, empresa que
fornece ao Responsável pelo Pagamento
do Vale-Pedágio os dispositivos do ValePedágio.
- Informar os zeros não significativos.
# -- X -- 4 Sequencia XML - - 0 – 1
11 CNPJPg 4 CNPJ do responsável pelo pagamento do
Vale-Pedágio
CE N 1 - 1 14 ER9 - Responsável pelo pagamento do Vale
Pedágio. Informar somente quando o
responsável não for o emitente do MDFe.
- Informar os zeros não significativos.
12 CPFPg 4 CNPJ do responsável pelo pagamento do
Vale-Pedágio
CE N 1 - 1 11 ER10 Informar os zeros não significativos.
13 nCompra 4 Número do comprovante de compra E N 1 - 1 1 - 20 ER57 Número de ordem do comprovante de
compra do Vale-Pedágio fornecido para
cada veículo ou combinação veicular, por
viagem.
14 vValePed 4 Valor do Vale-Pedagio E N 1 - 1 13, 2 ER27 15 posições, sendo 13 inteiras e 2
decimais.
Número de ordem do comprovante de
compra do Vale-Pedágio fornecido para
cada veículo ou combinação veicular, por
viagem.
15 infContratante 2 Grupo de informações dos contratantes
do serviço de transporte
G 0 - n
16 CPF 3 Número do CPF do contratente do
serviço
CE N 1 - 1 11 ER10 Informar os zeros não significativos.
17 CNPJ 3 Número do CNPJ do contratante do
serviço
CE N 1 - 1 14 ER9 Informar os zeros não significativos.
18 veicTracao 1 Dados do Veículo com a Tração G 1 - 1
19 cInt 2 Código interno do veículo E C 0 - 1 1 - 10 ER35
20 placa 2 Placa do veículo E C 1 - 1 4 ER40
21 RENAVAM 2 RENAVAM do veículo E C 0 - 1 9 - 11 ER35
22 tara 2 Tara em KG E N 1 - 1 1 - 6 ER58
23 capKG 2 Capacidade em KG E N 0 - 1 1 - 6 ER58
24 capM3 2 Capacidade em M3 E N 0 - 1 1 - 3 ER32
25 prop 2 Proprietários do Veículo.
Só preenchido quando o veículo não
pertencer à empresa emitente do MDF-e
G 0 - 1
26 CPF 3 Número do CPF CE N 1 - 1 11 ER10 Informar os zeros não significativos.
27 CNPJ 3 Número do CNPJ CE N 1 - 1 14 ER9 Informar os zeros não significativos.
28 RNTRC 3 Registro Nacional dos Transportadores
Rodoviários de Carga
E N 1 - 1 8 ER41 Registro obrigatório do proprietário,
coproprietário ou arrendatário do veículo
junto à ANTT para exercer a atividade de
transportador rodoviário de cargas por
conta de terceiros e mediante
remuneração.
29 xNome 3 Razão Social ou Nome do proprietário E C 1 - 1 2 - 60 ER35
# -- X -- 3 Sequencia XML - - 0 – 1
30 IE 3 Inscrição Estadual E C 1 - 1 0 - 14 ER29
31 UF 3 UF E C 1 - 1 2 D5
32 tpProp 3 Tipo Proprietário E N 1 - 1 1 D14 Preencher com:
0-TAC – Agregado;
1-TAC Independente; ou
2 – Outros.
33 condutor 2 Informações do(s) Condutor(es) do
veículo
G 1 - 10
34 xNome 3 Nome do Condutor E C 1 - 1 2 - 60 ER35
35 CPF 3 CPF do Condutor E N 1 - 1 11 ER10
36 tpRod 2 Tipo de Rodado E N 1 - 1 2 D15 Preencher com:
01 - Truck;
02 - Toco;
03 - Cavalo Mecânico;
04 - VAN;
05 - Utilitário;
06 - Outros.
37 tpCar 2 Tipo de Carroceria E N 1 - 1 2 D16 Preencher com:
00 - não aplicável;
01 - Aberta;
02 - Fechada/Baú;
03 - Granelera;
04 - Porta Container;
05 - Sider
38 UF 2 UF em que veículo está licenciado E C 1 - 1 2 D5 Sigla da UF de licenciamento do veículo.
39 veicReboque 1 Dados dos reboques G 0 - 3
40 cInt 2 Código interno do veículo E C 0 - 1 1 - 10 ER35
41 placa 2 Placa do veículo E C 1 - 1 4 ER40
42 RENAVAM 2 RENAVAM do veículo E C 0 - 1 9 - 11 ER35
43 tara 2 Tara em KG E N 1 - 1 1 - 6 ER58
44 capKG 2 Capacidade em KG E N 1 - 1 1 - 6 ER58
45 capM3 2 Capacidade em M3 E N 0 - 1 1 - 3 ER32
46 prop 2 Proprietários do Veículo.
Só preenchido quando o veículo não
pertencer à empresa emitente do MDF-e
G 0 - 1
47 CPF 3 Número do CPF CE N 1 - 1 11 ER10 Informar os zeros não significativos.
48 CNPJ 3 Número do CNPJ CE N 1 - 1 14 ER9 Informar os zeros não significativos.
49 RNTRC 3 Registro Nacional dos Transportadores
Rodoviários de Carga
E N 1 - 1 8 ER41 Registro obrigatório do proprietário,
coproprietário ou arrendatário do veículo
junto à ANTT para exercer a atividade de
transportador rodoviário de cargas por
conta de terceiros e mediante
remuneração.
50 xNome 3 Razão Social ou Nome do proprietário E C 1 - 1 1 - 60 ER35
# -- X -- 3 Sequencia XML - - 0 – 1
51 IE 3 Inscrição Estadual E C 1 - 1 0 - 14 ER29
52 UF 3 UF E C 1 - 1 2 D5
53 tpProp 3 Tipo Proprietário E N 1 - 1 1 D14 Preencher com:
0-TAC Agregado;
1-TAC Independente;
2 – Outros.
54 tpCar 2 Tipo de Carroceria E N 1 - 1 2 D16 Preencher com:
00 - não aplicável;
01 - Aberta;
02 - Fechada/Baú;
03 - Granelera;
04 - Porta Container;
05 - Sider
55 UF 2 UF em que veículo está licenciado E C 1 - 1 2 D5 Sigla da UF de licenciamento do veículo.
56 codAgPorto 1 Código de Agendamento no porto E C 0 - 1 0 - 16 ER35
57 lacRodo 1 Lacres G 0 - n
58 nLacre 2 Número do Lacre E C 1 - 1 1 - 20 ER35