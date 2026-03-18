# Sistema de Tributação do CT-e - Documentação para Contador

## 1. VISÃO GERAL DO SISTEMA

O módulo CT-e (Conhecimento de Transporte Eletrônico) está implementado seguindo a **versão 4.00** do layout da SEFAZ, com suporte completo para diferentes regimes tributários.

### 1.1 Regimes Tributários Suportados

✅ **Lucro Real** - Tributação normal com ICMS calculado sobre BC integral ou reduzida
✅ **Lucro Presumido** - Tributação normal com ICMS calculado sobre BC integral ou reduzida  
✅ **Simples Nacional** - Regime simplificado sem destaque de ICMS no CT-e
⚠️ **Isentos/Imunes** - Suporte para CST 40/41/51 sem cálculo de ICMS

---

## 2. CÓDIGOS DE SITUAÇÃO TRIBUTÁRIA (CST) IMPLEMENTADOS

### 2.1 CST 00 - Tributação Normal

**Quando usar:**
- Empresa no Lucro Real ou Presumido
- Operação tributada normalmente
- Sem benefícios fiscais

**Cálculo Implementado:**
