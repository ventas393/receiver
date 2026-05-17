<?php
// 1. Inicializar el entorno e importar clases core de Dolibarr
require '../../main.inc.php';

// PROTECCIÓN CSRF v23: Validar estrictamente el token de seguridad antes de operar
if (!GETPOST('token', 'alpha') || GETPOST('token', 'alpha') != $_SESSION['newtoken']) {
    accessforbidden('Token de seguridad CSRF expirado o inválido. Intente recargar la página.');
}

// Protección de seguridad oficial del módulo de Tesorería de Dolibarr
if (empty($user->rights->banque->lire) && empty($user->rights->accounting->mouvement->lire)) {
    accessforbidden();
}

// Forzamos el método 2 (POST puro) para capturar el lote de la precarga
$movimientos_seleccionados = GETPOST('movimientos', 'array', 2); 

if (empty($movimientos_seleccionados) || !is_array($movimientos_seleccionados) || count($movimientos_seleccionados) <= 0) {
    setEventMessages("No seleccionaste ningún movimiento de extracto válido para procesar.", null, 'errors');
    header("Location: precarga_bancos.php");
    exit;
}

$db->begin(); // Iniciar transacción SQL atómica
$error_count = 0;

// =========================================================================
// 🚀 RECORRIDO DE LOTES EXTRAYENDO EL TERCERO DIRECTO DE LA FILA COMPANY
// =========================================================================
foreach ($movimientos_seleccionados as $bankid) {
    
    // Consulta base del movimiento del extracto bancario de Dolibarr (Tu query original exitoso)
    $sql_m = "SELECT b.rowid, b.amount, b.label as detalle, b.datev, ba.account_number as cuenta_puc ";
    $sql_m .= " FROM " . MAIN_DB_PREFIX . "bank as b ";
    $sql_m .= " INNER JOIN " . MAIN_DB_PREFIX . "bank_account as ba ON b.fk_account = ba.rowid ";
    $sql_m .= " WHERE b.rowid = " . (int)$bankid;
    
    $res_m = $db->query($sql_m);
    if ($res_m && $db->num_rows($res_m) > 0) {
        $mov = $db->fetch_object($res_m);
        
        $monto_real      = (double)$mov->amount;
        $cuenta_banco    = !empty($mov->cuenta_puc) ? preg_replace('/[^0-9]/', '', $mov->cuenta_puc) : "111005";
        $id_asiento      = "CO-BAN-MOV-" . $mov->rowid;
        $valor_absoluto  = abs($monto_real);
        
        // 🚨 ENVOLTURA MAESTRA DE FECHA SQL: String limpio DATE de 10 caracteres entre comillas simples rígidas
        $fecha_clean = date("Y-m-d", $db->jdate($mov->datev));
        
        // Inicializamos las variables del subledger con contingencia por defecto
        $fk_soc_real = 0;
        $nit_real    = "222222222";
        $nombre_real = "Tercero Extracto Bancario";

        // 🧠 TU REGLA DE ORO EXTRAÍDA DE LA IMAGEN: Buscamos estrictamente el registro 'company' o 'societe' de ese fk_bank
        $sql_url = "SELECT url_id FROM " . MAIN_DB_PREFIX . "bank_url WHERE fk_bank = " . (int)$bankid . " AND (type = 'company' OR type = 'societe' OR url LIKE '%socid=%') LIMIT 1";
        $res_url = $db->query($sql_url);
        
        if ($res_url && $db->num_rows($res_url) > 0) {
            $b_url = $db->fetch_object($res_url);
            // El url_id de la fila company contiene directamente el rowid del tercero real (Roberto, Frisby, Distracom)
            $fk_soc_real = (int)$b_url->url_id;
            $db->free($res_url);
        }

        // 3. Con el ID del tercero real rescatado, extraemos el NIT y Razón Social verdaderos de llxu3_societe
        if ($fk_soc_real > 0) {
            $sql_s = "SELECT siren, nom FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . $fk_soc_real;
            $res_s = $db->query($sql_s);
            if ($res_s && $db->num_rows($res_s) > 0) {
                $obj_s = $db->fetch_object($res_s);
                $nombre_real = htmlspecialchars($obj_s->nom, ENT_QUOTES, 'UTF-8');
                $ced_clean   = preg_replace('/[^0-9]/', '', $obj_s->siren);
                if (!empty($ced_clean)) { $nit_real = (string)$ced_clean; }
                $db->free($res_s);
            }
        }

        // --- ⚖️ INYECCIÓN EN PARTIDA DOBLE FISCAL EN COLOMBIA ---
        $sub_banco = $cuenta_banco . "01";

        if ($monto_real < 0) {
            // 🔴 CASO EGRESO: PAGO A PROVEEDOR 
            $account_proveedor = "220505";
            $sub_proveedor = $account_proveedor . $nit_real;
            
            // Renglón 1: Débito al pasivo del proveedor real (Ej: FRISBY o ROBERTO) - FECHA ENTRE COMILLAS SIMPLES RÍGIDAS
            $sql_ins1 = "INSERT INTO " . MAIN_DB_PREFIX . "co_bookkeeping (id_asiento, fecha, fk_journal, account_number, subledger_account, fk_soc, debito, credito, label_linea, source_type, fk_source_doc) ";
            $sql_ins1 .= " VALUES ('".$db->escape($id_asiento)."', '".$db->escape($fecha_clean)."', 'BAN', '220505', '".$sub_proveedor."', ".$fk_soc_real.", ".$valor_absoluto.", 0.00, 'Pago Proveedor Consolidado: ".$db->escape($nombre_real)."', 'bank', ".(int)$bankid.")";
            if (!$db->query($sql_ins1)) { $error_count++; }
            
            // Renglón 2: Crédito al activo de la cuenta de ahorros fija (Salida de dinero de la empresa)
            $sql_ins2 = "INSERT INTO " . MAIN_DB_PREFIX . "co_bookkeeping (id_asiento, fecha, fk_journal, account_number, subledger_account, fk_soc, debito, credito, label_linea, source_type, fk_source_doc) ";
            $sql_ins2 .= " VALUES ('".$db->escape($id_asiento)."', '".$db->escape($fecha_clean)."', 'BAN', '".$cuenta_banco."', '".$sub_banco."', ".$fk_soc_real.", 0.00, ".$valor_absoluto.", 'Salida de Efectivo Extracto Ref: ".$db->escape($mov->ref)."', 'bank', ".(int)$bankid.")";
            if (!$db->query($sql_ins2)) { $error_count++; }
            
        } else {
            // 🟢 CASO INGRESO: RECAUDO DE CLIENTE 
            $account_cliente = "130505";
            $sub_cliente = $account_cliente . $nit_real;

            // Renglón 1: Débito a la cuenta de ahorros fija (Ingresa dinero a la empresa)
            $sql_ins1 = "INSERT INTO " . MAIN_DB_PREFIX . "co_bookkeeping (id_asiento, fecha, fk_journal, account_number, subledger_account, fk_soc, debito, credito, label_linea, source_type, fk_source_doc) ";
            $sql_ins1 .= " VALUES ('".$db->escape($id_asiento)."', '".$db->escape($fecha_clean)."', 'BAN', '".$cuenta_banco."', '".$sub_banco."', ".$fk_soc_real.", ".$valor_absoluto.", 0.00, 'Ingreso de Efectivo Extracto Ref: ".$db->escape($mov->ref)."', 'bank', ".(int)$bankid.")";
            if (!$db->query($sql_ins1)) { $error_count++; }
            
            // Renglón 2: Crédito a la cartera del cliente real (Disminuye deudas)
            $sql_ins2 = "INSERT INTO " . MAIN_DB_PREFIX . "co_bookkeeping (id_asiento, fecha, fk_journal, account_number, subledger_account, fk_soc, debito, credito, label_linea, source_type, fk_source_doc) ";
            $sql_ins2 .= " VALUES ('".$db->escape($id_asiento)."', '".$db->escape($fecha_clean)."', 'BAN', '".$account_cliente."', '".$sub_cliente."', ".$fk_soc_real.", 0.00, ".$valor_absoluto.", 'Recaudo Cartera Unificado: ".$db->escape($nombre_real)."', 'bank', ".(int)$bankid.")";
            if (!$db->query($sql_ins2)) { $error_count++; }
        }
        
        // Marcamos la línea del extracto como Contabilizado exclusivamente si no hubo fallas de sintaxis
        if ($error_count == 0) {
            $db->query("UPDATE " . MAIN_DB_PREFIX . "bank_extrafields SET co_contabilizado = 1, co_id_asiento = '".$db->escape($id_asiento)."' WHERE fk_object = " . (int)$bankid);
        }
        
        $db->free($res_m);
    }
}

// =========================================================================
// 7. VERIFICACIÓN DE CONTROL DE TRANSACCIÓN ATÓMICA DE CIERRE
// =========================================================================
if ($error_count == 0) {
    $db->commit(); // Inyección física real masiva autorizada con éxito en las tablas de MariaDB
    setEventMessages("Los movimientos bancarios seleccionados fueron asentados con éxito en las tablas del Libro Mayor paralelo.", null, 'mesgs');
} else {
    $db->rollback(); // Reversión de seguridad ante anomalías
    setEventMessages("Error crítico al procesar la inyección contable bancaria. Transacción revertida de forma segura.", null, 'errors');
}

header("Location: precarga_bancos.php");
exit;
?>
