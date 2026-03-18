# ✅ CHECKLIST - CT-e Pronto para Produção

## 📋 PRÉ-REQUISITOS

### 1. Certificado Digital
- [ ] Certificado A1 instalado (arquivo .pfx)
- [ ] Certificado válido (verificar data de vencimento)
- [ ] Senha do certificado configurada
- [ ] Caminho do certificado correto em `/custom/cte/certificado.pfx`

### 2. Credenciamento SEFAZ
- [ ] Empresa credenciada na SEFAZ do estado
- [ ] CNPJ autorizado para emissão de CT-e
- [ ] Ambiente de homologação testado
- [ ] Ambiente de produção autorizado

### 3. Configurações do Sistema
- [ ] PHP 7.4 ou superior
- [ ] Extensões PHP: openssl, soap, curl, zip, xml
- [ ] Biblioteca NFePHP instalada via Composer
- [ ] Permissões de pasta configuradas (755)
- [ ] Pasta `/custom/cte/xml/` criada e com permissão de escrita
- [ ] Pasta `/custom/cte/logs/` criada e com permissão de escrita

---

## 🔧 CONFIGURAÇÕES DOLIBARR

### 1. Dados da Empresa (mysoc)
- [ ] CNPJ configurado corretamente
- [ ] Inscrição Estadual configurada
- [ ] Endereço completo e correto
- [ ] Código do município (IBGE) correto
- [ ] Telefone e e-mail configurados

### 2. Configurações do Módulo
- [ ] CTE_CERT_PASSWORD configurado
- [ ] CTE_PROXIMO_NUMERO iniciado (geralmente "1")
- [ ] CTE_AMBIENTE definido (1=Produção, 2=Homologação)
- [ ] CTE_SERIE_PADRAO definido

---

## 🧪 TESTES OBRIGATÓRIOS

### 1. Ambiente de Homologação
- [ ] Emitir CT-e de teste com dados fictícios
- [ ] Verificar retorno da SEFAZ (código 100 = sucesso)
- [ ] Validar XML gerado no validador da SEFAZ
- [ ] Testar todos os CST de ICMS (00, 20, 40, 60, 90, SN)
- [ ] Testar com e sem campos opcionais
- [ ] Testar importação de XML de NF-e

### 2. Validações do Sistema
- [ ] Testar validação de campos obrigatórios
- [ ] Testar máscara de CNPJ/CPF
- [ ] Testar cálculo automático de ICMS
- [ ] Testar navegação entre etapas
- [ ] Testar botão "Limpar Dados"
- [ ] Testar mensagens de erro

### 3. Casos de Teste
- [ ] CT-e Normal (tipo 0)
- [ ] CT-e Complemento (tipo 1)
- [ ] CT-e Substituição (tipo 3)
- [ ] Modal Rodoviário
- [ ] Tomador = Remetente
- [ ] Tomador = Destinatário
- [ ] Operação interna (mesmo estado)
- [ ] Operação interestadual

---

## 📄 VALIDAÇÃO DE DADOS

### 1. Dados do Remetente
- [ ] CNPJ válido
- [ ] Inscrição Estadual válida
- [ ] Código de município existe na tabela IBGE
- [ ] CEP válido
- [ ] Telefone no formato correto

### 2. Dados do Destinatário
- [ ] CNPJ/CPF válido
- [ ] Código de município existe na tabela IBGE
- [ ] CEP válido
- [ ] Endereço completo

### 3. Valores e Impostos
- [ ] Valor da prestação maior que zero
- [ ] Valor a receber = Valor da prestação
- [ ] ICMS calculado corretamente
- [ ] CST compatível com regime tributário
- [ ] Alíquota de ICMS correta para a UF

### 4. Documentos Vinculados
- [ ] Chave da NF-e com 44 dígitos
- [ ] Chave da NF-e válida (dígito verificador)
- [ ] NF-e existe e está autorizada

---

## 🚨 VALIDAÇÕES DE SEGURANÇA

### 1. Certificado Digital
- [ ] Certificado não está vencido
- [ ] Certificado pertence ao CNPJ correto
- [ ] Senha do certificado está protegida (não no código)
- [ ] Arquivo .pfx não está acessível via HTTP

### 2. Dados Sensíveis
- [ ] Senhas não são exibidas em logs
- [ ] XMLs com dados sensíveis protegidos
- [ ] Backup automático de XMLs ativado

### 3. Logs e Auditoria
- [ ] Sistema de logs implementado
- [ ] Logs rotacionados mensalmente
- [ ] Logs de erro separados dos logs de sucesso
- [ ] Registro de todas as emissões no banco

---

## 🔄 TESTES DE INTEGRAÇÃO

### 1. Importação de NF-e
- [ ] Upload de XML funcional
- [ ] Dados extraídos corretamente
- [ ] Destinatário preenchido
- [ ] Valores preenchidos
- [ ] Chave da NF-e capturada

### 2. Cálculos Automáticos
- [ ] ICMS CST 00 calculado
- [ ] ICMS CST 20 calculado (com redução)
- [ ] Sincronização de valores entre campos
- [ ] Lei da Transparência (se implementada)

---

## 📊 MONITORAMENTO E MANUTENÇÃO

### 1. Após Implantação
- [ ] Monitorar logs diariamente (primeiros 30 dias)
- [ ] Verificar taxa de rejeição pela SEFAZ
- [ ] Validar numeração sequencial
- [ ] Conferir backup de XMLs

### 2. Manutenção Preventiva
- [ ] Atualizar certificado antes do vencimento
- [ ] Atualizar biblioteca NFePHP periodicamente
- [ ] Revisar logs de erro mensalmente
- [ ] Fazer backup de XMLs mensalmente

### 3. Suporte ao Usuário
- [ ] Documentação disponível
- [ ] Manual de uso criado
- [ ] Treinamento dos usuários realizado
- [ ] Canal de suporte definido

---

## ⚠️ PONTOS DE ATENÇÃO

### Erros Comuns
1. **Certificado vencido** - Renovar 30 dias antes
2. **Numeração duplicada** - Verificar controle de numeração
3. **Código de município inválido** - Usar tabela IBGE atualizada
4. **Alíquota de ICMS incorreta** - Verificar legislação estadual
5. **Chave NF-e inválida** - Validar dígito verificador

### Limites e Restrições
- **Tamanho máximo do XML:** 500KB
- **Timeout SEFAZ:** 30 segundos
- **Tentativas de envio:** 3 tentativas com 2s de intervalo
- **Inutilização de numeração:** Disponível após 24h

---

## 🎯 APROVAÇÃO FINAL

### Homologação
- [ ] Todos os testes executados e aprovados
- [ ] Validação do contador realizada
- [ ] Documentação completa e revisada
- [ ] Treinamento concluído

### Produção
- [ ] Certificado de produção instalado
- [ ] Ambiente alterado para produção (tpAmb=1)
- [ ] Backup inicial realizado
- [ ] Equipe de suporte acionada
- [ ] Primeira emissão monitorada

---

## 📞 CONTATOS IMPORTANTES

- **SEFAZ:** Verificar site da SEFAZ do estado
- **Suporte NFePHP:** https://github.com/nfephp-org
- **Contador:** [Preencher]
- **Suporte Técnico:** [Preencher]

---

**Data da Última Revisão:** [Data]  
**Responsável:** [Nome]  
**Status:** ⚠️ **AGUARDANDO TESTES EM HOMOLOGAÇÃO**

---

## ✅ PRONTO PARA PRODUÇÃO?

O sistema estará pronto para produção quando:
1. ✅ Todos os itens deste checklist estiverem marcados
2. ✅ Contador validar os cálculos tributários
3. ✅ Pelo menos 10 CT-e de teste aprovados em homologação
4. ✅ Documentação completa e atualizada
5. ✅ Equipe treinada e preparada
