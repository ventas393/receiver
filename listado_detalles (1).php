<?php
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Error: No se pudo encontrar main.inc.php");

$id_periodo = GETPOST('id', 'int');
if (empty($id_periodo)) { header("Location: listado_periodos.php"); exit; }

// 1. CARGAR CABECERA
$sql_p = "SELECT * FROM llxu3_ofinova_nom_periodo WHERE rowid = " . (int)$id_periodo;
$res_p = $db->query($sql_p);
$periodo = $db->fetch_object($res_p);

$action = GETPOST('action', 'alpha');
$mes    = $periodo->mes;
$anio   = $periodo->anio;


// 2. CONSTANTES DINÁMICAS (Desde Configuración Dolibarr)
$smmlv_actual    = !empty($conf->global->OFINOVA_SMMLV) ? $conf->global->OFINOVA_SMMLV : 1300000;
$aux_trans_valor = !empty($conf->global->OFINOVA_AUX_TRANS) ? $conf->global->OFINOVA_AUX_TRANS : 162000;
$porc_salud_e    = (!empty($conf->global->OFINOVA_PORC_SALUD_EMP) ? $conf->global->OFINOVA_PORC_SALUD_EMP : 4) / 100;
$porc_pension_e  = (!empty($conf->global->OFINOVA_PORC_PENSION_EMP) ? $conf->global->OFINOVA_PORC_PENSION_EMP : 4) / 100;

// --- ACCIÓN: RECALCULAR NÓMINA DE UN PERIODO ---
if ($action == 'recalcular_nomina') {
    $fk_periodo = (int)GETPOST('fk_periodo', 'int');

    if ($fk_periodo > 0) {
        $db->begin();
        
        $error_flag = 0;

        // 🌟 EL TOQUE MÁGICO DE LIMPIEZA 🌟
        // Borra los cálculos previos de ESTE periodo si ya existían, sin tocar la tabla de periodos
        $sql_clean = "DELETE FROM ".MAIN_DB_PREFIX."ofinova_nom_detalles WHERE fk_periodo = ".$fk_periodo;
        $res_clean = $db->query($sql_clean);
        
        if (!$res_clean) {
            $error_flag++;
            $error_msg = "Error al limpiar registros previos: ".$db->lasterror();
            exit;
        }
        
    $label = "Nómina de " . dol_print_date(mktime(0,0,0,$mes,1,$anio), "%B %Y");
    
    $sql_p = "INSERT INTO llxu3_ofinova_nom_periodo (label, mes, anio, fecha_creacion, fk_user_crea, status) 
              VALUES ('".$db->escape($label)."', $mes, $anio, '".$db->idate(time())."', ".(int)$user->id.", 0)";
    
    if ($db->query($sql_p)) {
        $id_periodo = $db->last_insert_id("llxu3_ofinova_nom_periodo");

        // 3. BUSCAR EMPLEADOS ACTIVOS
        $sql_e = "SELECT fk_object as fk_soc, nominaofinova_sueldo as sueldo, nominaofinova_riesgo as riesgo 
                  FROM llxu3_societe_extrafields WHERE nominaofinova_is_emp = 1";
        $res_e = $db->query($sql_e);

        while ($emp = $db->fetch_object($res_e)) {
            $socid = (int)$emp->fk_soc;
            $sueldo_base = (double)$emp->sueldo;

            // --- A. MOTOR DE ANTICIPOS Y NOVEDADES ---
            $val_anticipos = 0; $val_bonos = 0;
            $sql_n = "SELECT tipo, monto FROM llxu3_ofinova_nom_novedades WHERE fk_soc = $socid AND status = 0";
            $res_n = $db->query($sql_n);
            while ($nov = $db->fetch_object($res_n)) {
                if ($nov->tipo == 'Anticipo') $val_anticipos += $nov->monto;
                else $val_bonos += $nov->monto;
            }

            // --- B. MOTOR DE HITOS (PRIMA / INTERESES) ---
            if ($mes == 1) { // Intereses en Enero
                $sql_int = "SELECT SUM(d.val_intereses_ces) as total FROM llxu3_ofinova_nom_detalles as d 
                            INNER JOIN llxu3_ofinova_nom_periodo as p ON d.fk_periodo = p.rowid 
                            WHERE d.fk_soc = $socid AND p.anio = ".($anio-1)." AND p.status = 2";
                $val_int = $db->fetch_object($db->query($sql_int))->total ?: 0;
                if ($val_int > 0) {
                    $db->query("INSERT INTO llxu3_ofinova_nom_novedades (fk_soc, fecha, tipo, monto, descripcion) VALUES ($socid, '".$db->idate(time())."', 'Intereses Cesantias', $val_int, 'Pago Anual')");
                    $val_bonos += $val_int;
                }
            }
            if ($mes == 6 || $mes == 12) { // Prima
                $m_ini = ($mes == 6) ? 1 : 7;
                $sql_pri = "SELECT SUM(d.val_prima) as total FROM llxu3_ofinova_nom_detalles as d 
                            INNER JOIN llxu3_ofinova_nom_periodo as p ON d.fk_periodo = p.rowid 
                            WHERE d.fk_soc = $socid AND p.anio = $anio AND p.mes >= $m_ini AND p.mes < $mes AND p.status = 2";
                $val_pri_acum = $db->fetch_object($db->query($sql_pri))->total ?: 0;
                $p_mes = ($sueldo_base + ($sueldo_base <= ($smmlv_actual*2) ? $aux_trans_valor : 0)) * 0.0833;
                $db->query("INSERT INTO llxu3_ofinova_nom_novedades (fk_soc, fecha, tipo, monto, descripcion) VALUES ($socid, '".$db->idate(time())."', 'Prima', ".($val_pri_acum + $p_mes).", 'Pago Semestral')");
                $val_bonos += ($val_pri_acum + $p_mes);
            }

            // --- C. CÁLCULOS DE LEY COLOMBIANA ---
            $aux_t = ($sueldo_base <= ($smmlv_actual * 2)) ? $aux_trans_valor : 0;
            $salud_e = $sueldo_base * $porc_salud_e;
            $pension_e = $sueldo_base * $porc_pension_e;
            
            // Exoneración Ley 1607 (< 10 SMMLV)
            $exonero = ($sueldo_base < ($smmlv_actual * 10));
            $salud_p = $exonero ? 0 : ($sueldo_base * 0.085);
            $sena = $exonero ? 0 : ($sueldo_base * 0.02);
            $icbf = $exonero ? 0 : ($sueldo_base * 0.03);

            $tab_arl = array(1=>0.00522, 2=>0.01044, 3=>0.02436, 4=>0.04350, 5=>0.06960);
            $arl_p = $sueldo_base * ($tab_arl[(int)$emp->riesgo] ?: 0.00522);

            // NETO FINAL
            //$neto = ($sueldo_base + $aux_t + $val_bonos) - ($salud_e + $pension_e + $val_anticipos);
            
            
            //--------------------------------------SECCION CERCA CONTRA ANTICIPOS
            
                    // --- 3. CÁLCULO DEL NETO CON CERCA DE SEGURIDAD ---

            // Lo que el empleado tiene a favor
            $total_devengado = $sueldo_base + $aux_t + $val_bonos;
            // Lo que se le debe quitar obligatoriamente por ley
            $total_deducciones_ley = $salud_e + $pension_e;
            
            // El "Cupo Máximo" para cobrar anticipos sin dejar el neto en negativo
            $disponible_para_anticipos = $total_devengado - $total_deducciones_ley;
            
            $val_anticipos_cobrados = 0;
            $val_anticipos_pendientes_proximo_mes = 0;
            
            if ($val_anticipos > $disponible_para_anticipos) {
                // CASO DE SOBREGIRO: El empleado debe más de lo que gana
                $val_anticipos_cobrados = $disponible_para_anticipos;
                $val_anticipos_pendientes_proximo_mes = $val_anticipos - $disponible_para_anticipos;
                $neto = 0; // El cheque sale en $0
            } else {
                // CASO NORMAL: Los anticipos caben en el sueldo
                $val_anticipos_cobrados = $val_anticipos;
                $neto = $disponible_para_anticipos - $val_anticipos_cobrados;
            }
            
            // --- 4. INSERTAR EN DETALLES ---
            $sql_ins = "INSERT INTO llxu3_ofinova_nom_detalles (
                fk_periodo, fk_soc, sueldo_base, val_sueldo_pagar, val_aux_trans, val_salud, val_pension, val_anticipos, total_neto,
                val_cesantias, val_intereses_ces, val_prima, val_vacaciones, val_arl_patronal, val_pension_patronal, val_salud_patronal, val_caja_comp, val_sena, val_icbf
            ) VALUES (
                $id_periodo, $socid, $sueldo_base, $sueldo_base, $aux_t, $salud_e, $pension_e, $val_anticipos, $neto,
                ".(($sueldo_base+$aux_t)*0.0833).", ".(($sueldo_base+$aux_t)*0.01).", ".(($sueldo_base+$aux_t)*0.0833).", ".($sueldo_base*0.0417).", $arl_p, ".($sueldo_base*0.12).", $salud_p, ".($sueldo_base*0.04).", $sena, $icbf
            )";
            
            if ($db->query($sql_ins)) {
                // --- 5. MANEJO DE NOVEDADES (LA CLAVE DEL TRASPASO) ---
                if ($val_anticipos_pendientes_proximo_mes > 0) {
                    // Marcamos las actuales como liquidadas (status 1)
                    $db->query("UPDATE llxu3_ofinova_nom_novedades SET status=1, fk_periodo=$id_periodo WHERE fk_soc=$socid AND status=0 AND tipo='Anticipo'");
                    
                    // E INSERTAMOS UNA NUEVA NOVEDAD con el saldo restante para el próximo mes
                    $desc_saldo = "Saldo pendiente de anticipos no cubiertos en periodo ".$id_periodo;
                    $db->query("INSERT INTO llxu3_ofinova_nom_novedades (fk_soc, fecha, tipo, monto, descripcion, status) 
                                VALUES ($socid, '".$db->idate(time())."', 'Anticipo', $val_anticipos_pendientes_proximo_mes, '".$db->escape($desc_saldo)."', 0)");
                } else {
                    // Si no hay saldo pendiente, cerramos todo normal
                    $db->query("UPDATE llxu3_ofinova_nom_novedades SET status=1, fk_periodo=$id_periodo WHERE fk_soc=$socid AND status=0");
                }
            }



        }
        if ($error_flag == 0) {
            // Si no hubo errores en ninguna consulta, guardamos de verdad en la DB
            $db->commit();
            
            // Refinamos el mensaje de éxito indicando el periodo procesado
            setEventMessages("¡Nómina reliquidada con éxito! Se absorbieron los nuevos anticipos para el periodo ID: ".$id_periodo, null, 'mesgs');
            header("Location: ".$_SERVER["PHP_SELF"]."?fk_periodo=".$fk_periodo);
        exit;
        } else {
            // Si algo falló, deshacemos todo para no dejar la nómina a medias o corrupta
            $db->rollback();
            setEventMessages("No se pudo recalcular la nómina. ".$error_msg, null, 'errors');
            exit;
        }
        
    } else { $db->rollback(); setEventMessages("No se pudo recalcular la nómina. ".$error_msg, null, 'errors');}
        
    }
}


llxHeader('', 'Detalle Nómina - ' . $periodo->label);

print load_fiche_titre("Nómina Integral: " . $periodo->label, '', 'title_hr.png');

// --- TABLA DE LIQUIDACIÓN ---
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>Empleado</td>';
print '<td align="right">Sueldo/Días</td>';
print '<td align="right" title="Auxilio Transporte">Aux. Transp</td>';
print '<td align="center" style="color:#d9534f;">Deducciones (S+P)</td>';
print '<td align="right" style="color:#f0ad4e;">Anticipos</td>';
print '<td align="right" style="font-weight:bold; background:#eef;">NETO PAGAR</td>';
print '<td align="center" style="background:#f4f4f4;">Seg. Social Patronal</td>';
print '<td align="center" style="background:#f4f4f4;">Provisiones (Ces+Pri+Vac)</td>';
print '<td align="center">Acción</td>';
print '</tr>';

$sql_d = "SELECT d.*, s.nom as empleado_nom 
          FROM llxu3_ofinova_nom_detalles as d
          INNER JOIN ".MAIN_DB_PREFIX."societe as s ON d.fk_soc = s.rowid
          WHERE d.fk_periodo = " . (int)$id_periodo;
$res_d = $db->query($sql_d);

$tot_neto = 0; $tot_patronal = 0; $tot_provision = 0;

while ($linea = $db->fetch_object($res_d)) {
    // Cálculo de totales patronales para mostrar en una sola celda resumen
    $seg_social_pat = $linea->val_arl_patronal + $linea->val_pension_patronal + $linea->val_salud_patronal + $linea->val_caja_comp + $linea->val_sena + $linea->val_icbf;
    $provisiones_pat = $linea->val_cesantias + $linea->val_intereses_ces + $linea->val_prima + $linea->val_vacaciones;

    print '<tr class="oddeven">';
    print '<td><strong>'.$linea->empleado_nom.'</strong></td>';
    print '<td align="right">'.price($linea->val_sueldo_pagar).'<br><small>('.$linea->dias_trabajados.' d)</small></td>';
    print '<td align="right" style="color:#5cb85c;">'.price($linea->val_aux_trans).'</td>';
    
    $deducciones = $linea->val_salud + $linea->val_pension;
    print '<td align="center" style="color:#d9534f;">-'.price($deducciones).'</td>';
    print '<td align="right" style="color:#f0ad4e;">-'.price($linea->val_anticipos).'</td>';
    
    print '<td align="right" style="font-weight:bold; background:#f0f7ff;">'.price($linea->total_neto).'</td>';
    
    // Carga Patronal (Resumen)
    print '<td align="center" style="background:#fcfcfc;"><span title="ARL: '.price($linea->val_arl_patronal).', Pensión: '.price($linea->val_pension_patronal).'">'.price($seg_social_pat).'</span></td>';
    
    // Provisiones (Resumen)
    print '<td align="center" style="background:#fcfcfc;"><span title="Prima: '.price($linea->val_prima).', Cesantías: '.price($linea->val_cesantias).'">'.price($provisiones_pat).'</span></td>';
    
    print '<td align="center">';
    if ($periodo->status == 0) {
        print '<a href="eliminar_linea.php?id='.$linea->rowid.'&periodo='.$id_periodo.'" onclick="return confirm(\'¿Quitar empleado?\');">'.img_picto('Eliminar', 'delete.png').'</a>';
    }
    print '</td>';
    print '</tr>';

    $tot_neto += $linea->total_neto;
    $tot_patronal += $seg_social_pat;
    $tot_provision += $provisiones_pat;
}

// FILA DE TOTALES MAESTROS
print '<tr class="liste_total">';
print '<td colspan="5" align="right">COSTO TOTAL DE NÓMINA (Neto + Carga + Provisiones):</td>';
print '<td align="right">'.price($tot_neto).'</td>';
print '<td align="center">'.price($tot_patronal).'</td>';
print '<td align="center">'.price($tot_provision).'</td>';
print '<td></td>';
print '</tr>';
print '</table>';
print '</div>';

// --- ACCIONES CONTABLES ---
// --- ACCIONES DE CONTROL DE CICLO DE VIDA ---
print '<div class="tabsAction">';

// Caso 1: Está en Borrador (Status 0)
if ($periodo->status == 0) {
    print '<a class="butAction" href="inyectar_contabilidad.php?id='.$id_periodo.'" style="background:#28a745; color:white !important;">🚀 Validar y Contabilizar Todo</a>';

    // Botón dinámico con el token de seguridad CSRF de Dolibarr
    print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=recalcular_nomina&fk_periodo='.$id_periodo.'&token='.newToken().'">🔄 Recalcular y Absorber Anticipos</a>';


} 

// Caso 2: Está Contabilizado (Status 2) -> AQUÍ APARECERÁ EL BOTÓN DE REVERSA
elseif ($periodo->status == 2) {
    print '<a class="butActionDelete" href="reversar_contabilidad.php?id='.$id_periodo.'" 
              onclick="return confirm(\'⚠️ ¡ATENCIÓN! Esto eliminará el asiento del Libro Mayor y devolverá la nómina a Borrador para que puedas corregirla. ¿Deseas continuar?\');" 
              style="padding: 10px 20px; background:#d9534f; color:white !important; border-radius:4px; text-decoration:none;">🔄 Reversar Contabilidad</a>';
}

print '</div>';




/*if ($periodo->status == 0) {
    print '<div class="tabsAction">';
    print '<a class="butAction" href="inyectar_contabilidad.php?id='.$id_periodo.'" style="background:#28a745; color:white !important;">🚀 Validar y Contabilizar Todo</a>';
    print '</div>';
} else {
    print '<div class="info" style="margin-top:20px;">✅ Nómina Contabilizada. Los asientos han sido inyectados al Libro Mayor con auxiliares de Tercero (NIT).</div>';
}*/

llxFooter();



/*$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Error: No se pudo encontrar main.inc.php");

$id_periodo = GETPOST('id', 'int');
if (empty($id_periodo)) {
    header("Location: listado_periodos.php");
    exit;
}

// 1. CARGAR CABECERA DEL PERIODO
$sql_p = "SELECT * FROM llxu3_ofinova_nom_periodo WHERE rowid = " . $id_periodo;
$res_p = $db->query($sql_p);
$periodo = $db->fetch_object($res_p);

llxHeader('', 'Detalle de Nómina - ' . $periodo->label);

print load_fiche_titre("Detalle de Liquidación: " . $periodo->label, '', 'title_hr.png');

// 2. MOSTRAR TABLA DE EMPLEADOS LIQUIDADOS
print '<table class="noborder centpercent">';




print '<tr class="liste_titre">';


print '<td align="center">Acción</td>';
print '<td>Empleado (Tercero)</td>';
print '<td align="right">Sueldo Base</td>';
print '<td align="right">Días</td>';
print '<td align="right">Aux. Transp.</td>';
print '<td align="center" style="color:#d9534f;">Salud (4%)</td>';
print '<td align="center" style="color:#d9534f;">Pensión (4%)</td>';
print '<td align="right" style="color:#f0ad4e;">Anticipos</td>';
print '<td align="right" style="font-weight:bold;">Neto a Pagar</td>';
print '<td>Estado</td>';
print '</tr>';

$sql_d = "SELECT d.*, s.nom as empleado_nom 
          FROM llxu3_ofinova_nom_detalles as d
          INNER JOIN ".MAIN_DB_PREFIX."societe as s ON d.fk_soc = s.rowid
          WHERE d.fk_periodo = " . $id_periodo;
$res_d = $db->query($sql_d);

$total_periodo = 0;
while ($linea = $db->fetch_object($res_d)) {
    print '<tr class="oddeven">';
    print '<td align="center">';
    if ($periodo->status == 0) { // Solo si es borrador
        print '<a href="eliminar_linea.php?id='.$linea->rowid.'&periodo='.$id_periodo.'" 
                  onclick="return confirm(\'¿Seguro desea quitar a este empleado de la nómina?\');">';
        print img_picto('Eliminar', 'delete.png');
        print '</a>';
    }
    print '</td>';
    print '<td><a href="'.DOL_URL_ROOT.'/societe/card.php?id='.$linea->fk_soc.'">'.img_picto('', 'user').' '.$linea->empleado_nom.'</a></td>';
    print '<td align="right">'.price($linea->sueldo_base).'</td>';
    print '<td align="right">'.$linea->dias_trabajados.'</td>';
    print '<td align="right" style="color:#5cb85c;">'.price($linea->val_aux_trans).'</td>';
    print '<td align="center" style="color:#d9534f;">-'.price($linea->val_salud).'</td>';
    print '<td align="center" style="color:#d9534f;">-'.price($linea->val_pension).'</td>';
    print '<td align="right" style="color:#f0ad4e;">-'.price($linea->val_anticipos).'</td>';
    print '<td align="right" style="font-weight:bold; background: #f9f9f9;">'.price($linea->total_neto).'</td>';
    print '<td>'.($periodo->status == 0 ? '<span class="badge badge-warning">Borrador</span>' : '<span class="badge badge-success">Contabilizado</span>').'</td>';
    print '</tr>';
    $total_periodo += $linea->total_neto;
}

// FILA DE TOTALES

print '<tr class="liste_total">';
print '<td colspan="7" align="right">TOTAL DISPONIBILIDAD DE PAGOS:</td>';
print '<td align="right" style="font-size: 1.2em;">'.price($total_periodo).'</td>';
print '<td></td>';
print '</tr>';
print '</table>';

// 3. ACCIONES FINALES (INYECTAR AL BOOKKEEPING)
if ($periodo->status == 0) {
    print '<div class="tabsAction">';
    print '<a class="butAction" href="inyectar_contabilidad.php?id='.$id_periodo.'" style="background: #28a745; color: white !important;">🚀 Validar y Contabilizar Nómina</a>';
    print '<a class="butActionDelete" href="eliminar_periodo.php?id='.$id_periodo.'">Eliminar Borrador</a>';
    print '</div>';
} else {
    print '<div class="info" style="margin-top:20px;">✅ Este periodo ya ha sido inyectado al <b>Bookkeeping</b>. Los saldos de las cuentas auxiliares de los empleados han sido actualizados.</div>';
}

llxFooter();*/
