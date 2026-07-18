<?php
require_once __DIR__ . '/db.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$age = $_SESSION['age'];
$gender = $_SESSION['gender'];

// Check settings
$hf_token = get_hf_token($pdo);
$hf_model = get_ai_model($pdo);
$ai_provider = get_ai_provider($pdo);
$gemini_api_key = get_gemini_api_key($pdo);

// Fetch user's latest plan
$stmt = $pdo->prepare("SELECT * FROM plans WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$user_id]);
$plan = $stmt->fetch();

if (!$plan) {
    // If no plan, redirect to setup
    header("Location: setup_plan.php");
    exit;
}

// Fetch user's latest addiction info
$stmt = $pdo->prepare("SELECT * FROM addictions WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$user_id]);
$addiction = $stmt->fetch();
$addiction_name = $addiction ? htmlspecialchars($addiction['addiction_name']) : 'Habit';
$severity = $addiction ? htmlspecialchars($addiction['severity']) : 'Medium';

// --- LOGS & STREAKS CALCULATION ---
// Fetch all logs ordered by log_date DESC
$stmt = $pdo->prepare("SELECT log_date, clean_status FROM daily_logs WHERE user_id = ? ORDER BY log_date DESC");
$stmt->execute([$user_id]);
$all_logs = $stmt->fetchAll();

$current_streak = 0;
$best_streak = 0;
$total_clean_days = 0;
$total_logged_days = count($all_logs);

foreach ($all_logs as $l) {
    if ($l['clean_status'] == 1) {
        $total_clean_days++;
    }
}

// Current streak requires consecutive clean days ending today or yesterday
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$has_recent = false;
if ($total_logged_days > 0) {
    $most_recent_date = $all_logs[0]['log_date'];
    if ($most_recent_date === $today || $most_recent_date === $yesterday) {
        $has_recent = true;
    }
}

if ($has_recent) {
    foreach ($all_logs as $l) {
        if ($l['clean_status'] == 1) {
            $current_streak++;
        } else {
            break;
        }
    }
}

// Best streak (Forward scan)
$stmt = $pdo->prepare("SELECT log_date, clean_status FROM daily_logs WHERE user_id = ? ORDER BY log_date ASC");
$stmt->execute([$user_id]);
$all_logs_asc = $stmt->fetchAll();

$temp_streak = 0;
foreach ($all_logs_asc as $l) {
    if ($l['clean_status'] == 1) {
        $temp_streak++;
        if ($temp_streak > $best_streak) {
            $best_streak = $temp_streak;
        }
    } else {
        $temp_streak = 0;
    }
}

// Check if user has relapsed and then logged again (Resilience badge)
$has_relapse = false;
$has_logged_after_relapse = false;
foreach ($all_logs_asc as $l) {
    if ($l['clean_status'] == 0) {
        $has_relapse = true;
    } elseif ($has_relapse && $l['clean_status'] == 1) {
        $has_logged_after_relapse = true;
    }
}

// Fetch today's log (if already exists)
$stmt = $pdo->prepare("SELECT * FROM daily_logs WHERE user_id = ? AND log_date = ? LIMIT 1");
$stmt->execute([$user_id, $today]);
$todays_log = $stmt->fetch();

// Fetch last 15 logs for Chart.js
$stmt = $pdo->prepare("SELECT log_date, mood_score, craving_level, clean_status FROM daily_logs WHERE user_id = ? ORDER BY log_date ASC LIMIT 15");
$stmt->execute([$user_id]);
$chart_logs = $stmt->fetchAll();

// Fetch chat messages history
$stmt = $pdo->prepare("SELECT sender, message, created_at FROM chat_messages WHERE user_id = ? ORDER BY id ASC LIMIT 50");
$stmt->execute([$user_id]);
$chat_messages = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | BreakFree</title>
    <link rel="stylesheet" href="style.css">
    <!-- Marked.js for rendering markdown -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <!-- Chart.js for progress visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</head>
<body>
    <div class="gradient-bg-glow"></div>

    <header class="app-header">
        <div class="header-wrap">
            <a href="dashboard.php" class="logo-area">
                <span class="logo-icon">🌿</span>
                <span class="logo-text">Break<span class="logo-span">Free</span></span>
            </a>
            <div style="font-size: 0.85rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem;" class="d-none d-sm-flex">
                <span>Habit: <strong><?php echo $addiction_name; ?></strong></span>
                <span style="color: var(--text-dark);">|</span>
                <span class="badge badge-primary"><?php echo $severity; ?> Severity</span>
            </div>
            <nav class="main-nav">
                <button class="nav-link" onclick="openSettingsModal()">⚙️ Settings</button>
                <a href="auth.php?action=logout" class="nav-link btn-logout">Logout</a>
            </nav>
        </div>
    </header>

    <div class="dashboard-container">
        <!-- Sidebar Navigation (Desktop) -->
        <aside class="sidebar">
            <button class="sidebar-btn active" id="btn-tab-overview" onclick="switchDashboardTab('overview')">
                <span>🏠</span> Overview
            </button>
            <button class="sidebar-btn" id="btn-tab-plan" onclick="switchDashboardTab('plan')">
                <span>📋</span> Recovery Plan
            </button>
            <button class="sidebar-btn" id="btn-tab-chat" onclick="switchDashboardTab('chat')">
                <span>💬</span> AI Coach Chat
            </button>
            <button class="sidebar-btn" id="btn-tab-progress" onclick="switchDashboardTab('progress')">
                <span>📈</span> Progress & Logs
            </button>
            <button class="sidebar-btn" id="btn-tab-relief" onclick="switchDashboardTab('relief')">
                <span>🧘</span> Relief Center
            </button>
            
            <div style="margin-top: auto; padding: 1rem 0.5rem; font-size: 0.75rem; color: var(--text-dark); border-top: 1px solid var(--border-color);">
                Logged in as:<br>
                <strong style="color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></strong>
            </div>
        </aside>

        <!-- Main Workspace -->
        <main class="main-content">
            <!-- AI Coach Daily Nudge Banner -->
            <div class="alert alert-info mb-4 flex-between" style="border-left-width: 4px; padding: 1rem 1.25rem;">
                <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
                    <span style="font-size: 1.5rem;">💡</span>
                    <div>
                        <strong style="color: white; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.25rem;">AI Coach's Daily Nudge</strong>
                        <span id="nudge-text" style="font-size: 0.95rem; line-height: 1.4;"><?php echo htmlspecialchars($plan['coach_status'] ?? "Keep going! Log your status daily to receive personalized strategies."); ?></span>
                    </div>
                </div>
            </div>

            <!-- TAB 1: OVERVIEW -->
            <section class="tab-panel active" id="panel-overview">
                <!-- Stats Cards Row -->
                <div class="stats-row">
                    <div class="stat-card">
                        <span class="stat-label">Current Streak</span>
                        <div class="stat-value teal"><?php echo $current_streak; ?> Days</div>
                        <span class="stat-desc">Consecutive clean check-ins</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">All-time Best</span>
                        <div class="stat-value violet"><?php echo $best_streak; ?> Days</div>
                        <span class="stat-desc">Your record clean streak</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Total Clean Days</span>
                        <div class="stat-value amber"><?php echo $total_clean_days; ?> / <?php echo $total_logged_days; ?></div>
                        <span class="stat-desc">Logs showing clean status</span>
                    </div>
                </div>

                <div class="dashboard-grid">
                    <!-- Left: Check-in log -->
                    <div class="log-section">
                        <h2 class="mb-4">Daily Check-in</h2>
                        <?php if ($todays_log): ?>
                            <div class="alert alert-success">
                                ✓ You logged your progress for today! Feel free to update it below at any time.
                            </div>
                        <?php endif; ?>
                        
                        <form id="daily-log-form" onsubmit="submitDailyLog(event)">
                            <div class="form-group">
                                <label>Clean Status Today?</label>
                                <div class="status-toggle" style="margin: 0.5rem 0;">
                                    <button type="button" class="toggle-btn <?php echo (!$todays_log || $todays_log['clean_status'] == 1) ? 'active clean' : ''; ?>" id="log-clean-yes" onclick="setLogCleanStatus(1)">
                                        Yes, I stayed clean!
                                    </button>
                                    <button type="button" class="toggle-btn <?php echo ($todays_log && $todays_log['clean_status'] == 0) ? 'active relapse' : ''; ?>" id="log-clean-no" onclick="setLogCleanStatus(0)">
                                        No, I slipped.
                                    </button>
                                </div>
                                <input type="hidden" name="clean_status" id="log-clean-input" value="<?php echo $todays_log ? $todays_log['clean_status'] : '1'; ?>">
                            </div>

                            <div class="form-group">
                                <label>How intense are your cravings? (1 - None, 10 - Unbearable)</label>
                                <div class="craving-slider-wrap">
                                    <input type="range" class="slider" name="craving_level" id="log-craving" min="1" max="10" value="<?php echo $todays_log ? $todays_log['craving_level'] : '3'; ?>" oninput="document.getElementById('craving-val-display').innerText = this.value">
                                    <div class="slider-labels">
                                        <span>1 (None)</span>
                                        <strong style="color: var(--secondary); font-size: 1rem;" id="craving-val-display"><?php echo $todays_log ? $todays_log['craving_level'] : '3'; ?></strong>
                                        <span>10 (Severe)</span>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Current Mood / Emotional State</label>
                                <div class="mood-picker">
                                    <button type="button" class="mood-btn <?php echo ($todays_log && $todays_log['mood_score'] == 1) ? 'selected' : ''; ?>" onclick="selectMood(1)" id="mood-1">
                                        <span>😢</span>
                                        <span class="label">Awful</span>
                                    </button>
                                    <button type="button" class="mood-btn <?php echo ($todays_log && $todays_log['mood_score'] == 2) ? 'selected' : ''; ?>" onclick="selectMood(2)" id="mood-2">
                                        <span>😐</span>
                                        <span class="label">Low</span>
                                    </button>
                                    <button type="button" class="mood-btn <?php echo (!$todays_log || $todays_log['mood_score'] == 3) ? 'selected' : ''; ?>" onclick="selectMood(3)" id="mood-3">
                                        <span>🙂</span>
                                        <span class="label">Okay</span>
                                    </button>
                                    <button type="button" class="mood-btn <?php echo ($todays_log && $todays_log['mood_score'] == 4) ? 'selected' : ''; ?>" onclick="selectMood(4)" id="mood-4">
                                        <span>😊</span>
                                        <span class="label">Good</span>
                                    </button>
                                    <button type="button" class="mood-btn <?php echo ($todays_log && $todays_log['mood_score'] == 5) ? 'selected' : ''; ?>" onclick="selectMood(5)" id="mood-5">
                                        <span>🤩</span>
                                        <span class="label">Amazing</span>
                                    </button>
                                </div>
                                <input type="hidden" name="mood_score" id="log-mood-input" value="<?php echo $todays_log ? $todays_log['mood_score'] : '3'; ?>">
                            </div>

                            <div class="form-group">
                                <label for="log-notes">Reflection / Daily Notes</label>
                                <textarea id="log-notes" name="notes" class="form-control" placeholder="Optional. What triggered you today? How did you handle it?"><?php echo $todays_log ? htmlspecialchars($todays_log['notes']) : ''; ?></textarea>
                            </div>

                            <button type="submit" class="btn btn-teal btn-block" style="margin-top: 1.5rem;" id="log-submit-btn">
                                Save Today's Check-in
                            </button>
                        </form>
                    </div>

                    <!-- Right: Checklist -->
                    <div class="card">
                        <h2>Today's Recovery Routine</h2>
                        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem;">Action items compiled by your coach to build healthy habits:</p>
                        
                        <div class="checklist-item">
                            <input type="checkbox" id="task-1" class="checklist-cb">
                            <label for="task-1" class="checklist-label">Avoid primary triggers (identified in your plan)</label>
                        </div>
                        <div class="checklist-item">
                            <input type="checkbox" id="task-2" class="checklist-cb">
                            <label for="task-2" class="checklist-label">Log your daily progress and craving levels</label>
                        </div>
                        <div class="checklist-item">
                            <input type="checkbox" id="task-3" class="checklist-cb">
                            <label for="task-3" class="checklist-label">Practice 5 minutes of Box Breathing in Relief Center</label>
                        </div>
                        <div class="checklist-item">
                            <input type="checkbox" id="task-4" class="checklist-cb">
                            <label for="task-4" class="checklist-label">Engage in replacement habit (mindfulness/walk)</label>
                        </div>
                        <div class="checklist-item">
                            <input type="checkbox" id="task-5" class="checklist-cb">
                            <label for="task-5" class="checklist-label">Check in with your AI coach chat if craving strikes</label>
                        </div>
                    </div>
                </div>
            </section>

            <!-- TAB 2: MY PLAN -->
            <section class="tab-panel" id="panel-plan">
                <div class="card">
                    <div class="flex-between mb-4">
                        <h2>My De-Addiction Roadmap</h2>
                        <a href="setup_plan.php" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">🔄 Re-Generate Plan</a>
                    </div>
                    <div id="plan-markdown-container" class="plan-content" style="max-height: 70vh;">
                        <!-- Rendered by marked.js -->
                    </div>
                </div>
            </section>

            <!-- TAB 3: AI COACH CHAT -->
            <section class="tab-panel" id="panel-chat">
                <div class="chat-container">
                    <div class="chat-header">
                        <div class="coach-avatar">🌿</div>
                        <div class="coach-info">
                            <h3>Adaptive AI Coach</h3>
                            <div class="coach-status">Active Support Coach</div>
                        </div>
                    </div>
                    
                    <div class="chat-messages" id="chat-messages-box">
                        <!-- Welcome message if no chat history -->
                        <?php if (empty($chat_messages)): ?>
                            <div class="message-bubble coach">
                                Hello! I am your recovery coach. I have analyzed your habits, and today I'm ready to help you navigate through triggers, discuss slips safely, or share replacement strategies. What is on your mind?
                            </div>
                        <?php else: ?>
                            <?php foreach ($chat_messages as $m): ?>
                                <div class="message-bubble <?php echo $m['sender'] === 'user' ? 'user' : 'coach'; ?>">
                                    <?php echo nl2br(htmlspecialchars($m['message'])); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="chat-input-area">
                        <form class="chat-form" id="chat-form" onsubmit="sendCoachMessage(event)">
                            <input type="text" id="chat-input-text" class="chat-input" placeholder="Ask about triggers, replacement habits, or coping strategies..." autocomplete="off">
                            <button type="submit" class="btn btn-primary" id="chat-send-btn">Send</button>
                        </form>
                    </div>
                </div>
            </section>

            <!-- TAB 4: PROGRESS & LOGS -->
            <section class="tab-panel" id="panel-progress">
                <div class="card mb-4">
                    <h2>Habit Insights & Trends</h2>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem;">Visual tracking of your cravings vs. mood over the last 15 check-ins.</p>
                    <div style="position: relative; height: 350px; width: 100%;">
                        <canvas id="progressChart"></canvas>
                    </div>
                </div>

                <div class="dashboard-grid">
                    <!-- Left: Achievements -->
                    <div class="card">
                        <h2>Milestone Achievements</h2>
                        <div class="badges-grid">
                            <div class="badge-item <?php echo $total_logged_days >= 1 ? 'unlocked' : ''; ?>">
                                <div class="badge-icon">🌱</div>
                                <div class="badge-title">First Step</div>
                                <div class="badge-desc">Logged your first daily check-in</div>
                            </div>
                            <div class="badge-item <?php echo $best_streak >= 3 ? 'unlocked' : ''; ?>">
                                <div class="badge-icon">🔥</div>
                                <div class="badge-title">Consolidating</div>
                                <div class="badge-desc">Achieved a 3-day clean streak</div>
                            </div>
                            <div class="badge-item <?php echo $best_streak >= 7 ? 'unlocked' : ''; ?>">
                                <div class="badge-icon">🛡️</div>
                                <div class="badge-title">Week Clean</div>
                                <div class="badge-desc">Achieved a 7-day clean streak</div>
                            </div>
                            <div class="badge-item <?php echo $has_logged_after_relapse ? 'unlocked' : ''; ?>">
                                <div class="badge-icon">💪</div>
                                <div class="badge-title">Resilient</div>
                                <div class="badge-desc">Stayed clean/logged after a slip</div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Log History list -->
                    <div class="card" style="max-height: 400px; overflow-y: auto;">
                        <h2>Check-in History</h2>
                        <div style="margin-top: 1rem; display: flex; flex-direction: column; gap: 0.75rem;">
                            <?php if (empty($all_logs)): ?>
                                <p style="color: var(--text-dark); text-align: center; padding: 2rem 0;">No history logged yet. Complete today's check-in!</p>
                            <?php else: ?>
                                <?php foreach ($all_logs as $log): ?>
                                    <div style="background-color: rgba(255, 255, 255, 0.01); border: 1px solid var(--border-color); padding: 0.85rem 1.25rem; border-radius: var(--radius-sm); display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <div style="font-weight: 700; font-size: 0.95rem;"><?php echo date('M d, Y', strtotime($log['log_date'])); ?></div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;">
                                                Mood: <strong><?php echo $log['mood_score']; ?>/5</strong> | Cravings: <strong><?php echo $log['craving_level']; ?>/10</strong>
                                            </div>
                                        </div>
                                        <div>
                                            <?php if ($log['clean_status'] == 1): ?>
                                                <span class="badge" style="background-color: var(--success-glow); color: var(--success);">Clean</span>
                                            <?php else: ?>
                                                <span class="badge" style="background-color: var(--danger-glow); color: var(--danger);">Slipped</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- TAB 5: RELIEF CENTER -->
            <section class="tab-panel" id="panel-relief">
                <div class="dashboard-grid">
                    <!-- Left: Box Breathing -->
                    <div class="card text-center">
                        <h2>Box Breathing Tool</h2>
                        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem;">Settle your nervous system and dissolve cravings with paced box breathing.</p>
                        
                        <div class="breathing-container">
                            <div class="breathing-circle-outer">
                                <div class="breathing-circle-inner" id="breathing-bubble">
                                    <span class="breathing-prompt" id="breathing-label">Ready</span>
                                </div>
                            </div>
                            <div class="breathing-timer" id="breathing-timer">0s</div>
                            <button class="btn btn-secondary mt-4" id="breathing-action-btn" onclick="toggleBreathing()">Start Breathing Cycle</button>
                        </div>
                    </div>

                    <!-- Right: Emergency Support & Distraction -->
                    <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <h2>Craving SOS</h2>
                            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem;">Experiencing an intense urge right now? Get immediate support.</p>
                            
                            <button class="btn btn-primary btn-block" style="padding: 1.15rem; font-size: 1.1rem;" onclick="triggerCravingSOS()" id="sos-btn">
                                🚨 I'm Having a Craving! (AI Distress Help)
                            </button>
                            
                            <!-- SOS Result Area -->
                            <div id="sos-result-box" class="distraction-box" style="display: none;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                    <strong style="color: white; font-size: 1rem;">🔥 5-Minute Relief Exercise</strong>
                                    <button onclick="document.getElementById('sos-result-box').style.display='none'" style="background:transparent; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
                                </div>
                                <div id="sos-content" style="font-size: 0.95rem; color: var(--text-main); line-height: 1.5;">
                                    <!-- Loaded by AJAX -->
                                </div>
                            </div>
                        </div>

                        <!-- Static distraction carousel / reminders -->
                        <div style="margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                            <h3>Quick Distraction Prompts</h3>
                            <ul style="color: var(--text-muted); font-size: 0.85rem; margin-left: 1.25rem; margin-top: 0.5rem; display: flex; flex-direction: column; gap: 0.35rem;">
                                <li>Drink a large, full glass of ice-cold water immediately.</li>
                                <li>Do 10 pushups or jump up and down for 30 seconds.</li>
                                <li>Step outside and take 5 slow, deep breaths of fresh air.</li>
                                <li>Text or call a trusted family member or recovery partner.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Mobile Navigation Tabs (Fixed Bottom) -->
    <nav class="mobile-tabs-nav">
        <button class="mobile-tab-btn active" id="m-btn-overview" onclick="switchDashboardTab('overview')">
            <span>🏠</span>Overview
        </button>
        <button class="mobile-tab-btn" id="m-btn-plan" onclick="switchDashboardTab('plan')">
            <span>📋</span>Plan
        </button>
        <button class="mobile-tab-btn" id="m-btn-chat" onclick="switchDashboardTab('chat')">
            <span>💬</span>Coach
        </button>
        <button class="mobile-tab-btn" id="m-btn-progress" onclick="switchDashboardTab('progress')">
            <span>📈</span>Progress
        </button>
        <button class="mobile-tab-btn" id="m-btn-relief" onclick="switchDashboardTab('relief')">
            <span>🧘</span>Relief
        </button>
    </nav>

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
        // Global variables
        const planMarkdownRaw = <?php echo json_encode($plan['full_plan_text'] ?? ''); ?>;
        const chartLogsData = <?php echo json_encode($chart_logs); ?>;
        
        // Render Markdown Plan
        document.addEventListener('DOMContentLoaded', () => {
            if (planMarkdownRaw) {
                document.getElementById('plan-markdown-container').innerHTML = marked.parse(planMarkdownRaw);
            }
            
            // Scroll chat to bottom
            const chatBox = document.getElementById('chat-messages-box');
            if (chatBox) {
                chatBox.scrollTop = chatBox.scrollHeight;
            }

            // Render Progress Chart
            renderChart();
        });

        // Tab Switching Logic
        function switchDashboardTab(tabName) {
            // Hide all tab panels
            document.querySelectorAll('.tab-panel').forEach(panel => {
                panel.classList.remove('active');
            });
            // Show selected tab panel
            document.getElementById(`panel-${tabName}`).classList.add('active');

            // Deactivate all sidebar and mobile navigation buttons
            document.querySelectorAll('.sidebar-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.mobile-tab-btn').forEach(btn => btn.classList.remove('active'));

            // Activate current buttons
            const deskBtn = document.getElementById(`btn-tab-${tabName}`);
            const mobBtn = document.getElementById(`m-btn-${tabName}`);
            if (deskBtn) deskBtn.classList.add('active');
            if (mobBtn) mobBtn.classList.add('active');

            // Scroll chat to bottom if switching to chat
            if (tabName === 'chat') {
                setTimeout(() => {
                    const chatBox = document.getElementById('chat-messages-box');
                    if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
                }, 50);
            }
        }

        // Settings Modal Handlers
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
                alert('Please enter a Hugging Face API Token.');
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

        // Daily Log check-in Form
        function setLogCleanStatus(val) {
            document.getElementById('log-clean-input').value = val;
            const yesBtn = document.getElementById('log-clean-yes');
            const noBtn = document.getElementById('log-clean-no');

            if (val === 1) {
                yesBtn.classList.add('active', 'clean');
                noBtn.classList.remove('active', 'relapse');
            } else {
                noBtn.classList.add('active', 'relapse');
                yesBtn.classList.remove('active', 'clean');
            }
        }

        function selectMood(val) {
            document.getElementById('log-mood-input').value = val;
            document.querySelectorAll('.mood-btn').forEach(btn => btn.classList.remove('selected'));
            document.getElementById(`mood-${val}`).classList.add('selected');
        }

        function submitDailyLog(event) {
            event.preventDefault();
            const submitBtn = document.getElementById('log-submit-btn');
            submitBtn.innerText = 'Saving Check-in...';
            submitBtn.disabled = true;

            const form = document.getElementById('daily-log-form');
            const formData = new FormData(form);

            fetch('daily_log.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                submitBtn.innerText = 'Save Today\'s Check-in';
                submitBtn.disabled = false;
                if (data.success) {
                    alert('Daily check-in saved successfully!');
                    location.reload();
                } else {
                    alert('Error saving check-in: ' + data.error);
                }
            })
            .catch(err => {
                submitBtn.innerText = 'Save Today\'s Check-in';
                submitBtn.disabled = false;
                alert('Network connection error: ' + err.message);
            });
        }

        // AI Chatbox Messaging
        function sendCoachMessage(event) {
            event.preventDefault();
            const inputField = document.getElementById('chat-input-text');
            const message = inputField.value.trim();
            if (!message) return;

            // Clear input
            inputField.value = '';
            
            // Append User message bubble
            appendChatBubble('user', message);
            
            // Append Typing indicator
            const typingId = appendChatBubble('coach', '<div class="gap-2" style="align-items:center;"><span>🌿</span> <em>Coach is typing...</em></div>');

            const chatMessagesBox = document.getElementById('chat-messages-box');
            chatMessagesBox.scrollTop = chatMessagesBox.scrollHeight;

            const formData = new FormData();
            formData.append('message', message);

            fetch('coach_chat.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                // Remove typing bubble
                document.getElementById(typingId).remove();
                
                if (data.success) {
                    appendChatBubble('coach', data.reply);
                } else {
                    appendChatBubble('coach', '⚠️ Error: Unable to reach the AI Coach right now. Please check your API Settings. Error details: ' + data.error);
                }
                chatMessagesBox.scrollTop = chatMessagesBox.scrollHeight;
            })
            .catch(err => {
                document.getElementById(typingId).remove();
                appendChatBubble('coach', '⚠️ Network error: Could not reach the server.');
                chatMessagesBox.scrollTop = chatMessagesBox.scrollHeight;
            });
        }

        let bubbleIdCounter = 0;
        function appendChatBubble(sender, content) {
            const chatMessagesBox = document.getElementById('chat-messages-box');
            const bubble = document.createElement('div');
            const id = 'bubble-' + (++bubbleIdCounter);
            bubble.id = id;
            bubble.className = `message-bubble ${sender}`;
            
            if (content.includes('<div') || content.includes('<span')) {
                bubble.innerHTML = content;
            } else {
                // simple format newlines
                bubble.innerHTML = content.replace(/\n/g, '<br>');
            }
            
            chatMessagesBox.appendChild(bubble);
            return id;
        }

        // Chart.js Rendering
        function renderChart() {
            const ctx = document.getElementById('progressChart').getContext('2d');
            
            if (chartLogsData.length === 0) {
                ctx.font = '16px Plus Jakarta Sans';
                ctx.fillStyle = '#a1a1aa';
                ctx.textAlign = 'center';
                ctx.fillText('No check-in logs found. Log daily to view statistics!', 300, 150);
                return;
            }

            const labels = chartLogsData.map(log => {
                const date = new Date(log.log_date);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            });
            const cravings = chartLogsData.map(log => log.craving_level);
            const moods = chartLogsData.map(log => log.mood_score);
            const statusColors = chartLogsData.map(log => log.clean_status == 1 ? 'rgba(16, 185, 129, 0.4)' : 'rgba(239, 68, 68, 0.4)');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Craving Level (1-10)',
                            data: cravings,
                            borderColor: '#0d9488',
                            backgroundColor: 'rgba(13, 148, 136, 0.1)',
                            borderWidth: 3,
                            tension: 0.3,
                            yAxisID: 'y-cravings',
                            pointBackgroundColor: '#0d9488'
                        },
                        {
                            label: 'Mood Score (1-5)',
                            data: moods,
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99, 102, 241, 0.1)',
                            borderWidth: 3,
                            tension: 0.3,
                            yAxisID: 'y-mood',
                            pointBackgroundColor: '#6366f1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            grid: { color: 'rgba(255, 255, 255, 0.05)' },
                            ticks: { color: '#a1a1aa' }
                        },
                        'y-cravings': {
                            type: 'linear',
                            position: 'left',
                            min: 1,
                            max: 10,
                            ticks: { stepSize: 1, color: '#a1a1aa' },
                            grid: { color: 'rgba(255, 255, 255, 0.05)' },
                            title: { display: true, text: 'Cravings Intensity', color: '#0d9488' }
                        },
                        'y-mood': {
                            type: 'linear',
                            position: 'right',
                            min: 1,
                            max: 5,
                            ticks: { stepSize: 1, color: '#a1a1aa' },
                            grid: { display: false },
                            title: { display: true, text: 'Mood Score', color: '#6366f1' }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: { color: '#f4f4f5', font: { family: 'Plus Jakarta Sans' } }
                        }
                    }
                }
            });
        }

        // Box Breathing Mechanism
        let breathingInterval = null;
        let breathingCycleState = 0; // 0=ready, 1=inhale, 2=hold, 3=exhale, 4=hold_out
        let breathingTimerCount = 0;

        function toggleBreathing() {
            const btn = document.getElementById('breathing-action-btn');
            const bubble = document.getElementById('breathing-bubble');
            const timerEl = document.getElementById('breathing-timer');
            const labelEl = document.getElementById('breathing-label');

            if (breathingInterval) {
                // Stop
                clearInterval(breathingInterval);
                breathingInterval = null;
                breathingCycleState = 0;
                bubble.className = "breathing-circle-inner";
                labelEl.innerText = "Ready";
                timerEl.innerText = "0s";
                btn.innerText = "Start Breathing Cycle";
                btn.className = "btn btn-secondary mt-4";
            } else {
                // Start
                btn.innerText = "Stop Breathing Cycle";
                btn.className = "btn btn-logout mt-4";
                runBreathingStep();
                breathingInterval = setInterval(runBreathingStep, 1000);
            }
        }

        function runBreathingStep() {
            const bubble = document.getElementById('breathing-bubble');
            const timerEl = document.getElementById('breathing-timer');
            const labelEl = document.getElementById('breathing-label');

            if (breathingTimerCount <= 0) {
                // Transition state
                breathingCycleState = (breathingCycleState % 4) + 1;
                breathingTimerCount = 4; // 4 seconds per box breathing phase

                switch (breathingCycleState) {
                    case 1:
                        labelEl.innerText = "Breathe In";
                        bubble.className = "breathing-circle-inner inhale";
                        break;
                    case 2:
                        labelEl.innerText = "Hold Breath";
                        bubble.className = "breathing-circle-inner hold";
                        break;
                    case 3:
                        labelEl.innerText = "Breathe Out";
                        bubble.className = "breathing-circle-inner exhale";
                        break;
                    case 4:
                        labelEl.innerText = "Hold Out";
                        bubble.className = "breathing-circle-inner hold-out";
                        break;
                }
            }

            timerEl.innerText = breathingTimerCount + "s";
            breathingTimerCount--;
        }

        // Craving SOS Generator
        function triggerCravingSOS() {
            const sosBtn = document.getElementById('sos-btn');
            const resultBox = document.getElementById('sos-result-box');
            const contentBox = document.getElementById('sos-content');

            sosBtn.innerText = '🧠 Consultung AI Relief Coach...';
            sosBtn.disabled = true;
            resultBox.style.display = 'none';

            // Gather check-in inputs for immediate context
            const mood = document.getElementById('log-mood-input').value;
            const craving = document.getElementById('log-craving').value;

            const formData = new FormData();
            formData.append('mood_score', mood);
            formData.append('craving_level', craving);

            fetch('distract.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                sosBtn.innerText = '🚨 I\'m Having a Craving! (AI Distress Help)';
                sosBtn.disabled = false;

                if (data.success) {
                    contentBox.innerHTML = marked.parse(data.distraction);
                    resultBox.style.display = 'block';
                    // Scroll to distress section
                    resultBox.scrollIntoView({ behavior: 'smooth' });
                } else {
                    alert('Unable to load coping exercise: ' + data.error);
                }
            })
            .catch(err => {
                sosBtn.innerText = '🚨 I\'m Having a Craving! (AI Distress Help)';
                sosBtn.disabled = false;
                alert('Connection error: ' + err.message);
            });
        }
    </script>
</body>
</html>
