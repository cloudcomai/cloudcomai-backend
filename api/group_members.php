<?php
require __DIR__ . '/../lib/bootstrap.php';

// --- ENFORCE PRODUCTION CORS FOR LOCALHOST DEVELOPMENT ---
$allowed_origins = ['https://cloudcomai.com', 'http://localhost:5173'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
}

// Exit early if browser fires a preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$user = auth_user(); 
$method = $_SERVER['REQUEST_METHOD'];

// Standardize matching database token key index mapping rules
// Swapping out $user['id'] to $user['user_id'] based on your error logs
$currentLoggedInUserId = $user['user_id'] ?? $user['id'] ?? 0;
error_log("Logged-in User Payload before get: " . json_encode($user));

// 1. CHANNELS STATE LISTING
if ($method === 'GET') {
    $chat_id = (int)($_GET['chat_id'] ?? 0);
    
    // 🔍 DUMP RUNTIME DATA INTO THE SERVER ERROR LOG
    error_log("====== CLOUDCOMAI DEBUG START ======");
    error_log("Incoming Request - Chat ID: " . $chat_id);
    error_log("Logged-in User Payload: " . json_encode($user));
    
    $userIdValue = $user['id'] ?? 0;
    $userAlphanumericId = $user['user_id'] ?? '';
    
    // Check membership relationship status in database
    $m = db()->prepare('SELECT role, status FROM chat_members WHERE chat_id = ? AND (user_id = ? OR user_id = ?)');
    $m->execute([$chat_id, $userIdValue, $userAlphanumericId]);
    $db_row = $m->fetch();
    
    if (!$db_row) {
        error_log("❌ CRITICAL: No row found in chat_members for Chat ID: $chat_id and User ID: $userIdValue / $userAlphanumericId");
        error_log("====== CLOUDCOMAI DEBUG END ======");
        fail('Unauthorized access to group data. No member row exists.', 403);
    }
    
    error_log("Found member row: " . json_encode($db_row));
    
    if ($db_row['status'] !== 'active') {
        error_log("❌ CRITICAL: Member status is '" . $db_row['status'] . "' instead of 'active'");
        error_log("====== CLOUDCOMAI DEBUG END ======");
        fail('Unauthorized access to group data. Status is not active.', 403);
    }
    
    error_log("✅ SUCCESS: User is authorized.");
    error_log("====== CLOUDCOMAI DEBUG END ======");

    // Pull active members listing joined with full username profiles
    $st = db()->prepare('SELECT cm.user_id, cm.role, u.name, u.user_id as username FROM chat_members cm JOIN users u ON (u.id = cm.user_id OR u.user_id = cm.user_id) WHERE cm.chat_id=? AND cm.status="active"');
    $st->execute([$chat_id]);
    
    out(['members' => $st->fetchAll()]);
}


// 2. MEMBER STATUS MANIPULATION (ADD / REMOVE)
if ($method === 'POST') {
    // Read raw input payload parameters
    $d = input();
    $chat_id = (int)($d['chat_id'] ?? 0);
    $target_user_id = (int)($d['user_id'] ?? 0); // Kept integer reference parameter
    $action = (string)($d['action'] ?? 'add'); 

    // Extract current logged-in identity string parameters cleanly
    $currentSessionUserId = $user['id'] ?? $user['user_id'] ?? 0;

    // 🔍 DUMP RUNTIME DATA INTO THE SERVER ERROR LOG
    error_log("====== CLOUDCOMAI POST MEMBER ACTION START ======");
    error_log("Action Type: [" . $action . "] | Target Chat ID: [" . $chat_id . "] | Target User ID: [" . $target_user_id . "]");
    error_log("Raw Session User Array: " . json_encode($user));
    error_log("Extracted Executing User ID: [" . $currentSessionUserId . "]");

    // Step A: Find the executing user's current role inside this chat group
    $m = db()->prepare('SELECT role, status FROM chat_members WHERE chat_id=? AND user_id=? AND status="active"');
    $m->execute([$chat_id, $currentSessionUserId]);
    $currentUserRole = $m->fetch();
    
    error_log("Database Lookup for Executing User Role Result: " . json_encode($currentUserRole));

    if (!$currentUserRole) {
        error_log("❌ SECURITY EXCEPTION: Executing User [$currentSessionUserId] is not an active member of Chat #$chat_id");
        error_log("====== CLOUDCOMAI POST MEMBER ACTION END ======");
        fail('Unauthorized access to group settings', 403);
    }

    // Step B: Handle the ADD user route pipeline
    if ($action === 'add') {
        error_log("Processing 'add' route sequence...");
        
        // 💡 Remember from your GET error log that 'id' was missing from your table schemas. 
        // If your chat_members table has a custom unique primary index key name, we look up by user_id instead.
        $check = db()->prepare('SELECT role, status FROM chat_members WHERE chat_id=? AND user_id=?');
        $check->execute([$chat_id, $target_user_id]);
        $existingRow = $check->fetch();
        
        error_log("Target Check for existing record Result: " . json_encode($existingRow));

        if ($existingRow) {
            error_log("Found existing row context history. Updating status parameters to active...");
            $st = db()->prepare('UPDATE chat_members SET status="active", role="member" WHERE chat_id=? AND user_id=?');
            $st->execute([$chat_id, $target_user_id]);
        } else {
            error_log("No existing historical data context. Executing a clean parameterized SQL Row INSERT statement...");
            $st = db()->prepare('INSERT INTO chat_members (chat_id, user_id, role, status) VALUES (?, ?, "member", "active")');
            $st->execute([$chat_id, $target_user_id]);
        }
        
        error_log("✅ SUCCESS: Target User [$target_user_id] successfully mapped into chat group.");
        error_log("====== CLOUDCOMAI POST MEMBER ACTION END ======");
        out(['status' => 'ok', 'message' => 'Member added successfully']);
    }

    // Step C: Handle the REMOVE user route pipeline
    if ($action === 'remove') {
        error_log("Processing 'remove' route sequence...");
        error_log("Evaluating admin permission privileges. Executor Role: [" . ($currentUserRole['role'] ?? 'none') . "]");

        // Enforce administrative privileges only if deleting a third-party user profile
        if (($currentUserRole['role'] !== 'admin' && $currentUserRole['role'] !== 'owner') && $currentSessionUserId != $target_user_id) {
            error_log("❌ SECURITY EXCEPTION: Executor is not an admin, and is attempting to remove someone else.");
            error_log("====== CLOUDCOMAI POST MEMBER ACTION END ======");
            fail('Only administrators can remove members from this group chat.', 403);
        }
        
        error_log("Executing status update parameters to 'removed'...");
        $st = db()->prepare('UPDATE chat_members SET status="removed" WHERE chat_id=? AND user_id=?');
        $st->execute([$chat_id, $target_user_id]);
        
        error_log("✅ SUCCESS: Target User [$target_user_id] successfully marked as removed.");
        error_log("====== CLOUDCOMAI POST MEMBER ACTION END ======");
        out(['status' => 'ok', 'message' => 'Member removed successfully']);
    }
}
fail('Method not allowed', 405);
