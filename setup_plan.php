<?php
// Onboarding Form & Plan Generator
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sentiment_analyzer.php';

// Check login and verify user exists in DB
if (!isset($_SESSION['user_id'])) {
    session_write_close();
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Verify user exists in the database (handles local database resets/deletions)
$stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
if (!$stmt->fetch()) {
    $_SESSION = [];
    session_destroy();
    session_write_close();
    if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Your session is invalid or the database was reset. Please refresh the page and sign up again.']);
    } else {
        header("Location: index.php");
    }
    exit;
}

$username = $_SESSION['username'];
$age = $_SESSION['age'];
$gender = $_SESSION['gender'];

// Check settings
$hf_token = get_hf_token($pdo);
$hf_model = get_ai_model($pdo);
$ai_provider = get_ai_provider($pdo);
$gemini_api_key = get_gemini_api_key($pdo);

// Handle Save Token from AJAX/Post
if (isset($_POST['save_token'])) {
    $hf_token_post = trim($_POST['hf_token'] ?? '');
    $hf_model_post = trim($_POST['hf_model'] ?? 'Qwen/Qwen2.5-7B-Instruct');
    $ai_provider_post = trim($_POST['ai_provider'] ?? 'huggingface');
    $gemini_key_post = trim($_POST['gemini_api_key'] ?? '');

    $settings_to_save = [
        'hf_token' => $hf_token_post,
        'hf_model' => $hf_model_post,
        'ai_provider' => $ai_provider_post,
        'gemini_api_key' => $gemini_key_post
    ];

    try {
        $pdo->beginTransaction();
        foreach ($settings_to_save as $key => $val) {
            try {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                       ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
                $stmt->execute([$key, $val]);
            } catch (PDOException $ex) {
                // Fallback for MySQL
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                                       ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, $val]);
            }
            $_SESSION[$key] = $val;
        }
        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Database storage failed: ' . $e->getMessage()]);
        exit;
    }
}

// Handle Plan Generation AJAX Request
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $addiction = trim($_POST['addiction_name'] ?? '');
    $how_started = trim($_POST['how_started'] ?? '');
    $when_started = trim($_POST['when_started'] ?? '');
    $severity = trim($_POST['severity'] ?? 'Medium');
    $timeframe_days = intval($_POST['timeframe_days'] ?? 30);

    if (empty($addiction) || empty($how_started) || empty($when_started)) {
        echo json_encode(['success' => false, 'error' => 'Please fill in all details about your habit.']);
        exit;
    }

    if ($ai_provider === 'gemini') {
        if (empty($gemini_api_key)) {
            echo json_encode(['success' => false, 'error' => 'Gemini API Key is missing. Please save it in Settings (top right).']);
            exit;
        }
    } else {
        if (empty($hf_token)) {
            echo json_encode(['success' => false, 'error' => 'Hugging Face API Token is missing. Please save your API Token in Settings (top right).']);
            exit;
        }
    }

    // 1. Calculate duration in months
    $start_date = new DateTime($when_started);
    $now_date = new DateTime();
    $interval = $start_date->diff($now_date);
    $duration_months = ($interval->y * 12) + $interval->m;
    if ($duration_months <= 0) $duration_months = 1;

    // 2. Local Sentiment Analysis
    $sentiment = SentimentAnalyzer::analyze($how_started);
    $sentiment_score = $sentiment['score'];
    $sentiment_label = $sentiment['label'];
    $sentiment_desc = $sentiment['analysis'];

    // 3. Prepare AI Prompt for Hugging Face / Gemini
    $target_date = (new DateTime())->modify("+$timeframe_days days")->format('Y-m-d');
    
    $system_prompt = "You are an expert personal adaptive de-addiction coach. Your task is to design a highly personalized, structured, and realistic de-addiction plan based on the user's profile and sentiment analysis.
Your plan MUST:
1. Be structured for a timeframe of exactly $timeframe_days days.
2. Be broken down into clear, actionable phases: Phase 1 (Preparation), Phase 2 (Active Reduction), Phase 3 (Stabilization/Relapse Prevention).
3. Be empathetic, encouraging, and written in Markdown. Use headers, bullet points, and highlight key tools.
4. Include clear daily/weekly goals, replacement habits (e.g. mindfulness, healthy habits, walking), and coping triggers.
Do not output any introductory or concluding chatter. Start directly with the plan title.";

    $user_prompt = "User Profile:
- Gender: $gender, Age: $age
- Habit/Addiction: $addiction
- How it started: \"$how_started\"
- When it started: $when_started (Duration: $duration_months months)
- Severity Level: $severity
- Timeframe to be clean: $timeframe_days days
- Detected User Sentiment: $sentiment_label (Self-reflection assessment: $sentiment_desc)

Please generate the de-addiction plan now.";

    $plan_text = null;

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
                "temperature" => 0.7,
                "maxOutputTokens" => 2048
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
        
        $plan_text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
    } else {
        // 4. API Request to Hugging Face Serverless Chat Completion API
        $api_url = "https://router.huggingface.co/v1/chat/completions";
        $post_data = [
            "model" => $hf_model,
            "messages" => [
                ["role" => "system", "content" => $system_prompt],
                ["role" => "user", "content" => $user_prompt]
            ],
            "max_tokens" => 2048,
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

        $plan_text = $result['choices'][0]['message']['content'] ?? null;
    }

    if (empty($plan_text)) {
        echo json_encode(['success' => false, 'error' => 'Failed to parse AI response. Response details: ' . substr($response, 0, 300)]);
        exit;
    }

    // 5. Save to Database
    try {
        $pdo->beginTransaction();

        // Save addiction details
        $stmt = $pdo->prepare("INSERT INTO addictions (user_id, addiction_name, how_started, when_started, duration_months, severity) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $addiction, $how_started, $when_started, $duration_months, $severity]);

        // Initial coach status message
        $initial_coach_msg = "Hello! I am your adaptive de-addiction coach. I've designed a specialized $timeframe_days-day plan for your recovery from $addiction. Based on your inputs, I notice that you are approaching this with a $sentiment_label mindset. Let's work together step-by-step. Remember to log your progress daily so I can adjust your goals!";

        // Save generated plan
        $stmt = $pdo->prepare("INSERT INTO plans (user_id, target_date, sentiment_score, sentiment_label, raw_sentiment_analysis, full_plan_text, coach_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $target_date, $sentiment_score, $sentiment_label, $sentiment_desc, $plan_text, $initial_coach_msg]);

        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Database storage failed: ' . $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Plan | BreakFree</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="gradient-bg-glow"></div>
    
    <header class="app-header">
        <div class="header-wrap">
            <a href="dashboard.php" class="logo-area">
                <span class="logo-icon">🌿</span>
                <span class="logo-text">Break<span class="logo-span">Free</span></span>
            </a>
            <nav class="main-nav">
                <button class="nav-link" onclick="openSettingsModal()">⚙️ Settings</button>
                <a href="auth.php?action=logout" class="nav-link btn-logout">Logout</a>
            </nav>
        </div>
    </header>

    <div class="app-container" style="max-width: 680px; margin-top: 1.5rem;">
        
        <div class="card" id="form-card">
            <!-- Progress indicator -->
            <div class="onboarding-steps">
                <div class="step-dot active" id="dot-1"></div>
                <div class="step-dot" id="dot-2"></div>
                <div class="step-dot" id="dot-3"></div>
            </div>

            <!-- Panel 1: Addiction Info -->
            <div class="onboarding-panel active" id="panel-1">
                <h2 class="mb-4">Tell us about your habit</h2>
                <div class="form-group">
                    <label for="addiction_name">What habit/addiction do you want to overcome?</label>
                    <input type="text" id="addiction_name" class="form-control" placeholder="e.g. Cigarettes, Alcohol, Excessive Screen Time, Gaming" required>
                </div>
                <div class="form-group">
                    <label for="severity">How would you rate the severity?</label>
                    <select id="severity" class="form-control">
                        <option value="Low">Low (Occasional/Mild)</option>
                        <option value="Medium" selected>Medium (Regular/Moderate)</option>
                        <option value="High">High (Daily/Severe)</option>
                    </select>
                </div>
                <div style="text-align: right; margin-top: 1.5rem;">
                    <button class="btn btn-primary" onclick="nextStep(2)">Continue</button>
                </div>
            </div>

            <!-- Panel 2: Origin & Sentiment Input -->
            <div class="onboarding-panel" id="panel-2">
                <h2 class="mb-4">Your Journey & Origin</h2>
                <div class="form-group">
                    <label for="when_started">Around when did this habit start?</label>
                    <input type="date" id="when_started" class="form-control" max="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="how_started">How did it start, and how does it make you feel?</label>
                    <textarea id="how_started" class="form-control" placeholder="Tell us your story. e.g., 'Started in college due to peer pressure and stress. I feel tired and guilty every day, but I really want to change...'" required></textarea>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 1.5rem;">
                    <button class="btn btn-secondary" onclick="prevStep(1)">Back</button>
                    <button class="btn btn-primary" onclick="nextStep(3)">Continue</button>
                </div>
            </div>

            <!-- Panel 3: Timeframe & Generate -->
            <div class="onboarding-panel" id="panel-3">
                <h2 class="mb-4">Define Your Goal</h2>
                <div class="form-group">
                    <label for="timeframe_days">In how many days do you want to be completely free?</label>
                    <select id="timeframe_days" class="form-control">
                        <option value="14">14 Days (Rapid reduction/Mild habit)</option>
                        <option value="30" selected>30 Days (Standard 4-week program)</option>
                        <option value="60">60 Days (Recommended for moderate habits)</option>
                        <option value="90">90 Days (Complete lifestyle overhaul)</option>
                    </select>
                </div>
                
                <!-- Notice if credentials are missing -->
                <?php
                $has_credentials = false;
                $status_msg = "";
                if ($ai_provider === 'gemini') {
                    if (!empty($gemini_api_key)) {
                        $has_credentials = true;
                        $status_msg = "✓ AI Engine is ready. We will use Google Gemini API.";
                    } else {
                        $status_msg = "⚠️ <strong>Gemini API Key Required:</strong> Please click Settings (top right) to enter your Gemini API Key before generating.";
                    }
                } else {
                    if (!empty($hf_token)) {
                        $has_credentials = true;
                        $status_msg = "✓ AI Engine is ready. We will use Hugging Face (Model: " . htmlspecialchars($hf_model) . ").";
                    } else {
                        $status_msg = "⚠️ <strong>Hugging Face Token Required:</strong> Please click Settings (top right) to enter a free API Token before generating.";
                    }
                }
                ?>
                <div id="token-status-alert" class="alert <?php echo $has_credentials ? 'alert-success' : 'alert-danger'; ?> mb-4" style="text-align: left;">
                    <?php echo $status_msg; ?>
                </div>

                <div style="display: flex; justify-content: space-between; margin-top: 1.5rem;">
                    <button class="btn btn-secondary" onclick="prevStep(2)">Back</button>
                    <button class="btn btn-teal" id="generate-btn" onclick="generatePlan()" <?php echo !$has_credentials ? 'disabled' : ''; ?>>Create De-Addiction Plan</button>
                </div>
            </div>

            <!-- Loading screen -->
            <div class="loading-wrapper" id="loading-screen" style="display: none;">
                <div class="spinner"></div>
                <h2>Analyzing your profile...</h2>
                <p style="color: var(--text-muted); margin-top: 0.5rem;" id="loading-step-text">Performing local sentiment analysis...</p>
                <div class="loading-quote" id="loading-quote">"The secret of getting ahead is getting started." - Mark Twain</div>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div class="modal-overlay" id="settings-modal">
        <div class="modal-card">
            <div class="modal-header">
                <h3>🛠️ AI Coach Configuration</h3>
                <button class="modal-close" onclick="closeSettingsModal()">&times;</button>
            </div>
            <div class="form-group" style="text-align: left;">
                <label for="modal-ai-provider">AI Provider</label>
                <select id="modal-ai-provider" class="form-control" onchange="toggleProviderFields()">
                    <option value="huggingface" <?php echo $ai_provider === 'huggingface' ? 'selected' : ''; ?>>Hugging Face (Free Serverless)</option>
                    <option value="gemini" <?php echo $ai_provider === 'gemini' ? 'selected' : ''; ?>>Google Gemini (Fast & Smart)</option>
                </select>
            </div>
            
            <div id="hf-fields" style="display: <?php echo $ai_provider === 'huggingface' ? 'block' : 'none'; ?>;">
                <div class="form-group" style="text-align: left;">
                    <label for="modal-hf-token">Hugging Face Access Token (Free)</label>
                    <input type="password" id="modal-hf-token" class="form-control" placeholder="hf_..." value="<?php echo htmlspecialchars($hf_token ?? ''); ?>">
                    <span style="font-size: 0.75rem; color: var(--text-dark); display: block; margin-top: 0.25rem;">
                        Get a free token on <a href="https://huggingface.co/settings/tokens" target="_blank" style="color: var(--primary);">huggingface.co</a>
                    </span>
                </div>
                <div class="form-group" style="text-align: left;">
                    <label for="modal-hf-model">AI Model ID</label>
                    <input type="text" id="modal-hf-model" class="form-control" placeholder="e.g. Qwen/Qwen2.5-7B-Instruct" value="<?php echo htmlspecialchars($hf_model); ?>">
                </div>
            </div>

            <div id="gemini-fields" style="display: <?php echo $ai_provider === 'gemini' ? 'block' : 'none'; ?>;">
                <div class="form-group" style="text-align: left;">
                    <label for="modal-gemini-key">Gemini API Key</label>
                    <input type="password" id="modal-gemini-key" class="form-control" placeholder="AIzaSy..." value="<?php echo htmlspecialchars($gemini_api_key ?? ''); ?>">
                    <span style="font-size: 0.75rem; color: var(--text-dark); display: block; margin-top: 0.25rem;">
                        Get a Gemini API Key on <a href="https://aistudio.google.com/" target="_blank" style="color: var(--primary);">Google AI Studio</a>
                    </span>
                </div>
            </div>

            <div style="text-align: right; margin-top: 1.5rem;" class="gap-2">
                <button class="btn btn-secondary" onclick="closeSettingsModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveSettings()">Save Configuration</button>
            </div>
        </div>
    </div>

    <script>
        let currentStep = 1;
        const quotes = [
            '"The secret of getting ahead is getting started." - Mark Twain',
            '"It does not matter how slowly you go as long as you do not stop." - Confucius',
            '"Believe you can and you\'re halfway there." - Theodore Roosevelt',
            '"Strength does not come from physical capacity. It comes from an indomitable will." - Mahatma Gandhi',
            '"You don\'t have to control your thoughts; you just have to stop letting them control you." - Dan Millman',
            '"Every strike brings me closer to the next home run." - Babe Ruth'
        ];

        function nextStep(step) {
            // Simple validation
            if (currentStep === 1) {
                const name = document.getElementById('addiction_name').value.trim();
                if (!name) { alert('Please enter the habit name.'); return; }
            }
            if (currentStep === 2) {
                const date = document.getElementById('when_started').value;
                const story = document.getElementById('how_started').value.trim();
                if (!date) { alert('Please choose when the habit started.'); return; }
                if (!story) { alert('Please write a short description of how it started.'); return; }
            }

            document.getElementById(`panel-${currentStep}`).classList.remove('active');
            document.getElementById(`panel-${step}`).classList.add('active');
            document.getElementById(`dot-${currentStep}`).classList.add('completed');
            document.getElementById(`dot-${step}`).classList.add('active');
            currentStep = step;
        }

        function prevStep(step) {
            document.getElementById(`panel-${currentStep}`).classList.remove('active');
            document.getElementById(`panel-${step}`).classList.add('active');
            document.getElementById(`dot-${currentStep}`).classList.remove('active');
            document.getElementById(`dot-${step}`).classList.remove('completed');
            document.getElementById(`dot-${step}`).classList.add('active');
            currentStep = step;
        }

        function openSettingsModal() {
            document.getElementById('settings-modal').classList.add('open');
        }

        function closeSettingsModal() {
            document.getElementById('settings-modal').classList.remove('open');
        }

        function toggleProviderFields() {
            const provider = document.getElementById('modal-ai-provider').value;
            if (provider === 'gemini') {
                document.getElementById('hf-fields').style.display = 'none';
                document.getElementById('gemini-fields').style.display = 'block';
            } else {
                document.getElementById('hf-fields').style.display = 'block';
                document.getElementById('gemini-fields').style.display = 'none';
            }
        }

        function saveSettings() {
            const provider = document.getElementById('modal-ai-provider').value;
            const token = document.getElementById('modal-hf-token').value.trim();
            const model = document.getElementById('modal-hf-model').value.trim();
            const geminiKey = document.getElementById('modal-gemini-key').value.trim();
            
            if (provider === 'huggingface' && !token) {
                alert('Please enter a Hugging Face API token.');
                return;
            }
            if (provider === 'gemini' && !geminiKey) {
                alert('Please enter a Gemini API Key.');
                return;
            }

            const formData = new FormData();
            formData.append('save_token', '1');
            formData.append('ai_provider', provider);
            formData.append('hf_token', token);
            formData.append('hf_model', model);
            formData.append('gemini_api_key', geminiKey);

            fetch('setup_plan.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Configuration saved successfully!');
                    location.reload();
                } else {
                    alert('Error saving configuration: ' + data.error);
                }
            })
            .catch(err => {
                alert('Connection error: ' + err.message);
            });
        }

        function generatePlan() {
            // Hide panel content, show loader
            document.getElementById('panel-3').style.display = 'none';
            document.getElementById('dot-1').style.display = 'none';
            document.getElementById('dot-2').style.display = 'none';
            document.getElementById('dot-3').style.display = 'none';
            document.getElementById('loading-screen').style.display = 'flex';

            // Rotate quotes
            let quoteIndex = 0;
            const quoteInterval = setInterval(() => {
                quoteIndex = (quoteIndex + 1) % quotes.length;
                document.getElementById('loading-quote').innerText = quotes[quoteIndex];
            }, 3000);

            // Change progress description texts
            setTimeout(() => {
                document.getElementById('loading-step-text').innerText = 'Analyzing user biodata and sentiment patterns...';
            }, 1500);
            setTimeout(() => {
                document.getElementById('loading-step-text').innerText = 'Connecting to Hugging Face AI engine...';
            }, 3000);
            setTimeout(() => {
                document.getElementById('loading-step-text').innerText = 'Generating daily phase-by-phase de-addiction plan...';
            }, 6000);

            const formData = new FormData();
            formData.append('addiction_name', document.getElementById('addiction_name').value);
            formData.append('severity', document.getElementById('severity').value);
            formData.append('when_started', document.getElementById('when_started').value);
            formData.append('how_started', document.getElementById('how_started').value);
            formData.append('timeframe_days', document.getElementById('timeframe_days').value);

            fetch('setup_plan.php?ajax=1', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                clearInterval(quoteInterval);
                if (data.success) {
                    // Redirect to dashboard
                    location.href = 'dashboard.php';
                } else {
                    // Show error
                    alert('AI Generation Failed: ' + data.error);
                    document.getElementById('loading-screen').style.display = 'none';
                    document.getElementById('panel-3').style.display = 'block';
                    document.getElementById('dot-1').style.display = 'block';
                    document.getElementById('dot-2').style.display = 'block';
                    document.getElementById('dot-3').style.display = 'block';
                }
            })
            .catch(err => {
                clearInterval(quoteInterval);
                alert('Connection failure: ' + err.message);
                document.getElementById('loading-screen').style.display = 'none';
                document.getElementById('panel-3').style.display = 'block';
                document.getElementById('dot-1').style.display = 'block';
                document.getElementById('dot-2').style.display = 'block';
                document.getElementById('dot-3').style.display = 'block';
            });
        }
    </script>
</body>
</html>
