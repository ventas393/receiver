<?php
// 1. Cargar el entorno estándar de protección y librerías core de Dolibarr
require '../../main.inc.php';
dol_include_once('/societe/class/societe.class.php');

// REGLA DE ORO: Contexto único para aislar y blindar la persistencia de filtros de ventas
$contextpage = 'precargaventascol';

if (empty($user->rights->accounting->mouvement->creer ) && empty($user->rights->facture->lire)) {
    accessforbidden();
}


$langs->loadLangs(array("main", "accounting", "companies", "bills"));

$search_ref     = GETPOST('search_ref', 'alpha');
$search_societe = GETPOST('search_societe', 'alpha');
$search_nit     = GETPOST('search_nit', 'alpha');

if (GETPOST('button_search', 'alpha') !== '') {
    $_SESSION[$contextpage]['search_ref']     = $search_ref;
    $_SESSION[$contextpage]['search_societe'] = $search_societe;
    $_SESSION[$contextpage]['search_nit']     = $search_nit;
} elseif (!empty($_SESSION[$contextpage])) {
    $search_ref     = $_SESSION[$contextpage]['search_ref'];
    $search_societe = $_SESSION[$contextpage]['search_societe'];
    $search_nit     = $_SESSION[$contextpage]['search_nit'];
}

if (GETPOST('button_removefilter', 'alpha') !== '') {
    unset($_SESSION[$contextpage]);
    $search_ref = ''; $search_societe = ''; $search_nit = '';
}

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = GETPOSTINT('page') ? GETPOSTINT('page') : 0;
if (empty($page) || $page < 0) $page = 0;
$offset = $limit * $page;

$socstatic = new Societe($db);

// CONSULTA HÍBRIDA CON RESCATE DE ANTICIPOS
$sql = "SELECT f.rowid as facid, f.ref, f.datef, f.total_ht, f.total_tva, f.total_ttc, f.type as factype, ";
$sql .= " s.rowid as socid, s.nom as name, s.siren as nit_tercero, s.code_compta, ef.co_contabilizado, ";
$sql .= " MAX(el.json_response) as json_response "; 
$sql .= " FROM " . MAIN_DB_PREFIX . "facture as f ";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid ";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields as ef ON f.rowid = ef.fk_object ";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "electronicinvoice_apilog as el ON f.rowid = el.invoice_id ";
$sql .= " WHERE f.fk_statut IN (1, 2) ";
$sql .= " AND (ef.co_contabilizado IS NULL OR ef.co_contabilizado = 0) ";

// REGLA FLEXIBLE: Si es factura comercial exige éxito (status_response=1), SI ES ANTICIPO (type=4) o no tiene log, PALE PASO LIBRE
$sql .= " AND ( (f.type = 0 AND (el.status_response = 1 OR el.status_response IS NULL)) OR f.type = 4 ) ";

if (!empty($search_ref)) { $sql .= " AND f.ref LIKE '%" . $db->escape($search_ref) . "%'"; }
if (!empty($search_societe)) { $sql .= " AND s.nom LIKE '%" . $db->escape($search_societe) . "%'"; }
if (!empty($search_nit)) { $sql .= " AND s.siren LIKE '%" . $db->escape($search_nit) . "%'"; }

$sql .= " GROUP BY f.rowid, f.ref, f.datef, f.total_ht, f.total_tva, f.total_ttc, f.type, s.rowid, s.nom, s.siren, s.code_compta, ef.co_contabilizado ";

$resql_total = $db->query($sql);
$total_registros = $resql_total ? $db->num_rows($resql_total) : 0;
if ($resql_total) $db->free($resql_total);

$sql .= " ORDER BY f.datef DESC, f.rowid DESC";
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
$num_rows = $resql ? $db->num_rows($resql) : 0;

llxHeader('', "Precarga de Ventas - Colombia");

print_barre_liste("DOCUMENTOS DE VENTAS PENDIENTES POR ASENTAR", $page, $_SERVER["PHP_SELF"], "&search_ref=".urlencode($search_ref)."&search_societe=".urlencode($search_societe)."&search_nit=".urlencode($search_nit), '', '', '', $num_rows, $total_registros, 'bill', 0, '', '', $limit);

// FORMULARIO FILTROS (GET)
echo '<form method="GET" action="'.htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES, 'UTF-8').'">';
echo '<input type="hidden" name="button_search" value="1">';
echo '<table class="noborder centpercent tableform" style="margin-bottom: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; padding:8px;">';
echo '<tr class="liste_titre_filter">';
echo '  <td><strong>Referencia:</strong><br><input type="text" class="flat" name="search_ref" value="'.htmlspecialchars($search_ref).'"></td>';
echo '  <td><strong>Tercero:</strong><br><input type="text" class="flat" name="search_societe" value="'.htmlspecialchars($search_societe).'"></td>';
echo '  <td><strong>NIT:</strong><br><input type="text" class="flat" name="search_nit" value="'.htmlspecialchars($search_nit).'"></td>';
echo '  <td class="right" style="padding-top:14px;"><button type="submit" class="button">BUSCAR</button> <button type="submit" class="button" name="button_removefilter" value="1">VACIAR</button></td>';
echo '</tr></table></form>';

// FORMULARIO CONTABILIZACIÓN (POST)
echo '<form method="POST" action="procesar_asiento.php">';
echo '<input type="hidden" name="token" value="'.newToken().'">';
echo '<table class="noborder centpercent liste">';
echo '<tr class="liste_titre"><td width="30" class="center"><input type="checkbox" id="checkall_ventas" onclick="var checkboxes = document.getElementsByName(\'facturas_seleccionadas[]\'); for(var i=0; i<checkboxes.length; i++) { checkboxes[i].checked = this.checked; }"></td><td>Ref. Interna</td><td>Ref. Electrónica (DIAN)</td><td class="center">Fecha</td><td>Tercero (NIT)</td><td>Tipo Doc.</td><td class="right">Subtotal</td><td class="right">IVA</td><td class="right">Total</td><td class="center">Simulación</td></tr>';

if ($num_rows > 0) {
    while ($row = $db->fetch_object($resql)) {
        $facid = (int)$row->facid;
        
        if ($row->factype == 4) {
            $tipo_doc_txt = '<span class="badge" style="background:#fff3cd; color:#856404;">Anticipo</span>';
            $sim_puc_html = '<small>Crédito: 280505</small>';
        } else {
            $tipo_doc_txt = '<span class="badge" style="background:#e8f4fd; color:#004085;">Venta</span>';
            $sim_puc_html = '<small>Crédito: 4135xx / 240805</small>';
        }
        
        // RESTRACCIÓN REGEX BASADA EN TU JSON REAL
        $ref_electronica = '<span class="opacitymedium">- Interno -</span>';
        if (!empty($row->json_response)) {
            $json = json_decode($row->json_response, true);
            if (is_array($json) && !empty($json['message'])) {
                if (preg_match('/#([A-Z0-9]+)/i', $json['message'], $matches)) {
                    $ref_electronica = '<strong style="color: #28a745;"><span class="fa fa-globe"></span> ' . htmlspecialchars($matches[1]) . '</strong>';
                }
            }
        }

        echo '<tr class="oddeven">';
        echo '  <td class="center"><input type="checkbox" name="facturas_seleccionadas[]" value="' . $facid . '"></td>';
        echo '  <td><a href="' . DOL_URL_ROOT . '/compta/facture/card.php?id=' . $facid . '">' . $row->ref . '</a></td>';
        echo '  <td>' . $ref_electronica . '</td>';
        echo '  <td class="center">' . dol_print_date($db->jdate($row->datef), 'day') . '</td>';
        $socstatic->id = $row->socid; $socstatic->name = $row->name;
        echo '  <td>' . $socstatic->getNomUrl(1) . '<br><small>NIT: ' . $row->nit_tercero . '</small></td>';
        echo '  <td>' . $tipo_doc_txt . '</td>';
        echo '  <td class="right">' . price($row->total_ht) . '</td>';
        echo '  <td class="right">' . price($row->total_tva) . '</td>';
        echo '  <td class="right" style="font-weight:bold; color:#004085;">' . price($row->total_ttc) . '</td>';
        echo '  <td>' . $sim_puc_html . '</td>';
        echo '</tr>';
    }
    $db->free($resql);
}
echo '</table>';
if ($num_rows > 0) { echo '<br><div class="center"><button type="submit" class="button button-add">ASENTAR LOTES AL LIBRO MAYOR</button></div>'; }
echo '</form>';
llxFooter(); $db->close();
?>
