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
    
    // Simulate query
    $stmt = $pdo->prepare("SELECT id, name FROM listing_processes WHERE service_package_id = 2 AND agent_id = 17 AND status = 'active' LIMIT 1");
    $stmt->execute();
    $process = $stmt->fetch();
    
    echo "Matched process ID: " . ($process ? $process['id'] : 'none') . "\n";
    echo "Matched process Name: " . ($process ? $process['name'] : 'none') . "\n";

} catch (\PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
