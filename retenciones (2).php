<?php
// 1. Carga del entorno Dolibarr con detección dinámica de ruta
$res = 0;
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (!$res) die("Error crítico: No se pudo cargar el entorno de Dolibarr.");

// 2. Librerías necesarias
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/fourn.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

$id = GETPOST('id', 'int');
$action = GETPOST('action', 'alpha');

$type = GETPOST('type', 'alpha'); // Recibe 'venta' o 'compra'


// 3. Cargar Objeto Factura
$object = new Facture($db);
$result = $object->fetch($id);
if ($result <= 0) {
    $object = new FactureFournisseur($db);
    $object->fetch($id);
}
if ($object->id <= 0) dol_print_error($db, "Factura no encontrada");

// --- FIX PARA TABS v22 ---
if ($object->element == 'facture' || $object->element == 'invoice') $_GET['facid'] = $id; 
else $_GET['id'] = $id;

// 4. Cargar Tercero y Atributos (Extrafields)
$thirdparty = new Societe($db);
$thirdparty->fetch($object->socid);
if (method_exists($thirdparty, 'fetch_optionals')) $thirdparty->fetch_optionals();

//$nit = trim($thirdparty->idprof1);
$nit = trim($thirdparty->siren);
$nat = $thirdparty->array_options['options_ei_type_organization_id'] ?: 1;

// --- 5. LÓGICA DE RECLASIFICACIÓN CONTABLE (ACCION: accounting_apply) ---
if ($action == 'accounting_apply' && $user->admin) {
    $db->begin();
    
    $sql_m = "SELECT * FROM ".MAIN_DB_PREFIX."autocontabilidad_fiscal_espejo WHERE fk_facture = ".(int)$id;
    $res_m = $db->query($sql_m);
    $meta = $db->fetch_object($res_m);

    if ($meta && !empty($nit)) {
        $es_venta = ($object->element == 'facture' || $object->element == 'invoice');
        
        $pref_fue = $es_venta ? '135515' : '236540';
        $pref_iva = $es_venta ? '135517' : '236701';
        $pref_ica = $es_venta ? '135518' : '236801';

        $sql_b = "SELECT rowid, debit, credit, numero_compte FROM ".MAIN_DB_PREFIX."accounting_bookkeeping ";
        $sql_b .= " WHERE doc_ref = '".$db->escape($object->ref)."' AND fk_doc = ".(int)$id;
        $sql_b .= $es_venta ? " AND numero_compte LIKE '13%'" : " AND numero_compte LIKE '22%'";
        $sql_b .= " LIMIT 1";

        $res_b = $db->query($sql_b);
        
        if ($res_b && $db->num_rows($res_b) > 0) {
            $linea_t = $db->fetch_object($res_b);
            $total_ret = (double)($meta->val_retefuente + $meta->val_reteiva + $meta->val_reteica);

            // A. Ajustar saldo del tercero
            if ($linea_t->debit > 0) $sql_upd = "UPDATE ".MAIN_DB_PREFIX."accounting_bookkeeping SET debit = debit - ".$total_ret." WHERE rowid = ".(int)$linea_t->rowid;
            else $sql_upd = "UPDATE ".MAIN_DB_PREFIX."accounting_bookkeeping SET credit = credit - ".$total_ret." WHERE rowid = ".(int)$linea_t->rowid;
            $db->query($sql_upd);

            // B. Preparar líneas de retención
            $rets = array(
                array('cta' => $pref_fue.$nit, 'val' => (double)$meta->val_retefuente, 'lab' => 'ReteFuente: '.$thirdparty->name),
                array('cta' => $pref_iva.$nit, 'val' => (double)$meta->val_reteiva, 'lab' => 'ReteIVA: '.$thirdparty->name),
                array('cta' => $pref_ica.$nit, 'val' => (double)$meta->val_reteica, 'lab' => 'ReteICA: '.$thirdparty->name)
            );

            foreach ($rets as $r) {
                if ($r['val'] <= 0) continue;
                $v_deb = ($linea_t->debit > 0) ? $r['val'] : 0;
                $v_cre = ($linea_t->credit > 0) ? $r['val'] : 0;

                // --- INSERCIÓN CON REF Y PIECE_NUM INCLUIDOS ---
                $sql_ins = "INSERT INTO ".MAIN_DB_PREFIX."accounting_bookkeeping (";
                $sql_ins .= " doc_date, doc_type, doc_ref, fk_doc, fk_docdet, ";
                $sql_ins .= " ref, piece_num, "; // Columnas de enlace del asiento
                $sql_ins .= " numero_compte, label_compte, label_operation, ";
                $sql_ins .= " debit, credit, fk_user_author, entity, date_creation";
                $sql_ins .= ")";
                $sql_ins .= " SELECT ";
                $sql_ins .= " doc_date, doc_type, doc_ref, fk_doc, fk_docdet, ";
                $sql_ins .= " ref, piece_num, "; // Copiamos ref y piece_num del original
                $sql_ins .= " '".$db->escape($r['cta'])."', '".$db->escape($r['lab'])."', '".$db->escape($r['lab'])."', ";
                $sql_ins .= (double)$v_deb.", ".(double)$v_cre.", ".(int)$user->id.", ".(int)$conf->entity.", NOW() ";
                $sql_ins .= " FROM ".MAIN_DB_PREFIX."accounting_bookkeeping ";
                $sql_ins .= " WHERE rowid = ".(int)$linea_t->rowid;

                $db->query($sql_ins);
            }
            
            $db->query("UPDATE ".MAIN_DB_PREFIX."autocontabilidad_fiscal_espejo SET is_processed = 1 WHERE rowid = ".(int)$meta->rowid);
            $db->commit();
            setEventMessages("Asiento reclasificado con éxito. Los campos de enlace (ref/piece_num) han sido copiados.", null);
        } else {
            $db->rollback();
            setEventMessages("Error: No se encontró el registro contable original.", null, 'errors');
        }
    }
}

//if($object->element=='invoice_supplier'){$tipo = 'compra';}else{$tipo = 'venta';};
$tipo_espejo = ($object->element == 'facture') ? 'venta' : 'compra';
// 6. Lógica de Guardado en Tabla Espejo (POST)
/*if ($action == 'update' && ($user->admin || $user->rights->facture->creer)) {
    $tipo_espejo = ($object->element == 'facture') ? 'venta' : 'compra';
    $db->begin();
    $sql_delete = "DELETE FROM ".MAIN_DB_PREFIX."autocontabilidad_fiscal_espejo ";
    $sql_delete.= " WHERE fk_facture = ".(int)$id." AND tipo_factura = '".$tipo_espejo."'";
    $sql = "INSERT INTO ".MAIN_DB_PREFIX."autocontabilidad_fiscal_espejo (fk_facture, tipo_factura, base_total, val_retefuente, val_reteiva, val_reteica, date_creation)";
    $sql.= " VALUES (".(int)$id.", '".$tipo_espejo."', ".((double) GETPOST('base_total')).", ".((double) GETPOST('val_retefuente')).", ".((double) GETPOST('val_reteiva')).", ".((double) GETPOST('val_reteica')).", NOW())";
    if ($db->query($sql)) { $db->commit(); setEventMessages("Valores fiscales guardados.", null); } 
    else { $db->rollback(); }
}*/

//nuevo metodo con update

if ($action == 'update' && ($user->admin || $user->rights->facture->creer)) {
    $tipo_espejo = ($object->element == 'facture') ? 'venta' : 'compra';
    
    $db->begin();

    // 1. Limpieza y preparación de variables numéricas
    $base_total     = (double) GETPOST('base_total', 'alpha');
    $val_retefuente = (double) GETPOST('val_retefuente', 'alpha');
    $val_reteiva    = (double) GETPOST('val_reteiva', 'alpha');
    $val_reteica    = (double) GETPOST('val_reteica', 'alpha');

    // 2. Sentencia UPDATE (Solo afecta a las columnas de montos)
    $sql = "UPDATE " . MAIN_DB_PREFIX . "autocontabilidad_fiscal_espejo";
    $sql .= " SET base_total = " . $base_total . ",";
    $sql .= " val_retefuente = " . $val_retefuente . ",";
    $sql .= " val_reteiva = " . $val_reteiva . ",";
    $sql .= " val_reteica = " . $val_reteica;
    // No tocamos date_creation ni cufe para que permanezcan intactos
    $sql .= " WHERE fk_facture = " . (int)$id . " AND tipo_factura = '" . $db->escape($tipo_espejo) . "'";

    $resql = $db->query($sql);

    if ($resql) {
        // Verificamos si realmente se actualizó una fila o si no existía previamente
        if ($db->affected_rows($resql) > 0) {
            $db->commit();
            setEventMessages("Valores fiscales actualizados correctamente.", null, 'mesgs');
        } else {
            // Si no existe la fila, podrías decidir si insertar una nueva o solo avisar
            $db->commit(); 
            setEventMessages("No se encontró registro previo para actualizar, o los valores son idénticos.", null, 'warnings');
        }
    } else {
        $error_sql = $db->lasterror();
        $db->rollback();
        setEventMessages("Error al actualizar valores fiscales: " . $error_sql, null, 'errors');
    }
}


// 7. Cálculos Inteligentes para Vista
$sql_check = "SELECT * FROM ".MAIN_DB_PREFIX."autocontabilidad_fiscal_espejo WHERE fk_facture = ".(int)$id;
$res_check = $db->query($sql_check);
$existing = ($res_check) ? $db->fetch_object($res_check) : null;

if (empty($object->total_ht)) $object->fetch_lines();
$v_base = (double) $object->total_ht;
$v_tva  = (double) $object->total_tva;

$tiene_servicios = 0;
foreach($object->lines as $line) { 
    if ($line->product_type == 1) { 
        $tiene_servicios = 1; 
        break; 
    } 
}

$uvt_val = (double)($conf->global->AUTOCON_VALOR_UVT ?: 47065);
$tope_min = $tiene_servicios ? 
    ((double)$conf->global->AUTOCON_TOPE_SERVICIOS_UVT ?: 4) * $uvt_val : 
    ((double)$conf->global->AUTOCON_TOPE_COMPRAS_UVT ?: 27) * $uvt_val;

if ($existing) {
    $s_fue = $existing->val_retefuente; 
    $s_iva = $existing->val_reteiva; 
    $s_ica = $existing->val_reteica;
} else {
    $s_fue = 0; $s_iva = 0; $s_ica = 0; $p_f = 0;

    $reg_raw = (int) $thirdparty->array_options['options_ei_type_liability_id'];
    $es_GC = ($reg_raw == 7);
    $es_AUT = ($reg_raw == 9);
    $es_reteiva = ($reg_raw == 14);
    $es_RS = ($reg_raw == 112);
    $es_NR = ($reg_raw == 117);
    
    $decl = isset($thirdparty->array_options['options_puc_declarante']) ? $thirdparty->array_options['options_puc_declarante'] : '1';
    $nat = (int) ($thirdparty->array_options['options_ei_type_organization_id'] ?: 1);
    
    $val_ica_raw = $thirdparty->array_options['options_puc_tipo_ica'];
    $tar_ica = ($val_ica_raw !== null && $val_ica_raw !== '') ? (double)$val_ica_raw : 9.66;

    if ($v_base >= $tope_min) {
        // --- PERSONA JURÍDICA ---
        if ($nat == 1) {
            if ($object->element == 'invoice_supplier') {
                if ($es_NR) {
                    $p_f = ($tiene_servicios) ? ($conf->global->AUTOCON_PERC_S_JURIDICA / 100) : ($conf->global->AUTOCON_PERC_F_JURIDICA / 100);
                    $s_fue = $v_base * $p_f;
                }
                if ($es_AUT) $s_fue = 0;
            } 
            elseif ($object->element == 'facture') {
                $p_f = ($tiene_servicios) ? ($conf->global->AUTOCON_PERC_S_JURIDICA / 100) : ($conf->global->AUTOCON_PERC_F_JURIDICA / 100);
                $s_fue = $v_base * $p_f;
            }
        } 
        // --- PERSONA NATURAL ---
        elseif ($nat == 2) {
            if ($decl == 0) {
                $p_f = ($tiene_servicios) ? ($conf->global->AUTOCON_PERC_S_NATURAL / 100) : ($conf->global->AUTOCON_PERC_F_NATURAL / 100);
            } else {
                $p_f = ($tiene_servicios) ? ($conf->global->AUTOCON_PERC_S_NATURAL_DECL / 100) : ($conf->global->AUTOCON_PERC_F_NATURAL_DECL / 100);
            }
            $s_fue = $v_base * $p_f;
        }

        if ($thirdparty->array_options['options_arrendador'] == 1) {
            $p_f = ($tiene_servicios) ? ($conf->global->PORC_RETE_ARENDAMIENTOS / 100) : 0.035;
            $s_fue = $v_base * $p_f;
        }

        if ($thirdparty->array_options['options_percibe_honorarios_o_comisiones'] == 1 && $object->element == 'invoice_supplier') {
            $p_f = ($tiene_servicios) ? ($conf->global->AUTOCON_PERC_H_JURIDICA / 100) : 0.11;
            $s_fue = $v_base * $p_f;
        }
    }

    if ($v_base >= $tope_min) {
        if (($object->element == 'facture' && ($es_GC || $es_reteiva)) || ($object->element == 'invoice_supplier' && $es_RS)) {
            $s_iva = $v_tva * ($conf->global->AUTOCON_PERC_RETEIVA / 100);
        }
    }

    $tope_min_ica = ((double)$conf->global->AUTOCON_TOPE_ICA_UVT ?: 7) * $uvt_val;
    if ($v_base >= $tope_min_ica) {
        $s_ica = $v_base * ($tar_ica / 1000);
    }
} // <--- Cierre del else ($existing)



// --- 8. VISTA ---
llxHeader('', "Retenciones Colombia");
$head = array();
if ($object->element == 'facture' || $object->element == 'invoice') $head = facture_prepare_head($object);
else $head = (function_exists('facturefourn_prepare_head')) ? facturefourn_prepare_head($object) : supplier_invoice_prepare_head($object);

echo dol_get_fiche_head($head, 'retencionescol', "Retenciones", -1, $object->element);
dol_banner_tab($object, 'ref', '', 1, 'ref');

echo '<div class="fichecenter"><div class="underbanner clearboth"></div>';
echo '<div class="info">porc: '.$p_f.'nat:'.$nat.'serv:'.$tiene_servicios.'const:'.$conf->global->AUTOCON_PERC_F_JURIDICA.'Análisis: '.$tipo_espejo. '<b>'.($tiene_servicios?'Servicios':'Bienes').'</b> | Tope: $'.number_format($tope_min,0).' | '.($v_base>=$tope_min?'<span style="color:green">Aplica</span>':'<span style="color:red">Bajo el tope</span>').' | <span style="color:red">'.$nat.'</span></div><br>';

echo '<form method="post" action="'.$_SERVER["PHP_SELF"].'?id='.$id.'">';
echo '<input type="hidden" name="action" value="update"><input type="hidden" name="token" value="'.newToken().'">';

echo '<table class="border centpercent"><thead><tr class="liste_titre"><td>Concepto Tributario</td><td>Base de Referencia</td><td>Valor Retención ($)</td></tr></thead><tbody>';
echo '<tr><td>Base Imponible (Subtotal)</td><td>$ '.number_format($v_base, 2).'</td><td>-</td></tr>';
echo '<tr><td>IVA de la Factura</td><td>$ '.number_format($v_tva, 2).'</td><td>-</td></tr>';
echo '<tr class="oddeven"><td><b>ReteFuente</b></td><td><input type="text" size="8" name="base_total" value="'.$v_base.'"></td><td><input type="text" size="10" name="val_retefuente" value="'.$s_fue.'"></td></tr>';
echo '<tr class="oddeven"><td><b>ReteIVA</b></td><td>Calculado sobre valor IVA</td><td><input type="text" size="10" name="val_reteiva" value="'.$s_iva.'"></td></tr>';
echo '<tr class="oddeven"><td><b>ReteICA</b></td><td>Afecta Base Imponible</td><td><input type="text" size="10" name="val_reteica" value="'.$s_ica.'"></td></tr>';

//NUEVA BASE IMPONIBLE

$n_base = $v_base - $s_ica - $s_iva - $s_fue;

echo '<tr class="oddeven"><td><b>Nueva Base imponible</b></td><td>'.number_format($n_base).'</td><td></td></tr>';
echo '</tbody></table>';

//if($s_ica==0 && $s_iva ==0 && $s_fue==0){ 
    
    //echo '<div class="center"><br>NO HAY REGISTROS PARA AGREGAR</div>';
    
//} else {
echo '<div class="center"><br><input type="submit" class="button" value="💾 GUARDAR CAMBIOS"></div>';
    
//}
echo '</form>';

if ($existing) {
    echo '<br><hr><div class="center">';
    if ($existing->is_processed) {
        echo '<div class="warning" style="padding:10px; background:#d4edda; color:#155724; border:1px solid #c3e6cb;">✅ Esta factura ya ha sido reclasificada en el Libro Mayor.</div>';
    } else {
        echo '<p>Haga clic abajo para inyectar las retenciones con NIT y agruparlas en el mismo asiento:</p>';
        echo '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?id='.$id.'&action=accounting_apply&token='.newToken().'">🔥 APLICAR RECLASIFICACIÓN CONTABLE</a>';
    }
    echo '</div>';
}
echo '</div>';

echo dol_get_fiche_end();
llxFooter();
