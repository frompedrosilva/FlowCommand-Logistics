<?php
require 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Check against flow_users table
    $stmt = $pdo->prepare("SELECT * FROM flow_users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Set Session Data
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = [
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'avatarUrl' => $user['avatar_url'],
            'phone' => $user['phone']
        ];
        $_SESSION['last_activity'] = time();

        echo json_encode([
            'success' => true,
            'data' => [
                'user' => $_SESSION['user']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
}
?>