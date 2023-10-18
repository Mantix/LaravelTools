<?php
$host = "";       // Database host
$dbname = "";     // Database name
$username = "";   // Database username
$password = "";   // Database password
$prefix = "";     // Database table prefix

$dataTypeMapping = [
    'tinyint() unsigned' => 'unsignedTinyInteger',
    'tinyint()' => 'tinyInteger',
    'smallint() unsigned' => 'unsignedSmallInteger',
    'smallint()' => 'smallInteger',
    'mediumint() unsigned' => 'unsignedMediumInteger',
    'mediumint()' => 'mediumInteger',
    'bigint() unsigned' => 'unsignedBigInteger',
    'bigint()' => 'bigInteger',
    'int() unsigned' => 'unsignedInteger',
    'int()' => 'integer',
    'varchar()' => 'string',
    'mediumtext' => 'mediumText',
    'longtext' => 'longText',
    'text' => 'text',
    'char()' => 'char',
    'date' => 'date',
    'time' => 'time',
    'datetime' => 'dateTime',
    // Add more data types as needed
];

function camelCaseTableName(string $tableName): string {
    return str_replace(' ', '', ucwords(str_replace('_', ' ', $tableName)));
}

// Create a temporary directory to store model files
$zipDir = __DIR__ . '/temp_models/';
if (!is_dir($zipDir)) {
    mkdir($zipDir);
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

$tableData = array();
foreach ($tables as $table) {
    if (!isset($tableData[$table])) {
        $tableData[$table] = array(
            'name' => $table,
            'columns' => array(),
            'belongsToClasses' => array(),
            'hasManyClasses' => array(),
        );
    }

    $columns = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        $tableData[$table]['columns'][] = "'" . $column['Field'] . "'";
    }

    $foreignKeys = $pdo->query("SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = '$dbname' AND TABLE_NAME = '$table'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($foreignKeys as $foreignKey) {
        $tableData[$table]['belongsToClasses'][] = array(
            'localColumnName' => $foreignKey['COLUMN_NAME'],
            'referencedTableName' => $foreignKey['REFERENCED_TABLE_NAME'],
            'referencedColumnName' => $foreignKey['REFERENCED_COLUMN_NAME'],
        );

        if (!isset($tableData[$foreignKey['REFERENCED_TABLE_NAME']])) {
            $tableData[$foreignKey['REFERENCED_TABLE_NAME']] = array(
                'name' => $foreignKey['REFERENCED_TABLE_NAME'],
                'columns' => array(),
                'belongsToClasses' => array(),
                'hasManyClasses' => array(),
            );
        }

        $tableData[$foreignKey['REFERENCED_TABLE_NAME']]['hasManyClasses'][] = array(
            'localColumnName' => $foreignKey['REFERENCED_COLUMN_NAME'],
            'referencedTableName' => $table,
            'referencedColumnName' => $foreignKey['COLUMN_NAME'],
        );
    }
}

foreach ($tableData as $tableName => $tableProperties) {
    $tableWithoutPrefix = str_replace($prefix, '', $tableName);
    $tableCamelCase = camelCaseTableName($tableWithoutPrefix);

    $modelContent = "<?php\n\n";
    $modelContent .= "namespace App\Models;\n\n";
    $modelContent .= "use Illuminate\Database\Eloquent\Model;\n\n";
    $modelContent .= "class " . $tableCamelCase . " extends Model\n";
    $modelContent .= "{\n";

    // Generate $fillable property in the model
    $modelContent .= "    protected \$fillable = [" . implode(', ', $tableProperties['columns']) . "];\n\n";

    // Generate empty $hidden property in the model
    $modelContent .= "    protected \$hidden = [];\n\n";

    // Generate belongsTo relationship function
    foreach ($tableProperties['belongsToClasses'] as $belongsToClass) {
        $referencedTableWithoutPrefix = str_replace($prefix, '', $belongsToClass['referencedTableName']);
        $referencedTableCamelCase = camelCaseTableName($referencedTableWithoutPrefix);
        $localColumnName = $belongsToClass['localColumnName'];
        $referencedColumnName = $belongsToClass['referencedColumnName'];

        $modelContent .= "    public function " . lcfirst($referencedTableCamelCase) . "() {\n";
        $modelContent .= "        return \$this->belongsTo(App\\Models\\$referencedTableCamelCase::class, '$localColumnName', '$referencedColumnName');\n";
        $modelContent .= "    }\n\n";
    }

    // Generate belongsTo relationship function
    foreach ($tableProperties['hasManyClasses'] as $hasManyClass) {
        $referencedTableWithoutPrefix = str_replace($prefix, '', $hasManyClass['referencedTableName']);
        $referencedTableCamelCase = camelCaseTableName($referencedTableWithoutPrefix);
        $localColumnName = $hasManyClass['localColumnName'];
        $referencedColumnName = $hasManyClass['referencedColumnName'];

        $modelContent .= "    public function " . lcfirst($referencedTableCamelCase) . "s() {\n";
        $modelContent .= "        return \$this->hasMany(App\\Models\\$referencedTableCamelCase::class, '$localColumnName', '$referencedColumnName');\n";
        $modelContent .= "    }\n\n";
    }

    $modelContent .= "}\n";

    // Save the model to a file
    file_put_contents($zipDir . $tableCamelCase . '.php', $modelContent);
}

// Create a zip archive
$zip = new ZipArchive();
$zipFileName = 'models.zip';

if ($zip->open($zipFileName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    // Add model files to the zip archive
    $modelFiles = glob($zipDir . '*.php');
    foreach ($modelFiles as $file) {
        $zip->addFile($file, basename($file));
    }

    $zip->close();

    // Delete temporary model files
    foreach ($modelFiles as $file) {
        unlink($file);
    }

    // Prompt the browser to download the zip file
    header('Content-Type: application/zip');
    header("Content-Disposition: attachment; filename=\"$zipFileName\"");
    header('Content-Length: ' . filesize($zipFileName));
    readfile($zipFileName);

    // Clean up by deleting the zip file
    unlink($zipFileName);
} else {
    echo 'Failed to create the zip archive.';
}

// Remove the temporary directory
rmdir($zipDir);
