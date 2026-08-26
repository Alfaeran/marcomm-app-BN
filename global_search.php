<?php
require_once 'config/database.php';
$search = '1740059345';
$tables_result = $mysqli->query("SHOW TABLES");
while ($table_row = $tables_result->fetch_array()) {
    $table = $table_row[0];
    $cols_result = $mysqli->query("DESCRIBE $table");
    $where = [];
    while ($col_row = $cols_result->fetch_assoc()) {
        $where[] = "`" . $col_row['Field'] . "` LIKE '%$search%'";
    }
    if (!empty($where)) {
        $query = "SELECT * FROM $table WHERE " . implode(" OR ", $where);
        $res = $mysqli->query($query);
        if ($res && $res->num_rows > 0) {
            echo "--- Table: $table ---\n";
            while ($row = $res->fetch_assoc()) {
                print_r($row);
            }
        }
    }
}
?>
