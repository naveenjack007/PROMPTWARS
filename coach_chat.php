<?php
// AI Coach Chat AJAX Handler
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

// Check login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'User not authenticated.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$age = $_SESSION['age'];
$gender = $_SESSION['gender'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_message = trim($_POST['message'] ?? '');
    
    if (empty($user_message)) {
        echo json_encode(['success' => false, 'error' => 'Message cannot be empty.']);
        exit;
    }

    // Get settings
    $hf_token = get_hf_token($pdo);
    $hf_model = get_ai_model($pdo);
    $ai_provider = get_ai_provider($pdo);
    $gemini_api_key = get_gemini_api_key($pdo);

    if ($ai_provider === 'gemini') {
        if (empty($gemini_api_key)) {
            echo json_encode(['success' => false, 'error' => 'Gemini API Key is missing. Please save it in settings.']);
            exit;
        }
    } else {
        if (empty($hf_token)) {
            echo json_encode(['success' => false, 'error' => 'Hugging Face API Token is missing. Please save it in settings.']);
            exit;
        }
    }

    try {
        // 1. Fetch addiction details
        $stmt = $pdo->prepare("SELECT * FROM addictions WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $addiction = $stmt->fetch();
        $addiction_name = $addiction ? $addiction['addiction_name'] : 'habit';
        $severity = $addiction ? $addiction['severity'] : 'unknown';

        // 2. Fetch plan details
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $plan = $stmt->fetch();
        $plan_text = $plan ? $plan['full_plan_text'] : 'No active plan generated yet.';

        // 3. Fetch recent daily logs (last 5)
        $stmt = $pdo->prepare("SELECT log_date, mood_score, craving_level, clean_status, notes FROM daily_logs WHERE user_id = ? ORDER BY log_date DESC LIMIT 5");
        $stmt->execute([$user_id]);
        $logs = $stmt->fetchAll();
        
        $logs_summary = "";
        if (!empty($logs)) {
            foreach (array_reverse($logs) as $l) {
                $status = $l['clean_status'] == 1 ? "Clean" : "Relapsed/Slipped";
                $notes_str = !empty($l['notes']) ? " (Notes: " . $l['notes'] . ")" : "";
                $logs_summary .= "- Date: {$l['log_date']}, Status: {$status}, Mood: {$l['mood_score']}/5, Craving Level: {$l['craving_level']}/10{$notes_str}\n";
            }
        } else {
            $logs_summary = "No daily logs recorded yet.";
        }

        // 4. Fetch chat history (last 10 messages)
        $stmt = $pdo->prepare("SELECT sender, message FROM chat_messages WHERE user_id = ? ORDER BY id DESC LIMIT 10");
        $stmt->execute([$user_id]);
        $history = array_reverse($stmt->fetchAll());

        // 5. Construct System and Context Prompt
        $system_prompt = "You are BreakFree's personal adaptive de-addiction coach. Your name is 'Coach'.
Your task is to guide the user empathetically, encouraging them to overcome their habit of {$addiction_name} (Severity: {$severity}).
Always use the user's demographic profile (Age: {$age}, Gender: {$gender}), their customized recovery plan, and their recent daily check-in logs to provide context-aware, practical, and highly personalized advice.

Guidelines:
- Be warm, non-judgmental, motivating, and deeply empathetic.
- If they had a relapse (check the logs or user message), reassure them that slips are normal steps in recovery. Focus on identifying triggers and adapting strategies.
- Suggest actionable replacement habits (e.g. mindfulness breathing, drinking water, taking a walk, writing in a journal).
- Keep your replies relatively concise (1-3 paragraphs) so it's easy to read in a chat window.
- Respond in plain text or simple markdown formatting. Do not output metadata or system commands.

User Profile & Context:
- Habit: {$addiction_name}
- Age: {$age}, Gender: {$gender}
- Severity: {$severity}

User's Recovery Plan Outline:
\"\"\"
" . substr($plan_text, 0, 1000) . "... (truncated for length)
\"\"\"

Recent Daily Check-in Logs:
{$logs_summary}";

        // Save user's message to DB
        $stmt = $pdo->prepare("INSERT INTO chat_messages (user_id, sender, message) VALUES (?, 'user', ?)");
        $stmt->execute([$user_id, $user_message]);
        
        $coach_reply = null;

        if ($ai_provider === 'gemini') {
            // Call Gemini API
            $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . urlencode($gemini_api_key);
            
            // Build contents array mapping chat history
            $contents = [];
            
            // Add prior history to the contents array
            foreach ($history as $h) {
                $role = $h['sender'] === 'user' ? 'user' : 'model';
                $contents[] = [
                    "role" => $role,
                    "parts" => [["text" => $h['message']]]
                ];
            }
            
            // Add current message
            $contents[] = [
                "role" => "user",
                "parts" => [["text" => $user_message]]
            ];

            $post_data = [
                "systemInstruction" => [
                    "parts" => [
                        ["text" => $system_prompt]
                    ]
                ],
                "contents" => $contents,
                "generationConfig" => [
                    "temperature" => 0.7,
                    "maxOutputTokens" => 800
                ]
            ];

            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                echo json_encode(['success' => false, 'error' => 'Gemini API connection failed: ' . $curl_error]);
                exit;
            }

            $result = json_decode($response, true);
            if ($http_code !== 200) {
                $error_msg = $result['error']['message'] ?? 'Unknown Gemini API error (Code ' . $http_code . ')';
                echo json_encode(['success' => false, 'error' => 'AI Service Error (Gemini): ' . $error_msg]);
                exit;
            }
            
            $coach_reply = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;

        } else {
            // Call Hugging Face API
            $api_url = "https://router.huggingface.co/v1/chat/completions";
            
            $messages = [
                ["role" => "system", "content" => $system_prompt]
            ];
            
            foreach ($history as $h) {
                $role = $h['sender'] === 'user' ? 'user' : 'assistant';
                $messages[] = ["role" => $role, "content" => $h['message']];
            }
            
            $messages[] = ["role" => "user", "content" => $user_message];

            $post_data = [
                "model" => $hf_model,
                "messages" => $messages,
                "max_tokens" => 800,
                "temperature" => 0.7
            ];

            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $hf_token,
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                echo json_encode(['success' => false, 'error' => 'API connection failed: ' . $curl_error]);
                exit;
            }

            $result = json_decode($response, true);
            
            if ($http_code !== 200) {
                $error_msg = $result['error'] ?? 'Unknown Hugging Face API error (Code ' . $http_code . ')';
                if (is_array($error_msg)) {
                    $error_msg = json_encode($error_msg);
                }
                echo json_encode(['success' => false, 'error' => 'AI Service Error: ' . $error_msg]);
                exit;
            }

            $coach_reply = $result['choices'][0]['message']['content'] ?? null;
        }

        if (empty($coach_reply)) {
            echo json_encode(['success' => false, 'error' => 'No response from the AI coach.']);
            exit;
        }

        // Save coach response to DB
        $stmt = $pdo->prepare("INSERT INTO chat_messages (user_id, sender, message) VALUES (?, 'coach', ?)");
        $stmt->execute([$user_id, $coach_reply]);

        echo json_encode([
            'success' => true,
            'reply' => $coach_reply
        ]);
        exit;

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}
?>
