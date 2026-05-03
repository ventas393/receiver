-<?php
/*
 * Script final de procesamiento de facturas para InvoiceReceiver
 * Lógica: IVA basado en la configuración de la ficha del producto
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

$id = GETPOST('id', 'int');

// 1. Obtener datos del log y el Tercero (usando siren para el NIT)
$sql = "SELECT l.*, s.rowid as socid FROM " . MAIN_DB_PREFIX . "invoicereceiver_log as l";
$sql.= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON s.siren = l.vendor_taxid";
$sql.= " WHERE l.rowid = " . (int)$id;

$resql = $db->query($sql);
$data = ($resql) ? $db->fetch_object($resql) : null;

if (!$data || !$data->socid) {
    setEventMessages("Error: No se encontró proveedor vinculado al NIT " . $data->vendor_taxid, null, 'errors');
    header("Location: index.php"); exit;
}

// 2. Cargar el producto seleccionado para obtener su configuración de IVA
$sku_selected = $data->doc_type_contable;
$product = new Product($db);
if ($product->fetch('', $sku_selected) <= 0) {
    setEventMessages("Error: Debe seleccionar un producto válido antes de procesar.", null, 'errors');
    header("Location: index.php"); exit;
}

// --- LÓGICA CONTABLE SEGÚN FICHA DEL PRODUCTO ---
$tva_tx    = (float) $product->tva_tx; // Tomamos el IVA predeterminado del producto
$total_xml = (float) $data->total_amount;
$iva_xml   = (float) $data->tax_amount;

if ($tva_tx > 0) {
    // Escenario: IVA DEDUCIBLE (Costo / Activo con IVA)
    // El Precio Unitario es la base (Total - IVA)
    $pu_ht = $total_xml - $iva_xml;
    $tva_total = $iva_xml;
} else {
    // Escenario: IVA NO DEDUCIBLE (Gasto con IVA 0% en ficha)
    // El Precio Unitario es el TOTAL completo (IVA como mayor valor del gasto)
    $pu_ht = $total_xml;
    $tva_tx = 0;
    $tva_total = 0;
}

// 3. Crear cabecera de Factura de Proveedor
$db->begin();
$facture = new FactureFournisseur($db);
$facture->socid = $data->socid;
$facture->ref_supplier = $data->invoice_ref;
$facture->date = $db->jdate($data->date_creation);
$facture->date_facture = $db->jdate($data->date_creation);
$facture->entity = $conf->entity;

$id_factura = $facture->create($user);

if ($id_factura > 0) {
    
    // 1. Cargar el XML para extraer retenciones
    $xmlPath = DOL_DATA_ROOT . '/invoicereceiver/xmls/' . $data->xml_filename;
    
    if (!file_exists($xmlPath)) {
    // Si el archivo NO está, lanzamos una alerta y detenemos el proceso para esa factura
    setEventMessages("Error Crítico: No se encontró el archivo XML en la ruta: " . $xmlPath . ". Por favor, verifique que el archivo fue descargado correctamente.", null, 'errors');
    
    // Opcional: Podrías hacer un rollback o redirigir
    $db->rollback();
    header("Location: index.php");
    exit;
}

    if (file_exists($xmlPath)) {
        
        $sql_update = "UPDATE " . MAIN_DB_PREFIX . "invoicereceiver_log ";
    $sql_update .= " SET status = 'processed', fk_facture = " . (int)$id_factura;
    $sql_update .= " WHERE rowid = " . (int)$id;
    
    $res_update = $db->query($sql_update);
        
        if (!$res_update) {
            dol_syslog("Error al actualizar invoicereceiver_log: " . $db->lasterror());
        }
        
        $xml = simplexml_load_file($xmlPath);
        // Registrar Namespaces para poder usar XPath (Estándar DIAN)
        $xml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $retefuente = 0; $reteiva = 0; $reteica = 0;
        $base_total = (float) $data->total_amount - (float) $data->tax_amount; // Base aproximada

        // 2. Buscar nodos de retenciones en el XML
$retefuente = 0; $reteiva = 0; $reteica = 0; // Inicializar siempre en 0

if ($xml) {
    foreach ($xml->xpath('//cac:WithholdingTaxTotal') as $wtax) {
        // Usamos (array) y [0] con validación para evitar el error Fatal
        $resTax = $wtax->xpath('.//cac:TaxScheme/cbc:ID');
        $resAmount = $wtax->xpath('.//cbc:TaxAmount');

        if (!empty($resTax) && !empty($resAmount)) {
            $taxScheme = (string)$resTax[0];
            $amount = (float)$resAmount[0];
            
            if ($taxScheme == '06') $retefuente = $amount;
            if ($taxScheme == '05') $reteiva = $amount;
            if ($taxScheme == '07') $reteica = $amount;
        }
    }
}

// 3. Insertar en la tabla espejo (FORZANDO NÚMEROS)
// Usamos (float) para asegurar que si la variable es null, se convierta en 0
$sql_espejo = "INSERT INTO llxu3_autocontabilidad_fiscal_espejo ";
$sql_espejo.= "(fk_facture, tipo_factura, base_total, val_retefuente, val_reteiva, val_reteica, is_processed, date_creation) ";
$sql_espejo.= " VALUES (";
$sql_espejo.= (int)$id_factura . ", ";
$sql_espejo.= "'compra', ";
$sql_espejo.= (float)$base_total . ", ";
$sql_espejo.= (float)$retefuente . ", ";
$sql_espejo.= (float)$reteiva . ", ";
$sql_espejo.= (float)$reteica . ", ";
$sql_espejo.= "0, ";
$sql_espejo.= "'" . $db->idate(dol_now()) . "'";
$sql_espejo.= ")";

$db->query($sql_espejo);

    }
    
        // 4. Inserción de la línea (Usando el tipo real del producto)
    $sql_det = "INSERT INTO " . MAIN_DB_PREFIX . "facture_fourn_det ";
    $sql_det.= "(fk_facture_fourn, fk_product, ref, label, description, pu_ht, qty, tva_tx, total_ht, tva, total_ttc, product_type) ";
    $sql_det.= "VALUES (";
    $sql_det.= (int)$id_factura . ", ";
    $sql_det.= (int)$product->id . ", ";
    $sql_det.= "'" . $db->escape($product->ref) . "', ";
    $sql_det.= "'" . $db->escape($product->label) . "', ";
    $sql_det.= "'Importado XML Ref: " . $db->escape($data->invoice_ref) . "', ";
    $sql_det.= $pu_ht . ", ";
    $sql_det.= "1, ";         
    $sql_det.= $tva_tx . ", "; 
    $sql_det.= $pu_ht . ", ";  
    $sql_det.= $tva_total . ", ";
    $sql_det.= $total_xml . ", "; 
    // CAMBIO AQUÍ: Usamos el tipo dinámico en lugar de un "1" fijo
    $sql_det.= (int)$product->type; 
    $sql_det.= ")";

    //$db->query($sql_det);

    /*// 4. Inserción de la línea (Forzando Cantidad 1 y los valores calculados)
    $sql_det = "INSERT INTO " . MAIN_DB_PREFIX . "facture_fourn_det ";
    $sql_det.= "(fk_facture_fourn, fk_product, ref, label, description, pu_ht, qty, tva_tx, total_ht, tva, total_ttc, product_type) ";
    $sql_det.= "VALUES (";
    $sql_det.= $id_factura . ", ";
    $sql_det.= $product->id . ", ";
    $sql_det.= "'" . $db->escape($product->ref) . "', ";
    $sql_det.= "'" . $db->escape($product->label) . "', ";
    $sql_det.= "'Importado XML Ref: " . $db->escape($data->invoice_ref) . "', ";
    $sql_det.= $pu_ht . ", ";
    $sql_det.= "1, ";         // Cantidad forzada a 1
    $sql_det.= $tva_tx . ", "; // Tasa de IVA desde la ficha del producto
    $sql_det.= $pu_ht . ", ";  // total_ht
    $sql_det.= $tva_total . ", ";
    $sql_det.= $total_xml . ", "; // total_ttc
    $sql_det.= "1";           // Tipo: Servicio
    $sql_det.= ")";*/

    if ($db->query($sql_det)) {
        // 5. RECALCULAR TOTALES DE CABECERA (Vital para que no salga en $0)
        $facture->fetch($id_factura);
        $facture->update_price($db);

        // 6. VALIDAR FACTURA (Pasa a estado Abierta/Validada)
        if ($facture->validate($user) >= 0) {
            
            // 7. Vincular PDF original si existe
            if ($data->pdf_path) {
                require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
                $srcFile = DOL_DATA_ROOT . '/invoicereceiver/' . $data->pdf_path;
                $destDir = DOL_DATA_ROOT . '/fournisseur/facture/' . $facture->ref;
                if (file_exists($srcFile)) {
                    dol_mkdir($destDir);
                    dol_copy($srcFile, $destDir . '/' . basename($data->pdf_path));
                }
            }
            
            

            // 8. Marcar registro como procesado
            $db->query("UPDATE " . MAIN_DB_PREFIX . "invoicereceiver_log SET status = 'processed' WHERE rowid = " . $id);
            
            $db->commit();
            setEventMessages("Factura " . $facture->ref . " creada y validada con éxito.", null, 'mesgs');
            header("Location: " . DOL_URL_ROOT . "/fourn/facture/card.php?id=" . $id_factura);
            exit;
        }
    }
}


if ($id_factura > 0) {
    
    // --- NUEVA LÓGICA PARA LA TABLA ESPEJO (CONTABILIDAD FISAL) ---
    
    
    
    // ... (Continuar con la validación de la factura y adjuntar PDF) ...
}


// Si falla algo, revertir
$db->rollback();
setEventMessages("Error al procesar la factura: " . $facture->error, null, 'errors');
header("Location: index.php");
