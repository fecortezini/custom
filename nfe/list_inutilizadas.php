<?php
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->load("nfe@nfe");

$page = GETPOSTINT('page', 'int', 0);
$sortfield = GETPOST('sortfield', 'alpha', 'data_inutilizacao');
$sortorder = GETPOST('sortorder', 'alpha', 'DESC');

// Limite configurável
$defaultLimit = ($conf->liste_limit > 0) ? (int) $conf->liste_limit : 25;
$limit = (int) GETPOST('limit', 'int');
if ($limit <= 0) { $limit = $defaultLimit; }
$offset = $limit * $page;

// Validação do campo de ordenação
$allowed_sortfields = ['id', 'serie', 'numero_inicial', 'numero_final', 'justificativa', 'protocolo', 'data_inutilizacao'];
if (!in_array($sortfield, $allowed_sortfields)) {
    $sortfield = 'data_inutilizacao'; // Valor padrão seguro
}

// Filtros
$search_serie = GETPOST('search_serie', 'int');
$search_numero_inicial = GETPOST('search_numero_inicial', 'int');
$search_numero_final = GETPOST('search_numero_final', 'int');
$search_protocolo = GETPOST('search_protocolo', 'alpha');
$search_justificativa = GETPOST('search_justificativa', 'alpha');
$search_data_start = GETPOST('search_data_start', 'alpha');
$search_data_end = GETPOST('search_data_end', 'alpha');

// Parâmetros de filtro para links de ordenação
$param = '';
if (!empty($search_serie))           $param .= '&search_serie='.urlencode($search_serie);
if (!empty($search_numero_inicial))  $param .= '&search_numero_inicial='.urlencode($search_numero_inicial);
if (!empty($search_numero_final))    $param .= '&search_numero_final='.urlencode($search_numero_final);
if (!empty($search_protocolo))       $param .= '&search_protocolo='.urlencode($search_protocolo);
if (!empty($search_justificativa))   $param .= '&search_justificativa='.urlencode($search_justificativa);
if (!empty($search_data_start))      $param .= '&search_data_start='.urlencode($search_data_start);
if (!empty($search_data_end))        $param .= '&search_data_end='.urlencode($search_data_end);

// Construção do WHERE para usar na listagem e na contagem
$sqlWhere = " WHERE 1=1";

if (!empty($search_serie)) {
    $sqlWhere .= " AND serie = ".(int)$search_serie;
}
if (!empty($search_numero_inicial)) {
    $sqlWhere .= " AND numero_inicial >= ".(int)$search_numero_inicial;
}
if (!empty($search_numero_final)) {
    $sqlWhere .= " AND numero_final <= ".(int)$search_numero_final;
}
if (!empty($search_protocolo)) {
    $sqlWhere .= " AND protocolo LIKE '%".$db->escape($search_protocolo)."%'";

}
if (!empty($search_justificativa)) {
    $sqlWhere .= " AND justificativa LIKE '%".$db->escape($search_justificativa)."%'";

}
if (!empty($search_data_start)) {
    $sqlWhere .= " AND DATE(data_inutilizacao) >= '".$db->escape($search_data_start)."'";
}
if (!empty($search_data_end)) {
    $sqlWhere .= " AND DATE(data_inutilizacao) <= '".$db->escape($search_data_end)."'";
}

// Query de Contagem Total (com filtros)
$sql_count = "SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."nfe_inutilizadas" . $sqlWhere;
$res_count = $db->query($sql_count);
$total_rows = 0;
if ($res_count) {
    $obj_count = $db->fetch_object($res_count);
    $total_rows = $obj_count->total ?? 0;
}

// Ajusta página se offset exceder total
if ($offset >= $total_rows && $total_rows > 0) {
    $page = floor(($total_rows - 1) / $limit);
    $offset = $limit * $page;
}

// Query Principal
$sql = "SELECT id, serie, numero_inicial, numero_final, justificativa, protocolo, data_inutilizacao
        FROM ".MAIN_DB_PREFIX."nfe_inutilizadas" . $sqlWhere;

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit, $offset);

$resql = $db->query($sql);
if (!$resql) {
    dol_print_error($db);
    exit;
}

$num = $db->num_rows($resql);

$title = $langs->trans("Lista de Inutilizadas");
llxHeader('', $title, '', '', 0, 0, array(), array());

print load_fiche_titre($title, '', 'nfe@nfe/img/title_generic.png');

// Estilização Moderna + Paginação
print '<style>
    .nfe-container {
        margin-top: 20px;
    }

    /* Card Style for Filter */
    .nfe-filter-card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .nfe-filter-header {
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid #f0f0f0;
    }

    .nfe-filter-title {
        font-size: 1.1em;
        font-weight: 600;
        color: #333;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Grid Form */
    .nfe-filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        align-items: end;
    }

    .nfe-form-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .nfe-form-group label {
        font-size: 0.9em;
        font-weight: 500;
        color: #555;
    }

    .nfe-input {
        padding: 6px 10px;
        border: 1px solid #ccc;
        border-radius: 3px;
        font-size: 0.9em;
        width: 100%;
        box-sizing: border-box;
    }

    .nfe-input:focus {
        border-color: #888;
        outline: none;
        box-shadow: 0 0 3px rgba(0,0,0,0.1);
    }

    .nfe-row-group {
        display: flex;
        gap: 8px;
    }

    .nfe-btn-search {
        background: linear-gradient(0.4turn, #4c93c7, #5ea6dd);
        color: white;
        border: none;
        padding: 8px 20px;
        border-radius: 3px;
        cursor: pointer;
        font-size: 0.9em;
        white-space: nowrap;
        font-weight: 500;
    }

    .nfe-btn-search:hover {
        background: linear-gradient(0.4turn, #3d7aa8, #4b8bc4);
    }

    .nfe-full-width {
        grid-column: 1 / -1;
    }

    .nfe-flex-row {
        display: flex;
        gap: 15px;
        align-items: flex-end;
    }

    /* Pagination Styles */
    .nfe-pagination-wrapper {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 15px;
        background: #fafafa;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        margin: 15px 0;
        flex-wrap: wrap;
        gap: 12px;
        font-size: 0.9em;
    }
    
    .nfe-pagination-info {
        color: #666;
        font-weight: 500;
    }

    .nfe-pagination-controls {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    .nfe-page-size-selector {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .nfe-page-size-selector select {
        padding: 5px 8px;
        border: 1px solid #ccc;
        border-radius: 3px;
        font-size: 0.9em;
    }

    .nfe-page-nav {
        display: flex;
        gap: 4px;
    }

    .nfe-page-btn {
        padding: 6px 12px;
        border: 1px solid #ddd;
        background: white;
        color: #333;
        border-radius: 3px;
        text-decoration: none;
        font-size: 0.9em;
        transition: all 0.2s;
    }
    
    .nfe-page-btn:hover:not(.disabled) {
        background: #f5f5f5;
        border-color: #bbb;
    }
    
    .nfe-page-btn.active {
        background: #4c93c7;
        color: white;
        border-color: #4c93c7;
        font-weight: 600;
    }
    
    .nfe-page-btn.disabled {
        opacity: 0.4;
        cursor: not-allowed;
        pointer-events: none;
    }
    
    .nfe-page-jump {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .nfe-page-jump input {
        width: 50px;
        padding: 5px;
        border: 1px solid #ccc;
        border-radius: 3px;
        text-align: center;
        font-size: 0.9em;
    }
    
    .nfe-page-jump button {
        padding: 6px 12px;
        border: 1px solid #4c93c7;
        background: #4c93c7;
        color: white;
        border-radius: 3px;
        cursor: pointer;
        font-size: 0.9em;
    }

    .nfe-page-jump button:hover {
        background: #3d7aa8;
        border-color: #3d7aa8;
    }

    /* Table improvements */
    .table-responsive {
        overflow-x: auto;
        margin: 15px 0;
    }

    div.tabsAction {
        margin-top: 20px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .nfe-filter-grid {
            grid-template-columns: 1fr;
        }
        .nfe-pagination-wrapper {
            flex-direction: column;
            align-items: stretch;
        }
        .nfe-pagination-controls {
            justify-content: center;
        }
        .nfe-flex-row {
            flex-direction: column;
            align-items: stretch !important;
        }
    }
</style>';

print '<div class="nfe-container">';

// --- Filtros ---
print '<div class="nfe-filter-card">';
print '<div class="nfe-filter-header">';
print '<div class="nfe-filter-title"><i class="fa fa-filter"></i> '.$langs->trans("Filtros de Pesquisa").'</div>';
print '</div>';

print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
print '<div class="nfe-filter-grid">';

// Série
print '<div class="nfe-form-group">';
print '<label for="search_serie">'.$langs->trans("Série").'</label>';
print '<input type="number" id="search_serie" name="search_serie" value="'.dol_escape_htmltag($search_serie).'" class="nfe-input flat" placeholder="Ex: 1">';
print '</div>';

// Número Inicial
print '<div class="nfe-form-group">';
print '<label for="search_numero_inicial">'.$langs->trans("Número Inicial").'</label>';
print '<input type="number" id="search_numero_inicial" name="search_numero_inicial" value="'.dol_escape_htmltag($search_numero_inicial).'" class="nfe-input flat" placeholder="Ex: 1">';
print '</div>';

// Número Final
print '<div class="nfe-form-group">';
print '<label for="search_numero_final">'.$langs->trans("Número Final").'</label>';
print '<input type="number" id="search_numero_final" name="search_numero_final" value="'.dol_escape_htmltag($search_numero_final).'" class="nfe-input flat" placeholder="Ex: 100">';
print '</div>';

// Protocolo
print '<div class="nfe-form-group">';
print '<label for="search_protocolo">'.$langs->trans("Protocolo").'</label>';
print '<input type="text" id="search_protocolo" name="search_protocolo" value="'.dol_escape_htmltag($search_protocolo).'" class="nfe-input flat">';
print '</div>';

// Data Início
print '<div class="nfe-form-group">';
print '<label for="search_data_start">'.$langs->trans("Data Início").'</label>';
print '<input type="date" id="search_data_start" name="search_data_start" value="'.dol_escape_htmltag($search_data_start).'" class="nfe-input flat">';
print '</div>';

// Data Fim
print '<div class="nfe-form-group">';
print '<label for="search_data_end">'.$langs->trans("Data Fim").'</label>';
print '<input type="date" id="search_data_end" name="search_data_end" value="'.dol_escape_htmltag($search_data_end).'" class="nfe-input flat">';
print '</div>';

// Justificativa (linha inteira) + Botão
print '<div class="nfe-form-group nfe-full-width">';
print '<div class="nfe-flex-row">';
print '<div style="flex: 1;">';
print '<label for="search_justificativa">'.$langs->trans("Justificativa").'</label>';
print '<input type="text" id="search_justificativa" name="search_justificativa" value="'.dol_escape_htmltag($search_justificativa).'" class="nfe-input flat" placeholder="'.$langs->trans("Digite parte da justificativa...").'">';
print '</div>';
print '<div>';
print '<button type="submit" class="nfe-btn-search"><i class="fa fa-search"></i> '.$langs->trans("Pesquisar").'</button>';
print '</div>';
print '</div>';
print '</div>';

print '</div>'; // grid
print '</form>';
print '</div>'; // card

// --- Tabela Padrão Dolibarr ---
print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Cabeçalho
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans("Série"), $_SERVER["PHP_SELF"], "serie", "", $param, 'align="center"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Número Inicial"), $_SERVER["PHP_SELF"], "numero_inicial", "", $param, 'align="center"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Número Final"), $_SERVER["PHP_SELF"], "numero_final", "", $param, 'align="center"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Protocolo"), $_SERVER["PHP_SELF"], "protocolo", "", $param, 'align="center"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Data Inutilização"), $_SERVER["PHP_SELF"], "data_inutilizacao", "", $param, 'align="center"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Justificativa"), $_SERVER["PHP_SELF"], "justificativa", "", $param, '', $sortfield, $sortorder);
print '</tr>';

// Corpo da tabela
$i = 0;
if ($num > 0) {
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);
        
        // Alterna classes para efeito zebrado
        print '<tr class="oddeven">';
        
        // Série
        print '<td align="center">'.dol_escape_htmltag($obj->serie).'</td>';
        
        // Número Inicial
        print '<td align="center">'.dol_escape_htmltag($obj->numero_inicial).'</td>';
        
        // Número Final
        print '<td align="center">'.dol_escape_htmltag($obj->numero_final).'</td>';
        
        // Protocolo
        print '<td align="center">';
        if (!empty($obj->protocolo)) {
            print '<span class="">'.dol_escape_htmltag($obj->protocolo).'</span>';
        } else {
            print '<span class="opacitymedium">-</span>';
        }
        print '</td>';
        
        // Data
        print '<td align="center">';
        if (!empty($obj->data_inutilizacao)) {
            print dol_print_date(dol_stringtotime($obj->data_inutilizacao), 'dayhour');
        } else {
            print '<span class="opacitymedium">-</span>';
        }
        print '</td>';
        
        // Justificativa
        print '<td>';
        $justificativa = dol_escape_htmltag($obj->justificativa);
        if (strlen($justificativa) > 80) {
            print '<span title="'.dol_escape_htmltag($obj->justificativa).'">'.dol_trunc($justificativa, 80).'</span>';
        } else {
            print $justificativa;
        }
        print '</td>';
        
        print '</tr>';
        $i++;
    }
} else {
    print '<tr class="oddeven">';
    print '<td colspan="6" align="center" class="opacitymedium">';
    print '<br>'.$langs->trans("Nenhum registro encontrado").'<br><br>';
    print '</td>';
    print '</tr>';
}

print '</table>';
print '</div>';

// --- Paginação Customizada ---
$filters = [
    'search_serie' => $search_serie,
    'search_numero_inicial' => $search_numero_inicial,
    'search_numero_final' => $search_numero_final,
    'search_protocolo' => $search_protocolo,
    'search_justificativa' => $search_justificativa,
    'search_data_start' => $search_data_start,
    'search_data_end' => $search_data_end,
    'sortfield' => $sortfield,
    'sortorder' => $sortorder
];

if (!function_exists('nfe_inut_buildURL')) {
    function nfe_inut_buildURL($page, $limitVal, $filtersArray) {
        $params = array_merge(array_filter($filtersArray, function($v) { return $v !== null && $v !== ''; }), [
            'page' => $page,
            'limit' => $limitVal
        ]);
        return $_SERVER["PHP_SELF"] . '?' . http_build_query($params);
    }
}

$totalPages = ($total_rows > 0) ? ceil($total_rows / $limit) : 1;
$currentPage = $page + 1;
$startRecord = ($total_rows > 0) ? ($offset + 1) : 0;
$endRecord = min($offset + $limit, $total_rows);
$limitOptions = [25, 50, 100, 200];

print '<div class="nfe-pagination-wrapper">';

// Info de registros
print '<div class="nfe-pagination-info">';
print 'Mostrando <strong>'.$startRecord.'</strong> a <strong>'.$endRecord.'</strong> de <strong>'.$total_rows.'</strong> registros';
print '</div>';

print '<div class="nfe-pagination-controls">';

// Seletor de tamanho de página
print '<div class="nfe-page-size-selector">';
print '<label>Por página:</label>';
print '<select onchange="window.location.href=this.value">';
foreach ($limitOptions as $opt) {
    $selected = ($opt == $limit) ? ' selected' : '';
    $url = nfe_inut_buildURL(0, $opt, $filters);
    print '<option value="'.$url.'"'.$selected.'>'.$opt.'</option>';
}
print '</select>';
print '</div>';

// Navegação de páginas
print '<div class="nfe-page-nav">';

// Botão Anterior
$prevPage = max(0, $page - 1);
$prevDisabled = ($page == 0) ? 'disabled' : '';
$prevUrl = nfe_inut_buildURL($prevPage, $limit, $filters);
print '<a href="'.$prevUrl.'" class="nfe-page-btn '.$prevDisabled.'">‹ Anterior</a>';

// Páginas numeradas (mostra até 5 ao redor da atual)
$startPage = max(0, $page - 2);
$endPage = min($totalPages - 1, $page + 2);

if ($startPage > 0) {
    $firstUrl = nfe_inut_buildURL(0, $limit, $filters);
    print '<a href="'.$firstUrl.'" class="nfe-page-btn">1</a>';
    if ($startPage > 1) print '<span class="nfe-page-btn disabled">...</span>';
}

for ($p = $startPage; $p <= $endPage; $p++) {
    $pageUrl = nfe_inut_buildURL($p, $limit, $filters);
    $activeClass = ($p == $page) ? 'active' : '';
    print '<a href="'.$pageUrl.'" class="nfe-page-btn '.$activeClass.'">'.($p + 1).'</a>';
}

if ($endPage < $totalPages - 1) {
    if ($endPage < $totalPages - 2) print '<span class="nfe-page-btn disabled">...</span>';
    $lastUrl = nfe_inut_buildURL($totalPages - 1, $limit, $filters);
    print '<a href="'.$lastUrl.'" class="nfe-page-btn">'.$totalPages.'</a>';
}

// Botão Próximo
$nextPage = min($totalPages - 1, $page + 1);
$nextDisabled = ($page >= $totalPages - 1) ? 'disabled' : '';
$nextUrl = nfe_inut_buildURL($nextPage, $limit, $filters);
print '<a href="'.$nextUrl.'" class="nfe-page-btn '.$nextDisabled.'">Próximo ›</a>';

print '</div>'; // .nfe-page-nav

// Salto direto para página
if ($totalPages > 5) {
    print '<div class="nfe-page-jump">';
    print '<span>Ir para:</span>';
    print '<input type="number" id="nfeJumpPage" min="1" max="'.$totalPages.'" value="'.$currentPage.'">';
    print '<button onclick="jumpToPageInut('.$totalPages.', '.$limit.')">Ir</button>';
    print '</div>';
}

print '</div>'; // .nfe-pagination-controls
print '</div>'; // .nfe-pagination-wrapper

print '</div>'; // container

// JS para controle de paginação
$filtersJson = json_encode($filters);
print '<script>
function jumpToPageInut(totalPages, currentLimit) {
    var input = document.getElementById("nfeJumpPage");
    var pageNum = parseInt(input.value);
    if (!pageNum || pageNum < 1 || pageNum > totalPages) {
        alert("Página inválida. Digite um número entre 1 e " + totalPages);
        return;
    }
    var filters = '. $filtersJson .';
    filters.page = pageNum - 1;
    filters.limit = currentLimit;
    
    // Remove null/empty filters
    var params = new URLSearchParams();
    for (var key in filters) {
        if (filters[key] !== null && filters[key] !== "") {
            params.append(key, filters[key]);
        }
    }
    
    window.location.href = "' . $_SERVER["PHP_SELF"] . '?" + params.toString();
}
</script>';

llxFooter();
$db->close();
?>
