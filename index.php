<?php
require '../../main.inc.php';

// 1. GESTIÓN DE PARÁMETROS (Límite, Paginación y Filtros)
$limit = (int) GETPOST('limit', 'int');
if ($limit <= 0) $limit = 20;

$page = (int) GETPOST('page', 'int');
if ($page < 0) $page = 0;
$offset = $limit * $page;

$search_societe = GETPOST('search_societe', 'alpha');
$search_month   = GETPOST('search_month', 'int');
$search_year    = GETPOST('search_year', 'int');
$search_ref     = GETPOST('search_ref', 'alpha');

// Acción de recepción manual
if (GETPOST('action') == 'fetch') {
    dol_include_once('/invoicereceiver/class/invoicereceiver.class.php');
    $object = new Invoicereceiver($db);
    $result = $object->fetchEmails();
    setEventMessages($result, null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]); exit;
}

// 2. CONSULTA SQL UNIFICADA (Con Tercero y Tabla Espejo)
/*$sql = "SELECT l.rowid, l.date_creation, l.invoice_ref, l.total_amount, l.tax_amount, l.status, l.pdf_path, l.doc_type_contable,";
$sql.= " s.nom as supplier_name, s.rowid as socid,";
$sql.= " e.rowid as espejo_id, e.val_retefuente, e.val_reteiva, e.val_reteica";
$sql.= " FROM " . MAIN_DB_PREFIX . "invoicereceiver_log as l";
$sql.= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON s.siren = l.vendor_taxid";
$sql.= " LEFT JOIN " . MAIN_DB_PREFIX . "autocontabilidad_fiscal_espejo as e ON (e.fk_facture = l.rowid AND e.tipo_factura = 'compra')";
$sql.= " WHERE 1=1";*/

$sql = "SELECT l.rowid, l.date_creation, l.invoice_ref, l.total_amount, l.tax_amount, l.status,l.pdf_path, l.fk_facture,"; 
$sql.= " s.nom as supplier_name, s.rowid as socid,";
$sql.= " e.rowid as espejo_id, e.val_retefuente, e.val_reteiva, e.val_reteica";
$sql.= " FROM " . MAIN_DB_PREFIX . "invoicereceiver_log as l";
$sql.= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON s.siren = l.vendor_taxid";
// Ahora el JOIN es real: fk_facture con fk_facture
$sql.= " LEFT JOIN " . MAIN_DB_PREFIX . "autocontabilidad_fiscal_espejo as e ON (e.fk_facture = l.fk_facture AND e.tipo_factura = 'compra')";
$sql.= " WHERE 1=1";


if (!empty($search_societe)) $sql .= " AND s.nom LIKE '%".$db->escape($search_societe)."%'";
if (!empty($search_ref))     $sql .= " AND l.invoice_ref LIKE '%".$db->escape($search_ref)."%'";
if ($search_month > 0)       $sql .= " AND MONTH(l.date_creation) = ".(int)$search_month;
if ($search_year > 0)        $sql .= " AND YEAR(l.date_creation) = ".(int)$search_year;

$sql .= " ORDER BY l.rowid DESC";
$sql .= " LIMIT " . $limit . " OFFSET " . $offset;

$resql = $db->query($sql);

/*// 3. CARGA DE PRODUCTOS PARA EL SELECTOR
$sql_prod = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "product WHERE tobuy = 1 ORDER BY ref ASC";
$res_prod = $db->query($sql_prod);
$productos_compra = array();
if ($res_prod) {
    while ($p = $db->fetch_object($res_prod)) { $productos_compra[] = $p; }
}*/

// --- 1. CARGAR LISTA DE PRODUCTOS DE COMPRA PARA EL SELECTOR ---

$sql_prod = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "product WHERE tobuy = 1 ORDER BY ref ASC";

$res_prod = $db->query($sql_prod);
$productos_compra = array();
if ($res_prod) {
    while ($p = $db->fetch_object($res_prod)) {
        $productos_compra[] = $p;
    }
}

// --- CABECERA ---
llxHeader('', 'Receptor XML - Panel Pro');

$linkback = '<a href="'.$_SERVER["PHP_SELF"].'?action=fetch" class="butAction">EJECUTAR RECEPCIÓN</a>';
print load_fiche_titre("Panel de Recepción de Facturas XML", $linkback, 'title_generic');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.currentToken().'">';

print '<div class="fiche">';
print '<div class="div-table-responsive">';
print '<table class="tagtable liste listwithfilterbefore">';

// --- FILA DE FILTROS ---
print '<tr class="liste_titre_filter">';
print '<td></td>'; // ID
print '<td>'; 
print '<select name="search_month" class="flat"><option value="0">-- Mes --</option>';
for ($m=1;$m<=12;$m++) print '<option value="'.$m.'" '.($search_month==$m?'selected':'').'>'.dol_print_date(mktime(0,0,0,$m,1,2000), "%B").'</option>';
print '</select> ';
print '<select name="search_year" class="flat"><option value="0">-- Año --</option>';
$cy=(int)date('Y'); for($y=$cy;$y>=$cy-5;$y--) print '<option value="'.$y.'" '.($search_year==$y?'selected':'').'>'.$y.'</option>';
print '</select>';
print '</td>';
print '<td><input type="text" class="flat" name="search_societe" value="'.dol_escape_htmltag($search_societe).'" placeholder="Proveedor..." size="20"></td>';
print '<td align="center"><input type="text" class="flat" name="search_ref" value="'.dol_escape_htmltag($search_ref).'" placeholder="Ref..." size="10"></td>';
print '<td></td><td></td><td></td><td></td><td></td>'; // Celdas vacías
print '<td align="right"><input type="submit" class="button" value="FILTRAR"></td>';
print '</tr>';

// --- CABECERA DE TÍTULOS ---
print '<tr class="liste_titre">';
print '<td>ID</td>';
print '<td>Fecha</td>';
print '<td>Proveedor (DIAN)</td>';
print '<td align="center">Referencia</td>';
print '<td align="center">PDF</td>';
print '<td>Imputación</td>';
print '<td align="center">Retenciones</td>';
print '<td align="right">IVA</td>';
print '<td align="right">Total</td>';
print '<td align="right">Límite: <select name="limit" class="flat" onchange="this.form.submit()">';
foreach(array(20,50,100) as $v) print '<option value="'.$v.'" '.($limit==$v?'selected':'').'>'.$v.'</option>';
print '</select></td>';
print '</tr>';

// --- BUCLE DE DATOS ---
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        print '<tr class="oddeven">';
        print '<td><span class="opacitymedium">'.$obj->rowid.'</span></td>';
        print '<td>'.dol_print_date($db->jdate($obj->date_creation), 'dayhour').'</td>';
        
        // --- COLUMNA TERCERO CON ENLACE (CORREGIDA) ---
print '<td>';
if ($obj->socid > 0) {
    // Generamos el enlace nativo de Dolibarr a la ficha del tercero
    $surround_link = '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.$obj->socid.'">';
    print $surround_link . img_picto('', 'company') . ' <strong>' . dol_escape_htmltag($obj->supplier_name) . '</strong></a>';
} else {
    // Si el tercero no existe, mostramos el NIT y un aviso
    print '<span class="error" title="Debe crear este tercero en Dolibarr antes de procesar">' . img_warning() . ' NIT: ' . $obj->vendor_taxid . '</span>';
}
print '</td>';


        print '<td align="center">'.$obj->invoice_ref.'</td>';
        
        // PDF
        print '<td align="center">';
        if ($obj->pdf_path) {
            $url_pdf = DOL_URL_ROOT.'/document.php?modulepart=invoicereceiver&attachment=0&file='.urlencode($obj->pdf_path);
            print '<a href="'.$url_pdf.'" target="_blank">'.img_picto('', 'pdf').'</a>';
        }
        print '</td>';

        // Selector Producto
        /*print '<td>';
        if ($obj->status == 'raw') {
            print '<select class="flat" onchange="updateProduct('.$obj->rowid.', this.value)">';
            print '<option value="0">-- Seleccione --</option>';
            foreach ($productos_compra as $p) {
                $sel = ($obj->doc_type_contable == $p->ref) ? 'selected' : '';
                print '<option value="'.$p->ref.'" '.$sel.'>'.$p->ref.' - '.$p->label.'</option>';
            }
            print '</select>';
        } else { print '<span class="opacitymedium">'.$obj->doc_type_contable.'</span>'; }
        print '</td>';*/
        
        // --- SELECTOR DINÁMICO DE PRODUCTOS ---
            print '<td>';
            if ($obj->status == 'raw') {
                print '<select class="flat select_prod" style="max-width:250px" onchange="updateProduct('.$obj->rowid.', this.value)">';
                print '<option value="0">-- Seleccione un Ítem --</option>';
                foreach ($productos_compra as $prod) {
                    $selected = ($obj->doc_type_contable == $prod->ref) ? 'selected' : '';
                    print '<option value="'.$prod->ref.'" '.$selected.'>'.$prod->ref.' - '.dol_trunc($prod->label, 30).'</option>';
                }
                print '</select>';
            } else {
                print '<span class="opacitymedium">'.$obj->doc_type_contable.'</span>';
            }
            print '</td>';

        // Columna Espejo (Retenciones)
        print '<td align="center">';
        if ($obj->status == 'processed') {
            if ($obj->espejo_id) {
                $total_ret = $obj->val_retefuente + $obj->val_reteiva + $obj->val_reteica;
                print '<span class="badge" style="background:#f0f7ff; color:#0056b3; border:1px solid #0056b3;" title="RF: '.price($obj->val_retefuente).'">';
                print '<i class="fa fa-university"></i> '.price($total_ret).'</span>';
            } else { print '<span class="error">'.img_warning().' - '.$obj->espejo_id.'</span>'; }
        } else { print '-'; }
        print '</td>';

        print '<td align="right">'.price($obj->tax_amount).'</td>';
        print '<td align="right"><strong>'.price($obj->total_amount).'</strong></td>';
        
       /* if (!$xml_exists && $obj->status != 'processed') {
    print '<a href="#" class="butActionRefused" title="No se puede procesar sin el archivo XML">Procesar</a>';
} else { */
     print '<td align="right">';
        if ($obj->status == 'processed') print '<span class="badge" style="background:#e6f4ea; color:#1e7e34; border:1px solid #1e7e34;">PROCESADO</span>';
        else print ($obj->socid > 0) ? '<a href="process_invoice.php?id='.$obj->rowid.'" class="butAction">PROCESAR</a>' :'<a href="create_thirdparty_from_xml.php?id='.$obj->rowid.'&token='.currentToken().'" class="butAction"><i class="fa fa-plus-circle"></i> Crear Tercero</a>';
;
        print '</td>';
        print '</tr>';
//}

        
       
    }
}
print '</table></div></div></form>';

// JS AJAX
?>

<script type="text/javascript">
function updateProduct(rowid, sku) {
    console.log("Intentando guardar SKU: " + sku + " para registro: " + rowid);
    
    // Obtenemos el token de seguridad que Dolibarr genera para la sesión actual
    var token = '<?php echo currentToken(); ?>';

    $.post("ajax_update_type.php", {
        id: rowid,
        type: sku,
        token: token // Enviamos el token para evitar el error 403
    }, function(data) {
        console.log("Respuesta del servidor: " + data);
        if(data.trim() == "OK") {
            // Éxito: puedes poner un efecto visual aquí
            $.jnotify("Producto asignado correctamente", "mesgs");
        } else {
            alert("Error al guardar: " + data);
        }
    }).fail(function(xhr, status, error) {
        // Si el firewall sigue bloqueando, esto nos dará más pistas
        console.error("Error 403 detectado. Verifique permisos o ModSecurity.");
    });
}
</script>


<!--<script type="text/javascript">
function updateProduct(rowid, sku) {
    $.post("ajax_update_type.php", { id: rowid, type: sku, token: '<?php echo currentToken(); ?>' }, function(data) {
        if(data.trim() !== "OK") alert("Error al guardar selección");
    });
}
</script> -->
<?php
llxFooter();
