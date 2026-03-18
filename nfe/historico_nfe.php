<?php

/**
 * \file       htdocs/custom/nfe/historico_nfe.php
 * \ingroup    nfe
 * \brief      Página para visualizar o histórico de eventos de uma NF-e
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';

// Carrega traduções
$langs->load("bills");
$langs->load("nfe@nfe");

// --- CORREÇÃO: Controle de acesso - Apenas administradores podem ver o histórico ---
if (!$user->admin) {
    accessforbidden();
}

$id = GETPOST('id', 'int');
$error = 0;
$nfe_emitida = null;
$eventos = array();

// --- Lógica para buscar dados da NF-e ---
if ($id > 0) {
    $sql_nfe = "SELECT id, fk_facture, fk_nfe_origem, chave, protocolo, numero_nfe, serie, data_emissao, status
                FROM ".MAIN_DB_PREFIX."nfe_emitidas
                WHERE id = ".$id;

    $resql_nfe = $db->query($sql_nfe);
    if ($resql_nfe) {
        if ($db->num_rows($resql_nfe) > 0) {
            $nfe_emitida = $db->fetch_object($resql_nfe);
        } else {
            setEventMessage($langs->trans("Nenhuma Nota Fiscal Eletrônica foi encontrada com o ID fornecido."), 'error');
            $error++;
        }
    } else {
        dol_print_error($db);
        $error++;
    }

    // --- Lógica para buscar eventos da NF-e ---
    if (!$error) {
        $sql_eventos = "SELECT tpEvento, nSeqEvento, protocolo_evento, motivo_evento, data_evento";
        $sql_eventos .= " FROM " . MAIN_DB_PREFIX . "nfe_eventos";
        $sql_eventos .= " WHERE fk_nfe_emitida = " . $id;
        $sql_eventos .= " ORDER BY data_evento ASC";

        $resql_eventos = $db->query($sql_eventos);
        if ($resql_eventos) {
            while ($obj = $db->fetch_object($resql_eventos)) {
                $eventos[] = $obj;
            }
        } else {
            dol_print_error($db);
            $error++;
        }
    }

} else {
    setEventMessage($langs->trans("O ID da Nota Fiscal Eletrônica é obrigatório para visualizar o histórico."), 'error');
    $error++;
}

/* ==== NOVO BLOCO: eventos sintéticos ==== */
if ($nfe_emitida && !$error) {
    // 1. Evento de criação (apenas se autorizada)
    if (stripos($nfe_emitida->status, 'autorizada') !== false) {
        $e = new stdClass();
        $e->tpEvento = 'CREATION';
        $e->nSeqEvento = 0;
        $e->protocolo_evento = $nfe_emitida->protocolo;
        $e->motivo_evento = (stripos($nfe_emitida->status,'dev') !== false)
            ? 'NF-e de devolução autorizada.'
            : 'NF-e autorizada.';
        $e->data_evento = $nfe_emitida->data_emissao;
        $eventos[] = $e;
    }

    // 2. Se a nota É devolução: evento apontando origem
    if (!empty($nfe_emitida->fk_nfe_origem)) {
        $sqlOrig = "SELECT id, numero_nfe FROM ".MAIN_DB_PREFIX."nfe_emitidas WHERE id = ".(int)$nfe_emitida->fk_nfe_origem." LIMIT 1";
        $resOrig = $db->query($sqlOrig);
        if ($resOrig && $db->num_rows($resOrig) > 0) {
            $o = $db->fetch_object($resOrig);
            $e = new stdClass();
            $e->tpEvento = 'DEV_REF';
            $e->nSeqEvento = 0;
            $e->protocolo_evento = '';
            $e->motivo_evento = 'Esta NF-e é devolução da NF-e nº '.$o->numero_nfe.' (ID '.$o->id.').';
            $e->data_evento = $nfe_emitida->data_emissao;
            $eventos[] = $e;
        }
    }

    // 3. Se a nota NÃO é devolução: listar devoluções que a "devolveram"
    if (empty($nfe_emitida->fk_nfe_origem)) {
        $sqlFilhas = "SELECT id, numero_nfe, protocolo, status, data_emissao
                      FROM ".MAIN_DB_PREFIX."nfe_emitidas
                      WHERE fk_nfe_origem = ".(int)$nfe_emitida->id."
                      ORDER BY data_emissao ASC, id ASC";
        $resFilhas = $db->query($sqlFilhas);
        if ($resFilhas) {
            while ($f = $db->fetch_object($resFilhas)) {
                $e = new stdClass();
                $e->tpEvento = 'DEVOLVIDA'; // marca a nota original como devolvida em tal momento
                $e->nSeqEvento = 0;
                $e->protocolo_evento = $f->protocolo;
                $e->motivo_evento = 'Devolvida pela emissão da NF-e de devolução nº '.$f->numero_nfe.' (ID '.$f->id.') - '.$f->status;
                $e->data_evento = $f->data_emissao;
                $eventos[] = $e;
            }
        }
    }

    // 4. Ordenar cronologicamente (asc)
    usort($eventos, function($a,$b){
        return strcmp($a->data_evento, $b->data_evento);
    });
}
/* ==== FIM NOVO BLOCO ==== */

/*
 * View
 */

llxHeader('', $langs->trans("Histórico da NFe"));

//$title = $langs->trans("Histórico da NF-e");
//print load_fiche_titre($title, '', 'nfe@nfe/img/title_generic.png');

dol_fiche_head();

// Novo CSS para visual mais profissional e cabeçalho com título + ações
print '<style>
/* Cabeçalho com ação e título */
.nfe-header { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
.nfe-header .actions { display:flex; gap:8px; align-items:center; }
.nfe-header .title {
    flex:1;
    text-align:left;
}
/* Ajustado: título e número com tamanhos próximos */
.nfe-header h1 { margin:0; font-size:1.15rem; font-weight:800; color:#1f2937; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.nfe-header .sub { font-weight:700; color:#4b5563; font-size:1.05rem; margin-left:6px; }

/* Card resumo NFe */
.nfe-card { display:flex; gap:20px; align-items:flex-start; background:#fff; border:1px solid #e9ecef; padding:16px; border-radius:8px; box-shadow:0 2px 6px rgba(0,0,0,0.04); margin-bottom:12px; }
.nfe-card .left { flex:1; }
.nfe-dl dt { float:left; width:140px; font-weight:600; color:#495057; }
.nfe-dl dd { margin-left:140px; margin-bottom:8px; color:#212529; }

/* Badges suaves por status (verde levemente mais forte para daltonia) */
.badge { display:inline-block; padding:6px 12px; border-radius:999px; font-weight:700; font-size:0.95em; }
.badge-success { background: rgba(40, 167, 70, 0.49); color:#114d20; border:1px solid rgba(40,167,69,0.16); } /* tom um pouco mais intenso */
.badge-danger { background: rgba(228, 24, 44, 0.61); color:#7a1f2a; border:1px solid rgba(219, 32, 50, 0.12); }
.badge-warning { background: rgba(255,193,7,0.06); color:#7a5a00; border:1px solid rgba(255,193,7,0.12); }

/* Tabela de eventos */
.events-table { width:100%; border-collapse:collapse; margin-top:12px; }
.events-table thead th { background:#f8f9fa; padding:10px 12px; text-align:left; border-bottom:1px solid #e9ecef; color:#212529; }
.events-table tbody td { padding:12px; border-bottom:1px solid #f1f3f5; vertical-align:top; color:#212529; }
.events-table tbody tr:hover td { background:#fbfcfc; }

/* Meta info nos eventos */
.event-type { font-weight:700; color:#212529; }
.event-meta { color:#6c757d; font-size:0.9em; margin-top:6px; }

/* Mensagem centralizada */
.empty-msg { text-align:center; padding:18px; color:#6c757d; }

/* Responsivo */
@media (max-width:768px){
    .nfe-header { flex-direction:column; align-items:flex-start; gap:8px; }
    .nfe-card { flex-direction:column; align-items:flex-start; }
    .nfe-dl dt { float:none; width:auto; }
    .nfe-dl dd { margin-left:0; }
}
</style>';

// Conteúdo principal (cabeçalho + cartão) atualizado
if ($nfe_emitida) {
    // determina badge por status
    $status_normalized = strtolower((string)$nfe_emitida->status);
    $badge_class = 'badge-warning';
    if (strpos($status_normalized, 'autoriz') !== false) $badge_class = 'badge-success';
    if (strpos($status_normalized, 'cancel') !== false || strpos($status_normalized, 'rejeit') !== false) $badge_class = 'badge-danger';

    // Cabeçalho: título com número e badge (botão movido para abaixo do cartão)
    print '<div class="nfe-header">';
        print '<div class="title">';
            print '<h1>';
                print $langs->trans("Histórico da NF-e");
                print '<span class="sub">#'.dol_escape_htmltag($nfe_emitida->numero_nfe).'</span>';
                print '<span class="badge '.$badge_class.'">'.dol_escape_htmltag($nfe_emitida->status).'</span>';
            print '</h1>';
        print '</div>';
        print '<div class="actions" aria-hidden="true"></div>';
    print '</div>';

    // Card resumo (sem o botão distante)
    print '<div class="nfe-card">';
        print '<div class="left">';
            print '<dl class="nfe-dl">';
                print '<dt>'.$langs->trans("Chave de acesso").'</dt><dd>'.dol_escape_htmltag($nfe_emitida->chave).'</dd>';
                print '<dt>'.$langs->trans("Protocolo").'</dt><dd>'.dol_escape_htmltag($nfe_emitida->protocolo).'</dd>';
                print '<dt>'.$langs->trans("Data do evento").'</dt><dd>'.dol_print_date(dol_stringtotime($nfe_emitida->data_emissao), "standard").'</dd>';
                print '<dt>'.$langs->trans("Fatura (Id)").'</dt><dd>'.dol_escape_htmltag($nfe_emitida->fk_facture).'</dd>';
            print '</dl>';
        print '</div>';
    print '</div>';

    // Eventos
    print '<h3 style="margin-top:12px; margin-bottom:8px;">'.$langs->trans("Eventos").'</h3>';
    print '<table class="events-table">';
        print '<thead><tr><th>'.$langs->trans("Tipo do evento").'</th><th>'.$langs->trans("Data do evento").'</th><th>'.$langs->trans("Descrição").'</th><th>'.$langs->trans("Protocolo").'</th></tr></thead>';
        print '<tbody>';
        if (count($eventos) > 0) {
            foreach ($eventos as $evento) {
                print '<tr>';
                $tipoEvento = '';
                switch ($evento->tpEvento) {
                    case 'CREATION':
                        $tipoEvento = $langs->trans("Emissão Autorizada");
                        break;
                    case 'DEV_REF':
                        $tipoEvento = $langs->trans("Referência de Devolução");
                        break;
                    case 'DEVOLVIDA':
                        $tipoEvento = $langs->trans("Devolvida");
                        break;
                    case '110110':
                        $tipoEvento = $langs->trans("Carta de Correção Eletrônica").' ('.$evento->nSeqEvento.')';
                        break;
                    case '110111':
                        $tipoEvento = $langs->trans("Cancelamento");
                        break;
                    default:
                        $tipoEvento = dol_escape_htmltag($evento->tpEvento);
                }
                print '<td><div class="event-type">'.$tipoEvento.'</div></td>';
                print '<td>'.dol_print_date(dol_stringtotime($evento->data_evento),"dayhour").'</td>';
                print '<td>'.nl2br(dol_escape_htmltag($evento->motivo_evento)).'</td>';
                print '<td>'.dol_escape_htmltag($evento->protocolo_evento).'</td>';
                print '</tr>';
            }
        } else {
            print '<tr><td colspan="4" class="empty-msg">'.$langs->trans("Nenhum evento encontrado.").'</td></tr>';
        }
        print '</tbody>';
    print '</table>';
    // Botão "Mostrar lista" posicionado entre a informação da nota (cartão) e a lista de eventos
    print '<div style="margin:10px 0 16px 0;">';
        print '<a class="butAction" href="'.dol_buildpath('/custom/nfe/list.php',1).'">'.$langs->trans("BackToList").'</a>';
    print '</div>';

} else {
    // mantém tratamento de erro com saída visual melhor
    print '<div class="empty-msg">'.dol_htmloutput_errors(null).'</div>';
}

dol_fiche_end();

llxFooter();
$db->close();
