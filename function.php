<?php

/**
 * Función Maestra de Inyección Contable Fiscal (V23)
 */
function syncFacturaAlLibroMayor($factura_id, $tipo = 'venta') 
{
    global $db, $conf, $user;

    $table = MAIN_DB_PREFIX . "accounting_bookkeeping";
    
    // 1. Identificar el tipo de documento técnico
    $doc_type = ($tipo == 'venta') ? 'customer_invoice' : 'supplier_invoice';

    // 2. Localizar el asiento original en el Libro Mayor
    // Usamos $db->escape() correctamente para limpiar las variables
    $sql_base = "SELECT piece_num, code_journal, doc_date, doc_ref, entity, thirdparty_code, ref 
                 FROM " . $table . " 
                 WHERE fk_doc = " . (int)$factura_id . " 
                 AND doc_type = '" . $db->escape($doc_type) . "' 
                 LIMIT 1";
    
    $res_base = $db->query($sql_base);
    if (!$res_base) return "Error SQL Consulta Base";
    $base = $db->fetch_object($res_base);

    if (!$base) return "No se encontró el asiento base";

    // 3. Obtener datos del Espejo Fiscal
    $sql_esp = "SELECT * FROM " . MAIN_DB_PREFIX . "autocontabilidad_fiscal_espejo 
                WHERE fk_facture = " . (int)$factura_id . " AND tipo_factura = '" . $db->escape($tipo) . "'";
    $res_esp = $db->query($sql_esp);
    $esp = $db->fetch_object($res_esp);

    if (!$esp) {
        $esp = (object) array('val_retefuente'=>0, 'val_reteiva'=>0, 'val_reteica'=>0, 'val_autorenta'=>0, 'val_autoica'=>0);
    }

    // 4. Buscar el NIT (siren)
    $sql_nit = "SELECT s.siren FROM " . MAIN_DB_PREFIX . "societe as s ";
    if ($tipo == 'venta') {
        $sql_nit .= "INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.fk_soc = s.rowid WHERE f.rowid = " . (int)$factura_id;
    } else {
        $sql_nit .= "INNER JOIN " . MAIN_DB_PREFIX . "facture_fourn as ff ON ff.fk_soc = s.rowid WHERE ff.rowid = " . (int)$factura_id;
    }
    
    $res_nit = $db->query($sql_nit);
    $obj_nit = $db->fetch_object($res_nit);
    $nit = ($obj_nit && $obj_nit->siren) ? preg_replace('/[^0-9]/', '', $obj_nit->siren) : '';

    // 5. Cuentas desde Constantes (con fallback manual)
    $c_rf_vta  = $conf->global->CONTABILIDADCOL_CTA_RF_VTA  ?: '135515';
    $c_rf_com  = $conf->global->CONTABILIDADCOL_CTA_RF_COM  ?: '236540';
    $c_ar_pas  = $conf->global->CONTABILIDADCOL_CTA_AR_PAS ?: '236575';
    $c_ai_pas  = $conf->global->CONTABILIDADCOL_CTA_AI_PAS ?: '236805';
    // IVA e ICA
    $c_iva_vta = $conf->global->CONTABILIDADCOL_CTA_IVA_VTA ?: '135517';
    $c_iva_com = $conf->global->CONTABILIDADCOL_CTA_IVA_COM ?: '236701';
    $c_ica_vta = $conf->global->CONTABILIDADCOL_CTA_ICA_VTA ?: '135518';
    $c_ica_com = $conf->global->CONTABILIDADCOL_CTA_ICA_COM ?: '236801';

    // 6. Limpieza de inyecciones previas
    $db->query("DELETE FROM " . $table . " WHERE piece_num = " . (int)$base->piece_num . " AND import_key = 'FISCAL_COL'");

    $db->begin();
    $lines = array();

    if ($tipo == 'venta') {
        // Retenciones (Cuenta + NIT)
        $lines[] = array('cta' => $c_rf_vta.$nit,  'lab' => 'ReteFuente Recibida', 'd' => $esp->val_retefuente, 'c' => 0);
        $lines[] = array('cta' => $c_iva_vta, 'lab' => 'ReteIVA Recibida',    'd' => $esp->val_reteiva,    'c' => 0);
        $lines[] = array('cta' => $c_ica_vta.$nit, 'lab' => 'ReteICA Recibida',    'd' => $esp->val_reteica,    'c' => 0);
        
        // Autorretenciones (Activa con NIT, Pasiva SOLA)
        $lines[] = array('cta' => $c_rf_vta.$nit,  'lab' => 'AutoRenta Activo',   'd' => $esp->val_autorenta,  'c' => 0);
        $lines[] = array('cta' => $c_ar_pas,       'lab' => 'AutoRenta Pasivo',   'd' => 0, 'c' => $esp->val_autorenta);
        
        $lines[] = array('cta' => $c_ica_vta.$nit, 'lab' => 'AutoICA Activo',     'd' => $esp->val_autoica,    'c' => 0);
        $lines[] = array('cta' => $c_ai_pas,       'lab' => 'AutoICA Pasivo',     'd' => 0, 'c' => $esp->val_autoica);
    } else {
        // Compras (Cuenta + NIT)
        $lines[] = array('cta' => $c_rf_com.$nit,  'lab' => 'ReteFuente por Pagar', 'd' => 0, 'c' => $esp->val_retefuente);
        $lines[] = array('cta' => $c_iva_com.$nit, 'lab' => 'ReteIVA por Pagar',    'd' => 0, 'c' => $esp->val_reteiva);
        $lines[] = array('cta' => $c_ica_com.$nit, 'lab' => 'ReteICA por Pagar',    'd' => 0, 'c' => $esp->val_reteica);
    }

    // 7. Inserción de Líneas
    foreach ($lines as $l) {
        $sens = ($l['d'] > 0 || ($l['d'] == 0 && strpos($l['cta'], '1355') === 0)) ? 'D' : 'C';
        
        $sql_ins = "INSERT INTO " . $table . " (entity, piece_num, doc_date, doc_type, doc_ref, fk_doc, numero_compte, 
                    label_operation, debit, credit, montant, sens, ref, fk_user_author, date_creation, code_journal, 
                    thirdparty_code, import_key) ";
        $sql_ins.= "VALUES (";
        $sql_ins.= (int)$base->entity . ", ";
        $sql_ins.= (int)$base->piece_num . ", ";
        $sql_ins.= "'" . $db->idate($base->doc_date) . "', ";
        $sql_ins.= "'" . $db->escape($doc_type) . "', ";
        $sql_ins.= "'" . $db->escape($base->doc_ref) . "', ";
        $sql_ins.= (int)$factura_id . ", ";
        $sql_ins.= "'" . $db->escape($l['cta']) . "', ";
        $sql_ins.= "'" . $db->escape($l['lab']) . "', ";
        $sql_ins.= (float)$l['d'] . ", ";
        $sql_ins.= (float)$l['c'] . ", ";
        $sql_ins.= (float)($l['d'] + $l['c']) . ", ";
        $sql_ins.= "'" . $sens . "', ";
        $sql_ins.= "'" . $db->escape($base->ref) . "', ";
        $sql_ins.= (int)$user->id . ", NOW(), ";
        $sql_ins.= "'" . $db->escape($base->code_journal) . "', ";
        $sql_ins.= "'" . $db->escape($base->thirdparty_code) . "', ";
        $sql_ins.= "'FISCAL_COL'";
        $sql_ins.= ")";
        
        $db->query($sql_ins);
    }

    // 8. Neteo de Cuenta de Tercero
    $total_ret = (float)($esp->val_retefuente + $esp->val_reteiva + $esp->val_reteica);
    if ($total_ret > 0) {
        $cta_prefix = ($tipo == 'venta') ? '1305' : '2205';
        $sql_upd = "UPDATE " . $table . " SET ";
        if ($tipo == 'venta') {
            $sql_upd .= "debit = debit - $total_ret, montant = montant - $total_ret ";
        } else {
            $sql_upd .= "credit = credit - $total_ret, montant = montant - $total_ret ";
        }
        $sql_upd .= "WHERE piece_num = " . (int)$base->piece_num . " AND numero_compte LIKE '" . $db->escape($cta_prefix) . "%' AND (import_key IS NULL OR import_key = '')";
        $db->query($sql_upd);
    }

    $db->commit();
    return "OK";
}
