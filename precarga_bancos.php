<?php
// 1. Inicializar el entorno estándar de Dolibarr e importar clases del módulo de Cajas y Bancos
require '../../main.inc.php';
dol_include_once('/compta/bank/class/account.class.php');

// Contexto único de persistencia en sesión para retener las búsquedas de Bancos
$contextpage = 'precargabancoscol';

// Protección perimetral de acceso obligatoria en Dolibarr (Permiso real v23: bank)
if (empty($user->rights->banque->lire) && empty($user->rights->accounting->mouvement->lire)) {
    accessforbidden();
}

// Cargar diccionarios y traducciones contables y financieras
$langs->loadLangs(array("banks", "main", "accounting", "companies"));

// 2. CAPTURA Y VALIDACIÓN DE PARÁMETROS DE ORDENACIÓN, PAGINACIÓN Y LÍMITES
$sortfield = GETPOST('sortfield', 'alpha') ?: 'b.datev';
$sortorder = GETPOST('sortorder', 'alpha') ?: 'DESC';

// VALIDACIÓN DE LISTA BLANCA PARA sortfield - SEGURIDAD CONTRA INYECCIÓN SQL
$allowed_sortfields = array('b.rowid', 'b.datev', 'b.amount', 'b.label', 'ba.label', 'aj.code');
if (!in_array($sortfield, $allowed_sortfields)) {
    $sortfield = 'b.datev';
}

// Validar sortorder
if (!in_array(strtoupper($sortorder), array('ASC', 'DESC'))) {
    $sortorder = 'DESC';
}

$page = max(0, GETPOSTINT('page'));
$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;

// Inicializar variables de filtro
$search_ref = '';
$search_account = 0;

// Gestión de filtros con persistencia en sesión
if (GETPOST('button_removefilter', 'alpha')) {
    // Destruir todos los filtros de sesión
    unset($_SESSION[$contextpage]);
    $search_ref = '';
    $search_account = 0;
} else {
    // Filtro de referencia/detalle
    $ref_post = GETPOST('search_ref', 'alpha');
    if ($ref_post) {
        $search_ref = $ref_post;
        $_SESSION[$contextpage]['search_ref'] = $search_ref;
    } elseif (isset($_SESSION[$contextpage]['search_ref'])) {
        $search_ref = $_SESSION[$contextpage]['search_ref'];
    }
    
    // Filtro de cuenta bancaria (validado como entero desde GETPOST)
    if (GETPOSTINT('search_account') > 0) {
        $search_account = GETPOSTINT('search_account');
        $_SESSION[$contextpage]['search_account'] = $search_account;
    } elseif (isset($_SESSION[$contextpage]['search_account'])) {
        $search_account = (int)$_SESSION[$contextpage]['search_account'];
    }
}

$offset = $limit * $page;

// 3. CONSTRUCCIÓN DE LA CONSULTA SQL ÚNICA CON PAGINACIÓN CORRECTA
$sql  = "SELECT b.rowid as bankid, b.num_chq, b.datev, b.amount, b.label, b.fk_account, ";
$sql .= " ba.label as bank_name, ba.account_number as puc_banco, aj.code as diario_banco, ";
$sql .= " MAX(bu.type) as tipo_enlace ";
$sql .= " FROM " . MAIN_DB_PREFIX . "bank as b ";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "bank_account as ba ON b.fk_account = ba.rowid ";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "accounting_journal as aj ON ba.fk_accountancy_journal = aj.rowid ";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "bank_extrafields as ef ON b.rowid = ef.fk_object ";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "bank_url as bu ON b.rowid = bu.fk_bank ";
$sql .= " WHERE 1 = 1 ";
$sql .= " AND (ef.co_contabilizado IS NULL OR ef.co_contabilizado = 0) ";

if (!empty($search_ref)) {
    $sql .= " AND (b.label LIKE '%" . $db->escape($search_ref) . "%' OR b.num_chq LIKE '%" . $db->escape($search_ref) . "%')";
}
if ($search_account > 0) {
    $sql .= " AND b.fk_account = " . (int)$search_account;
}

// AGRUPACIÓN MAESTRA: Evita duplicados por plano cartesiano de enlaces
$sql .= " GROUP BY b.rowid, b.num_chq, b.datev, b.amount, b.label, b.fk_account, ba.label, ba.account_number, aj.code ";

// Obtener el total de registros ANTES de aplicar LIMIT
$sql_count = "SELECT COUNT(DISTINCT b.rowid) as cnt FROM " . MAIN_DB_PREFIX . "bank as b ";
$sql_count .= " INNER JOIN " . MAIN_DB_PREFIX . "bank_account as ba ON b.fk_account = ba.rowid ";
$sql_count .= " LEFT JOIN " . MAIN_DB_PREFIX . "bank_extrafields as ef ON b.rowid = ef.fk_object ";
$sql_count .= " LEFT JOIN " . MAIN_DB_PREFIX . "bank_url as bu ON b.rowid = bu.fk_bank ";
$sql_count .= " WHERE 1 = 1 ";
$sql_count .= " AND (ef.co_contabilizado IS NULL OR ef.co_contabilizado = 0) ";

if (!empty($search_ref)) {
    $sql_count .= " AND (b.label LIKE '%" . $db->escape($search_ref) . "%' OR b.num_chq LIKE '%" . $db->escape($search_ref) . "%')";
}
if ($search_account > 0) {
    $sql_count .= " AND b.fk_account = " . (int)$search_account;
}

$resql_count = $db->query($sql_count);
$total_documentos = 0;
if ($resql_count) {
    $obj_count = $db->fetch_object($resql_count);
    $total_documentos = $obj_count->cnt;
}

// Agregar ORDER BY y LIMIT a la consulta principal
$sql .= " ORDER BY " . $sortfield . " " . $sortorder;
$sql .= " LIMIT " . (int)$offset . ", " . (int)$limit;

$resql = $db->query($sql);

$filas_bancos = array();
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $filas_bancos[] = $obj;
    }
    $db->free($resql);
}

// --- ESTE ES EL FIN DEL BLOQUE DE CONSULTAS. ABAJO INICIA EL llxHeader() ---

llxHeader('', $langs->trans("Precarga Bancaria (Colombia)"));

$accountstatic = new Account($db);

// Barra de título nativa con paginador integrado
print_barre_liste($langs->trans("Transacciones de Caja y Bancos por Asentar (Colombia)"), $page, $_SERVER["PHP_SELF"], '&search_ref='.urlencode($search_ref).'&search_account='.$search_account, $sortfield, $sortorder, '', -1, $total_documentos);

// =========================================================================
// BARRA DE FILTRADO SUPERIOR TIPO CARD ALINEADA (SOLUCIÓN DEFINITIVA)
// =========================================================================
echo '<div class="div-table-responsive">';
echo '<form method="GET" action="'.htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES, 'UTF-8').'">';
echo '<input type="hidden" name="sortfield" value="'.htmlspecialchars($sortfield, ENT_QUOTES, 'UTF-8').'">';
echo '<input type="hidden" name="sortorder" value="'.htmlspecialchars($sortorder, ENT_QUOTES, 'UTF-8').'">';
echo '<input type="hidden" name="limit" value="'.htmlspecialchars($limit, ENT_QUOTES, 'UTF-8').'">';

echo '<table class="fichapr tableform centpercent" style="margin-bottom: 15px;">';
echo '<tr>';
echo '<td class="titlefield" width="150">Buscar por Detalle / Ref:</td>';
echo '<td><input type="text" class="flat" name="search_ref" size="25" value="'.htmlspecialchars($search_ref, ENT_QUOTES, 'UTF-8').'"></td>';
echo '<td class="titlefield" width="150">Filtrar por Banco:</td>';
echo '<td>';
// Se ejecuta directamente sin romper la concatenación del string HTML
$accountstatic->select_account($search_account, 'search_account', 1, '', 1);
echo '</td>';
echo '<td class="right">';
echo '<input type="submit" class="button button-search" value="'.htmlspecialchars($langs->trans("Search"), ENT_QUOTES, 'UTF-8').'">';
echo ' <input type="submit" name="button_removefilter" class="button" value="'.htmlspecialchars($langs->trans("RemoveFilter"), ENT_QUOTES, 'UTF-8').'">';
echo '</td>';
echo '</tr>';
echo '</table>';
echo '</form>';
echo '</div>';

// 4. FORMULARIO MASIVO EXCLUSIVO PARA POST (Inyección en Lote) - CON PRESERVACIÓN DE FILTROS
echo '<form method="POST" id="form_lote_bancos" action="procesar_asiento_bancos.php">';
echo '<input type="hidden" name="token" value="'.newToken().'">';
// Preservar filtros en el formulario POST
echo '<input type="hidden" name="search_ref" value="'.htmlspecialchars($search_ref, ENT_QUOTES, 'UTF-8').'">';
echo '<input type="hidden" name="search_account" value="'.(int)$search_account.'">';
echo '<input type="hidden" name="sortfield" value="'.htmlspecialchars($sortfield, ENT_QUOTES, 'UTF-8').'">';
echo '<input type="hidden" name="sortorder" value="'.htmlspecialchars($sortorder, ENT_QUOTES, 'UTF-8').'">';

echo '<table class="noborder centpercent">';
echo '<tr class="liste_titre">';
echo '<td width="30" class="center"><input type="checkbox" id="checkall" onclick="var checkboxes = document.getElementsByName(\'movimientos[]\'); for(var i=0; i<checkboxes.length; i++) { checkboxes[i].checked = this.checked; }"></td>';
echo '<td>Ref. Transacción / Detalle</td>';
echo '<td>Cuenta Financiera (Origen)</td>';
echo '<td>Fecha Valor</td>';
echo '<td class="right">Efectivo Saliente (Egreso)</td>';
echo '<td class="right">Efectivo Entrante (Ingreso)</td>';
echo '<td class="center">Diario Destino</td>';
echo '<td class="center" width="310">Simulación Asiento PUC Tesorería</td>';
echo '</tr>';

if ($total_documentos > 0) {
    foreach ($filas_bancos as $obj) {
        $diario_destino = !empty($obj->diario_banco) ? $obj->diario_banco : 'BCO-GEN';
        $puc_destino = !empty($obj->puc_banco) ? trim($obj->puc_banco) : '11200501';
        $ref_visual = !empty($obj->num_chq) ? $obj->num_chq : "MOV-" . $obj->bankid;
        
        $texto_label = strtoupper($obj->label);
        $simulacion = '';

        // =========================================================================
        // MATRIZ CONTABLE DE EVALUACIÓN MULTIEXCEPCIÓN EN LA PRECARGA (CORREGIDO)
        // =========================================================================
        if ($obj->tipo_enlace == 'transfer' || $obj->tipo_enlace == 'payment_sc' || strpos($texto_label, 'TRANSFERENCIA A CAJA') !== false || strpos($texto_label, 'TRASLADO') !== false) {
            // Caso 1: Traslados Internos entre cuentas y cajas
            if ($obj->amount > 0) {
                $simulacion = '<small style="color: #ffc107; font-weight:bold;">Débito: '.$puc_destino.' (Caja Efectivo)<br>Crédito: 110595 (Cajas Transitorias)</small>';
            } else {
                $simulacion = '<small style="color: #ffc107; font-weight:bold;">Débito: 110595 (Cajas Transitorias)<br>Crédito: '.$puc_destino.' (Banco Ahorros)</small>';
            }
        } elseif (strpos($texto_label, 'SALDO INICIAL') !== false || strpos($texto_label, 'APERTURA') !== false || strpos($texto_label, 'BALANCE INICIAL') !== false) {
            // Caso 2: Saldos Iniciales de Apertura (Patrimonio)
            $simulacion = '<small style="color: #6f42c1; font-weight:bold;">Débito: '.$puc_destino.' (Banco Ahorros)<br>Crédito: 310505 (Capital / Balance)</small>';
        } elseif (strpos($texto_label, '4X1000') !== false || strpos($texto_label, 'GMF') !== false || strpos($texto_label, 'GRAVAMEN') !== false || strpos($texto_label, 'COMISION') !== false) {
            // Caso 3: Gastos Directos del Extracto (4x1000 / Comisiones)
            $cuenta_gasto = (strpos($texto_label, '4X1000') !== false || strpos($texto_label, 'GMF') !== false) ? '530505' : '530515';
            $simulacion = '<small style="color: #6c757d; font-weight:bold;">Débito: '.$cuenta_gasto.' (Gasto Financiero)<br>Crédito: '.$puc_destino.' (Banco Ahorros)</small>';
        } else {
            // Caso 4: Transacciones Comerciales Ordinarias
            if ($obj->amount > 0) {
                $simulacion = '<small style="color: #28a745; font-weight:bold;">Débito: '.$puc_destino.' (Banco Ahorros)<br>Crédito: 130505+NIT (Cierre Cartera)</small>';
            } else {
                $simulacion = '<small style="color: #dc3545; font-weight:bold;">Débito: 220505+NIT (Cierre Prov)<br>Crédito: '.$puc_destino.' (Banco Ahorros)</small>';
            }
        }

        $egreso_txt  = ($obj->amount < 0) ? price(abs($obj->amount)) : '-';
        $ingreso_txt = ($obj->amount > 0) ? price($obj->amount) : '-';

        echo '<tr class="oddeven">';
        echo '<td class="center"><input type="checkbox" name="movimientos[]" value="'.$obj->bankid.'"></td>';
        echo '<td><strong>' . $ref_visual . '</strong><br><small class="opacitymedium">' . htmlspecialchars($obj->label, ENT_QUOTES, 'UTF-8') . '</small></td>';
        echo '<td>' . htmlspecialchars($obj->bank_name, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . dol_print_date($db->jdate($obj->datev), 'day') . '</td>';
        echo '<td class="right text-danger">' . $egreso_txt . '</td>';
        echo '<td class="right text-success">' . $ingreso_txt . '</td>';
        echo '<td class="center"><span class="badge" style="background-color: #f8f9fa; font-weight:bold; color:#0056b3; padding:4px 6px;">' . htmlspecialchars($diario_destino, ENT_QUOTES, 'UTF-8') . '</span></td>';
        echo '<td class="left" style="padding: 5px; line-height: 1.2;">' . $simulacion . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '<br><div class="center">';
    echo '<input type="submit" class="button button-primary" value="CONTABILIZAR TRANSACCIONES BANCARIAS EN LOTE">';
    echo '</div></form>';
} else {
    echo '</table></form>';
    print '<div class="info" style="margin-top: 10px;">No se encontraron movimientos bancarios pendientes.</div>';
}

llxFooter();
$db->close();
?>
