<?php

$host = 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com';
$port = 4000;
$db   = 'reozom_plugging_db';
$user = 'jXL4hCqbduoKnk5.root';
$pass = 'ERSE7wXkBWD0sbey';
$ca   = __DIR__ . DIRECTORY_SEPARATOR . 'isrgrootx1.pem';

echo "Testing connection with CA: " . $ca . "\n";
echo "File exists: " . (file_exists($ca) ? 'YES' : 'NO') . "\n";

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE                      => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE           => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_SSL_CA                 => $ca,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "SUCCESS: Connected to TiDB Cloud Serverless successfully!\n";
    $stmt = $pdo->query("SELECT VERSION() as v");
    $row = $stmt->fetch();
    echo "TiDB Version: " . $row['v'] . "\n";
} catch (\PDOException $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
