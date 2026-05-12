<?php
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Error: No se pudo encontrar main.inc.php");

$id_periodo = GETPOST('id', 'int');
$now = $db->idate(time());

if ($id_periodo > 0) {
    $db->begin();

    // 1. Obtener datos del Periodo
    $sql_p = "SELECT * FROM llxu3_ofinova_nom_periodo WHERE rowid = " . (int)$id_periodo;
    $res_p = $db->query($sql_p);
    $periodo = $db->fetch_object($res_p);

    if ($periodo && $periodo->status == 0) {
        
        // Obtener el siguiente piece_num (Número de Asiento Único para toda la nómina)
        $sql_max = "SELECT MAX(CAST(piece_num AS UNSIGNED)) as max_p FROM ".MAIN_DB_PREFIX."accounting_bookkeeping";
        $res_max = $db->query($sql_max);
        $next_piece = ($db->fetch_object($res_max)->max_p) + 1;

        // 2. Consulta Maestra (INNER JOIN para empleados en el periodo, LEFT JOIN para extrafields)
        $sql_d = "SELECT d.*, s.nom as emp_nom, s.siren as nit, s.rowid as socid,
                  ex.nominaofinova_cta_gas, ex.nominaofinova_cta_pas, 
                  ex.nominaofinova_cta_ant, ex.nominaofinova_eps, ex.nominaofinova_afp, ex.nominaofinova_arl
                  FROM llxu3_ofinova_nom_detalles as d
                  INNER JOIN ".MAIN_DB_PREFIX."societe as s ON d.fk_soc = s.rowid
                  LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields as ex ON d.fk_soc = ex.fk_object
                  WHERE d.fk_periodo = " . (int)$id_periodo;
        
        $res_d = $db->query($sql_d);
        $error = 0;

        while ($linea = $db->fetch_object($res_d)) {
            $asiento = array();
            $nit_c = str_replace(array(' ', '-', '.'), '', $linea->nit); // NIT limpio para concatenar

            // Función para mapear: numero_compte (Padre) y subledger_account (Cuenta+NIT)
            $map = function($cta_base, $defecto, $nit) {
                $padre = (!empty($cta_base) && $cta_base != '0') ? $cta_base : $defecto;
                return array('pc' => $padre, 'sc' => $padre . $nit);
            };

            // --- A. BLOQUE PAGO EMPLEADO (DEVENGADOS Y DEDUCCIONES) ---
            
            // Sueldo (Cuenta Gasto + NIT)
            $m = $map($linea->nominaofinova_cta_gas, '510506', $nit_c);
            $asiento[] = array('pc'=>$m['pc'], 'sc'=>$m['sc'], 'd'=>$linea->val_sueldo_pagar, 'h'=>0, 'nom'=>$linea->emp_nom, 'nit'=>$linea->nit);
            
            // Auxilio Transporte (Cuenta 510527 + NIT)
            if ($linea->val_aux_trans > 0)
                $asiento[] = array('pc'=>'510527', 'sc'=>'510527'.$nit_c, 'd'=>$linea->val_aux_trans, 'h'=>0, 'nom'=>$linea->emp_nom, 'nit'=>$linea->nit);

            // Salud y Pensión Empleado (Crédito a entidades - Sin NIT empleado en cuenta)
            $asiento[] = array('pc'=>'237005', 'sc'=>'237005', 'd'=>0, 'h'=>$linea->val_salud, 'nom'=>'EPS', 'nit'=>'');
            $asiento[] = array('pc'=>'238030', 'sc'=>'238030', 'd'=>0, 'h'=>$linea->val_pension, 'nom'=>'AFP', 'nit'=>'');
            
            // Cruce de Anticipos (Cuenta Anticipo + NIT)
            if ($linea->val_anticipos > 0) {
                $m = $map($linea->nominaofinova_cta_ant, '136505', $nit_c);
                $asiento[] = array('pc'=>$m['pc'], 'sc'=>$m['sc'], 'd'=>0, 'h'=>$linea->val_anticipos, 'nom'=>$linea->emp_nom, 'nit'=>$linea->nit);
            }

            // Neto a Pagar (Cuenta Pasivo + NIT)
            $m = $map($linea->nominaofinova_cta_pas, '250505', $nit_c);
            $asiento[] = array('pc'=>$m['pc'], 'sc'=>$m['sc'], 'd'=>0, 'h'=>$linea->total_neto, 'nom'=>$linea->emp_nom, 'nit'=>$linea->nit);


            // --- B. BLOQUE PROVISIONES MENSUALES (GASTO VS PASIVO ESTIMADO) ---
            
            $provisiones = array(
                array('g'=>'510530', 'p'=>'261005', 'v'=>$linea->val_cesantias, 'l'=>'Cesantias'),
                array('g'=>'510533', 'p'=>'261010', 'v'=>$linea->val_intereses_ces, 'l'=>'Int. Cesantias'),
                array('g'=>'510536', 'p'=>'261020', 'v'=>$linea->val_prima, 'l'=>'Prima'),
                array('g'=>'510539', 'p'=>'261015', 'v'=>$linea->val_vacaciones, 'l'=>'Vacaciones')
            );

            foreach ($provisiones as $pv) {
                if ($pv['v'] > 0) {
                    $asiento[] = array('pc'=>$pv['g'], 'sc'=>$pv['g'].$nit_c, 'd'=>$pv['v'], 'h'=>0, 'nom'=>$linea->emp_nom, 'nit'=>$linea->nit);
                    $asiento[] = array('pc'=>$pv['p'], 'sc'=>$pv['p'].$nit_c, 'd'=>0, 'h'=>$pv['v'], 'nom'=>$linea->emp_nom, 'nit'=>$linea->nit);
                }
            }

            // --- C. CARGA PATRONAL (ARL, PENSION PATRONAL, CAJA) ---

            if ($linea->val_arl_patronal > 0) {
                $asiento[] = array('pc'=>'510568', 'sc'=>'510568'.$nit_c, 'd'=>$linea->val_arl_patronal, 'h'=>0, 'nom'=>$linea->emp_nom, 'nit'=>$linea->nit);
                $asiento[] = array('pc'=>'237006', 'sc'=>'237006', 'd'=>0, 'h'=>$linea->val_arl_patronal, 'nom'=>'Entidad ARL', 'nit'=>'');
            }

            if ($linea->val_pension_patronal > 0) {
                $asiento[] = array('pc'=>'510570', 'sc'=>'510570'.$nit_c, 'd'=>$linea->val_pension_patronal, 'h'=>0, 'nom'=>$linea->emp_nom, 'nit'=>$linea->nit);
                $asiento[] = array('pc'=>'238030', 'sc'=>'238030', 'd'=>0, 'h'=>$linea->val_pension_patronal, 'nom'=>'AFP Patronal', 'nit'=>'');
            }

            if ($linea->val_caja_comp > 0) {
                $asiento[] = array('pc'=>'510572', 'sc'=>'510572'.$nit_c, 'd'=>$linea->val_caja_comp, 'h'=>0, 'nom'=>$linea->emp_nom, 'nit'=>$linea->nit);
                $asiento[] = array('pc'=>'237010', 'sc'=>'237010', 'd'=>0, 'h'=>$linea->val_caja_comp, 'nom'=>'Caja Compensacion', 'nit'=>'');
            }

            // --- D. VACACIONES DISFRUTADAS (Limpia el pasivo si hubo pago este mes) ---
            if ($linea->val_vacaciones_pagadas > 0) {
                $asiento[] = array('pc'=>'261015', 'sc'=>'261015'.$nit_c, 'd'=>$linea->val_vacaciones_pagadas, 'h'=>0, 'nom'=>$linea->emp_nom, 'nit'=>$linea->nit);
                // El crédito ya está en el neto (2505) arriba
            }
            
                        // --- BLOQUE DE NOVEDADES (ANTICIPOS, COMISIONES, BONOS) ---
            $sql_nov = "SELECT tipo, monto, descripcion FROM llxu3_ofinova_nom_novedades 
                        WHERE fk_soc = ".(int)$linea->socid." AND fk_periodo = ".(int)$id_periodo;
            $res_nov = $db->query($sql_nov);

            while ($nov = $db->fetch_object($res_nov)) {
                if ($nov->monto <= 0) continue;

                if ($nov->tipo == 'Anticipo') {
                    // CRUCE DE ANTICIPO: Crédito a la 1365+NIT (Mata la deuda)
                    $m_ant = $map($linea->nominaofinova_cta_ant, '136505', $nit_c);
                    $asiento[] = array('pc' => $m_ant['pc'], 'sc' => $m_ant['sc'], 'd' => 0, 'h' => $nov->monto, 'nom' => $linea->emp_nom, 'nit' => $linea->nit);
                } 
                elseif ($nov->tipo == 'Comision' || $nov->tipo == 'Bono') {
                    // GASTO: Débito a la 5105+NIT (Aumenta el costo laboral)
                    $m_gas = $map($linea->nominaofinova_cta_gas, '510506', $nit_c);
                    $asiento[] = array('pc' => $m_gas['pc'], 'sc' => $m_gas['sc'], 'd' => $nov->monto, 'h' => 0, 'nom' => $linea->emp_nom, 'nit' => $linea->nit);
                }
                // Si es Prima o Intereses (Hitos), ya los manejamos en el bloque prestacional, 
                // pero este bucle asegura que cualquier otra novedad "viva" se contabilice.
            }


            // --- INYECCIÓN FINAL AL BOOKKEEPING ---
            foreach ($asiento as $mov) {
                if (empty($mov['d']) && empty($mov['h'])) continue; 
                
                $sens = ($mov['d'] > 0) ? 'D' : 'C';
                //$montant = ($mov['d'] > 0) ? $mov['d'] : $mov['h'];
                // En el inyector, asegúrate de redondear a 2 decimales para la contabilidad
                    $montant = round(($mov['d'] > 0 ? $mov['d'] : $mov['h']), 2);

                
                $sql_in = "INSERT INTO ".MAIN_DB_PREFIX."accounting_bookkeeping (
                    entity, piece_num, doc_date, doc_type, doc_ref, fk_doc, fk_docdet, 
                    thirdparty_code, subledger_account, subledger_label, 
                    numero_compte, label_compte, label_operation, 
                    debit, credit, montant, sens, 
                    fk_user_author, date_creation, code_journal, journal_label
                ) VALUES (
                    ".$conf->entity.", ".$next_piece.", '$now', 'nomina', 'NOM-".$id_periodo."', ".(int)$id_periodo.", ".(int)$linea->rowid.", 
                    '".$db->escape($mov['nit'])."', '".$db->escape($mov['sc'])."', '".$db->escape($mov['nom'])."', 
                    '".$db->escape($mov['pc'])."', 'NOMINA COL', '".$db->escape("Nom ".$periodo->label." - ".$linea->emp_nom)."', 
                    ".$mov['d'].", ".$mov['h'].", ".$montant.", '$sens', 
                    ".$user->id.", '$now', 'NOM', 'Diario de Nomina'
                )";
                
                if (!$db->query($sql_in)) $error++;
            }
        }

        if ($error == 0) {
            $db->query("UPDATE llxu3_ofinova_nom_periodo SET status = 2 WHERE rowid = " . (int)$id_periodo);
            $db->commit();
            setEventMessages("Contabilización Integral Exitosa (Asiento #".$next_piece."). Se han causado provisiones, parafiscales y salarios.", null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error crítico en la inyección contable: ".$db->lasterror(), null, 'errors');
        }
    }
}

header("Location: listado_detalles.php?id=" . $id_periodo);
exit;
