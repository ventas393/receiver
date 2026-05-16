<?php
// 1. Inicializar el entorno e importar librerías core de Dolibarr
require '../../main.inc.php';
dol_include_once('/compta/facture/class/facture.class.php');
dol_include_once('/societe/class/societe.class.php'); 

// Contexto único de persistencia en memoria (Ajustar según archivo: 'precargacompras', 'precargabancos')
$contextpage = 'precargaventascol';

// Protección perimetral de acceso obligatoria del ERP
if (!$user->rights->facture->lire) {
    accessforbidden();
}

// Cargar diccionarios de traducciones contables
$langs->loadLangs(array("bills", "main", "accounting", "companies"));

// =========================================================================
// 2. CAPTURA NATIVA DE PARÁMETROS CON VALIDACIÓN ESTRICTA
// =========================================================================

// VALIDACIÓN SEGURA: sortfield - WHITELIST de campos permitidos
$allowed_sortfields = array('f.datef', 'f.ref', 'f.total_ttc', 's.nom', 'f.type');
$sortfield = GETPOST('sortfield', 'alpha');
$sortfield = (in_array($sortfield, $allowed_sortfields, true)) ? $sortfield : 'f.datef';

// VALIDACIÓN SEGURA: sortorder - Solo ASC o DESC
$sortorder = strtoupper(GETPOST('sortorder', 'alpha'));
$sortorder = ($sortorder === 'ASC') ? 'ASC' : 'DESC';

// Paginación
$page = max(0, GETPOSTINT('page'));
$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;
$offset = $limit * $page;

// Inicializar la matriz de filtros con validación de sesión
if (!isset($_SESSION[$contextpage])) {
    $_SESSION[$contextpage] = array();
}

// VALIDACIÓN SEGURA: Filtros de búsqueda con persistencia en sesión
$search_fields = array('search_ref', 'search_ref_dian', 'search_tercero', 'search_tipo', 'search_account');
$search_params = array();

foreach ($search_fields as $field) {
    $post_value = GETPOST($field, 'alpha');
    
    if ($post_value !== '') {
        // Valor POST tiene prioridad
        $search_params[$field] = $post_value;
        $_SESSION[$contextpage][$field] = $post_value;
    } elseif (GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
        // Botón de reset/search presionado - limpiar
        $search_params[$field] = '';
        unset($_SESSION[$contextpage][$field]);
    } else {
        // Usar valor de sesión si existe
        $search_params[$field] = $_SESSION[$contextpage][$field] ?? '';
    }
}

// Acción del botón de la papelera: Destruir de forma atómica los filtros de sesión
if (GETPOST('button_removefilter', 'alpha')) {
    foreach ($search_fields as $field) {
        unset($_SESSION[$contextpage][$field]);
        $search_params[$field] = '';
    }
}

// Extraer variables de búsqueda para uso en template
$search_ref = $search_params['search_ref'] ?? '';
$search_ref_dian = $search_params['search_ref_dian'] ?? '';
$search_tercero = $search_params['search_tercero'] ?? '';
$search_tipo = intval($search_params['search_tipo'] ?? 0);
$search_account = $search_params['search_account'] ?? '';

// --- A PARTIR DE AQUÍ SE INVOCAN LAS CONSULTAS SQL Y LUEGO EL llxHeader() ---

llxHeader('', $langs->trans("Precarga Contable Colombia"));

$socstatic = new Societe($db);

// =========================================================================
// 3. CONSULTA SQL HÍBRIDA: COMERCIALES DIAN + ANTICIPOS (SEGURA Y OPTIMIZADA)
// =========================================================================
// 🧠 REGLA FISCAL HÍBRIDA:
// - Facturas comerciales (type=0): EXIGEN éxito en API DIAN (status_response = 1)
// - Facturas de anticipo (type=3): PERMITEN acceso libre (sin requerir DIAN)
// - Notas Crédito (type=2): EXIGEN éxito en API DIAN (status_response = 1)
// =========================================================================

$sql = "SELECT f.rowid as facid, f.ref, f.datef, f.total_ht, f.total_tva, f.total_ttc, f.type as factype, ";
$sql .= " s.rowid as socid, s.nom as name, s.siren as nit_tercero, s.code_compta, ef.co_contabilizado, ";
$sql .= " MAX(el.json_response) as json_response, MAX(el.status_response) as status_response ";
$sql .= " FROM " . MAIN_DB_PREFIX . "facture as f ";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid ";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields as ef ON f.rowid = ef.fk_object ";
// LEFT JOIN para permitir anticipos sin DIAN, pero con agregación segura
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "electronicinvoice_apilog as el ON f.rowid = el.invoice_id ";
$sql .= " WHERE f.fk_statut IN (1, 2) ";
$sql .= " AND (ef.co_contabilizado IS NULL OR ef.co_contabilizado = 0) ";

// 🧠 REGLA FISCAL HÍBRIDA SEGURA: 
// Exigimos éxito en el API si es factura comercial (type=0 o type=2) O 
// permitimos el acceso libre si es Anticipo (type=3)
$sql .= " AND ( (f.type IN (0, 2) AND el.status_response = 1) OR f.type = 3 ) ";

// Filtros de búsqueda
if (!empty($search_ref)) {
    $sql .= " AND f.ref LIKE '%" . $db->escape($search_ref) . "%'";
}
if (!empty($search_tercero)) {
    $sql .= " AND (s.nom LIKE '%" . $db->escape($search_tercero) . "%' OR s.siren LIKE '%" . $db->escape($search_tercero) . "%')";
}
if ($search_tipo > 0) {
    if ($search_tipo == 1) $sql .= " AND f.type = 0";
    if ($search_tipo == 2) $sql .= " AND f.type = 2";
    if ($search_tipo == 3) $sql .= " AND f.type = 3";
}

// Agrupamos estrictamente para triturar los reintentos de logs y dejar una sola fila limpia por documento
$sql .= " GROUP BY f.rowid, f.ref, f.datef, f.total_ht, f.total_tva, f.total_ttc, f.type, s.rowid, s.nom, s.siren, s.code_compta, ef.co_contabilizado ";

// ============= QUERY COUNT SEPARADO (con misma lógica) =============
$sql_count = "SELECT COUNT(DISTINCT f.rowid) as total FROM " . MAIN_DB_PREFIX . "facture as f ";
$sql_count .= " INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid ";
$sql_count .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields as ef ON f.rowid = ef.fk_object ";
$sql_count .= " LEFT JOIN " . MAIN_DB_PREFIX . "electronicinvoice_apilog as el ON f.rowid = el.invoice_id ";
$sql_count .= " WHERE f.fk_statut IN (1, 2) ";
$sql_count .= " AND (ef.co_contabilizado IS NULL OR ef.co_contabilizado = 0) ";
$sql_count .= " AND ( (f.type IN (0, 2) AND el.status_response = 1) OR f.type = 3 ) ";

if (!empty($search_ref)) {
    $sql_count .= " AND f.ref LIKE '%" . $db->escape($search_ref) . "%'";
}
if (!empty($search_tercero)) {
    $sql_count .= " AND (s.nom LIKE '%" . $db->escape($search_tercero) . "%' OR s.siren LIKE '%" . $db->escape($search_tercero) . "%')";
}
if ($search_tipo > 0) {
    if ($search_tipo == 1) $sql_count .= " AND f.type = 0";
    if ($search_tipo == 2) $sql_count .= " AND f.type = 2";
    if ($search_tipo == 3) $sql_count .= " AND f.type = 3";
}

// Ejecutar COUNT para obtener el total
$resql_count = $db->query($sql_count);
$total_documentos_sin_filtro = 0;
if ($resql_count) {
    $obj_count = $db->fetch_object($resql_count);
    $total_documentos_sin_filtro = intval($obj_count->total);
}

// Añadir ORDER BY y LIMIT (seguro porque sortfield fue validado)
$sql .= " ORDER BY " . $sortfield . " " . $sortorder;
$sql .= " LIMIT " . intval($offset) . ", " . intval($limit);

$resql = $db->query($sql);

if ($resql) {
    $filas_validas_html = array(); 

    while ($obj = $db->fetch_object($resql)) {
        $cufe_detectado = '';
        $clean_ref_electronica = '';
        
        if (!empty($obj->json_response)) {
            $data_json = json_decode($obj->json_response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (!empty($data_json['cufe'])) {
                    $cufe_detectado = $data_json['cufe'];
                }
                $archivo_dian = !empty($data_json['urlinvoicexml']) ? $data_json['urlinvoicexml'] : (!empty($data_json['urlinvoicepdf']) ? $data_json['urlinvoicepdf'] : '');
                if (!empty($archivo_dian)) {
                    $clean_ref_electronica = str_replace(array('.xml', '.pdf', 'FES-', 'NCS-'), '', $archivo_dian);
                }
            }
        }

        // REGLA FISCAL COLOMBIANA: 
        // - Excluir comerciales sin CUFE
        // - Permitir anticipos sin CUFE (no requieren DIAN)
        if ($obj->factype != 3 && empty($cufe_detectado)) {
            continue; 
        }

        // FILTRO search_ref_dian - Se mantiene en PHP porque requiere string matching post-procesamiento
        if (!empty($search_ref_dian) && stripos($clean_ref_electronica, $search_ref_dian) === false) {
            continue;
        }

        $obj->clean_ref_dian = $clean_ref_electronica;
        $filas_validas_html[] = $obj;
    }
    $db->free($resql);

    $total_documentos = count($filas_validas_html);

    // 4. BARRA DE HERRAMIENTAS Y PAGINACIÓN NATIVA
    $param = '&search_ref=' . urlencode($search_ref) . '&search_ref_dian=' . urlencode($search_ref_dian) . '&search_tercero=' . urlencode($search_tercero) . '&search_tipo=' . intval($search_tipo);
    print_barre_liste($langs->trans("Documentos de Ventas Pendientes por Asentar (Colombia)"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $total_documentos_sin_filtro, $limit, 'title_accoun');

    // 5. UNIFICACIÓN: UN SOLO FORMULARIO QUE ENVUELVE A LA TABLA GENERAL
    echo '<form method="POST" id="main_form_col" action="procesar_asiento.php">';
    echo '<input type="hidden" name="token" value="'.newToken().'">';

    echo '<table class="noborder centpercent">';
    
    // FILA DE FILTRADO
    echo '<tr class="liste_titre_filter">';
    echo '<td></td>'; 
    echo '<td><input type="text" class="flat" name="search_ref" size="8" value="'.htmlspecialchars($search_ref, ENT_QUOTES, 'UTF-8').'"></td>';
    echo '<td><input type="text" class="flat" name="search_ref_dian" size="10" value="'.htmlspecialchars($search_ref_dian, ENT_QUOTES, 'UTF-8').'"></td>';
    echo '<td></td>'; 
    echo '<td><input type="text" class="flat" name="search_tercero" size="12" value="'.htmlspecialchars($search_tercero, ENT_QUOTES, 'UTF-8').'"></td>';
    
    echo '<td><select name="search_tipo" class="flat">';
    echo '<option value="0"'.($search_tipo==0?' selected':'').'>-- Todos --</option>';
    echo '<option value="1"'.($search_tipo==1?' selected':'').'>Factura Venta</option>';
    echo '<option value="2"'.($search_tipo==2?' selected':'').'>Nota Crédito</option>';
    echo '<option value="3"'.($search_tipo==3?' selected':'').'>Factura Anticipo</option>';
    echo '</select></td>';
    
    echo '<td colspan="3"></td>'; 
    
    // Botones de acción del filtro (Sanitizados)
    echo '<td class="center">';
    echo '<input type="submit" class="button" value="'.htmlspecialchars($langs->trans("Search"), ENT_QUOTES, 'UTF-8').'" name="button_search" onclick="var f=document.getElementById(\'main_form_col\'); f.method=\'GET\'; return true;">';
    echo ' <input type="submit" class="button" value="'.htmlspecialchars($langs->trans("Reset"), ENT_QUOTES, 'UTF-8').'" name="button_removefilter" onclick="var f=document.getElementById(\'main_form_col\'); f.method=\'GET\'; return true;">';
    echo ' <a href="'.$_SERVER["PHP_SELF"].'" class="button">'.htmlspecialchars($langs->trans("Refresh"), ENT_QUOTES, 'UTF-8').'</a>';
    echo '</td>';
    echo '</tr>';

    // CABECERAS REALES DE LA TABLA
    echo '<tr class="liste_titre">';
    echo '<td width="30"><input type="checkbox" id="checkall" onclick="var checkboxes = document.getElementsByName(\'facturas[]\'); for(var i=0; i<checkboxes.length; i++) { checkboxes[i].checked = this.checked; }"></td>';
    echo '<td>'.$langs->trans("Ref. Interna").'</td>';
    echo '<td>'.$langs->trans("Ref. Electrónica (DIAN)").'</td>'; 
    echo '<td>'.$langs->trans("Fecha").'</td>';
    echo '<td>'.$langs->trans("Tercero / Empresa (NIT)").'</td>';
    echo '<td>'.$langs->trans("Tipo Doc").'</td>';
    echo '<td class="right">'.$langs->trans("Subtotal (Base)").'</td>';
    echo '<td class="right">'.$langs->trans("IVA Generado").'</td>';
    echo '<td class="right">'.$langs->trans("Total Factura").'</td>';
    echo '<td class="center" width="250">'.$langs->trans("Simulación Asiento PUC").'</td>';
    echo '</tr>';

    // 6. RENDERIZACIÓN DE LAS FILAS
    if ($total_documentos > 0) {
        foreach ($filas_validas_html as $obj) {
            $socstatic->id = intval($obj->socid);
            $socstatic->name = htmlspecialchars($obj->name, ENT_QUOTES, 'UTF-8');

            if ($obj->factype == 3) {
                $ref_electronica = '<span class="opacitymedium"><small>No requiere (Interno)</small></span>';
                $tipo_doc = "Factura de Anticipo (IVA 0%)";
                $simulacion_puc = '<small style="color: #ffc107; font-weight: bold;">Debita: 130505 (Cartera)<br>Crédita: 280505 (Anticipo Pasivo)<br>IVA: $0.00 (Exento)</small>';
            } else {
                // SEGURIDAD: Escapar clean_ref_dian para evitar XSS
                $ref_electronica = '<span class="badge badge-dot" style="background-color: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; font-weight: bold;">' 
                    . htmlspecialchars($obj->clean_ref_dian, ENT_QUOTES, 'UTF-8') . '</span>';
                $tipo_doc = ($obj->factype == 2) ? "Nota Crédito (NC-CLI)" : "Factura Venta (VENT)";
                $simulacion_puc = ($obj->factype == 2) 
                    ? '<small style="color: #dc3545;">Debita: 417505 (Devolución)<br>Debita: 240810 (IVA Reversado)<br>Crédita: 130505 (Cartera)</small>'
                    : '<small style="color: #28a745;">Debita: 130505 (Cartera)<br>Crédita: 4135xx (Ingreso)<br>Crédita: 240805 (IVA Ventas)</small>';
            }

            // SEGURIDAD: Validar facid como integer y escapar valores en atributos HTML
            $facid_safe = intval($obj->facid);
            $ref_safe = htmlspecialchars($obj->ref, ENT_QUOTES, 'UTF-8');
            $nit_safe = htmlspecialchars($obj->nit_tercero ?? '', ENT_QUOTES, 'UTF-8');

            echo '<tr class="oddeven">';
            echo '<td><input type="checkbox" name="facturas[]" value="' . $facid_safe . '"></td>';
            echo '<td><a href="' . DOL_URL_ROOT . '/compta/facture/card.php?id=' . $facid_safe . '">'.img_object('', 'bill').' ' . $ref_safe . '</a></td>';
            echo '<td>' . $ref_electronica . '</td>';
            echo '<td>' . dol_print_date($db->jdate($obj->datef), 'day') . '</td>';
            echo '<td>';
            echo $socstatic->getNomUrl(1); 
            echo '<br><span class="opacitymedium sizeonlytext"><small><strong>NIT:</strong> ' . (!empty($nit_safe) ? $nit_safe : htmlspecialchars($langs->trans("NoRegistered"), ENT_QUOTES, 'UTF-8')) . '</small></span>';
            echo '</td>';
            echo '<td><strong>' . htmlspecialchars($tipo_doc, ENT_QUOTES, 'UTF-8') . '</strong></td>';
            echo '<td class="right">' . price($obj->total_ht) . '</td>';
            echo '<td class="right">' . price($obj->total_tva) . '</td>';
            echo '<td class="right"><strong>' . price($obj->total_ttc) . '</strong></td>';
            echo '<td class="left" style="padding: 5px; line-height: 1.2;">' . $simulacion_puc . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '<br><div class="center">';
        echo '<input type="submit" class="button button-primary" value="GENERAR ASIENTOS COLOMBIANOS AUTOMÁTICOS" onclick="var f=document.getElementById(\'main_form_col\'); f.method=\'POST\'; f.action=\'procesar_asiento.php\'; return true;">';
        echo '</div></form>';
    } else {
        echo '</table></form>';
        print '<div class="info" style="margin-top: 10px;">No se encontraron documentos pendientes con aval de la DIAN o que coincidan con la búsqueda.</div>';
    }
} else {
    dol_print_error($db);
}

llxFooter();
$db->close();
?>