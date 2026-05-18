<?php
// --- 1. ENTORNO ---
$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res) die("Error: No se pudo encontrar main.inc.php");

$action          = GETPOST('action', 'alpha');
$fk_bank_account = GETPOST('fk_bank_account', 'int');
$filter_month    = GETPOST('filter_month', 'int') ? GETPOST('filter_month', 'int') : date('m');
$filter_year     = GETPOST('filter_year', 'int') ? GETPOST('filter_year', 'int') : date('Y');

// --- 2. PROCESAR CARGA ---
if ($action == 'import' && isset($_FILES['file'])) {
    $handle = fopen($_FILES['file']['tmp_name'], "r");
    $db->begin();
    fgetcsv($handle, 5000, ";"); // Saltar cabecera
    while (($data = fgetcsv($handle, 5000, ";")) !== FALSE) {
        $date_raw = trim($data[0]);
        $amount   = (float)str_replace(['.', ','], ['', '.'], $data[2]);
        if (empty($date_raw) || $amount == 0) continue;
        
        $date_f = date("Y-m-d", strtotime(str_replace('/', '-', $date_raw)));
        $label  = $db->escape(trim($data[1]));

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."ofinova_extracto (date_mvmt, label, amount, fk_bank_account, fk_user_creat, status, date_creation) ";
        $sql.= "VALUES ('$date_f', '$label', $amount, $fk_bank_account, $user->id, 0, '".$db->idate(time())."')";
        $db->query($sql);
    }
    $db->commit();
    setEventMessages("Carga exitosa", null, 'mesgs');
}

// --- ACCIÓN: ELIMINAR EXTRACTO POR BANCO Y FECHA ---
if ($action == 'delete_extracto' && $user->admin) { // Solo administradores
    $db->begin();
    
    $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."ofinova_extracto 
                WHERE fk_bank_account = ".(int)GETPOST('bank', 'int')."
                AND MONTH(date_mvmt) = ".(int)GETPOST('month', 'int')." 
                AND YEAR(date_mvmt) = ".(int)GETPOST('year', 'int');
    
    if ($db->query($sql_del)) {
        $db->commit();
        setEventMessages("Extracto eliminado correctamente para el periodo seleccionado.", null, 'mesgs');
    } else {
        $db->rollback();
        setEventMessages("Error al eliminar: ".$db->lasterror(), null, 'errors');
    }
    // Redireccionamos para limpiar la URL
    header("Location: ".$_SERVER["PHP_SELF"]."?bank_account=".$fk_bank_account);
    exit;
}
// --- ACCIÓN: SOLO CONCILIAR (MARCAR COMO PROCESADO PARA EVITAR DOBLE REGISTRO) ---
if ($action == 'solo_marcar') {
    $id_extracto = (int)GETPOST('id_extracto', 'int');
    $id_banco    = (int)GETPOST('fk_bank_account', 'int'); // Para la redirección del filtro

    if ($id_extracto > 0) {
        $db->begin();

        // ÚNICO PASO: Actualizar el estado a 1 (Conciliado) en tu tabla de extractos
        $sql_u = "UPDATE ".MAIN_DB_PREFIX."ofinova_extracto SET status = 1 WHERE rowid = ".$id_extracto;
        $res_u = $db->query($sql_u);

        if ($res_u) {
            $db->commit();
            setEventMessages("Movimiento marcado como conciliado correctamente.", null, 'mesgs');
        } else {
            $error_msg = $db->lasterror();
            $db->rollback();
            setEventMessages("Error al actualizar el estado del extracto: ".$error_msg, null, 'errors');
        }
        
        $return_bank  = (int)GETPOST('fk_bank_account', 'int');
        $return_month = (int)GETPOST('month', 'int');
        $return_year  = (int)GETPOST('year', 'int');

        // Armamos la URL con los mismos nombres de parámetros que usa tu archivo principal
        $url = "conciliador_ofinova.php?fk_bank_account=".$return_bank."&filter_month=".$return_month."&filter_year=".$return_year;
        
        header("Location: ".$url);
        exit;
    }
}


llxHeader('', 'Conciliación de Extractos');

// --- 3. INTERFAZ DE CARGA ---
print load_fiche_titre("Importación y Conciliación", '', 'title_bank.png');

print '<form action="'.$_SERVER["PHP_SELF"].'" method="post" enctype="multipart/form-data" style="background:#fff; padding:20px; border:1px solid #ddd; border-radius:8px;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="import">';
print 'Banco: <select name="fk_bank_account" required>';
$res_b = $db->query("SELECT rowid, label FROM ".MAIN_DB_PREFIX."bank_account WHERE entity=".$conf->entity." AND clos=0");
while($obj_b = $db->fetch_object($res_b)) {
    print '<option value="'.$obj_b->rowid.'" '.($fk_bank_account==$obj_b->rowid?'selected':'').'>'.$obj_b->label.'</option>';
}
print '</select> ';
print 'Archivo: <input type="file" name="file" accept=".csv" required> ';
print '<input type="submit" class="butAction" value="SUBIR EXTRACTO">';
print '</form><br>';

// --- 4. DASHBOARD (LA BARRA QUE FALLABA) ---
print '<div style="background:#f8f9fa; padding:15px; border:1px solid #ddd; border-radius:8px; display:flex; align-items:center; gap:15px;">';
print '<form method="get" action="'.$_SERVER["PHP_SELF"].'" style="display:flex; align-items:center; gap:10px; width:100%;">';
    print '<strong>Filtrar Banco:</strong> <select name="fk_bank_account">';
    $res_b = $db->query("SELECT rowid, label FROM ".MAIN_DB_PREFIX."bank_account WHERE entity=".$conf->entity." AND clos=0");
    while($obj_b = $db->fetch_object($res_b)) {
        print '<option value="'.$obj_b->rowid.'" '.($fk_bank_account==$obj_b->rowid?'selected':'').'>'.$obj_b->label.'</option>';
    }
    print '</select>';
    
    print ' <strong>Mes:</strong> <input type="number" name="filter_month" value="'.$filter_month.'" min="1" max="12" style="width:50px;">';
    print ' <strong>Año:</strong> <input type="number" name="filter_year" value="'.$filter_year.'" style="width:70px;">';
    print '<input type="submit" class="button" value="Actualizar Vista">';
    
print '</form></div><br>';

// --- BOTÓN DE ELIMINACIÓN DE SEGURIDAD ---
print '<div style="float: right; margin-bottom: 10px;">';
$url_delete = $_SERVER["PHP_SELF"]."?action=delete_extracto&token=".newToken()."&bank=".$fk_bank_account."&month=".$filter_month."&year=".$filter_year;
print '<a class="buttonDelete" href="'.$url_delete.'" onclick="return confirm(\'¿ESTÁ SEGURO? Se eliminarán TODOS los movimientos de este banco para el periodo seleccionado. Esta acción no se puede deshacer.\');">'.img_picto('', 'delete').' Eliminar Extracto del Mes</a>';
print '</div>';



// --- 4.5 BLOQUE DE TOTALIZADORES DEL EXTRACTO ---
$sql_t = "SELECT 
    SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as ingresos,
    SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as egresos,
    COUNT(*) as total_filas
    FROM ".MAIN_DB_PREFIX."ofinova_extracto 
    WHERE fk_bank_account = ".(int)$fk_bank_account."
    AND MONTH(date_mvmt) = ".(int)$filter_month." 
    AND YEAR(date_mvmt) = ".(int)$filter_year;

$res_t = $db->query($sql_t);
if ($res_t) {
    $data_t = $db->fetch_object($res_t);
    $total_in = (float)$data_t->ingresos;
    $total_out = (float)$data_t->egresos;
    $neto = $total_in - $total_out;

    print '<div style="display: flex; gap: 20px; margin-bottom: 25px;">';
    
    // Tarjeta Ingresos (Débitos en cuenta / Entradas)
    print '<div style="flex: 1; background: #fff; border: 1px solid #ddd; border-left: 5px solid #28a745; padding: 15px; border-radius: 4px; box-shadow: 2px 2px 5px rgba(0,0,0,0.05);">';
    print '<div style="font-size: 0.85em; color: #666; font-weight: bold; margin-bottom: 5px;">INGRESOS DEL MES (+)</div>';
    print '<div style="font-size: 1.6em; color: #28a745; font-weight: bold;">' . price($total_in) . '</div>';
    print '</div>';

    // Tarjeta Egresos (Créditos en cuenta / Salidas)
    print '<div style="flex: 1; background: #fff; border: 1px solid #ddd; border-left: 5px solid #dc3545; padding: 15px; border-radius: 4px; box-shadow: 2px 2px 5px rgba(0,0,0,0.05);">';
    print '<div style="font-size: 0.85em; color: #666; font-weight: bold; margin-bottom: 5px;">EGRESOS DEL MES (-)</div>';
    print '<div style="font-size: 1.6em; color: #dc3545; font-weight: bold;">' . price($total_out) . '</div>';
    print '</div>';

    // Tarjeta Flujo Neto
    $color_n = ($neto >= 0) ? '#007bff' : '#fd7e14';
    $label_n = ($neto >= 0) ? 'SUPERÁVIT' : 'DÉFICIT';
    print '<div style="flex: 1; background: #fff; border: 1px solid #ddd; border-left: 5px solid '.$color_n.'; padding: 15px; border-radius: 4px; box-shadow: 2px 2px 5px rgba(0,0,0,0.05);">';
    print '<div style="font-size: 0.85em; color: #666; font-weight: bold; margin-bottom: 5px;">BALANCE NETO ('.$label_n.')</div>';
    print '<div style="font-size: 1.6em; color: '.$color_n.'; font-weight: bold;">' . price($neto) . '</div>';
    print '</div>';

    print '</div>';
}


// --- 5. TABLA DE RESULTADOS (DASHBOARD) ---
$sql = "SELECT * FROM ".MAIN_DB_PREFIX."ofinova_extracto WHERE 1=1 ";
if ($fk_bank_account > 0) $sql .= " AND fk_bank_account = $fk_bank_account";
$sql .= " AND MONTH(date_mvmt) = $filter_month AND YEAR(date_mvmt) = $filter_year ORDER BY date_mvmt ASC";

$res = $db->query($sql);
if ($res && $db->num_rows($res) > 0) {
    print '<table class="noborder" width="100%">';
    // Encabezados estilo Dolibarr
    print '<tr class="liste_titre"><td>Fecha</td><td>Revisado</td><td>Concepto</td><td align="right">Débito</td><td align="right">Crédito</td><td align="center">Estado</td><td align="right">Acción</td></tr>';
    
    while ($row = $db->fetch_object($res)) {
        
        
        print '<tr class="oddeven" style="height:40px;">';
        print '<td>'.dol_print_date($db->jdate($row->date_mvmt), 'day').'</td>';
        print '<td>';
        if ($row->status == 0) {
            print '<form action="'.$_SERVER["PHP_SELF"].'" method="POST" style="display:inline; margin:0; padding:0;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="solo_marcar">';
    print '<input type="hidden" name="id_extracto" value="'.(int)$row->rowid.'">'; // ID de la línea del extracto
    print '<input type="hidden" name="fk_bank_account" value="'.(int)$fk_bank_account.'">'; // Para mantener el filtro al redireccionar
    print '<input type="hidden" name="month" value="'.(int)$filter_month.'">';
    print '<input type="hidden" name="year" value="'.(int)$filter_year.'">';
    
    // Botón con estilo nativo de Dolibarr pero forzando color azul para diferenciarlo
// =========================================================================
// 🚨 BOTÓN DE ACCIÓN CON VENTANA DE CONFIRMACIÓN INTEGRADA
// =========================================================================
print '<button type="submit" class="button" onclick="return confirm(\'¿Está seguro de que desea conciliar únicamente los movimientos seleccionados sin inyectar asientos adicionales?\');" style="background-color: #2563eb; color: white; border: none; padding: 5px 10px; border-radius: 3px; font-weight: bold; cursor: pointer;">';
print '🔍 Solo Conciliar';
print '</button>';

print '</form>';
        };
        print '</td>';
        print '<td>'.$row->label.'</td>';
        
        // Columna Débito (Rojo) / Crédito (Verde)
        print '<td align="right" style="color:#dc3545; font-weight:bold;">'.($row->amount < 0 ? price(abs($row->amount)) : '').'</td>';
        print '<td align="right" style="color:#28a745; font-weight:bold;">'.($row->amount > 0 ? price($row->amount) : '').'</td>';
        
        // Estado con Badge
        print '<td align="center">';
        print $row->status ? '<span class="badge" style="background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:4px 10px; border-radius:15px;">CONCILIADO</span>' 
                           : '<span class="badge" style="background:#fff3cd; color:#856404; border:1px solid #ffeeba; padding:4px 10px; border-radius:15px;">PENDIENTE</span>';
        print '</td>';
        
        print '<td align="right" style="white-space:nowrap; vertical-align:middle; padding:5px;">';

        if ($row->status == 0) { // Si el movimiento está PENDIENTE
        print '<div style="display:flex; align-items:center; gap:5px; justify-content:flex-end;">';
    
                // --- FORMULARIO DE PROCESAMIENTO ---
        print '<form action="action_conciliar.php" method="post" style="display:flex; align-items:center; gap:5px; margin:0;">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="id_extracto" value="'.$row->rowid.'">';
        
        // Estilo común para selectores
        $sel_s = "border:1px solid #ccc; border-radius:4px; padding:2px; font-size:0.82em; max-width:110px; background:#fff;";
        
        // --- SELECTOR DE TERCEROS ---
        print '<div style="margin-bottom: 4px;">';
        // 1. Agregamos data-placeholder y dejamos el style en 100% para que Select2 se adapte correctamente
        print '<select id="fk_soc_'.$row->rowid.'" name="fk_soc_anticipo" class="select2" data-placeholder="TERCERO" style="width: 130px;">';
            // 2. La primera opción debe estar vacía obligatoriamente para que funcione el placeholder
            print '<option value=""></option>'; 
            
            $res_s = $db->query("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".(int)$conf->entity.") AND status=1 ORDER BY nom ASC");
        // 3. Eliminamos la condición del 'selected' que apuntaba al ID 84
        while($s=$db->fetch_object($res_s)){ 
            print '<option value="'.$s->rowid.'">'.$s->nom.'</option>'; 
        }
        print '</select>';
        print '</div>';
        
        // --- SELECTOR DE PRODUCTOS/CONCEPTOS ---
        print '<div>';
        // 1. Agregamos el atributo data-placeholder="CONCEPTO"
        print '<select id="fk_prod_'.$row->rowid.'" name="fk_product_anticipo" class="select2" data-placeholder="CONCEPTO" style="width: 130px;">';
            // 2. Dejamos la primera opción completamente vacía para habilitar el placeholder
            print '<option value=""></option>';
            
            $res_p = $db->query("SELECT rowid, label FROM ".MAIN_DB_PREFIX."product WHERE tosell=1 OR tobuy=1");
        // 3. Eliminamos la condición que seleccionaba por defecto el ID 46
        while($p=$db->fetch_object($res_p)){ 
            print '<option value="'.$p->rowid.'">'.$p->label.'</option>'; 
        }
        print '</select>';
        print '</div>';

        
        // --- SELECTOR DE TERCEROS ---
       /* print '<div style="margin-bottom: 4px;">';
        print '<select id="fk_soc_'.$row->rowid.'" name="fk_soc_anticipo" class="select2" style="width: 130px;">';
            print '<option value="0">-- Tercero --</option>';
            $res_s = $db->query("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".(int)$conf->entity.") AND status=1 ORDER BY nom ASC");
        while($s=$db->fetch_object($res_s)){ print '<option value="'.$s->rowid.'" '.($s->rowid==84?'selected':'').'>'.$s->nom.'</option>'; }
        print '</select>';
        print '</div>';*/ 
        
        // --- SELECTOR DE PRODUCTOS/CONCEPTOS ---
        /*print '<div>';
        print '<select id="fk_prod_'.$row->rowid.'" name="fk_product_anticipo" class="select2" style="width: 130px;">';
            print '<option value="0">-- Concepto --</option>';
            $res_p = $db->query("SELECT rowid, label FROM ".MAIN_DB_PREFIX."product WHERE tosell=1 OR tobuy=1");
        while($p=$db->fetch_object($res_p)){ print '<option value="'.$p->rowid.'" '.($p->rowid==46?'selected':'').'>'.$p->label.'</option>'; }
        print '</select>';
        print '</div>';*/


        // 1. Selector de Terceros
        /*print '<select name="fk_soc_anticipo" style="'.$sel_s.'"><option value="0">👤 Tercero...</option>';
        $res_s = $db->query("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".(int)$conf->entity.") AND status=1 ORDER BY nom ASC");
        while($s=$db->fetch_object($res_s)){ print '<option value="'.$s->rowid.'" '.($s->rowid==84?'selected':'').'>'.$s->nom.'</option>'; }
        print '</select>';*/

        // 2. Selector de Empleados
       /* print '<select name="fk_user_salary" style="'.$sel_s.'"><option value="0">🏃‍♂️ Empleado...</option>';
        $res_u = $db->query("SELECT rowid, firstname FROM ".MAIN_DB_PREFIX."user WHERE statut=1 ORDER BY firstname ASC");
        while($u=$db->fetch_object($res_u)){ print '<option value="'.$u->rowid.'">'.$u->firstname.'</option>'; }
        print '</select>';*/

        // 3. Selector de Concepto
        /*print '<select name="fk_product_anticipo" style="'.$sel_s.'"><option value="0">📋 Concepto...</option>';
        $res_p = $db->query("SELECT rowid, label FROM ".MAIN_DB_PREFIX."product WHERE tosell=1 OR tobuy=1");
        while($p=$db->fetch_object($res_p)){ print '<option value="'.$p->rowid.'" '.($p->rowid==46?'selected':'').'>'.$p->label.'</option>'; }
        print '</select>';*/

        // 4. NUEVO: Selector de Banco/Caja Destino (Solo para Traslados)
        print '<select name="fk_bank_dest" style="'.$sel_s.' border-color:#6f42c1;"><option value="0">🔁 Destino...</option>';
        $res_c = $db->query("SELECT rowid, label FROM ".MAIN_DB_PREFIX."bank_account WHERE entity=".(int)$conf->entity." AND clos=0 AND rowid != ".(int)$fk_bank_account);
        while($c=$db->fetch_object($res_c)){ print '<option value="'.$c->rowid.'">'.$c->label.'</option>'; }
        print '</select>';

        // --- BOTONES DE ACCIÓN ---
        $btn_s = "border:none; border-radius:15px; padding:4px 10px; font-weight:bold; cursor:pointer; font-size:0.8em; color:#fff !important;";
        
        if ($row->amount < 0) {
            // EGRESOS
            print '<button type="submit" name="action" value="gasto_rapido" style="'.$btn_s.' background:#444;">⚡ Gasto</button>';
            print '<button type="submit" name="action" value="anticipo_proveedor" style="'.$btn_s.' background:#007bff;">💸 Ant.</button>';
            // --- BOTÓN PARA ANTICIPO DE NÓMINA (A LA TABLA ESPEJO DE NOVEDADES) ---

    print '<button type="submit" name="action" value="anticipo_nomina_espejo" class="butActionSmall" style="background:#ff8c00; color:#fff !important; border:none; cursor:pointer;" title="Registrar como Anticipo en el Módulo de Nómina">🏃‍♂️ Ant. Nom.</button>';


            // BOTÓN DE TRASLADO (Morado)
            print '<button type="submit" name="action" value="traslado_interno" style="'.$btn_s.' background:#6f42c1;" title="Traslado a otra cuenta">🔁 Tras.</button>';
        } else {
            // INGRESOS
            // --- BOTÓN VERDE DE INGRESO (Para montos positivos > 0) ---
            print '<button type="submit" name="action" value="ingreso_directo" style="background:#28a745; border:none; color:white; padding:4px 8px;" title="Registrar Rendimientos o Reintegros">🟢 Ingresos Varios N.Op</button>';

            print '<button type="submit" name="action" value="anticipo_cliente" style="'.$btn_s.' background:#28a745;">💰 Pago Cliente</button>';
        }
        print '</form>';


        // --- FORMULARIO DE PROCESAMIENTO ---
        /*print '<form action="action_conciliar.php" method="post" style="display:flex; align-items:center; gap:5px; margin:0;">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="id_extracto" value="'.$row->rowid.'">';
        
        // Estilo común para selectores
        $sel_s = "border:1px solid #ccc; border-radius:4px; padding:2px; font-size:0.82em; max-width:115px; background:#fff;";

        // 1. Selector de Terceros (Clientes/Proveedores)
        print '<select name="fk_soc_anticipo" style="'.$sel_s.'">';
        print '<option value="0">👤 Tercero...</option>';
        $res_s = $db->query("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".(int)$conf->entity.") AND status=1 ORDER BY nom ASC");
        while($s=$db->fetch_object($res_s)){ 
            $selected = ($s->rowid == 84) ? 'selected' : '';
            print '<option value="'.$s->rowid.'" '.$selected.'>'.$s->nom.'</option>'; 
        }
        print '</select>';

        // 2. Selector de Empleados (Usuarios) - PARA NÓMINA
        print '<select name="fk_user_salary" style="'.$sel_s.'">';
        print '<option value="0">🏃‍♂️ Empleado...</option>';
        $res_u = $db->query("SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."user WHERE statut=1 ORDER BY firstname ASC");
        while($u=$db->fetch_object($res_u)){ 
            print '<option value="'.$u->rowid.'">'.$u->firstname.' '.$u->lastname.'</option>'; 
        }
        print '</select>';

        // 3. Selector de Concepto (Productos/Servicios)
        print '<select name="fk_product_anticipo" style="'.$sel_s.'">';
        print '<option value="0">📋 Concepto...</option>';
        $res_p = $db->query("SELECT rowid, label FROM ".MAIN_DB_PREFIX."product WHERE tosell=1 OR tobuy=1");
        while($p=$db->fetch_object($res_p)){ 
            $selected = ($p->rowid == 46) ? 'selected' : '';
            print '<option value="'.$p->rowid.'" '.$selected.'>'.$p->label.'</option>'; 
        }
        print '</select>';

        // --- BOTONES DE ACCIÓN ---
        $btn_s = "border:none; border-radius:15px; padding:4px 10px; font-weight:bold; cursor:pointer; font-size:0.8em; color:#fff !important; transition: 0.3s;";
        
        if ($row->amount < 0) {
            // EGRESOS (Débitos)
            print '<button type="submit" name="action" value="gasto_rapido" style="'.$btn_s.' background:#444;" title="Gasto Genérico">⚡ Gasto</button>';
            print '<button type="submit" name="action" value="anticipo_proveedor" style="'.$btn_s.' background:#007bff;" title="Anticipo a Proveedor">💸 Ant.</button>';
            print '<button type="submit" name="action" value="pago_nomina" style="'.$btn_s.' background:#fd7e14;" title="Anticipo Nómina/Salario">🏃‍♂️ Nom.</button>';
        } else {
            // INGRESOS (Créditos)
            print '<button type="submit" name="action" value="anticipo_cliente" style="'.$btn_s.' background:#28a745;" title="Anticipo de Cliente">💰 Anticipo</button>';
        }
        print '</form>';*/

        // --- 4. BUSCADOR INTELIGENTE (VINCULAR) ---
        $monto_abs = abs($row->amount);
        $t_fac = ($row->amount > 0 ? 'facture' : 'facture_fourn');
        $sql_v = "SELECT f.rowid, f.ref FROM ".MAIN_DB_PREFIX.$t_fac." as f WHERE f.paye=0 AND f.fk_statut=1 AND f.total_ttc=".(double)$monto_abs." LIMIT 5";
        $res_v = $db->query($sql_v);
        if ($res_v && $db->num_rows($res_v) > 0) {
            print '<select onchange="if(confirm(\'¿Vincular a esta factura?\')) window.location.href=\'action_conciliar.php?action=vincular_directo&id_extracto='.$row->rowid.'&id_facture=\'+this.value" 
                          style="border:1px solid #ffc107; border-radius:15px; padding:3px 8px; background:#fff3cd; font-weight:bold; font-size:0.8em; cursor:pointer;">';
            print '<option value="">🔍 Vincular...</option>';
            while($v=$db->fetch_object($res_v)){ print '<option value="'.$v->rowid.'">'.$v->ref.'</option>'; }
            print '</select>';
        } else {
            $u_search = ($row->amount > 0 ? 'compta/facture/list.php' : 'fourn/facture/list.php');
            print '<a href="'.DOL_URL_ROOT.'/'.$u_search.'?search_montant_ttc='.$monto_abs.'" target="_blank" style="padding:4px; background:#f8f9fa; border:1px solid #ddd; border-radius:50%; width:22px; height:22px; display:flex; align-items:center; justify-content:center; text-decoration:none;" title="Búsqueda manual">🔍</a>';
        }

    print '</div>';
} else {
    // ESTADO CONCILIADO
    print '<div style="display:flex; align-items:center; justify-content:flex-end; gap:5px;">';
    print '<span class="badge" style="background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:4px 12px; border-radius:15px; font-weight:bold; font-size:0.8em;">✅ CONCILIADO</span>';
    if ($row->fk_facture > 0) {
        $u_fac = ($row->amount > 0) ? 'compta/facture/card.php' : 'fourn/facture/card.php';
        print '<a href="'.DOL_URL_ROOT.'/'.$u_fac.'?id='.$row->fk_facture.'">'.img_picto('', 'object_bill').'</a>';
    }
    print '</div>';
}

print '</td>';


        // --- COLUMNA DE ACCIÓN ---
       /* print '<td align="right" style="white-space:nowrap; vertical-align:middle;">';
        if ($row->status == 0) {
            print '<div style="display:flex; align-items:center; gap:5px; justify-content:flex-end;">';

                // Formulario para Gasto/Anticipo
                print '<form action="action_conciliar.php" method="post" style="display:flex; align-items:center; gap:5px; margin:0;">';
                print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id_extracto" value="'.$row->rowid.'">';
                
                // Selectores de Tercero y Concepto
                $sel_s = "border:1px solid #ccc; border-radius:4px; padding:2px; font-size:0.85em; max-width:130px;";
                print '<select name="fk_soc_anticipo" style="'.$sel_s.'">';
                $res_s = $db->query("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE entity=".(int)$conf->entity." AND status=1 ORDER BY nom ASC");
                while($s=$db->fetch_object($res_s)){ print '<option value="'.$s->rowid.'" '.($s->rowid==84?'selected':'').'>'.$s->nom.'</option>'; }
                print '</select>';

                print '<select name="fk_product_anticipo" style="'.$sel_s.'">';
                $res_p = $db->query("SELECT rowid, label FROM ".MAIN_DB_PREFIX."product WHERE tosell=1 OR tobuy=1");
                while($p=$db->fetch_object($res_p)){ print '<option value="'.$p->rowid.'" '.($p->rowid==46?'selected':'').'>'.$p->label.'</option>'; }
                print '</select>';

                $btn_s = "border:none; border-radius:15px; padding:4px 10px; font-weight:bold; cursor:pointer; font-size:0.8em; color:#fff !important;";
                if ($row->amount < 0) {
                    print '<button type="submit" name="action" value="gasto_rapido" style="'.$btn_s.' background:#444;">⚡ Gasto</button>';
                    print '<button type="submit" name="action" value="anticipo_proveedor" style="'.$btn_s.' background:#007bff;">💸 Anticipo</button>';
                } else {
                    print '<button type="submit" name="action" value="anticipo_cliente" style="'.$btn_s.' background:#28a745;">💰 Anticipo</button>';
                }
                print '</form>';

                // Buscador Inteligente (Vincular)
                $monto_abs = abs($row->amount);
                $t_fac = ($row->amount > 0 ? 'facture' : 'facture_fourn');
                $sql_v = "SELECT f.rowid, f.ref FROM ".MAIN_DB_PREFIX.$t_fac." as f WHERE f.paye=0 AND f.fk_statut=1 AND f.total_ttc=".(double)$monto_abs." LIMIT 5";
                $res_v = $db->query($sql_v);
                if ($res_v && $db->num_rows($res_v) > 0) {
                    print '<select onchange="if(confirm(\'¿Vincular a esta factura?\')) window.location.href=\'action_conciliar.php?action=vincular_directo&id_extracto='.$row->rowid.'&id_facture=\'+this.value" 
                                  style="border:1px solid #ffc107; border-radius:15px; padding:3px 8px; background:#fff3cd; font-weight:bold; font-size:0.8em; cursor:pointer;">';
                    print '<option value="">🔍 Vincular...</option>';
                    while($v=$db->fetch_object($res_v)){ print '<option value="'.$v->rowid.'">'.$v->ref.'</option>'; }
                    print '</select>';
                } else {
                    $u_search = ($row->amount > 0 ? 'compta/facture/list.php' : 'fourn/facture/list.php');
                    print '<a href="'.DOL_URL_ROOT.'/'.$u_search.'?search_montant_ttc='.$monto_abs.'" target="_blank" style="padding:4px; background:#f8f9fa; border:1px solid #ddd; border-radius:50%; width:22px; height:22px; display:flex; align-items:center; justify-content:center; text-decoration:none;" title="Búsqueda manual">🔍</a>';
                }

            print '</div>';
        } else {
            if ($row->fk_facture > 0) {
                $u_fac = ($row->amount > 0) ? 'compta/facture/card.php' : 'fourn/facture/card.php';
                print '<a href="'.DOL_URL_ROOT.'/'.$u_fac.'?id='.$row->fk_facture.'">'.img_picto('Ver Factura', 'object_bill').'</a>';
            }
        }
        print '</td>'; */
        
        print '</tr>';
    }
    print '</table>';
}

// --- 5. TABLA DE RESULTADOS ---
/*$sql = "SELECT * FROM ".MAIN_DB_PREFIX."ofinova_extracto WHERE 1=1 ";
if ($fk_bank_account > 0) $sql .= " AND fk_bank_account = $fk_bank_account";
$sql .= " AND MONTH(date_mvmt) = $filter_month AND YEAR(date_mvmt) = $filter_year ORDER BY date_mvmt DESC";

$res = $db->query($sql);
if ($res && $db->num_rows($res) > 0) {
    print '<table class="noborder" width="100%">';
    print '<tr class="liste_titre"><td>Fecha</td><td>Concepto</td><td align="right">Monto</td><td align="center">Estado</td><td align="center">Acción</td></tr>';
    while ($row = $db->fetch_object($res)) {
        $color = ($row->amount < 0) ? "red" : "green";
        print '<tr class="oddeven">';
        print '<td>'.$row->date_mvmt.'</td>';
        print '<td>'.$row->label.'</td>';
        print '<td align="right" style="color:'.$color.'; font-weight:bold;">'.price($row->amount).'</td>';
        print '<td align="center">'.($row->status ? '✅' : '⏳').'</td>';
        
        //COLUMNA DE ACCION 
        // --- COLUMNA DE ACCIÓN ---
        print '<td align="right" style="white-space:nowrap; vertical-align:middle; padding:8px;">';

if ($row->status == 0) {
    print '<div style="display:flex; align-items:center; gap:8px; justify-content:flex-end;">';

    // --- FORMULARIO DE ACCIONES RÁPIDAS ---
    print '<form action="action_conciliar.php" method="post" style="display:flex; align-items:center; gap:6px; margin:0;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="id_extracto" value="'.$row->rowid.'">';

    // Selectores con estilo minimalista
    $select_style = "border:1px solid #ccc; border-radius:4px; padding:2px 5px; background:#fff; font-size:0.9em; max-width:140px;";
    
    // Tercero
    print '<select name="fk_soc_anticipo" style="'.$select_style.'">';
    print '<option value="0">👤 Tercero...</option>';
    $sql_soc = "SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".(int)$conf->entity.") AND status = 1 ORDER BY nom ASC";
    $res_soc = $db->query($sql_soc);
    while ($obj_soc = $db->fetch_object($res_soc)) {
        $selected = ($obj_soc->rowid == 84) ? ' selected' : '';
        print '<option value="'.$obj_soc->rowid.'"'.$selected.'>'.$obj_soc->nom.'</option>';
    }
    print '</select>';

    // Concepto
    print '<select name="fk_product_anticipo" style="'.$select_style.'">';
    print '<option value="0">📋 Concepto...</option>';
    $sql_prod = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."product WHERE tosell=1 OR tobuy=1";
    $res_prod = $db->query($sql_prod);
    while ($obj_prod = $db->fetch_object($res_prod)) {
        $selected = ($obj_prod->rowid == 46) ? ' selected' : '';
        print '<option value="'.$obj_prod->rowid.'"'.$selected.'>'.$obj_prod->label.'</option>';
    }
    print '</select>';

    // Botones con estilo moderno (Pill buttons)
    $btn_base = "border:none; border-radius:20px; padding:4px 12px; font-weight:600; cursor:pointer; font-size:0.85em; transition:0.3s; display:flex; align-items:center; gap:4px; color:#fff !important;";

    if ($row->amount < 0) {
        // Gasto Rápido (Gris Carbono)
        print '<button type="submit" name="action" value="gasto_rapido" class="button" style="'.$btn_base.' background:#343a40;">⚡ Gasto</button>';
        // Anticipo Proveedor (Azul Cobalto)
        print '<button type="submit" name="action" value="anticipo_proveedor" class="button" style="'.$btn_base.' background:#007bff;">💸 Anticipo</button>';
    } else {
        // Anticipo Cliente (Verde Esmeralda)
        print '<button type="submit" name="action" value="anticipo_cliente" class="button" style="'.$btn_base.' background:#28a745;">💰 Anticipo</button>';
    }
    print '</form>';

    // --- BUSCADOR INTELIGENTE (LA LUPA) ---
    $monto_abs = abs($row->amount);
    $table_fac = ($row->amount > 0 ? 'facture' : 'facture_fourn');
    $sql_v = "SELECT f.rowid, f.ref FROM ".MAIN_DB_PREFIX.$table_fac." as f WHERE f.paye=0 AND f.fk_statut=1 AND f.total_ttc=".(double)$monto_abs." LIMIT 3";
    $res_v = $db->query($sql_v);

    if ($res_v && $db->num_rows($res_v) > 0) {
        print '<select onchange="if(confirm(\'¿Vincular?\')) window.location.href=\'action_conciliar.php?action=vincular_directo&id_extracto='.$row->rowid.'&id_facture=\'+this.value" 
                      style="border:1px solid #ffc107; border-radius:20px; padding:3px 10px; background:#fff3cd; cursor:pointer; font-weight:bold; font-size:0.85em;">';
        print '<option value="">🔍 Vincular...</option>';
        while ($f = $db->fetch_object($res_v)) { print '<option value="'.$f->rowid.'">'.$f->ref.'</option>'; }
        print '</select>';
    } else {
        $url_search = ($row->amount > 0) ? 'compta/facture/list.php' : 'fourn/facture/list.php';
        print '<a href="'.DOL_URL_ROOT.'/'.$url_search.'?search_montant_ttc='.$monto_abs.'" target="_blank" 
                  style="text-decoration:none; padding:4px 8px; background:#f8f9fa; border:1px solid #ddd; border-radius:50%; display:flex; align-items:center; justify-content:center; width:24px; height:24px;" title="Buscar factura">🔍</a>';
    }

    print '</div>';
} else {
    // ESTADO FINALIZADO
    print '<div style="display:flex; align-items:center; justify-content:flex-end; gap:5px;">';
    print '<span style="background:#d4edda; color:#155724; padding:4px 12px; border-radius:20px; font-weight:bold; font-size:0.85em; border:1px solid #c3e6cb;">✅ Conciliado</span>';
    if ($row->fk_facture > 0) {
        $url_fac = ($row->amount > 0) ? 'compta/facture/card.php' : 'fourn/facture/card.php';
        print '<a href="'.DOL_URL_ROOT.'/'.$url_fac.'?id='.$row->fk_facture.'" style="color:#007bff; text-decoration:none;">'.img_picto('', 'object_bill').'</a>';
    }
    print '</div>';
}

print '</td>';






        
        print '</tr>';
    }
    print '</table>';
}*/ else {
    print '<p class="opacitymed" style="text-align:center;">No hay datos para este filtro.</p>';
}

llxFooter();
?>
<script type="text/javascript">
    jQuery(document).ready(function() {
        // Inicializamos los selectores
        var $selects = jQuery(".select2").select2({
            placeholder: "-- Seleccione --",
            allowClear: true
        });

        // Evento para mostrar el mensaje "Selección guardada"
        $selects.on('change', function() {
            var val = jQuery(this).val();
            var id_attr = jQuery(this).attr('id');
            
            if(val != "0") {
                // Crear o mostrar el mensaje arriba
                if(!jQuery('#msg-guardado').length) {
                    jQuery('body').append('<div id="msg-guardado" style="position:fixed; top:20px; right:20px; background:#28a745; color:white; padding:10px 20px; border-radius:5px; z-index:9999; font-weight:bold; box-shadow:0 2px 5px rgba(0,0,0,0.2);">✅ Selección capturada</div>');
                }
                
                jQuery('#msg-guardado').fadeIn().delay(1000).fadeOut();
                console.log("Campo " + id_attr + " actualizado a: " + val);
            }
        });
    });
</script>

