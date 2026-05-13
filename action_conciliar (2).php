<?php
// --- 1. CONFIGURACIÓN DE ENTORNO ---
$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res) die("Error: No se pudo encontrar main.inc.php");

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/salaries/class/paymentsalary.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

$id_extracto         = GETPOST('id_extracto', 'int');
$action              = GETPOST('action', 'alpha');
$fk_soc_anticipo     = GETPOST('fk_soc_anticipo', 'int');
$fk_product_anticipo = GETPOST('fk_product_anticipo', 'int');
$fk_user_salary      = GETPOST('fk_user_salary', 'int');
$id_facture_exist    = GETPOST('id_facture', 'int');
$fk_bank_dest        = GETPOST('fk_bank_dest', 'int');

if ($id_extracto > 0) {
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."ofinova_extracto WHERE rowid = ".(int)$id_extracto;
    $res = $db->query($sql);
    $mov = $db->fetch_object($res);

    if ($mov) {
        $db->begin();
        $error = 0;
        $id_factura_final = 0; 
        $es_venta   = ($mov->amount > 0);
        $monto_abs  = abs($mov->amount);
        $fecha_pago = $db->jdate($mov->date_mvmt);
        $now        = $db->idate(time());
        $f_pago_sql = $db->idate($fecha_pago);
        
                // --- CASO 1: ANTICIPO DE NÓMINA (SISTEMA OFINOVA POR TERCEROS) ---
                // --- CASO 1: ANTICIPO DE NÓMINA (SISTEMA OFINOVA POR TERCEROS) ---
                // --- CASO ANTICIPO DE NÓMINA (CORREGIDO CON CUENTA+NIT) ---
                // --- CASO ANTICIPO DE NÓMINA (CORREGIDO CON VÍNCULO DE TERCERO) ---
                // --- CASO: ANTICIPO DE NÓMINA CON INYECCIÓN CONTABLE DIRECTA ---
                // --- CASO ANTICIPO: BANCO + NOVEDAD + BOOKKEEPING (VERSIÓN FINAL) ---
                // --- CASO ANTICIPO: DINÁMICO Y PRECISO (130505 + BANCO REAL) ---
        if ($action == 'pago_nomina' || $action == 'anticipo_nomina_espejo') {
            if ($fk_soc_anticipo > 0) {
                $db->begin();

                // 1. OBTENER MAESTROS DEL EMPLEADO (NIT Y CUENTA)
                $sql_e = "SELECT s.nom, s.siren, ex.nominaofinova_cta_ant 
                          FROM ".MAIN_DB_PREFIX."societe as s 
                          LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields as ex ON s.rowid = ex.fk_object 
                          WHERE s.rowid = ".(int)$fk_soc_anticipo;
                $res_e = $db->query($sql_e);
                $emp = $db->fetch_object($res_e);
                
                $nit_c = str_replace(array(' ', '-', '.'), '', $emp->siren);
                // Ajuste: Prefijo 130505 por defecto para anticipos
                $cta_padre = (!empty($emp->nominaofinova_cta_ant)) ? $emp->nominaofinova_cta_ant : '130505';
                $cta_auxiliar = $cta_padre . $nit_c;

                // 2. OBTENER LA CUENTA CONTABLE DEL BANCO (Davivienda o Bancolombia)
                $sql_banco = "SELECT account_number, label FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".(int)$mov->fk_bank_account;
                $res_banco = $db->query($sql_banco);
                $bank_acc = $db->fetch_object($res_banco);
                $cta_contable_banco = (!empty($bank_acc->account_number)) ? $bank_acc->account_number : '111005'; // Fallback
                $nombre_banco = $bank_acc->label;

                // 3. INSERCIÓN EN BANCO (Tesorería)
                $sql_bank = "INSERT INTO ".MAIN_DB_PREFIX."bank (datec, datev, dateo, amount, label, fk_account, fk_user_author, fk_type, numero_compte, origin_type) 
                             VALUES ('$now', '$f_pago_sql', '$f_pago_sql', -".(double)$monto_abs.", '".$db->escape("Anticipo: ".$mov->label)."', ".(int)$mov->fk_bank_account.", ".(int)$user->id.", 'VIR', '$cta_auxiliar', 'ofinova_ant')";
                
                if ($db->query($sql_bank)) {
                    $id_bank_line = $db->last_insert_id(MAIN_DB_PREFIX."bank");
                    $db->query("INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, type) VALUES ($id_bank_line, ".(int)$fk_soc_anticipo.", 'company')");

                    // 4. NOVEDAD DE NÓMINA
                    $db->query("INSERT INTO llxu3_ofinova_nom_novedades (fk_soc, fecha, tipo, monto, status, descripcion) VALUES (".(int)$fk_soc_anticipo.", '$f_pago_sql', 'Anticipo', ".(double)$monto_abs.", 0, '".$db->escape($mov->label)."')");

                    // 5. INYECCIÓN AL BOOKKEEPING (CONTABILIDAD REAL)
                    $res_max = $db->query("SELECT MAX(CAST(piece_num AS UNSIGNED)) as max_p FROM ".MAIN_DB_PREFIX."accounting_bookkeeping");
                    $next_piece = ($db->fetch_object($res_max)->max_p) + 1;

                    // Línea Débito: 130505 + NIT
                    $sql_d = "INSERT INTO ".MAIN_DB_PREFIX."accounting_bookkeeping (entity, piece_num, doc_date, doc_type, doc_ref, fk_doc, numero_compte, subledger_account, subledger_label, thirdparty_code, debit, credit, montant, sens, fk_user_author, date_creation, code_journal, journal_label) 
                              VALUES (".$conf->entity.", $next_piece, '$f_pago_sql', 'bank', 'ANT-".$id_bank_line."', $id_bank_line, '$cta_padre', '$cta_auxiliar', '".$db->escape($emp->nom)."', '".$db->escape($nit_c)."', ".(double)$monto_abs.", 0, ".(double)$monto_abs.", 'D', ".$user->id.", '$now', 'NOM', 'Diario de Nomina')";
                    
                    // Línea Crédito: Cuenta real del Banco (11200501 / 11200502)
                    $sql_c = "INSERT INTO ".MAIN_DB_PREFIX."accounting_bookkeeping (entity, piece_num, doc_date, doc_type, doc_ref, fk_doc, numero_compte, subledger_account, subledger_label, debit, credit, montant, sens, fk_user_author, date_creation, code_journal, journal_label) 
                              VALUES (".$conf->entity.", $next_piece, '$f_pago_sql', 'bank', 'ANT-".$id_bank_line."', $id_bank_line, '$cta_contable_banco', '$cta_contable_banco', '".$db->escape($nombre_banco)."', 0, ".(double)$monto_abs.", ".(double)$monto_abs.", 'C', ".$user->id.", '$now', 'NOM', 'Diario de Nomina')";

                    if ($db->query($sql_d) && $db->query($sql_c)) {
                        $db->query("UPDATE llxu3_ofinova_extracto SET status=1 WHERE rowid=".(int)$id_extracto);
                        $db->commit();
                        setEventMessages("¡Procesado! Banco: $cta_contable_banco, Empleado: $cta_auxiliar.", null, 'mesgs');
                        $id_factura_final = -1;
                    } else {
                        $db->rollback();
                        setEventMessages("Error Bookkeeping: ".$db->lasterror(), null, 'errors');
                    }
                } else {
                    $db->rollback();
                    setEventMessages("Error Banco: ".$db->lasterror(), null, 'errors');
                }
            }
        }


        // --- CASO 1: PAGO DE NÓMINA (VINCULADO POR PERIODO) ---
        /*if ($action == 'pago_nomina' && $fk_user_salary > 0) {
            $sql_b = "SELECT rowid FROM ".MAIN_DB_PREFIX."salary WHERE fk_user=".(int)$fk_user_salary." AND '$f_pago_sql' BETWEEN datesp AND dateep LIMIT 1";
            $res_b = $db->query($sql_b);
            if ($res_b && $db->num_rows($res_b) > 0) {
                $id_salary_parent = (int)$db->fetch_object($res_b)->rowid;
                $sql_bank = "INSERT INTO ".MAIN_DB_PREFIX."bank (datec, datev, dateo, amount, label, fk_account, fk_user_author, fk_type, origin_type) VALUES ('$now', '$f_pago_sql', '$f_pago_sql', -$monto_abs, '".$db->escape("Nómina: ".$mov->label)."', ".(int)$mov->fk_bank_account.", ".(int)$user->id.", 'VIR', 'payment_salary')";
                if ($db->query($sql_bank)) {
                    $id_bank_line = $db->last_insert_id(MAIN_DB_PREFIX."bank");
                    $sql_pay = "INSERT INTO ".MAIN_DB_PREFIX."payment_salary (fk_user, datep, datev, salary, amount, label, fk_bank, fk_typepayment, entity, datec, fk_user_author, fk_salary) VALUES (".(int)$fk_user_salary.", '$f_pago_sql', '$f_pago_sql', $monto_abs, $monto_abs, 'Nom: ".$db->escape($mov->label)."', ".(int)$id_bank_line.", 2, ".(int)$conf->entity.", '$now', ".(int)$user->id.", $id_salary_parent)";
                    if ($db->query($sql_pay)) {
                        $id_pay_sal = $db->last_insert_id(MAIN_DB_PREFIX."payment_salary");
                        $db->query("UPDATE ".MAIN_DB_PREFIX."bank SET origin_id = ".(int)$id_pay_sal." WHERE rowid = ".(int)$id_bank_line);
                        $db->query("INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, type) VALUES (".(int)$id_bank_line.", ".(int)$id_pay_sal.", 'payment_salary')");
                        $id_factura_final = -1;
                    } else { $error++; }
                } else { $error++; }
            } else { $error++; setEventMessages("⚠️ No se encontró Nómina abierta para el periodo.", null, 'errors'); }
        }*/

        // --- CASO 2: TRASLADO INTERNO (CON CUENTA PUENTE 110595) ---
                // --- CASO 2: TRASLADO INTERNO (USANDO MÉTODO NATIVO DE DOLIBARR) ---
                // --- CASO 2: TRASLADO INTERNO (SQL DIRECTO - IMITANDO PROCESO NATIVO V23) ---
        elseif ($action == 'traslado_interno' && $fk_bank_dest > 0) {
            $db->begin();
            $now = $db->idate(time());
            $f_pago_sql = $db->idate($fecha_pago);
            $cta_puente = "110595";

            // 1. OBTENER LABELS PARA LOS CONCEPTOS
            $res_orig = $db->query("SELECT label FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".(int)$mov->fk_bank_account);
            $label_orig = ($res_orig) ? $db->fetch_object($res_orig)->label : "Banco";
            
            $res_dest = $db->query("SELECT label FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".(int)$fk_bank_dest);
            $label_dest = ($res_dest) ? $db->fetch_object($res_dest)->label : "Caja";

            // 2. INSERTAR SALIDA (Origen)
            // Llenamos numero_compte y code_compta con la 110595
            $sql_orig  = "INSERT INTO ".MAIN_DB_PREFIX."bank (datec, datev, dateo, amount, label, fk_account, fk_user_author, fk_type, numero_compte) VALUES (";
            $sql_orig .= "'$now', '$f_pago_sql', '$f_pago_sql', -".(double)$monto_abs.", ";
            $sql_orig .= "'".$db->escape("Traslado a $label_dest: ".$mov->label)."', ";
            $sql_orig .= (int)$mov->fk_bank_account.", ".(int)$user->id.", 'VIR', '$cta_puente')";
            
            $res_q_orig = $db->query($sql_orig);
            $id_db_orig = $db->last_insert_id(MAIN_DB_PREFIX."bank");

            // 3. INSERTAR ENTRADA (Destino)
            $sql_dest  = "INSERT INTO ".MAIN_DB_PREFIX."bank (datec, datev, dateo, amount, label, fk_account, fk_user_author, fk_type, numero_compte) VALUES (";
            $sql_dest .= "'$now', '$f_pago_sql', '$f_pago_sql', ".(double)$monto_abs.", ";
            $sql_dest .= "'".$db->escape("Traslado desde $label_orig: ".$mov->label)."', ";
            $sql_dest .= (int)$fk_bank_dest.", ".(int)$user->id.", 'VIR', '$cta_puente')";
            
            $res_q_dest = $db->query($sql_dest);
            $id_db_dest = $db->last_insert_id(MAIN_DB_PREFIX."bank");

            if ($res_q_orig && $res_q_dest) {
                // 4. CREAR VÍNCULOS "banktransfert" (IGUAL AL ASIENTO NATIVO QUE ME MOSTRASTE)
                $url_orig = "/erp/compta/bank/line.php?rowid=".$id_db_orig;
                $url_dest = "/erp/compta/bank/line.php?rowid=".$id_db_dest;
                $lbl_link = "(banktransfert)";

                $db->query("INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, label, type) VALUES ($id_db_orig, $id_db_dest, '".$db->escape($url_dest)."', '$lbl_link', 'banktransfert')");
                $db->query("INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, label, type) VALUES ($id_db_dest, $id_db_orig, '".$db->escape($url_orig)."', '$lbl_link', 'banktransfert')");

                $id_factura_final = -1; 
                $db->query("UPDATE ".MAIN_DB_PREFIX."ofinova_extracto SET status=1 WHERE rowid=".(int)$id_extracto);
                $db->commit();
                setEventMessages("Traslado interno conciliado con éxito (Vínculos corregidos).", null, 'mesgs');
            } else {
                $db->rollback();
                setEventMessages("Error al procesar el traslado en la base de datos.", null, 'errors');
            }
        }

        // --- ACCIÓN: SOLO MARCAR (CONCILIACIÓN MANUAL SIN ASIENTO) ---
        elseif ($action == 'solo_marcar') {
            $id_extracto = (int)GETPOST('id_extracto', 'int');
        
            if ($id_extracto > 0) {
                $db->begin();
                
                $sql = "UPDATE ".MAIN_DB_PREFIX."ofinova_extracto SET status = 1 WHERE rowid = ".$id_extracto;
                
                if ($db->query($sql)) {
                    $db->commit();
                    setEventMessages("Movimiento marcado como conciliado manualmente.", null, 'mesgs');
                } else {
                    $db->rollback();
                    setEventMessages("Error al actualizar estado.", null, 'errors');
                }
        
                // Redirección manteniendo el filtro
                $sql_e = "SELECT * FROM ".MAIN_DB_PREFIX."ofinova_extracto WHERE rowid = ".$id_extracto;
                $mov = $db->fetch_object($db->query($sql_e));
                $time_mvmt = strtotime($mov->date_mvmt);
                
                header("Location: conciliador_ofinova.php?fk_bank_account=".$mov->fk_bank_account."&month=".date('m', $time_mvmt)."&year=".date('Y', $time_mvmt));
                exit;
            }
        }


        // --- CASO 3: VINCULAR A FACTURA EXISTENTE ---
        elseif ($action == 'vincular_directo' && $id_facture_exist > 0) {
            $pago_cls = $es_venta ? 'Paiement' : 'PaiementFourn';
            $fac = ($es_venta) ? new Facture($db) : new FactureFournisseur($db);
            $t_bank = ($es_venta) ? 'payment' : 'payment_supplier';
            $t_fac = ($es_venta) ? 'facture' : 'facture_fourn';
            if ($fac->fetch($id_facture_exist) > 0) {
                $pago = new $pago_cls($db);
                if ($es_venta) { $pago->datep = $fecha_pago; $pago->datev = $fecha_pago; }
                $pago->datepaye = $fecha_pago; $pago->amounts = array($fac->id => $monto_abs); $pago->paiementid = 7;
                if ($pago->create($user) > 0) {
                    $pago->add_to_invoice($pago->amounts);
                    $pago->addPaymentToBank($user, $t_bank, "Vinc: ".$mov->label, $mov->fk_bank_account, '', '', $fecha_pago, $fecha_pago);
                    $db->query("UPDATE ".MAIN_DB_PREFIX.$t_fac." SET paye=1, fk_statut=2, date_closing='$f_pago_sql' WHERE rowid=".$fac->id);
                    $id_factura_final = $fac->id;
                } else { $error++; }
            }
        }

        // --- CASO 4: GASTOS Y ANTICIPOS (CREACIÓN NUEVA) ---
        
        // --- ACCIÓN: INGRESO DIRECTO (Rendimientos, Intereses, Reintegros) ---
        // --- ACCIÓN: INGRESO DIRECTO (Rendimientos, Reintegros, etc.) ---
elseif ($action == 'ingreso_directo') {
    $id_extracto = (int)GETPOST('id_extracto', 'int');
    $fk_soc_id = (int)GETPOST('fk_soc_'.$id_extracto, 'int');

    $sql_e = "SELECT * FROM ".MAIN_DB_PREFIX."ofinova_extracto WHERE rowid = ".$id_extracto;
    $res_e = $db->query($sql_e);
    $mov = $db->fetch_object($res_e);

    if ($mov) {
        // 1. OBTENER CUENTA BANCARIA Y TERCERO
        $sql_b = "SELECT account_number FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".(int)$mov->fk_bank_account;
        $obj_b = $db->fetch_object($db->query($sql_b));
        $cta_banco = (!empty($obj_b->account_number)) ? $obj_b->account_number : '111005';
        
        $sql_soc = "SELECT nom, siren FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".$fk_soc_id;
        $obj_soc = $db->fetch_object($db->query($sql_soc));
        $tercero_nom = $db->escape($obj_soc->nom);
        $nit = str_replace(array('.', '-', ' '), '', $obj_soc->siren);

        $fecha_ahora = date('Y-m-d H:i:s');
        $monto = abs($mov->amount);

        // 2. INSERCIÓN EN TABLA 'bank' (Realidad Bancaria)
        $sql_bank = "INSERT INTO ".MAIN_DB_PREFIX."bank (datec, datev, dateo, amount, label, fk_account, fk_user_author, fk_type, rappro, author)
                     VALUES ('$fecha_ahora', '".$mov->date_mvmt."', '".$mov->date_mvmt."', $monto, '".$db->escape($mov->label)."', ".(int)$mov->fk_bank_account.", ".$user->id.", 'VIR', 0, '".$db->escape($user->firstname)."')";
        $res_bank = $db->query($sql_bank);
        $fk_bank_line = $db->last_insert_id(MAIN_DB_PREFIX."bank");

        // 3. OBTENER PRÓXIMO NÚMERO DE ASIENTO
        $res_max = $db->query("SELECT MAX(CAST(piece_num AS UNSIGNED)) as max_p FROM ".MAIN_DB_PREFIX."accounting_bookkeeping");
        $next_p = ($db->fetch_object($res_max)->max_p) + 1;

        // 4. INSERCIÓN CONTABLE (DÉBITO - BANCO)
        $sql_d = "INSERT INTO ".MAIN_DB_PREFIX."accounting_bookkeeping (entity, piece_num, doc_date, doc_type, doc_ref, fk_doc, numero_compte, subledger_account, subledger_label, label_operation, debit, credit, montant, sens, fk_user_author, date_creation, code_journal, journal_label) 
                  VALUES (".$conf->entity.", $next_p, '".$mov->date_mvmt."', 'BANC', 'EXT-".$id_extracto."', $fk_bank_line, '$cta_banco', '$cta_banco', 'Banco', '".$db->escape($mov->label)."', $monto, 0, $monto, 'D', ".$user->id.", '$fecha_ahora', 'BANK', 'Ingresos Bancarios')";
        $res_d = $db->query($sql_d);

        // 5. INSERCIÓN CONTABLE (CRÉDITO - INGRESO)
        $sql_c = "INSERT INTO ".MAIN_DB_PREFIX."accounting_bookkeeping (entity, piece_num, doc_date, doc_type, doc_ref, fk_doc, numero_compte, subledger_account, subledger_label, label_operation, debit, credit, montant, sens, fk_user_author, date_creation, code_journal, journal_label) 
                  VALUES (".$conf->entity.", $next_p, '".$mov->date_mvmt."', 'BANC', 'EXT-".$id_extracto."', $fk_bank_line, '421005', '$nit', '$tercero_nom', '".$db->escape($mov->label)."', 0, $monto, $monto, 'C', ".$user->id.", '$fecha_ahora', 'BANK', 'Ingresos Bancarios')";
        $res_c = $db->query($sql_c);

        // 6. ACTUALIZAR ESTADO DEL EXTRACTO
        $res_u = $db->query("UPDATE ".MAIN_DB_PREFIX."ofinova_extracto SET status = 1 WHERE rowid = ".$id_extracto);

        // --- VALIDACIÓN Y CIERRE ---
        if ($res_bank && $res_d && $res_c && $res_u) {
            $db->commit(); // <--- AQUÍ ESTABA EL FALLO: Sin esto no se guarda nada
            setEventMessages("¡Ingreso #$next_p conciliado con éxito!", null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error al procesar: ".$db->lasterror(), null, 'errors');
        }

                // --- LA REDIRECCIÓN MAESTRA ---
        // Extraemos mes y año de la fecha del movimiento que acabamos de procesar
        $time_mvmt = strtotime($mov->date_mvmt);
        $m = date('m', $time_mvmt);
        $y = date('Y', $time_mvmt);

        // Volvemos al conciliador con los 3 parámetros clave
        header("Location: conciliador_ofinova.php?fk_bank_account=".$mov->fk_bank_account."&month=".$m."&year=".$y);
        exit;
    }
}



        else {
            $id_p = ($fk_product_anticipo > 0) ? $fk_product_anticipo : 46;
            $prod = new Product($db); $prod->fetch($id_p);
            $t_iva = (double)$prod->tva_tx;
            $base_c = $monto_abs / (1 + ($t_iva / 100));

            if ($es_venta) {
                $fac = new Facture($db); $fac->type = 3; $fac->socid = ($fk_soc_anticipo > 0 ? $fk_soc_anticipo : 84);
                $fac->date = $fecha_pago; $fac->libelle = "Anticipo: ".$mov->label; $res_f = $fac->create($user);
            } else {
                $fac = new FactureFournisseur($db); $fac->type = ($action == 'anticipo_proveedor' ? 3 : 0);
                $fac->socid = ($fk_soc_anticipo > 0 ? $fk_soc_anticipo : 84); $fac->date = $fecha_pago;
                $fac->ref_supplier = "EXT-".$mov->rowid; $fac->libelle = $mov->label; $res_f = $fac->create($user);
            }

            if ($res_f > 0) {
                if ($es_venta) $fac->addline($prod->label, $base_c, 1, $t_iva, 0, 0, $prod->id);
                else $fac->addline($prod->label, $base_c, $t_iva, 0, 0, 1, $prod->id);
                if ($fac->validate($user) >= 0) {
                    $pago_cls = $es_venta ? 'Paiement' : 'PaiementFourn';
                    $pago = new $pago_cls($db);
                    if ($es_venta) { $pago->datep = $fecha_pago; $pago->datev = $fecha_pago; }
                    $pago->datepaye = $fecha_pago; $pago->amounts = array($fac->id => $monto_abs); $pago->paiementid = 7;
                    if ($pago->create($user) > 0) {
                        $pago->add_to_invoice($pago->amounts);
                        $pago->addPaymentToBank($user, ($es_venta?'payment':'payment_supplier'), "Ajuste: ".$mov->label, $mov->fk_bank_account, '', '', $fecha_pago, $fecha_pago);
                        $tabla = $es_venta ? 'facture' : 'facture_fourn';
                        $db->query("UPDATE ".MAIN_DB_PREFIX.$tabla." SET paye=1, fk_statut=2, date_closing='$f_pago_sql' WHERE rowid=".$fac->id);
                        $id_factura_final = $fac->id;
                    } else { $error++; }
                } else { $error++; }
            } else { $error++; }
        }

        // --- FINALIZACIÓN ---
        if ($error == 0 && ($id_factura_final > 0 || $id_factura_final == -1)) {
            $id_rel = ($id_factura_final == -1) ? 0 : $id_factura_final;
            $db->query("UPDATE ".MAIN_DB_PREFIX."ofinova_extracto SET status=1, fk_facture=".(int)$id_rel." WHERE rowid=".(int)$id_extracto);
            $db->commit();
            setEventMessages("Operación realizada con éxito.", null, 'mesgs');
        } else {
            $db->rollback();
            if (empty($_SESSION['dol_errors'])) setEventMessages("Error al procesar la conciliación.", null, 'errors');
        }
        header("Location: conciliador_ofinova.php?fk_bank_account=".$mov->fk_bank_account."&filter_month=".date('m', $fecha_pago)."&filter_year=".date('Y', $fecha_pago));
        exit;
    }
}
