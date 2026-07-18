<?php
// Daily Log Check-in Handler
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

// Check login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'User not authenticated.']);
    exit;
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mood_score = intval($_POST['mood_score'] ?? 3);
    $craving_level = intval($_POST['craving_level'] ?? 5);
    $clean_status = intval($_POST['clean_status'] ?? 1); // 1 = Clean, 0 = Relapsed
    $notes = trim($_POST['notes'] ?? '');
    
    // Validate inputs
    if ($mood_score < 1 || $mood_score > 5) $mood_score = 3;
    if ($craving_level < 1 || $craving_level > 10) $craving_level = 5;
    if ($clean_status !== 0 && $clean_status !== 1) $clean_status = 1;
    
    $today = date('Y-m-d');
    
    try {
        // Check if a log already exists for today
        $stmt = $pdo->prepare("SELECT id FROM daily_logs WHERE user_id = ? AND log_date = ?");
        $stmt->execute([$user_id, $today]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update today's log
            $stmt = $pdo->prepare("UPDATE daily_logs SET mood_score = ?, craving_level = ?, clean_status = ?, notes = ? WHERE id = ?");
            $stmt->execute([$mood_score, $craving_level, $clean_status, $notes, $existing['id']]);
        } else {
            // Insert new log
            $stmt = $pdo->prepare("INSERT INTO daily_logs (user_id, log_date, mood_score, craving_level, clean_status, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $today, $mood_score, $craving_level, $clean_status, $notes]);
        }

        // We can also trigger a dynamic update to the Coach's status message
        // Let's generate a quick status comment from the coach depending on today's logs
        update_coach_weekly_status($pdo, $user_id, $mood_score, $craving_level, $clean_status);
        
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

/**
 * Updates the coach's direct status comment depending on today's daily log
 */
function update_coach_weekly_status($pdo, $user_id, $mood, $craving, $clean) {
    // Generate context-aware coaching encouragement
    if ($clean === 0) {
        $status = "I see today was tough, and you had a slip. Please don't be discouraged—recovery isn't linear. Let's talk in the Coach tab. I want to help you unpack what triggered this craving and adapt our plan to keep you safe.";
    } elseif ($craving >= 7) {
        $status = "Great job staying clean today, but I notice your craving intensity is very high ($craving/10). That takes immense strength. Make sure to use your replacement habits (like deep breathing or drinking cold water) and reach out to me in chat if you need support!";
    } elseif ($mood <= 2) {
        $status = "You stayed clean today, but your mood is feeling low. Remember that emotional shifts are common as your body adjusts. Focus on rest and gentle self-care today. You are doing amazing work.";
    } else {
        $status = "Another successful clean day! Your mood is positive and cravings are manageable. This consistent progress builds momentum. Keep following the plan, you are winning this battle!";
    }
    
    try {
        // Update the coach_status of the latest plan
        $stmt = $pdo->prepare("UPDATE plans SET coach_status = ? WHERE user_id = ? AND id = (SELECT max(id) FROM plans WHERE user_id = ?)");
        $stmt->execute([$status, $user_id, $user_id]);
    } catch (PDOException $e) {
        // Ignore
    }
}
?>
