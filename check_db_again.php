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
    
    echo "--- LISTING PROCESSES ---\n";
    $stmt = $pdo->query("SELECT id, name, agent_id, service_package_id, status FROM listing_processes");
    while ($row = $stmt->fetch()) {
        echo "ID: {$row['id']} | Name: {$row['name']} | Agent: {$row['agent_id']} | Package: {$row['service_package_id']} | Status: {$row['status']}\n";
    }

} catch (\PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
