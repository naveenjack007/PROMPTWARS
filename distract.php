<?php
// Urgent Distraction Generator Endpoint
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
        // Fetch addiction details
        $stmt = $pdo->prepare("SELECT addiction_name FROM addictions WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $addiction = $stmt->fetch();
        $addiction_name = $addiction ? $addiction['addiction_name'] : 'habit';

        // Build prompts
        $system_prompt = "You are BreakFree's urgent support assistant. When a user experiences an intense craving, your job is to immediately provide a highly engaging, custom 5-minute distraction activity or coping exercise to help them overcome it.
Guidelines:
- Keep it extremely action-oriented and brief.
- Provide 3-4 clear, bulleted steps they can do right now in 5 minutes.
- Tailor the response to their habit ({$addiction_name}), craving level ({$craving_level}/10) and mood ({$mood_score}/5).
- Do not output any introductory or concluding sentences. Start directly with the exercise name and bullet points.";

        $user_prompt = "Habit: {$addiction_name}
Craving Intensity: {$craving_level}/10
Mood: {$mood_score}/5 (where 1 is worst, 5 is best)

Provide the 5-minute distraction activity now.";

        $exercise_text = null;

        if ($ai_provider === 'gemini') {
            $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . urlencode($gemini_api_key);
            $post_data = [
                "contents" => [
                    [
                        "role" => "user",
                        "parts" => [
                            ["text" => $system_prompt . "\n\n" . $user_prompt]
                        ]
                    ]
                ],
                "generationConfig" => [
                    "temperature" => 0.8,
                    "maxOutputTokens" => 500
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
            
            $exercise_text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
        } else {
            // Hugging Face
            $api_url = "https://router.huggingface.co/v1/chat/completions";
            $post_data = [
                "model" => $hf_model,
                "messages" => [
                    ["role" => "system", "content" => $system_prompt],
                    ["role" => "user", "content" => $user_prompt]
                ],
                "max_tokens" => 500,
                "temperature" => 0.8
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

            $exercise_text = $result['choices'][0]['message']['content'] ?? null;
        }

        if (empty($exercise_text)) {
            echo json_encode(['success' => false, 'error' => 'Failed to generate distraction exercise.']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'distraction' => $exercise_text
        ]);
        exit;

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}
?>
