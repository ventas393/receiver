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

// 2. CAPTURA NATIVA DE PARÁMETROS DE ORDENACIÓN, PAGINACIÓN Y LÍMITES (Filtro 'alpha' corregido)
$sortfield = GETPOST('sortfield', 'alpha') ? GETPOST('sortfield', 'alpha') : 'f.datef';
$sortorder = GETPOST('sortorder', 'alpha') ? GETPOST('sortorder', 'alpha') : 'DESC';
$page = GETPOSTINT('page') > 0 ? GETPOSTINT('page') : 0;
$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;

// Inicializar la matriz de filtros nativa para la barra de herramientas
$search_array = array();

// Mapeo e interceptación dinámica de filtros con persistencia estricta en $_SESSION
$search_fields = array('search_ref', 'search_ref_dian', 'search_tercero', 'search_tipo', 'search_account');
foreach ($search_fields as $field) {
    if (GETPOST($field, 'alpha') !== '') {
        ${$field} = GETPOST($field, 'alpha');
        $_SESSION[$contextpage][$field] = ${$field};
    } elseif (GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
        ${$field} = '';
        unset($_SESSION[$contextpage][$field]);
    } elseif (!empty($_SESSION[$contextpage][$field])) {
        ${$field} = $_SESSION[$contextpage][$field];
    } else {
        ${$field} = '';
    }
    $search_array[$field] = ${$field};
}

// Acción del botón de la papelera: Destruir de forma atómica los filtros de sesión
if (GETPOST('button_removefilter', 'alpha')) {
    foreach ($search_array as $key => $val) {
        unset($_SESSION[$contextpage][$key]);
        $search_array[$key] = '';
    }
    $search_ref = ''; $search_ref_dian = ''; $search_tercero = ''; $search_tipo = ''; $search_account = '';
}

$offset = $limit * $page;

// --- A PARTIR DE AQUÍ SE INVOCAN LAS CONSULTAS SQL Y LUEGO EL llxHeader() ---


llxHeader('', $langs->trans("Precarga Contable Colombia"));

$socstatic = new Societe($db);

// =========================================================================
// 🚀 3. CONSTRUCCIÓN DE LA CONSULTA SQL PURIFICADA (FILTRO API DIAN ÉXITO)
// =========================================================================
$sql = "SELECT f.rowid as facid, f.ref, f.datef, f.total_ht, f.total_tva, f.total_ttc, f.type as factype, ";
$sql .= " s.rowid as socid, s.nom as name, s.siren as nit_tercero, s.code_compta, ef.co_contabilizado, ";
$sql .= " el.json_response ";
$sql .= " FROM " . MAIN_DB_PREFIX . "facture as f ";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid ";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields as ef ON f.rowid = ef.fk_object ";
// Forzamos el cruce únicamente si la factura fue aprobada de forma exitosa ante la DIAN (status_response = 1)
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "electronicinvoice_apilog as el ON f.rowid = el.invoice_id AND el.status_response = 1 ";
$sql .= " WHERE f.fk_statut IN (1, 2) ";
$sql .= " AND (ef.co_contabilizado IS NULL OR ef.co_contabilizado = 0) ";

// Agrupamos estrictamente por la cabecera para triturar cualquier duplicidad residual en el búfer
$sql .= " GROUP BY f.rowid, f.ref, f.datef, f.total_ht, f.total_tva, f.total_ttc, f.type, s.rowid, s.nom, s.siren, s.code_compta, ef.co_contabilizado, el.json_response ";

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

$sql .= " ORDER BY " . $sortfield . " " . $sortorder;

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

        // REGLA FISCAL COLOMBIANA: Excluir de la lista ventas o notas comerciales que no tengan CUFE aprobado
        if ($obj->factype != 3 && empty($cufe_detectado)) {
            continue; 
        }

        if (!empty($search_ref_dian) && stripos($clean_ref_electronica, $search_ref_dian) === false) {
            continue;
        }

        $obj->clean_ref_dian = $clean_ref_electronica;
        $filas_validas_html[] = $obj;
    }
    $db->free($resql);

    $total_documentos = count($filas_validas_html);

    // 4. BARRA DE HERRAMIENTAS Y PAGINACIÓN NATIVA
    $param = '&search_ref=' . urlencode($search_ref) . '&search_ref_dian=' . urlencode($search_ref_dian) . '&search_tercero=' . urlencode($search_tercero) . '&search_tipo=' . $search_tipo;
    print_barre_liste($langs->trans("Documentos de Ventas Pendientes por Asentar (Colombia)"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $total_documentos, $limit, 'title_accountancy');

    // 5. UNIFICACIÓN: UN SOLO FORMULARIO QUE ENVUELVE A LA TABLA GENERAL
    echo '<form method="POST" id="main_form_col" action="procesar_asiento.php">';
    echo '<input type="hidden" name="token" value="'.newToken().'">';

    echo '<table class="noborder centpercent">';
    
    // FILA DE FILTRADO (Uso de htmlspecialchars estándar PHP para evitar Fatal Errors)
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
    
    // Botones de acción del filtro (Sanitizados de forma segura)
    echo '<td class="center">';
    echo '<input type="submit" class="button" value="'.htmlspecialchars($langs->trans("Search"), ENT_QUOTES, 'UTF-8').'" onclick="var f=document.getElementById(\'main_form_col\'); f.method=\'GET\'; f.action=\''.$_SERVER["PHP_SELF"].'\';">';
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
        $lote_paginado = array_slice($filas_validas_html, $offset, $limit);

        foreach ($lote_paginado as $obj) {
            $socstatic->id = $obj->socid;
            $socstatic->name = $obj->name;

            if ($obj->factype == 3) {
                $ref_electronica = '<span class="opacitymedium"><small>No requiere (Interno)</small></span>';
                $tipo_doc = "Factura de Anticipo (IVA 0%)";
                $simulacion_puc = '<small style="color: #ffc107; font-weight: bold;">Debita: 130505 (Cartera)<br>Crédita: 280505 (Anticipo Pasivo)<br>IVA: $0.00 (Exento)</small>';
            } else {
                $ref_electronica = '<span class="badge badge-dot" style="background-color: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; font-weight: bold;">' . $obj->clean_ref_dian . '</span>';
                $tipo_doc = ($obj->factype == 2) ? "Nota Crédito (NC-CLI)" : "Factura Venta (VENT)";
                $simulacion_puc = ($obj->factype == 2) 
                    ? '<small style="color: #dc3545;">Debita: 417505 (Devolución)<br>Debita: 240810 (IVA Reversado)<br>Crédita: 130505 (Cartera)</small>'
                    : '<small style="color: #28a745;">Debita: 130505 (Cartera)<br>Crédita: 4135xx (Ingreso)<br>Crédita: 240805 (IVA Ventas)</small>';
            }

            echo '<tr class="oddeven">';
            echo '<td><input type="checkbox" name="facturas[]" value="'.$obj->facid.'"></td>';
            echo '<td><a href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.$obj->facid.'">'.img_object('', 'bill').' '.$obj->ref.'</a></td>';
            echo '<td>'.$ref_electronica.'</td>';
            echo '<td>'.dol_print_date($db->jdate($obj->datef), 'day').'</td>';
            echo '<td>';
            echo $socstatic->getNomUrl(1); 
            echo '<br><span class="opacitymedium sizeonlytext"><small><strong>NIT:</strong> '.(!empty($obj->nit_tercero) ? $obj->nit_tercero : $langs->trans("NoRegistered")).'</small></span>';
            echo '</td>';
            echo '<td><strong>'.$tipo_doc.'</strong></td>';
            echo '<td class="right">'.price($obj->total_ht).'</td>';
            echo '<td class="right">'.price($obj->total_tva).'</td>';
            echo '<td class="right"><strong>'.price($obj->total_ttc).'</strong></td>';
            echo '<td class="left" style="padding: 5px; line-height: 1.2;">'.$simulacion_puc.'</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '<br><div class="center">';
        echo '<input type="submit" class="button button-primary" value="GENERAR ASIENTOS COLOMBIANOS AUTOMÁTICOS" onclick="var f=document.getElementById(\'main_form_col\'); f.method=\'POST\'; f.action=\'procesar_asiento.php\';">';
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
