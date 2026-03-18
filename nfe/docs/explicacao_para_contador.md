# Explicação (para contadores) — Como o sistema usa as regras fiscais

Resumo rápido
- O sistema identifica a operação pelo CFOP (e pelas UFs origem/destino). O CFOP é a chave primária para buscar a regra fiscal.  
- Se existir regra específica por NCM ela tem prioridade sobre a regra genérica por CFOP.  
- A regra fiscal contém apenas as alíquotas e parâmetros (MVA, alíquotas, crédito ICMS, PIS/COFINS, IPI, etc.).  
- O CSOSN é calculado dinamicamente pela função fiscal (foi validada por contadores). O sistema NÃO decide o CSOSN a partir da tabela de regras — usa as alíquotas da regra para aplicar os cálculos.

Fluxo passo-a-passo (alto nível)
1. Determina CFOP do item (função determinarCfopPorItem): usa origem/destino, origem do produto e regime para decidir CFOP.  
2. Busca regra: getTaxRule(db, product, mysoc, dest, cfop) → retorna registro ativo (UF origem, UF destino, CFOP, opcional NCM).  
3. Calcula CSOSN: determinarCSOSN(...) — função que considera regime do produto, CRT do emitente e contexto do destinatário.  
4. Aplica alíquotas da regra:
   - ICMS crédito (CSOSN 101/201): vCred = vProd * (icms_cred_aliq / 100)
   - ICMS-ST (CSOSN 201/202/203): 
     - BC_ST = vProd * (1 + MVA/100) * (1 - red_bc/100)
     - ICMS_próprio = vProd * (icms_aliq_interestadual / 100)
     - vICMSST = max( (BC_ST * icms_st_aliq/100) - ICMS_próprio , 0 )
   - PIS/COFINS: vPIS = vProd * (pis_aliq/100); vCOFINS = vProd * (cofins_aliq/100)
5. Totalizadores: para Simples Nacional o campo vICMS do total é ZERO; vST e vBCST são preenchidos conforme cálculos; crédito ICMS é armazenado separadamente (informativo).

Campos principais da regra (o que um contador precisa preencher)
- uf_origin, uf_dest, cfop, (ncm opcional)
- icms_aliq_interna, icms_aliq_interestadual, icms_cred_aliq
- icms_st_mva, icms_st_aliq, icms_st_red_bc
- pis_cst, pis_aliq, cofins_cst, cofins_aliq
- ipi_cst, ipi_aliq, ipi_cenq
- active, date_start/date_end (vigência)

Prioridade e vigência
- Regra ativa (active = 1) e dentro do período date_start/date_end.
- Procura primeiro regra com NCM específico; se não houver, usa regra genérica por CFOP.
- Indique na descrição (label) rota, vigência e observações legais para facilitar auditoria.

Boas práticas e pontos de verificação (para o contador)
- Preencher MVA e alíquotas ST com base na legislação estadual vigente; indicar fonte legal no label ou observações.  
- Registrar se a regra é por NCM (produto específico) ou genérica por CFOP.  
- Quando houver dúvidas sobre crédito ICMS, confirme se o destinatário permite crédito (regime dele) — o sistema calcula CSOSN, mas o cadastro da alíquota define o valor do crédito.  
- Testar casos: venda interna, venda interestadual para contribuinte, venda interestadual para não contribuinte (DIFAL), produtos imunes/isenções, devolução.  
- Revisar logs (error.log): o sistema gera logs por item com resumo de CFOP, CSOSN, alíquotas e valores calculados — útil para conferência.

Exemplo prático (resumo)
- Operação: Venda interestadual com ST para contribuinte. CFOP → regra com icms_st_mva=40%, icms_st_aliq=18%, icms_aliq_interestadual=12%.  
- Cálculo: BC_ST = vProd * 1.40 ; vICMSProprio = vProd * 12% ; vICMSST = (BC_ST * 18%) - vICMSProprio (min 0).  

Onde revisar no sistema
- Lista de regras: Custom → NFe → Regras Fiscais (onde uf/cfop/ncm/aliquotas são cadastradas).  
- Logs de emissão: arquivos de log Apache/PHP (erro e debug) exibem detalhamento por item.  
- Função determinarCSOSN: responsabilidade de escolher CSOSN (mantida por contadores).

Conclusão curta
- Continue cadastrando regras por CFOP (com NCM quando necessário). CFOP é a causa; CSOSN é a consequência. O sistema aplica as alíquotas da regra para fazer os cálculos e usa a função de CSOSN (auditada) para definir o tratamento tributário correto.
