<?php
$host = '127.0.0.1';
$db   = 'reozom_play_plugin';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

$stmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$db' ORDER BY TABLE_NAME, ORDINAL_POSITION");

$columns = $stmt->fetchAll();

$markdown = "# Database Schema ($db)\n\n";

$tables = [];
foreach ($columns as $row) {
    $tables[$row['TABLE_NAME']][] = $row;
}

foreach ($tables as $tableName => $cols) {
    $markdown .= "## Table: `$tableName`\n\n";
    $markdown .= "| Column | Data Type | Nullable | Key | Default | Extra |\n";
    $markdown .= "|---|---|---|---|---|---|\n";
    foreach ($cols as $col) {
        $default = $col['COLUMN_DEFAULT'] ?? 'NULL';
        $markdown .= sprintf("| %s | %s | %s | %s | %s | %s |\n",
            $col['COLUMN_NAME'],
            $col['DATA_TYPE'],
            $col['IS_NULLABLE'],
            $col['COLUMN_KEY'],
            $default,
            $col['EXTRA']
        );
    }
    $markdown .= "\n";
}

file_put_contents('schema_pdo.md', $markdown);
echo "Generated schema_pdo.md";
