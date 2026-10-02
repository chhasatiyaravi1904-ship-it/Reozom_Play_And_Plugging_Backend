<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = Illuminate\Support\Facades\DB::select('SHOW TABLES');
$markdown = "# Database Schema\n\n";

foreach ($tables as $table) {
    $tableName = array_values((array)$table)[0];
    $markdown .= "## Table: $tableName\n\n";
    $markdown .= "| Column | Type | Null | Key | Default | Extra |\n";
    $markdown .= "|---|---|---|---|---|---|\n";
    
    $columns = Illuminate\Support\Facades\DB::select("SHOW COLUMNS FROM $tableName");
    foreach ($columns as $column) {
        $markdown .= sprintf(
            "| %s | %s | %s | %s | %s | %s |\n",
            $column->Field,
            $column->Type,
            $column->Null,
            $column->Key,
            $column->Default ?? 'NULL',
            $column->Extra
        );
    }
    $markdown .= "\n";
}

file_put_contents('schema.md', $markdown);
echo 'Generated schema.md';
