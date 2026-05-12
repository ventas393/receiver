<?php
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Error: No se pudo encontrar main.inc.php");

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';

$id = GETPOST('id', 'int');
$action = GETPOST('action', 'alpha');
$monto = GETPOST('monto_nov', 'int'); // Usamos int para probar
$object = new Societe($db);

if ($id > 0) {
    $object->fetch($id);

    // --- 1. PROCESAR GUARDADO ---
    if ($action == 'update') {
        $db->begin();
        $sueldo_raw = GETPOST('txt_sueldo_base', 'none');
        $sueldo_clean = str_replace(array('.', ','), array('', '.'), $sueldo_raw);
        
        $sql_up = "UPDATE ".MAIN_DB_PREFIX."societe_extrafields SET ";
        $sql_up .= " nominaofinova_is_emp = ".(int)GETPOST('is_emp', 'int').", ";
        $sql_up .= " nominaofinova_sueldo = ".(double)$sueldo_clean.", ";
        $sql_up .= " nominaofinova_eps = ".(GETPOST('eps', 'int') > 0 ? GETPOST('eps', 'int') : "NULL").", ";
        $sql_up .= " nominaofinova_afp = ".(GETPOST('afp', 'int') > 0 ? GETPOST('afp', 'int') : "NULL").", ";
        $sql_up .= " nominaofinova_arl = ".(GETPOST('arl', 'int') > 0 ? GETPOST('arl', 'int') : "NULL").", ";
        $sql_up .= " nominaofinova_riesgo = ".(int)GETPOST('riesgo', 'int').", ";
        $sql_up .= " nominaofinova_cta_gas = '".$db->escape(GETPOST('cta_gas', 'alpha'))."', ";
        $sql_up .= " nominaofinova_cta_pas = '".$db->escape(GETPOST('cta_pas', 'alpha'))."', ";
        $sql_up .= " nominaofinova_cta_ant = '".$db->escape(GETPOST('cta_ant', 'alpha'))."' ";
        $sql_up .= " WHERE fk_object = ".(int)$id;

        if ($db->query($sql_up)) {
            $db->commit();
            setEventMessages("Datos actualizados correctamente.", null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error SQL: ".$db->lasterror(), null, 'errors');
        }
    }
    
    //INYECTOR 2.0
    // --- L�0�7GICA DE PROCESAMIENTO (DENTRO DEL IF DE ACTION) ---
// --- 1. DETECCI�0�7N DE EMERGENCIA (AL PRINCIPIO DEL ARCHIVO) ---
// --- 1. CAPTURA DE VARIABLES ---

// --- 2. PROCESAMIENTO CON LOG DE ERRORES ---

/*if ($action == 'add_novedad_manual' && $id > 0) {
    $id = GETPOST('id', 'int');
    $action = GETPOST('action', 'alpha');
    $monto = (double)GETPOST('monto_nov', 'double');
    $tipo = GETPOST('tipo_nov', 'alpha');
    $desc = GETPOST('desc_nov', 'alpha');

    if ($monto > 0) {
        $db->begin();

        // PASO A: Insertar Novedad
        $sql_nov = "INSERT INTO llxu3_ofinova_nom_novedades (fk_soc, fecha, tipo, monto, descripcion, status) ";
        $sql_nov .= "VALUES ($id, '".$db->idate(time())."', '".$db->escape($tipo)."', $monto, '".$db->escape($desc)."', 0)";
        
        $res_nov = $db->query($sql_nov);
        if (!$res_nov) {
            $err = $db->lasterror();
            $db->rollback();
            die("�7�4 ERROR EN TABLA NOVEDADES: <br>".$err."<br><br>SQL: ".$sql_nov);
        }

        // PASO B: Inyecci��n Contable (Solo si es Anticipo)
        if ($tipo == 'Anticipo') {
            // Buscamos datos con el prefijo options_ que es el est��ndar de Dolibarr
            $sql_s = "SELECT s.nom, s.siren, ex.options_nominaofinova_cta_ant as cta 
                      FROM ".MAIN_DB_PREFIX."societe as s 
                      LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields as ex ON s.rowid = ex.fk_object 
                      WHERE s.rowid = ".$id;
            $res_s = $db->query($sql_s);
            $obj_s = $db->fetch_object($res_s);

            $nit_c = str_replace(array(' ', '-', '.'), '', $obj_s->siren);
            $cta_p = (!empty($obj_s->cta)) ? $obj_s->cta : '136505';
            $cta_aux = $cta_p . $nit_c;

            $res_max = $db->query("SELECT MAX(CAST(piece_num AS UNSIGNED)) as max_p FROM ".MAIN_DB_PREFIX."accounting_bookkeeping");
            $nxt = ($db->fetch_object($res_max)->max_p) + 1;

            // L��nea D��bito (136505 + NIT)
            $sql_d = "INSERT INTO ".MAIN_DB_PREFIX."accounting_bookkeeping (entity, piece_num, doc_date, doc_type, doc_ref, numero_compte, subledger_account, subledger_label, thirdparty_code, debit, credit, montant, sens, fk_user_author, date_creation, code_journal, journal_label) 
                      VALUES (".$conf->entity.", $nxt, '".$db->idate(time())."', 'anticipo', 'MAN-".$id."', '$cta_p', '$cta_aux', '".$db->escape($obj_s->nom)."', '$nit_c', $monto, 0, $monto, 'D', ".$user->id.", '".$db->idate(time())."', 'NOM', 'Anticipo Manual')";
            
            $res_d = $db->query($sql_d);
            if (!$res_d) {
                $err = $db->lasterror();
                $db->rollback();
                die("�7�4 ERROR EN BOOKKEEPING (D�0�7BITO): <br>".$err."<br><br>SQL: ".$sql_d);
            }

            // L��nea Cr��dito (Caja)
            $sql_c = "INSERT INTO ".MAIN_DB_PREFIX."accounting_bookkeeping (entity, piece_num, doc_date, doc_type, doc_ref, numero_compte, subledger_account, subledger_label, debit, credit, montant, sens, fk_user_author, date_creation, code_journal, journal_label) 
                      VALUES (".$conf->entity.", $nxt, '".$db->idate(time())."', 'anticipo', 'MAN-".$id."', '111005', '111005', 'Caja General', 0, $monto, $monto, 'C', ".$user->id.", '".$db->idate(time())."', 'NOM', 'Salida Caja')";

            $res_c = $db->query($sql_c);
            if (!$res_c) {
                $err = $db->lasterror();
                $db->rollback();
                die("�7�4 ERROR EN BOOKKEEPING (CR�0�7DITO): <br>".$err."<br><br>SQL: ".$sql_c);
            }
        }

        $db->commit();
        // Si todo sale bien, forzamos un mensaje y paramos para confirmar
        die("�7�3 �0�3TODO BIEN! Se insertaron todos los registros. Ahora puedes quitar este log y dejar la redirecci��n.");
    }
}*/


if ($action == 'add_novedad_manual') {
    $monto = GETPOST('monto_nov', 'int');
    $tipo  = GETPOST('tipo_nov', 'alpha');
    $desc  = GETPOST('desc_nov', 'alpha');

    if ($monto > 0 && $id > 0) {
        $db->begin();
        
        // 1. INSERCI�0�7N EN NOVEDADES (Para todos los tipos)
        $sql_nov = "INSERT INTO llxu3_ofinova_nom_novedades (fk_soc, fecha, tipo, monto, descripcion, status) ";
        $sql_nov .= "VALUES ($id, '".$db->idate(time())."', '".$db->escape($tipo)."', $monto, '".$db->escape($desc)."', 0)";
        
        $res_nov = $db->query($sql_nov);
        
        if ($res_nov) {
            // --- CASO A: ES ANTICIPO (Requiere Contabilidad) ---
            if ($tipo == 'Anticipo') {
                $res_max = $db->query("SELECT MAX(CAST(piece_num AS UNSIGNED)) as max_p FROM ".MAIN_DB_PREFIX."accounting_bookkeeping");
                $next_piece = ($db->fetch_object($res_max)->max_p) + 1;

                $sql_s = "SELECT siren, nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".(int)$id;
                $obj_s = $db->fetch_object($db->query($sql_s));
                $nit_c = str_replace(array(' ', '-', '.'), '', $obj_s->siren);

                $sql_ex = "SELECT options_nominaofinova_cta_ant FROM ".MAIN_DB_PREFIX."societe_extrafields WHERE fk_object = ".(int)$id;
                $obj_ex = $db->fetch_object($db->query($sql_ex));

                $cta_padre = (!empty($obj_ex->options_nominaofinova_cta_ant)) ? $obj_ex->options_nominaofinova_cta_ant : '136505';
                $cta_aux = $cta_padre . $nit_c;
                $label_op = "Anticipo Manual";

                $sql_d = "INSERT INTO ".MAIN_DB_PREFIX."accounting_bookkeeping (entity, piece_num, doc_date, doc_type, doc_ref, numero_compte, subledger_account, subledger_label, thirdparty_code, debit, credit, montant, sens, fk_user_author, date_creation, code_journal, journal_label) 
                          VALUES (".$conf->entity.", $next_piece, '".$db->idate(time())."', 'anticipo', 'MAN-".$id."', '$cta_padre', '$cta_aux', '".$db->escape($obj_s->nom)."', '$nit_c', $monto, 0, $monto, 'D', ".$user->id.", '".$db->idate(time())."', 'NOM', '$label_op')";

                $sql_c = "INSERT INTO ".MAIN_DB_PREFIX."accounting_bookkeeping (entity, piece_num, doc_date, doc_type, doc_ref, numero_compte, subledger_account, subledger_label, debit, credit, montant, sens, fk_user_author, date_creation, code_journal, journal_label) 
                          VALUES (".$conf->entity.", $next_piece, '".$db->idate(time())."', 'anticipo', 'MAN-".$id."', '110505', '110505', 'Caja General', 0, $monto, $monto, 'C', ".$user->id.", '".$db->idate(time())."', 'NOM', 'Salida Caja por $tipo')";

                if ($db->query($sql_d) && $db->query($sql_c)) {
                    $db->commit();
                    setEventMessages("�0�3�0�7xito! Anticipo registrado y Asiento #$next_piece creado.", null, 'mesgs');
                } else {
                    $db->rollback();
                    setEventMessages("Error Contable: ".$db->lasterror(), null, 'errors');
                }
            } 
            // --- CASO B: ES BONO O COMISI�0�7N (Solo N��mina) ---
            else {
                $db->commit();
                setEventMessages("�0�3�0�7xito! Novedad de $tipo registrada para la pr��xima n��mina.", null, 'mesgs');
            }
            
            // REDIRECCI�0�7N FINAL PARA LIMPIAR EL FORMULARIO
            header("Location: ".$_SERVER["PHP_SELF"]."?id=".$id);
            exit;

        } else {
            // FALLO REAL EN LA TABLA DE NOVEDADES
            $error_msg = $db->error(); 
            $db->rollback();
            die("<h2>�7�4 FALLO REAL EN NOVEDADES</h2>" . $error_msg . "<br>SQL: " . $sql_nov);
        }
    }
}

    // --- 2. CONSULTA DE DATOS ACTUALES (PARA LA VISTA DE LECTURA) ---
    $sql_v = "SELECT x.*, s1.nom as eps_nom, s2.nom as afp_nom, s3.nom as arl_nom 
              FROM ".MAIN_DB_PREFIX."societe_extrafields as x
              LEFT JOIN ".MAIN_DB_PREFIX."societe as s1 ON x.nominaofinova_eps = s1.rowid
              LEFT JOIN ".MAIN_DB_PREFIX."societe as s2 ON x.nominaofinova_afp = s2.rowid
              LEFT JOIN ".MAIN_DB_PREFIX."societe as s3 ON x.nominaofinova_arl = s3.rowid
              WHERE x.fk_object = ".(int)$id;
    $res_v = $db->query($sql_v);
    $data = $db->fetch_object($res_v);

    llxHeader('', 'N��mina');
    $head = societe_prepare_head($object);
    dol_fiche_head($head, 'Nomina', $object->nom, -1, 'company');

    // --- 3. TABLA DE INFORMACI�0�7N CARGADA (SOLO LECTURA) ---
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border centpercent">';
    print '<tr class="liste_titre"><td colspan="4">Informaci��n Actual en Base de Datos</td></tr>';
    print '<tr><td class="titlefield">Estado:</td><td>'.($data->nominaofinova_is_emp ? img_picto('Activo','statut4').' Empleado' : 'No configurado').'</td>';
    print '<td class="titlefield">Sueldo Base:</td><td class="nowrap"><strong>'.price($data->nominaofinova_sueldo).'</strong></td></tr>';
    print '<tr><td class="titlefield">EPS:</td><td>'.($data->eps_nom ?: '---').'</td>';
    print '<td class="titlefield">Pensi��n:</td><td>'.($data->afp_nom ?: '---').'</td></tr>';
    print '<tr><td class="titlefield">ARL:</td><td>'.($data->arl_nom ?: '---').' (Riesgo '.$data->nominaofinova_riesgo.')</td>';
    print '<td class="titlefield">Cta. Anticipo:</td><td>'.$data->nominaofinova_cta_ant.'</td></tr>';
    print '<tr><td class="titlefield">Cta. Gasto:</td><td>'.$data->nominaofinova_cta_gas.'</td>';
    print '<td class="titlefield">Cta. Pasivo:</td><td>'.$data->nominaofinova_cta_pas.'</td></tr>';
    print '</table><br>';

    // --- 4. FORMULARIO PARA ACTUALIZAR ---
    print '<form action="'.$_SERVER["PHP_SELF"].'?id='.$id.'" method="POST">';
    print '<input type="hidden" name="action" value="update"><input type="hidden" name="token" value="'.newToken().'">';
    
    print '<table class="border centpercent">';
    print '<tr class="liste_titre"><td colspan="2">Actualizar Par��metros</td></tr>';
    
    print '<tr><td class="titlefield">Es Empleado</td><td><input type="checkbox" name="is_emp" value="1" '.($data->nominaofinova_is_emp ? 'checked' : '').'></td></tr>';
    print '<tr><td>Sueldo Base</td><td><input type="text" name="txt_sueldo_base" value="'.price($data->nominaofinova_sueldo).'"></td></tr>';

    // Selectores (Definidos igual que antes)
    function selectTercero($db, $name, $current) {
        $out = '<select name="'.$name.'" class="flat"><option value="0">-- Seleccione --</option>';
        $res = $db->query("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE status=1 ORDER BY nom ASC");
        while($s = $db->fetch_object($res)) { $sel = ($s->rowid == $current) ? 'selected' : ''; $out .= '<option value="'.$s->rowid.'" '.$sel.'>'.$s->nom.'</option>'; }
        return $out.'</select>';
    }

    print '<tr><td>Entidad EPS</td><td>'.selectTercero($db, 'eps', $data->nominaofinova_eps).'</td></tr>';
    print '<tr><td>Fondo Pensi��n</td><td>'.selectTercero($db, 'afp', $data->nominaofinova_afp).'</td></tr>';
    print '<tr><td>Entidad ARL</td><td>'.selectTercero($db, 'arl', $data->nominaofinova_arl).'</td></tr>';

    print '<tr><td>Nivel Riesgo ARL</td><td><select name="riesgo" class="flat">';
    foreach(array(1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V') as $k=>$v) { print '<option value="'.$k.'" '.($data->nominaofinova_riesgo==$k?'selected':'').'>'.$v.'</option>'; }
    print '</select></td></tr>';

    function selectAccountPUC($db, $name, $current) {
        $sql = "SELECT account_number, label FROM ".MAIN_DB_PREFIX."accounting_account WHERE active=1 AND fk_pcg_version='PUC-CO' ORDER BY account_number ASC";
        $res = $db->query($sql);
        $out = '<select name="'.$name.'" class="flat select2" style="width:90%"><option value="0">-- Seleccione Cuenta --</option>';
        while($acc=$db->fetch_object($res)) { $sel = ($acc->account_number==$current) ? 'selected' : ''; $out .= '<option value="'.$acc->account_number.'" '.$sel.'>'.$acc->account_number.' - '.$acc->label.'</option>'; }
        return $out.'</select>';
    }

    print '<tr><td>Cuenta Gasto (5105)</td><td>'.selectAccountPUC($db, 'cta_gas', $data->nominaofinova_cta_gas).'</td></tr>';
    print '<tr><td>Cuenta Pasivo (2505)</td><td>'.selectAccountPUC($db, 'cta_pas', $data->nominaofinova_cta_pas).'</td></tr>';
    print '<tr><td>Cuenta Anticipo (1365)</td><td>'.selectAccountPUC($db, 'cta_ant', $data->nominaofinova_cta_ant).'</td></tr>';

    print '</table>';
    print '<div class="center"><br><input type="submit" class="button" value="ACTUALIZAR DATOS"></div>';
    print '</form>';
    
    // --- L�0�7GICA DE PROCESAMIENTO DEL ANTICIPO MANUAL ---


print '<br><table class="border" width="100%">';
print '<tr class="liste_titre"><td colspan="2">Registrar Novedad Manual</td></tr>';

// Usamos la URL completa para evitar que se pierda el contexto
$post_url = $_SERVER["PHP_SELF"] . '?id=' . $id;

print '<form action="' . $form_url . '" method="POST">';
// MUY IMPORTANTE: Token de seguridad de Dolibarr
print '<input type="hidden" name="token" value="'. (function_exists('newToken')?newToken():$_SESSION['newtoken']) .'">';
print '<input type="hidden" name="action" value="add_novedad_manual">';

print '<tr><td class="titlefield">Tipo de Novedad</td><td>';
print '<select name="tipo_nov" class="flat">
        <option value="Anticipo">Anticipo (Deducible)</option>
        <option value="Bono">Bonificacion (No Salarial)</option>
        <option value="Comision">Comision (Salarial)</option>
       </select></td></tr>';

print '<tr><td>Monto ($)</td><td><input type="number" name="monto_nov" id="monto_nov" value="" required></td></tr>';
print '<tr><td>Descripci��n</td><td><input type="text" name="desc_nov" value="" style="width:80%"></td></tr>';

print '<tr><td colspan="2" align="center">';
print '<input type="submit" class="button" value="GUARDAR REGISTRO">';
print '</td></tr>';
print '</form></table>';







    dol_fiche_end();
    llxFooter();
}