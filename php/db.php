<?php
session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$host = 'host';
$db   = 'db';
$user = 'user';
$pass = 'pass';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // --- Auto-Initialize Tables ---
    
    // Users Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS flow_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        name VARCHAR(100),
        role VARCHAR(50),
        email VARCHAR(100),
        phone VARCHAR(50),
        avatar_url VARCHAR(255)
    )");

    // Migration: Check if phone exists, if not add it (For existing installs)
    $stmt = $pdo->query("SHOW COLUMNS FROM flow_users LIKE 'phone'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE flow_users ADD COLUMN phone VARCHAR(50)");
    }

    // Trucks Table 
    $pdo->exec("CREATE TABLE IF NOT EXISTS flow_trucks (
        id VARCHAR(50) PRIMARY KEY,
        plate VARCHAR(20),
        carrier VARCHAR(100),
        type VARCHAR(50),
        status VARCHAR(50),
        priority VARCHAR(20),
        eta DATETIME,
        actual_arrival DATETIME NULL,
        dock_id VARCHAR(50) NULL,
        hidden BOOLEAN DEFAULT 0,
        data JSON, 
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Docks Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS flow_docks (
        id VARCHAR(50) PRIMARY KEY,
        name VARCHAR(100),
        type VARCHAR(50),
        status VARCHAR(50),
        assigned_truck_id VARCHAR(50) NULL,
        data JSON
    )");

    // Drivers Table (New)
    $pdo->exec("CREATE TABLE IF NOT EXISTS flow_drivers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100),
        phone VARCHAR(50),
        language VARCHAR(50),
        notes TEXT,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        frequency INT DEFAULT 1,
        UNIQUE KEY unique_driver (name, phone)
    )");

    // --- Seed Default Admin User ---
    $stmt = $pdo->query("SELECT COUNT(*) FROM flow_users");
    if ($stmt->fetchColumn() == 0) {
        $username = 'frompedrosilva';
        $passwordRaw = 'PASS';
        $hash = password_hash($passwordRaw, PASSWORD_DEFAULT);
        
        $sql = "INSERT INTO flow_users (username, password, name, role, email) VALUES (?, ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([$username, $hash, 'Pedro Silva', 'Junior Traffic Controller', 'flow@frompdrosilva.nl']);
    }

} catch (\PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB Connection Failed: ' . $e->getMessage()]);
    exit;
}
?>