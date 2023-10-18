<?php

$host = "localhost";       // Database host
$dbname = "";       // Database name
$username = "";   // Database username
$password = "";   // Database password
$prefix = ""; // Database table prefix

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

// Create a temporary directory to store migration files
$zipDir = __DIR__ . '/temp_migrations/';
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

foreach ($tables as $table) {
    $tableWithoutPrefix = str_replace($prefix, '', $table);
    $migrationName = 'Create' . ucwords($tableWithoutPrefix) . 'Table';
    $fileName = date('Y_m_d_His') . '_create_' . strtolower($tableWithoutPrefix) . '_table.php';

    $columns = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);

    $migrationContent = '<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ' . $migrationName . ' extends Migration {
    public function up()     {
        Schema::create(\'' . $tableWithoutPrefix . '\', function (Blueprint $table) {' . "\n";

    foreach ($columns as $column) {
        $dataType = preg_replace('/[0-9]+/', '', $column['Type']);
        $method = $dataTypeMapping[$dataType] ?? die('NO METHOD FOUND FOR ' . $dataType);

        $migrationContent .= "            \$table->$method('{$column['Field']}'";

        if ($method === 'string' && strpos($column['Type'], 'char') !== false) {
            $length = intval(preg_replace('/[^0-9]/', '', $column['Type']));
            $migrationContent .= ", $length";
        }

        if ($column['Null'] == 'NO') {
            $migrationContent .= ')->nullable(false)';
        } else {
            $migrationContent .= ')->nullable(true)';
        }

        if ($column['Default'] !== null) {
            $migrationContent .= "->default('{$column['Default']}')";
        }

        if ($column['Extra'] == 'auto_increment') {
            $migrationContent .= '->autoIncrement()';
        }

        $migrationContent .= ';' . "\n";
    }

    $keys = $pdo->query("SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = '$dbname' AND TABLE_NAME = '$table'")->fetchAll(PDO::FETCH_ASSOC);

    if (count($keys)) {
        $migrationContent .= "\n";
        foreach ($keys as $key) {
            $migrationContent .= '            $table->foreign(\'' . $key['COLUMN_NAME'] . '\')->references(\'' . $key['REFERENCED_COLUMN_NAME'] . '\')->on(\'' . str_replace($prefix, '', $key['REFERENCED_TABLE_NAME']) . '\');' . "\n";
        }
    }

    $migrationContent .= '        });' . "\n";
    $migrationContent .= '    }' . "\n";

    $migrationContent .= '    public function down() {
        Schema::dropIfExists(\'' . $tableWithoutPrefix . '\');
    }
}
';

    file_put_contents($zipDir . $fileName, $migrationContent);
}

// Create a zip archive
$zip = new ZipArchive();
$zipFileName = 'migrations.zip';

if ($zip->open($zipFileName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    // Add migration files to the zip archive
    $migrationFiles = glob($zipDir . '*.php');
    foreach ($migrationFiles as $file) {
        $zip->addFile($file, basename($file));
    }

    $zip->close();

    // Delete temporary migration files
    foreach ($migrationFiles as $file) {
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
