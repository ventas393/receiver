<?php
$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res) die("Error: No se pudo encontrar main.inc.php");

$langs->loadLangs(array("bills", "companies"));
llxHeader('', "Importar CSV DIAN", '');

echo "<h1>Importar Reporte DIAN (Semicolon CSV)</h1>";

if (GETPOST('action') == 'import' && !empty($_FILES['csv_file']['tmp_name'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, "r");
    
    $count_ok = 0;
    $count_exists = 0;
    $row = 0;

    while (($data = fgetcsv($handle, 5000, ";")) !== FALSE) { 
        $row++;
        if ($row == 1) continue; // Saltar cabecera

        $cufe = isset($data[1]) ? trim($data[1]) : ''; 
        if (empty($cufe) || strlen($cufe) < 20) continue;

        // Verificar duplicados por CUFE
        $sql_check = "SELECT cufe FROM llxu3_dian_excel_import WHERE cufe = '".$db->escape($cufe)."'";
        $res_check = $db->query($sql_check);
        if ($db->num_rows($res_check) > 0) {
            $count_exists++;
            continue;
        }

        // Limpieza de valores numéricos (convertir "19478, 92" a 19478.92)
        $clean_iva = (float)str_replace(' ', '', str_replace(',', '.', $data[13]));
        $clean_total = (float)str_replace(' ', '', str_replace(',', '.', $data[29]));
        
        // Formatear fecha (D-M-Y a Y-M-D para MySQL)
        $date_raw = $data[7];
        $date_formatted = date("Y-m-d", strtotime($date_raw));

        $sql = "INSERT INTO llxu3_dian_excel_import (tipo_documento, prefijo, folio, cufe, fecha_emision, nit_emisor, nombre_emisor, iva, total_factura, estado_documento) ";
        $sql .= "VALUES (";
        $sql .= "'".$db->escape($data[0])."', ";
        $sql .= "'".$db->escape($data[3])."', ";
        $sql .= "'".$db->escape($data[2])."', ";
        $sql .= "'".$db->escape($cufe)."', ";
        $sql .= "'".$db->escape($date_formatted)."', ";
        $sql .= "'".$db->escape($data[9])."', ";
        $sql .= "'".$db->escape($data[10])."', ";
        $sql .= $clean_iva.", ";
        $sql .= $clean_total.", ";
        $sql .= "'".$db->escape($data[30])."'";
        $sql .= ")";

        if ($db->query($sql)) $count_ok++;
    }
    fclose($handle);
    setEventMessages("Importación exitosa: $count_ok cargados, $count_exists duplicados.", null, 'mesgs');
}

echo '<form method="post" enctype="multipart/form-data" action="'.$_SERVER["PHP_SELF"].'">';
echo '<input type="hidden" name="token" value="'.newToken().'">';
echo '<input type="hidden" name="action" value="import">';
echo '<table class="noborder" width="100%">';
echo '<tr class="liste_titre"><td>Subir archivo CSV (separado por punto y coma ;)</td></tr>';
echo '<tr><td><input type="file" name="csv_file" accept=".csv" required></td></tr>';
echo '<tr><td><input type="submit" class="button" value="Sincronizar Datos"></td></tr>';
echo '</table></form>';

llxFooter();
