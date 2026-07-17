<?php
require 'db.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

// --- Helper Functions ---

function addToDockHistory($pdo, $dockId, $action, $user) {
    if (!$dockId) return;
    
    // Fetch current dock data
    $stmt = $pdo->prepare("SELECT data FROM flow_docks WHERE id = ?");
    $stmt->execute([$dockId]);
    $row = $stmt->fetch();
    
    if ($row && $row['data']) {
        $data = json_decode($row['data'], true);
        $history = $data['history'] ?? [];
        
        $history[] = [
            'user' => $user,
            'action' => $action,
            'timestamp' => date('c')
        ];
        
        // Keep history manageable
        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }
        
        $data['history'] = $history;
        $newData = json_encode($data);
        
        $stmtUpdate = $pdo->prepare("UPDATE flow_docks SET data = ? WHERE id = ?");
        $stmtUpdate->execute([$newData, $dockId]);
    }
}

// --- Session Management ---

if ($action === 'check_session') {
    $timeout = 1800; // 30 minutes
    
    if (isset($_SESSION['user_id'])) {
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            // Session expired
            session_unset();
            session_destroy();
            echo json_encode(['success' => false, 'message' => 'Session expired']);
        } else {
            // Session valid
            $_SESSION['last_activity'] = time(); // Renew timer
            echo json_encode(['success' => true, 'user' => $_SESSION['user']]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($action === 'logout') {
    session_unset();
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// --- User Management ---

if ($action === 'create_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    $name = $input['name'] ?? '';
    $role = $input['role'] ?? 'Operator';
    $email = $input['email'] ?? '';
    $phone = $input['phone'] ?? '';

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Username and password required']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO flow_users (username, password, name, role, email, phone) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $hash, $name, $role, $email, $phone]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
        } else {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    exit;
}

if ($action === 'save_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // This handles editing other users
    $id = $input['id'] ?? null;
    $name = $input['name'] ?? '';
    $role = $input['role'] ?? 'Operator';
    $email = $input['email'] ?? '';
    $phone = $input['phone'] ?? '';
    $password = $input['password'] ?? '';

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit;
    }

    try {
        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE flow_users SET name=?, role=?, email=?, phone=?, password=? WHERE id=?");
            $stmt->execute([$name, $role, $email, $phone, $hash, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE flow_users SET name=?, role=?, email=?, phone=? WHERE id=?");
            $stmt->execute([$name, $role, $email, $phone, $id]);
        }
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $input['id'] ?? null;
    if (!$id) { echo json_encode(['success' => false]); exit; }
    
    // Prevent self-delete logic should happen on frontend, but good to check here too
    if ($_SESSION['user_id'] == $id) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete yourself']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM flow_users WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Updates the CURRENTLY logged in user
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }

    $name = $input['name'] ?? '';
    $phone = $input['phone'] ?? '';
    $avatar = $input['avatarUrl'] ?? '';
    
    $stmt = $pdo->prepare("UPDATE flow_users SET name = ?, phone = ?, avatar_url = ? WHERE id = ?");
    $stmt->execute([$name, $phone, $avatar, $_SESSION['user_id']]);
    
    // Update session
    $_SESSION['user']['name'] = $name;
    $_SESSION['user']['phone'] = $phone;
    $_SESSION['user']['avatarUrl'] = $avatar;
    
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'get_users') {
    // Only return non-sensitive data
    $stmt = $pdo->query("SELECT id, username, name, role, email, phone, avatar_url FROM flow_users ORDER BY id DESC");
    $users = $stmt->fetchAll();
    echo json_encode(['success' => true, 'data' => $users]);
    exit;
}

// --- Data Operations ---

if ($action === 'get_data') {
    // Fetch Trucks
    $stmt = $pdo->query("SELECT * FROM flow_trucks");
    $dbTrucks = $stmt->fetchAll();
    $trucks = [];
    foreach ($dbTrucks as $row) {
        $truckObj = json_decode($row['data'], true);
        if (!$truckObj) continue;
        // Ensure sync
        $truckObj['id'] = $row['id'];
        $truckObj['plate'] = $row['plate'];
        $truckObj['status'] = $row['status'];
        $truckObj['dockId'] = $row['dock_id'];
        $truckObj['hidden'] = (bool)$row['hidden'];
        $trucks[] = $truckObj;
    }

    // Fetch Docks
    $stmt = $pdo->query("SELECT * FROM flow_docks");
    $dbDocks = $stmt->fetchAll();
    $docks = [];
    foreach ($dbDocks as $row) {
        $dockObj = json_decode($row['data'], true);
        if (!$dockObj) continue;
        $dockObj['id'] = $row['id'];
        $dockObj['status'] = $row['status'];
        $dockObj['assignedTruckId'] = $row['assigned_truck_id'];
        $docks[] = $dockObj;
    }

    echo json_encode([
        'success' => true, 
        'data' => [
            'trucks' => $trucks, 
            'docks' => $docks
        ]
    ]);
    exit;
}

if ($action === 'get_drivers') {
    $stmt = $pdo->query("SELECT * FROM flow_drivers ORDER BY frequency DESC, last_seen DESC");
    $drivers = $stmt->fetchAll();
    echo json_encode(['success' => true, 'data' => $drivers]);
    exit;
}

if ($action === 'save_truck' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $truck = $input;
    $id = $truck['id'];
    $plate = $truck['plate'];
    $carrier = strtoupper($truck['carrier']);
    $truck['carrier'] = $carrier;
    
    $type = $truck['type'];
    $status = $truck['status'];
    $priority = $truck['priority'];
    $eta = isset($truck['eta']) && $truck['eta'] ? date('Y-m-d H:i:s', strtotime($truck['eta'])) : null;
    $actual = isset($truck['actualArrival']) && $truck['actualArrival'] ? date('Y-m-d H:i:s', strtotime($truck['actualArrival'])) : null;
    $dockId = $truck['dockId'] ?? null;
    
    // --- Frequent Driver Saving Logic ---
    if (isset($truck['isFrequentDriver']) && $truck['isFrequentDriver'] && !empty($truck['driverName'])) {
        $dName = trim($truck['driverName']);
        $dPhone = trim($truck['driverPhone'] ?? '');
        $dLang = $truck['driverLanguage'] ?? 'N/A';
        
        try {
            // Check if exist based on name and phone
            $stmtD = $pdo->prepare("INSERT INTO flow_drivers (name, phone, language, last_seen, frequency) 
                                    VALUES (?, ?, ?, NOW(), 1) 
                                    ON DUPLICATE KEY UPDATE 
                                    language = VALUES(language), 
                                    last_seen = NOW(), 
                                    frequency = frequency + 1");
            $stmtD->execute([$dName, $dPhone, $dLang]);
        } catch (Exception $e) {
            // Fail silently on driver save to not block truck registration
        }
    }
    // ------------------------------------

    // --- Audit & Merge Logic ---
    $currentUser = $_SESSION['user']['name'] ?? 'System';
    $now = date('c');

    $stmtCk = $pdo->prepare("SELECT data FROM flow_trucks WHERE id = ?");
    $stmtCk->execute([$id]);
    $existing = $stmtCk->fetch();
    
    $oldDockId = null;
    
    if ($existing && $existing['data']) {
        $existingData = json_decode($existing['data'], true);
        $oldDockId = $existingData['dockId'] ?? null;
        
        // Preserve immutable fields if not in input (defensive)
        $truck['registrationTime'] = $existingData['registrationTime'] ?? $now;
        $truck['createdBy'] = $existingData['createdBy'] ?? $currentUser;
        
        // Append History
        $history = $existingData['history'] ?? [];
        // Only add history if status changed or it's a significant update
        if (($existingData['status'] ?? '') !== $status) {
            $history[] = [
                'user' => $currentUser,
                'action' => "Status changed to $status",
                'timestamp' => $now
            ];
        } else {
             // General Edit
             // Limit history spam: only log if last log was > 5 mins ago or different user
             $lastLog = end($history);
             if (!$lastLog || $lastLog['user'] !== $currentUser || (strtotime($now) - strtotime($lastLog['timestamp']) > 300)) {
                 $history[] = [
                    'user' => $currentUser,
                    'action' => 'Updated details',
                    'timestamp' => $now
                 ];
             }
        }
        $truck['history'] = $history;
        
        // Preserve cancellationTime if present in DB but missing in input (Edge Case Fix)
        if (!isset($truck['cancellationTime']) && isset($existingData['cancellationTime'])) {
            $truck['cancellationTime'] = $existingData['cancellationTime'];
        }

    } else {
        // New Record
        $truck['registrationTime'] = $now;
        $truck['createdBy'] = $currentUser;
        $truck['history'] = [[
            'user' => $currentUser,
            'action' => 'Created registration',
            'timestamp' => $now
        ]];
    }
    
    $truck['lastEditedBy'] = $currentUser;
    $truck['lastEditedAt'] = $now;
    // ---------------------------

    // Logic: If Cancelled or Completed, release dock
    if ($status === 'CANCELLED' || $status === 'COMPLETED') {
        if ($status === 'CANCELLED') {
             $truck['hidden'] = true;
             $priority = 'NORMAL';
             $truck['priority'] = 'NORMAL';
        }
        // Force release dock in truck object
        $truck['dockId'] = null;
        $dockId = null;
    }
    
    // --- Update Dock History based on Truck Movement ---
    if ($oldDockId && $oldDockId !== $dockId) {
        $actionMsg = "Truck {$plate} ({$carrier}) ";
        if ($status === 'COMPLETED') $actionMsg .= "completed/left";
        elseif ($status === 'CANCELLED') $actionMsg .= "cancelled/removed";
        else $actionMsg .= "left/moved";
        
        addToDockHistory($pdo, $oldDockId, $actionMsg, $currentUser);
    }
    
    if ($dockId && $dockId !== $oldDockId) {
        addToDockHistory($pdo, $dockId, "Truck {$plate} ({$carrier}) assigned", $currentUser);
    }
    // ---------------------------------------------------
    
    $hidden = isset($truck['hidden']) && $truck['hidden'] ? 1 : 0;
    $jsonData = json_encode($truck);

    $sql = "INSERT INTO flow_trucks (id, plate, carrier, type, status, priority, eta, actual_arrival, dock_id, hidden, data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            plate=?, carrier=?, type=?, status=?, priority=?, eta=?, actual_arrival=?, dock_id=?, hidden=?, data=?";
            
    $stmt = $pdo->prepare($sql);
    $params = [
        $id, $plate, $carrier, $type, $status, $priority, $eta, $actual, $dockId, $hidden, $jsonData,
        $plate, $carrier, $type, $status, $priority, $eta, $actual, $dockId, $hidden, $jsonData
    ];
    
    try {
        $stmt->execute($params);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_dock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dock = $input;
    $id = $dock['id'];
    $name = $dock['name'];
    $type = $dock['type'];
    $status = $dock['status'];
    $assigned = $dock['assignedTruckId'] ?? null;

    // --- Dock History Audit Logic ---
    $currentUser = $_SESSION['user']['name'] ?? 'System';
    $now = date('c');

    $stmtCk = $pdo->prepare("SELECT data FROM flow_docks WHERE id = ?");
    $stmtCk->execute([$id]);
    $existing = $stmtCk->fetch();

    $history = [];
    if ($existing && $existing['data']) {
        $existingData = json_decode($existing['data'], true);
        $history = $existingData['history'] ?? [];

        $actions = [];
        
        // Track Name Changes
        if (($existingData['name'] ?? '') !== $name) {
            $actions[] = "Renamed: " . ($existingData['name'] ?? 'Unknown') . " -> " . $name;
        }
        
        // Track Type Changes
        if (($existingData['type'] ?? '') !== $type) {
            $actions[] = "Type: " . ($existingData['type'] ?? 'Unknown') . " -> " . $type;
        }

        // Track Status Changes
        if (($existingData['status'] ?? '') !== $status) {
            $msg = "Status: " . ($existingData['status'] ?? 'New') . " -> " . $status;
            if ($status === 'MAINTENANCE' && isset($dock['reopenDate'])) {
                $msg .= " (Until: " . $dock['reopenDate'] . ")";
            }
            $actions[] = $msg;
        }
        
        // Track Truck Assignments via Dock Settings
        $oldAssigned = $existingData['assignedTruckId'] ?? null;
        if ($oldAssigned !== $assigned) {
            if ($assigned) {
                // Fetch truck details for better logging
                $stmtT = $pdo->prepare("SELECT plate, carrier FROM flow_trucks WHERE id = ?");
                $stmtT->execute([$assigned]);
                $tRow = $stmtT->fetch();
                if ($tRow) {
                    $actions[] = "Assigned Truck {$tRow['plate']} ({$tRow['carrier']})";
                } else {
                    $actions[] = "Assigned Truck ID: $assigned";
                }
            } else {
                $actions[] = "Released Truck";
            }
        }
        
        // General Edits
        if (empty($actions)) {
             // If no status/assignment change, log a generic update if notes changed
             if (($existingData['notes'] ?? '') !== ($dock['notes'] ?? '')) {
                 $actions[] = "Notes updated";
             }
        }

        if (!empty($actions)) {
            $history[] = [
                'user' => $currentUser,
                'action' => implode(', ', $actions),
                'timestamp' => $now
            ];
        }
        
        // Keep last 100 history items
        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }

    } else {
        $history[] = ['user' => $currentUser, 'action' => 'Dock Created', 'timestamp' => $now];
    }
    
    $dock['history'] = $history;
    // --------------------------------

    $jsonData = json_encode($dock);

    $sql = "INSERT INTO flow_docks (id, name, type, status, assigned_truck_id, data)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            name=?, type=?, status=?, assigned_truck_id=?, data=?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id, $name, $type, $status, $assigned, $jsonData, $name, $type, $status, $assigned, $jsonData]);
    
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete_dock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $input['id'];
    $stmt = $pdo->prepare("DELETE FROM flow_docks WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>