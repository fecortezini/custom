# Documentação Simples do Cálculo de Impostos (para contador)

Este documento explica, de forma simples, como o nosso sistema aplica e calcula impostos nas NF-e, o que é necessário para ativar uma regra e como a função de regras fiscais (taxrules) determina qual regra usar.

---

## 1. Visão geral (em uma frase)
Cada item da nota procura uma "regra fiscal" no banco de dados; essa regra diz as alíquotas e parâmetros (ICMS, ST, PIS, COFINS). Se não encontrar regra específica, o sistema tenta um fallback mais genérico. Com a regra definida, aplica-se fórmulas simples (porcentagens) para calcular os valores.

---

## 2. Fluxo simplificado por item (passo-a-passo)
1. Determina CFOP do item (ver seção 4).
2. Busca a regra fiscal aplicável (função taxrules / getTaxRule - seção 3).
3. Se encontrar regra:
   - Calcula ICMS (ou CSOSN) conforme o regime do produto.
   - Se houver Substituição Tributária (ST), calcula base ST (com MVA e redução) e o ICMS-ST.
   - Calcula PIS e COFINS como % sobre o valor do produto.
4. Acumula totais da nota (vProd, vPIS, vCOFINS, vST, etc.).
5. Define CFOP principal da nota pelo grupo de maior valor.

---

## 3. Como funciona a função de regras fiscais (getTaxRule)
Objetivo: retornar a regra (linha da tabela de regras) que indica alíquotas e parâmetros para uma operação.

Prioridade da busca (ordem aplicada):
1. Regra específica por NCM:
   - Deve corresponder exatamente a: uf_origin, uf_dest, cfop e NCM do produto, e estar marcada como ativa.
   - Se encontrada, é aplicada imediatamente (maior prioridade).
2. Regra genérica por CFOP:
   - Mesmos uf_origin, uf_dest e cfop, mas NCM = NULL ou vazio (regra que vale para qualquer NCM naquele CFOP).
   - Usada se a específica não existir.
3. Tratamento de devolução:
   - Se for devolução e não houver regra para o CFOP de entrada, o sistema tenta usar uma regra equivalente do CFOP de saída mapeado (fallback adicional).
4. Se nenhuma regra for encontrada:
   - O sistema lança erro crítico e bloqueia a emissão até que a regra seja criada/ajustada.

Observação: a busca exige que a regra esteja marcada `active = 1`.

---

## 4. Como o sistema determina CFOP (simples)
- Por item, o CFOP é decidido por:
  - Se operação interestadual e destinatário consumidor final não contribuinte → CFOP de DIFAL (ex.: 6108).
  - Pelo "regime ICMS" do produto (campo prd_regime_icms):
    - Regime 1 (normal): 5102 (interna) / 6102 (interestadual)
    - Regime 2 (substituto): 5403 / 6403
    - Regime 3 (substituído): 5405 / 6404 (ou similar)
    - Regimes 4/5/6 (isento, não tributado, suspenso): usam CFOP de venda normal; tributações tratadas por CSOSN.
- CFOP principal da nota = CFOP cujo grupo de itens soma o maior valor total.

---

## 5. Fórmulas usadas (fáceis de entender)
- PIS:
  - vPIS = vProd × (pis_aliq / 100)
- COFINS:
  - vCOFINS = vProd × (cofins_aliq / 100)
- ICMS próprio (quando aplicável):
  - vICMS = vProd × (aliquota_icms / 100)  (dependendo do CSOSN/CSOSN tratado)
- ICMS Substituição Tributária (ST):
  1. BC_ST_inicial = vProd × (1 + MVA/100)
  2. BC_ST_final = BC_ST_inicial × (1 - reducao_percentual/100)  (se houver redução)
  3. ICMS_próprio = vProd × (aliquota_icms_interestadual / 100)
  4. ICMS_ST = (BC_ST_final × aliquota_icms_st / 100) - ICMS_próprio
  5. Se ICMS_ST resultar negativo → considera-se 0

Obs.: Nomes das colunas na tabela de regras usadas pelo sistema: icms_st_mva, icms_st_aliq, icms_st_predbc, icms_interestadual_aliq, pis_aliq, cofins_aliq, icms_cred_aliq, etc.

---

## 6. O que é necessário para "ativar" um imposto / regra (checklist técnico)
Para que o sistema aplique corretamente a tributação de um item, é necessário:
- Produto:
  - ter NCM preenchido (extrafields: prd_ncm).
  - ter regime ICMS definido (extrafields: prd_regime_icms).
  - ter preço/quantidade/total corretos nos campos usados (preco_venda_semtaxa, quantidade, total_semtaxa).
- Destinatário:
  - ter UF correta e indicador de tipo (indiedest) para distinguir contribuinte/consumidor.
- Criar/atualizar uma linha em llx_custom_tax_rules (ou tabela equivalente) com:
  - uf_origin, uf_dest
  - cfop (exato)
  - ncm (opcional; se vazio = regra genérica para o CFOP)
  - marcar active = 1
  - preencher alíquotas e parâmetros relevantes (pis_aliq, cofins_aliq, icms_st_mva, icms_st_aliq, icms_interestadual_aliq, icms_cred_aliq, icms_st_predbc etc.)
- Testar com nota de homologação (ambiente de teste) antes de produção.

---

## 7. Exemplo prático (rápido)
- Venda de produto com vProd = R$100, pis_aliq = 0.65%, cofins_aliq = 3%:
  - vPIS = 100 × 0.0065 = R$0,65
  - vCOFINS = 100 × 0.03 = R$3,00
- Se ST com MVA 40% e aliquota ST 18% e aliquota própria 12%:
  - BC_ST_inicial = 100 × 1.40 = 140
  - ICMS_próprio = 100 × 0.12 = 12
  - ICMS_ST = (140 × 0.18) - 12 = 25.2 - 12 = R$13,20

---

## 8. Pontos que o contador deve validar / aprovar
- Conferir se a prioridade de regras (NCM específico → CFOP genérico → fallback de devolução) está alinhada com a política tributária da empresa.
- Validar alíquotas e MVAs cadastradas na tabela de regras.
- Confirmar tratamento de CSOSN/CSOSN (simples nacional) e casos de crédito de ICMS (campo icms_cred_aliq).
- Revisar mapeamentos de CFOP para devolução.
- Testar cenários: venda interna, interestadual para contribuinte, interestadual para consumidor final (DIFAL), devolução, ST, isenção.

---

## 9. Observações finais
- O sistema depende diretamente da existência de regras ativas no banco. Se uma operação não encontrar regra, a emissão falha: é responsabilidade operacional garantir a cobertura das combinações mais comuns.
- Recomenda-se que o contador revise e aprove uma planilha com todas as regras (uf_orig × uf_dest × cfop × ncm) antes de ativar em produção.

Se desejar, gero uma planilha CSV com os campos mínimos que precisamos para cada regra (uf_origin, uf_dest, cfop, ncm, active, pis_aliq, cofins_aliq, icms_st_mva, icms_st_aliq, icms_st_predbc, icms_interestadual_aliq, icms_cred_aliq) para facilitar a validação.
