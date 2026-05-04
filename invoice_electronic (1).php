<?php
// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (! $res && ! empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res=@include($_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php");
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp=empty($_SERVER['SCRIPT_FILENAME'])?'':$_SERVER['SCRIPT_FILENAME'];$tmp2=realpath(__FILE__); $i=strlen($tmp)-1; $j=strlen($tmp2)-1;
while($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i]==$tmp2[$j]) { $i--; $j--; }
if (! $res && $i > 0 && file_exists(substr($tmp, 0, ($i+1))."/main.inc.php")) $res=@include(substr($tmp, 0, ($i+1))."/main.inc.php");
if (! $res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php")) $res=@include(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php");
// Try main.inc.php using relative path
if (! $res && file_exists("../main.inc.php")) $res=@include("../main.inc.php");
if (! $res && file_exists("../../main.inc.php")) $res=@include("../../main.inc.php");
if (! $res && file_exists("../../../main.inc.php")) $res=@include("../../../main.inc.php");
if (! $res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once '../class/apilog.class.php';

global $user, $conf;
$langs->load('electronicinvoice@electronicinvoice');

/*ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);*/

// Obtener el nombre de la empresa desde la configuración de Dolibarr
$company_name = getDolGlobalString('MAIN_INFO_SOCIETE_NOM');

// Cargar información de la empresa
$mysoc = new Societe($db);
$mysoc->fetch(1);

// Obtener el ID de la factura
$invoice_id = GETPOST('id', 'int');
if ($invoice_id <= 0) {
    print "Invalid invoice ID";
    exit;
}
$invoice = new Facture($db);
if ($invoice->fetch($invoice_id) <= 0) {
    print "Failed to load invoice";
    exit;
}

// Nit de empresa
$company_id_number = getDolGlobalString('MAIN_INFO_SIREN');

$client = new Societe($db);
$client->fetch($invoice->socid);


$customer = [
    "type_document_identification_id" => (integer) $client->array_options['options_ei_type_document_identification_id'],
    "identification_number" => (integer) $client->idprof1,
    "type_organization_id" => (integer) $client->array_options['options_ei_type_organization_id'],
    "name" =>  $client->name,
    "phone" =>  $client->phone,
    "address" =>  $client->address,
    "email" => $client->email,
    "municipality_id" => (integer) $client->array_options['options_ei_municipality_id'],
    "merchant_registration" => (string) $client->array_options['options_ei_merchant_registration'],
    "type_liability_id" => (integer) $client->array_options['options_ei_type_liability_id'],
    "dv" => (integer) $client->array_options['options_ei_verification_digit'],
    "type_regime_id" => (integer) $client->array_options['options_ei_type_regime_id'],
];

$date = dol_print_date($invoice->date, '%Y-%m-%d');
$time = dol_print_date($invoice->date_creation, '%H:%M:%S');
if (empty($time)) {
    $time = date('H:i:s');
}
$prefix = getDolGlobalString('ELECTRONICINVOICE_INVOICE_PREFIX');
$resolution = getDolGlobalString('ELECTRONICINVOICE_INVOICE_RESOLUTION_NUMBER');
$enable_email_dolibarr = getDolGlobalString('ELECTRONICINVOICE_DOLIBARR_EMAIL');
$format_print = getDolGlobalString('ELECTRONICINVOICE_FORMAT_PRINT');

if($invoice->module_source == "takepos" && !empty(getDolGlobalString('TAKEPOS_INVOICE_RESOLUTION_'.$invoice->pos_source)) && !empty(getDolGlobalString('TAKEPOS_INVOICE_PREFIX_'.$invoice->pos_source))){
    $prefix = getDolGlobalString('TAKEPOS_INVOICE_PREFIX_'.$invoice->pos_source);
    $resolution = getDolGlobalString('TAKEPOS_INVOICE_RESOLUTION_'.$invoice->pos_source);
}


// ============================================================================
// LÓGICA DINÁMICA DE TÉRMINOS Y MÉTODOS DE PAGO
// ============================================================================

/**
 * Mapea el código de método de pago de Dolibarr al ID de la API DIAN
 * @param string $code Código del método de pago en Dolibarr (LIQ, CB, VIR, CHQ, etc.)
 * @return int ID del método de pago para la API DIAN
 */
function mapPaymentMethodToAPI($code) {
    $mapping = [
        'LIQ' => 10,  // Efectivo
        'CB'  => 48,  // Tarjeta de crédito
        'VIR' => 47,  // Transferencia bancaria
        'CHQ' => 20,  // Cheque
        'PRE' => 49,  // Débito automático / Orden de pago
        'TIP' => 47,  // Transferencia (alias)
        'FAC' => 30,  // Transferencia Crédito (según usuario)
    ];
    return isset($mapping[$code]) ? $mapping[$code] : 10; // Default: Efectivo
}

/**
 * Obtiene los datos de payment_form dinámicamente desde Dolibarr
 * @param object $invoice Objeto factura de Dolibarr
 * @param object $db Conexión a base de datos
 * @param string $invoiceDate Fecha de la factura en formato Y-m-d
 * @return array Datos del payment_form para la API
 */
function getPaymentFormData($invoice, $db, $invoiceDate) {
    $payment_form_id = 1;  // Default: Contado
    $payment_method_id = 10;  // Default: Efectivo
    $duration_days = 0;
    $payment_due_date = $invoiceDate;
    
    // Obtener días del término de pago desde llx_c_payment_term
    if (!empty($invoice->cond_reglement_id) && $invoice->cond_reglement_id > 0) {
        $sql = "SELECT nbjour, type_cdr FROM ".MAIN_DB_PREFIX."c_payment_term WHERE rowid = ".intval($invoice->cond_reglement_id);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $duration_days = (int) $obj->nbjour;
            
            // Si hay más de 1 día, es pago a crédito (<=1 se considera contado)
            if ($duration_days > 1) {
                $payment_form_id = 2;  // Crédito
                // Calcular fecha de vencimiento
                $dateObj = new DateTime($invoiceDate);
                $dateObj->add(new DateInterval('P'.$duration_days.'D'));
                $payment_due_date = $dateObj->format('Y-m-d');
            }
        }
    }
    
    // Obtener código del método de pago desde llx_c_paiement
    if (!empty($invoice->mode_reglement_id) && $invoice->mode_reglement_id > 0) {
        $sql = "SELECT code FROM ".MAIN_DB_PREFIX."c_paiement WHERE id = ".intval($invoice->mode_reglement_id);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $payment_method_id = mapPaymentMethodToAPI($obj->code);
        }
    }
    
    // Si es pago a crédito y no se especificó método, usar Transferencia Crédito (30)
    if ($payment_form_id == 2 && $payment_method_id == 10) {
        $payment_method_id = 30;  // Transferencia Crédito para pagos a crédito
    }
    
    return [
        "payment_form_id" => $payment_form_id,
        "payment_method_id" => $payment_method_id,
        "payment_due_date" => $payment_due_date,
        "duration_measure" => (string) $duration_days
    ];
}

//RETENCIONES 


    // --- EXTRACCIÓN DE RETENCIONES DESDE TABLA ESPEJO ---
    $withholding_taxes = array();
    $sql_espejo = "SELECT base_total, val_retefuente, val_reteiva, val_reteica ";
    $sql_espejo.= " FROM llxu3_autocontabilidad_fiscal_espejo WHERE tipo_factura = 'venta' AND fk_facture = " . $invoice_id;
    $res_espejo = $db->query($sql_espejo);
    
    if ($res_espejo && $db->num_rows($res_espejo) > 0) {
        $data_ret = $db->fetch_object($res_espejo);
        $base_ret = (float) $data_ret->base_total;
        if ($base_ret > 0) {
            if ($data_ret->val_retefuente > 0) {
                $withholding_taxes[] = array(
                    "code" => "06", "taxable_amount" => number_format($base_ret, 2, '.', ''),
                    "percent" => number_format(($data_ret->val_retefuente/$base_ret)*100, 2, '.', ''),
                    "tax_amount" => number_format($data_ret->val_retefuente, 2, '.', '')
                );
            }
            if ($data_ret->val_reteiva > 0) {
                $withholding_taxes[] = array(
                    "code" => "05", "taxable_amount" => number_format($base_ret, 2, '.', ''),
                    "percent" => number_format(($data_ret->val_reteiva/$base_ret)*100, 2, '.', ''),
                    "tax_amount" => number_format($data_ret->val_reteiva, 2, '.', '')
                );
            }
            if ($data_ret->val_reteica > 0) {
                $withholding_taxes[] = array(
                    "code" => "07", "taxable_amount" => number_format($base_ret, 2, '.', ''),
                    "percent" => number_format(($data_ret->val_reteica/$base_ret)*100, 2, '.', ''),
                    "tax_amount" => number_format($data_ret->val_reteica, 2, '.', '')
                );
            }
        }}


// Obtener datos de pago dinámicamente
$payment_form = getPaymentFormData($invoice, $db, $date);

$json_generate = [
    "number" => "",
    "type_document_id" => 1,
    "date" => $date,
    "time" => $time,
    "customer" => $customer,
    "payment_form" => $payment_form,
    "resolution_number" => $resolution,
    "prefix" => $prefix,
    "legal_monetary_totals" => [
        "line_extension_amount" => 0.00,
        "tax_exclusive_amount" => 0.00,
        "tax_inclusive_amount" => 0.00,
        "allowance_total_amount" => 0.00,
        "charge_total_amount" => 0.00,
        "payable_amount" => (float) abs($invoice->total_ttc)
    ],
    //"withholding_taxes" => $withholding_taxes,
            
    "tax_totals" => [],
    "notes" => $invoice->note_public,
    "invoice_template" => $format_print,
    "template_token" => password_hash($company_id_number, PASSWORD_DEFAULT),
    "sendmail" => true,
    "sendmailtome" => true,
 ];
 
 if (!empty($withholding_taxes)) {
    $json_generate["withholding_taxes"] = $withholding_taxes;
}

// ====================================================================
// AGREGAR ORDER_REFERENCE SI EXISTE
// ====================================================================
$order_date = $invoice->array_options['options_ei_order_date'] ?? '';
$order_ref = $invoice->array_options['options_ei_order_reference'] ?? '';

if (!empty($order_date) || !empty($order_ref)) {
    $json_generate['order_reference'] = array();

    if (!empty($order_ref)) {
        $json_generate['order_reference']['id_order'] = (string) $order_ref;
    }

    if (!empty($order_date)) {
        $json_generate['order_reference']['issue_date_order'] = dol_print_date($order_date, '%Y-%m-%d');
    }
}

// ====================================================================
// AGREGAR RECEIPT_DOCUMENT_REFERENCES SI EXISTE
// ====================================================================
$receipt_doc_date = $invoice->array_options['options_ei_receipt_doc_date'] ?? '';
$receipt_doc_id = $invoice->array_options['options_ei_receipt_doc_id'] ?? '';

if (!empty($receipt_doc_date) || !empty($receipt_doc_id)) {
    $json_generate['receipt_document_references'] = array();
    $ref_doc = array();
    
    if (!empty($receipt_doc_id)) {
        $ref_doc['id'] = (string) $receipt_doc_id;
    }
    
    if (!empty($receipt_doc_date)) {
        $ref_doc['issue_date'] = dol_print_date($receipt_doc_date, '%Y-%m-%d');
    }
    
    $json_generate['receipt_document_references'][] = $ref_doc;
}


$procede = false;
    
    //Detectamos el tipo de documento
if($invoice->type == 2 && $invoice->fk_facture_source!=""){ //Nota de crédito
    $dataNextConsecutive = [
        'type_document_id' => 4,
        'prefix' => 'NC'
    ];
    
    $prefix = 'NC';
    
    $route =  'credit-note';
    
    $json_generate["type_document_id"] = 4;
    
    $json_generate["prefix"] = $prefix;
    
    $Type_lines = 'credit_note_lines';
    
    
    //No lleva resoluación
    unset($json_generate["resolution_number"]);
    
    unset($json_generate["payment_form"]);
    
    
    $invoice_rel_info =  invoice_rel_info($invoice->fk_facture_source);
    
    if($invoice_rel_info){
        
        $procede = true;
        
        $json_generate["billing_reference"] = [
            "number"        => $invoice_rel_info['number'],
            "uuid"          => $invoice_rel_info['cufe'],
            "issue_date"    => $invoice_rel_info['date'],
        ];
    }
    
    $json_generate["discrepancyresponsecode"] = 2;
	$json_generate["discrepancyresponsedescription"] =  "ANULACION DE FACTURA";
        
}
elseif($invoice->type == 1 && $invoice->fk_facture_source!=""){ //Nota de débito o factura de reemplazo
    $dataNextConsecutive = [
        'type_document_id' => 5,
        'prefix' => 'ND'
    ];
    
    $route =  'debit-note';
    
    $prefix = 'ND';
    
    $json_generate["type_document_id"] = 5;
    
    $json_generate["prefix"] = $prefix;
       
    $Type_lines = 'debit_note_lines';
    
    $legalbk = $json_generate["legal_monetary_totals"];
    
    unset($json_generate["legal_monetary_totals"]);
    
    $json_generate["requested_monetary_totals"] = $legalbk;
       
    //No lleva resoluación
    unset($json_generate["resolution_number"]);
    
    unset($json_generate["payment_form"]);
    
    $invoice_rel_info =  invoice_rel_info($invoice->fk_facture_source);
    
    if($invoice_rel_info){
        
        $procede = true;
        
        $json_generate["billing_reference"] = [
            "number"        => $invoice_rel_info['number'],
            "uuid"          => $invoice_rel_info['cufe'],
            "issue_date"    => $invoice_rel_info['date'],
        ];
    }
    
    $json_generate["discrepancyresponsecode"] = 3;
	$json_generate["discrepancyresponsedescription"] =  "REEMPLAZO DE FACTURA";
}
else{ //Facturas Estándar
    $procede = true;
    $Type_lines = 'invoice_lines';
    
    $dataNextConsecutive = [
            'type_document_id' => 1,
            'prefix' => $prefix
    ];
    
    $route =  'invoice';
}

$json_generate[$Type_lines] = [];

$tax_totals = [];

foreach ($invoice->lines as $line) {
    // Calcular valores: NETO (con descuento) y el descuento para informar
    $base_amount = (float) abs($line->subprice * $line->qty);
    $line_extension_neto = (float) abs($line->total_ht);
    $discount_amount = $base_amount - $line_extension_neto;
    
    // Construir datos de la línea con valor NETO (descuento ya aplicado)
    $lineData = [
        "free_of_charge_indicator" => false,
        "price_amount" => (float) abs($line->subprice),
        "base_quantity" => $line->qty,
        "code" => $line->ref,
        "description" => !empty($line->desc) ? $line->desc : $line->product_label,
        "unit_measure_id" => 70,
        "invoiced_quantity" => $line->qty,
        "line_extension_amount" => $line_extension_neto,
        "type_item_identification_id" => 4,
        "tax_totals" => [
            [
                "tax_id" => 1,
                "tax_amount" => (float) abs($line->total_tva),
                "taxable_amount" => $line_extension_neto,
                "percent" => (float) $line->tva_tx
            ]
        ],
    ];
    
    // Agregar allowance_charges solo si hay descuento (informativo, no suma a totales)
    if ($line->remise_percent > 0 && $discount_amount > 0) {
        $lineData["allowance_charges"] = [
            [
                "discount_id" => 1,
                "charge_indicator" => false,
                "allowance_charge_reason" => "DESCUENTO GENERAL",
                "amount" => number_format($discount_amount, 2, '.', ''),
                "base_amount" => number_format($base_amount, 2, '.', '')
            ]
        ];
    }
    
    $json_generate[$Type_lines][] = $lineData;

    // Agrupar tax_totals por porcentaje
    $percent = (float) $line->tva_tx;
    if (!isset($tax_totals[$percent])) {
        $tax_totals[$percent] = [
            "tax_id" => 1,
            "tax_amount" => 0,
            "taxable_amount" => 0,
            "percent" => $percent
        ];
    }
    $tax_totals[$percent]['tax_amount'] += (float) abs($line->total_tva);
    $tax_totals[$percent]['taxable_amount'] += (float) abs($line->total_ht);
}

// Convertir tax_totals a array
$json_generate['tax_totals'] = array_values($tax_totals);

// Actualizar legal_monetary_totals con valores NETOS (de Dolibarr)
// Los descuentos ya están aplicados en total_ht, NO se reportan en allowance_total_amount
$json_generate['legal_monetary_totals']['line_extension_amount'] = (float) abs($invoice->total_ht);
$json_generate['legal_monetary_totals']['tax_exclusive_amount'] = (float) abs($invoice->total_ht);
$json_generate['legal_monetary_totals']['tax_inclusive_amount'] = (float) abs($invoice->total_ttc);

/**
 * Función para realizar peticiones HTTP POST
 *
 * @param string $url URL de destino
 * @param string $data Datos a enviar en formato JSON
 * @param array $headers Cabeceras HTTP
 * @return object Respuesta decodificada
 */
function http_post($url, $data, $headers = array()) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response);
}

function invoice_rel_info($invoice_id){
    global $db;
    // Obtener los logs relacionados a la factura
$queryLogs = new ApiLog($db);
$logs = $queryLogs->fetchAll('DESC', 'rowid', 0, 0, '(invoice_id:=:'. $invoice_id .')');

    if (is_array($logs)) {


        foreach ($logs as $log) {


            if($log->status_response){
                $request = json_decode($log->json_request, true);
                $response = json_decode($log->json_response, true);
                
                if(isset($request['number']) && !empty($request['number']) && isset($response['cufe']) && !empty($response['cufe'])){
                    
                    return [
                        "number"    => $request['prefix'].$request['number'],
                        "cufe"      => $response['cufe'],
                        "date"      => $request['date'],
                    ];
                }
                
            }
        }
    }
    
    return false;
}

/**
 * Función para descargar archivos desde URL
 *
 * @param string $url URL del archivo a descargar
 * @param string $savePath Ruta donde guardar el archivo
 * @return boolean True si se descargó correctamente, False en caso contrario
 */
function downloadFile($url, $savePath) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $data = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if (empty($data) || !empty($error)) {
        return false;
    }

    $file = fopen($savePath, "w+");
    if ($file == false) {
        return false;
    }
    fwrite($file, $data);
    fclose($file);

    return true;
}

/**
 * Función para enviar correo con archivos adjuntos
 *
 * @param string $to Destinatario
 * @param string $subject Asunto
 * @param string $message Mensaje
 * @param array $attachments Array de rutas de archivos adjuntos
 * @return boolean True si se envió correctamente, False en caso contrario
 */
function sendEmailWithAttachments($to, $subject, $message, $attachments = array()) {
    global $conf, $langs, $user, $mysoc;

    $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');

    // Verificar si el destinatario es válido
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $filename_list = array();
    $mimetype_list = array();
    $mimefilename_list = array();

    // Preparar archivos adjuntos
    foreach ($attachments as $attachment) {
        if (file_exists($attachment['path'])) {
            $filename_list[] = $attachment['path'];
            $mimetype_list[] = $attachment['mime'] ?: 'application/octet-stream';
            $mimefilename_list[] = $attachment['name'] ?: basename($attachment['path']);
        }
    }

    // Crear y enviar el email
    $mailfile = new CMailFile(
        $subject,
        $to,
        $from,
        $message,
        $filename_list,
        $mimetype_list,
        $mimefilename_list,
        '', '', 0, 1
    );

    if ($mailfile->error) {
        return false;
    }

    $result = $mailfile->sendfile();
    return $result;
}

if (isset($_POST['action']) && $_POST['action'] == 'consume_api' && $procede) {

    $url = getDolGlobalString('ELECTRONICINVOICE_API_URL');
    $token = getDolGlobalString('ELECTRONICINVOICE_API_TOKEN');
    // Primera Solicitud: Obtener el número consecutivo
    $urlNextConsecutive = $url.'/api/ubl2.1/next-consecutive';
    
    
    $datajsonNC = json_encode($dataNextConsecutive);
    $headers = array(
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token
    );

    $responseNextConsecutive = http_post($urlNextConsecutive, $datajsonNC, $headers);
    if (isset($responseNextConsecutive->success) && $responseNextConsecutive->success) {
        $json_generate['number'] = $responseNextConsecutive->number;
       
        // Segunda Solicitud: Enviar la factura
        $urlInvoice = $url.'/api/ubl2.1/'.$route;
        $dataInvoice = json_encode($json_generate);
        $responseInvoice = http_post($urlInvoice, $dataInvoice, $headers);

        $url_pdf = property_exists($responseInvoice, 'urlinvoicepdf') ? $responseInvoice->urlinvoicepdf : '-';
        $url_xml = property_exists($responseInvoice, 'urlinvoicexml') ? $responseInvoice->urlinvoicexml : '-';
        $status = ($responseInvoice->ResponseDian->Envelope->Body->SendBillSyncResponse->SendBillSyncResult->IsValid ?? 'false') === "true" ? 1 : 0;
        $company_id_number = getDolGlobalString('MAIN_INFO_SIREN');

        $newLog = new ApiLog($db);
        $newLog->invoice_id = $invoice_id;
        $newLog->json_request = json_encode($json_generate);
        $newLog->json_response = json_encode($responseInvoice);
        $newLog->status_response = $status;
        $newLog->url_pdf = $url.'/api/invoice/'.$company_id_number.'/'.$url_pdf;
        $newLog->date_sent = dol_now();
        $result = $newLog->create($user);
        if ($result < 0) {
            echo "Error al crear el registro: " . $result. 'error:' .$newLog->error;
        }

        // Si la respuesta es positiva, enviar correo con PDF y XML
        if ($status == 1) {
            // Crear directorio temporal para descargar archivos
            $tempDir = DOL_DATA_ROOT . '/temp/electronicinvoice/' . $invoice_id;
            if (!file_exists($tempDir)) {
                dol_mkdir($tempDir);
            }

            // Rutas para guardar los archivos
            $pdfPath = $tempDir . '/factura_' . $invoice->ref . '.pdf';
            $xmlPath = $tempDir . '/factura_' . $invoice->ref . '.xml';

            // Descargar PDF y XML
            $pdfUrl = $url.'/api/invoice/'.$company_id_number.'/'.$url_pdf;
            $xmlUrl = $url.'/api/invoice/'.$company_id_number.'/'.$url_xml;

            $pdfDownloaded = downloadFile($pdfUrl, $pdfPath);
            $xmlDownloaded = downloadFile($xmlUrl, $xmlPath);

            // Si se descargaron correctamente, enviar correo
            if ($pdfDownloaded && $xmlDownloaded && !empty($client->email) && $enable_email_dolibarr) {
                $subject = $langs->trans('ElectronicInvoiceMailSubject', $company_name);
                $message = $langs->trans('ElectronicInvoiceMailBody', $client->name, $company_name);

                $attachments = array(
                    array('path' => $pdfPath, 'name' => 'factura_' . $invoice->ref . '.pdf', 'mime' => 'application/pdf'),
                    array('path' => $xmlPath, 'name' => 'factura_' . $invoice->ref . '.xml', 'mime' => 'application/xml')
                );

                $mailSent = sendEmailWithAttachments($client->email, $subject, $message, $attachments);
                
                if(!isset($_POST['from_pos'])){
                    if ($mailSent) {
                        setEventMessages($langs->trans('ElectronicInvoiceMailSent', $client->email), null, 'mesgs');
                    } else {
                        setEventMessages($langs->trans('ElectronicInvoiceMailError'), null, 'errors');
                    }
                }

                // Limpieza: eliminar archivos temporales
                unlink($pdfPath);
                unlink($xmlPath);
                rmdir($tempDir);
                
                
            } else {
                if (!$pdfDownloaded || !$xmlDownloaded) {
                    setEventMessages($langs->trans('ElectronicInvoiceFileDownloadError'), null, 'errors');
                }
                if (empty($client->email)) {
                    setEventMessages($langs->trans('ElectronicInvoiceNoClientEmail'), null, 'warnings');
                }
            }
        }
    } else {
        print "Error al obtener el número consecutivo para la factura electrónica: " . json_encode($responseNextConsecutive);
    }
    
                
    if(isset($_POST['from_pos']) && $_POST['from_pos'] == "YES"){
    // Si el envío fue exitoso y tenemos la URL del PDF
            if (isset($url_pdf) && $url_pdf != '-') {
                $response['success'] = true;
                $response['pdf_url'] = $url.'/api/invoice/'.$company_id_number.'/'.$url_pdf;
            }
        
        echo json_encode($response); 
        exit;
    }
}


llxHeader();




$head = facture_prepare_head($invoice);
$titre = $langs->trans("Invoice");
$picto = 'bill';
dol_fiche_head($head, 'electronicinvoice', $titre, 0, $picto);

$token_form = newToken();

// --- CUADRO RESUMEN ANTES DEL ENVÍO ---
print '<div class="div-table-responsive" style="margin-bottom: 20px; border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9;">';
print '<h3>📥 Resumen de Reporte DIAN</h3>';
print '<table class="noborder" width="100%">';
print '<tr><td><strong>Subtotal:</strong></td><td align="right">'.price($base_ret).'</td></tr>';
//print '<tr><td><strong>IVA (19%):</strong></td><td align="right">'.price($total_iva).'</td></tr>';

if ($data_ret->val_retefuente > 0) 
    print '<tr style="color: #a00;"><td>(-) ReteFuente:</td><td align="right">-'.price($data_ret->val_retefuente).'</td></tr>';
if ($data_ret->val_reteiva > 0) 
    print '<tr style="color: #a00;"><td>(-) ReteIVA:</td><td align="right">-'.price($data_ret->val_reteiva).'</td></tr>';
if ($data_ret->val_reteica > 0) 
    print '<tr style="color: #a00;"><td>(-) ReteICA:</td><td align="right">-'.price($data_ret->val_reteica).'</td></tr>';

//print '<tr style="font-size: 1.2em; border-top: 2px solid #333;">';
//print '<td><strong>Total Factura:</strong></td><td align="right"><strong>'.price($total_factura_con_iva).'</strong></td></tr>';
print '</table>';
print '</div>';


//---------------------------------------------------//----------------------------------------------//

$actionUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . urlencode($invoice_id);
print '<form method="post" action="' . $actionUrl . '">';
print '<input type="hidden" name="token" value="'.$token_form.'">';
print '<input type="hidden" name="action" value="consume_api">';
print '<input type="hidden" name="id" value="'.$invoice_id.'">';
//print '<input type="submit" class="button" value="Enviar a API">'

//Botón con ID para control y función de desactivación

// Consultamos si ya existe una respuesta exitosa para esta factura
$sql_check = "SELECT rowid FROM llxu3_electronicinvoice_apilog "; // O la tabla donde guardes la respuesta
$sql_check.= " WHERE invoice_id = " . $invoice_id . " AND status_response = 1";
$res_check = $db->query($sql_check);

$ya_enviada = ($res_check && $db->num_rows($res_check) > 0);

if ($ya_enviada) {
    print '<div class="info">✅ Esta factura ya fue reportada con éxito a la DIAN.</div>';
} else {
    // Aquí imprimes el botón con la protección de JS que vimos arriba

            $sql_check_espejo = "SELECT rowid FROM llxu3_autocontabilidad_fiscal_espejo WHERE tipo_factura = 'venta' AND fk_facture = " .$invoice_id;
            $res_check_espejo = $db->query($sql_check_espejo);
            
            // Si no hay resultados, la factura NO ha pasado por el proceso de espejo
            $existe_en_espejo = ($res_check_espejo && $db->num_rows($res_check_espejo) > 0);

            if ($existe_en_espejo) {
                print '<button type="submit" id="btnEnviarDian" class="butAction" onclick="desactivarBoton(this)">';
                    print '<i class="fa fa-paper-plane"></i> ENVIAR A FACTURADOR';
                print '</button>';
            } else {
                print '<div class="info">Esta factura aún no tiene las retenciones registradas.</div>';
            }
}

?>


<script type="text/javascript">
function desactivarBoton(boton) {
    // Pequeño delay para dejar que el formulario se envíe antes de bloquearlo
    setTimeout(function() {
        boton.disabled = true;
        boton.innerHTML = '<i class="fa fa-spinner fa-spin"></i> PROCESANDO ENVÍO...';
        boton.style.opacity = '0.5';
        boton.style.cursor = 'not-allowed';
    }, 50);
}
</script>'

<?php
print '</form>';

// Obtener los logs relacionados a la factura
$queryLogs = new ApiLog($db);
$logs = $queryLogs->fetchAll('DESC', 'rowid', 0, 0, '(invoice_id:=:'. $invoice_id .')');

if (is_array($logs)) {
    print '<div class="underbanner clearboth"></div>';
    print '<div class="div-table-responsive">';
    print '<table class="tagtable nobottomiftotal liste">';
    print '<tr class="liste_titre" style="background: var(--colorbacktitle1) !important;">';
    print '
            <th class="liste_titre">Fecha de Envío</th>
            <th class="liste_titre">Estado DIAN</th>
            <th class="wrapcolumntitle liste_titre">PDF</th>
            <td class="wrapcolumntitle liste_titre" width="40%">Solicitud JSON</td>
            <th class="wrapcolumntitle liste_titre" width="40%">Respuesta JSON</th>
    ';
    print '</tr>';

    foreach ($logs as $log) {
        print '<tr class="oddeven">';
        print '<td style="vertical-align: top">' . dol_print_date($log->date_sent, 'dayhour') . '</td>';
        print '<td class="nowrap center" style="vertical-align: top"><span class="badge badge-status'.($log->status_response ? '4' : '8').' badge-status" title="'.($log->status_response ? 'Si' : 'No').'">'.($log->status_response ? 'Si' : 'No').'</span></td>';
        print $log->url_pdf != '-' ? '<td style="vertical-align: top"><a href="' . htmlspecialchars($log->url_pdf) . '" target="_blank"><span class="fas fa-download valignmiddle"></span></a></td>' : '';
        print '<td><textarea readonly style="width: 100%; min-height: 80px;">' . htmlspecialchars(json_encode(json_decode($log->json_request, true), JSON_PRETTY_PRINT)) . '</textarea></td>';
        print '<td><textarea readonly style="width: 100%; min-height: 80px;">' . htmlspecialchars(json_encode(json_decode($log->json_response, true), JSON_PRETTY_PRINT)) . '</textarea></td>';
        print '</tr>';
    }
    print '</table>';
    print '</div>';
}

dol_fiche_end();
llxFooter();
$db->close();