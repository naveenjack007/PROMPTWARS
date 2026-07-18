<?php
// cooking-todo-app/index.php

require_once __DIR__ . '/db.php';

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$username = $_SESSION['username'];
$dbEngine = $_SESSION['db_engine'] ?? 'Unknown';

// Check if database is initialized by checking if recipes table exists
$dbNeedsInit = false;
try {
    $pdo = getDBConnection();
    $pdo->query("SELECT 1 FROM recipes LIMIT 1");
} catch (PDOException $e) {
    $dbNeedsInit = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PrepMaster Dashboard - Intelligent Culinary Planner</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-logo">
                <span class="logo-icon">🍳</span>
                <h2>PrepMaster</h2>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-item active" data-tab="dashboard">
                    <span class="nav-icon">📊</span>
                    <span>Dashboard</span>
                </div>
                <div class="nav-item" data-tab="pantry">
                    <span class="nav-icon">📦</span>
                    <span>Pantry Inventory</span>
                </div>
                <div class="nav-item" data-tab="scanner">
                    <span class="nav-icon">📸</span>
                    <span>Receipt Scanner</span>
                </div>
                <div class="nav-item" data-tab="budget">
                    <span class="nav-icon">💵</span>
                    <span>Budget & Stats</span>
                </div>
            </nav>
            
            <div class="sidebar-footer">
                <div class="user-profile">
                    <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                    <div class="user-info">
                        <h4><?php echo htmlspecialchars($username); ?></h4>
                        <p>Database: <?php echo htmlspecialchars($dbEngine); ?></p>
                    </div>
                </div>
                <div class="nav-item" data-tab="logout" style="border-top: 1px solid rgba(255,255,255,0.05); margin-top: 8px;">
                    <span class="nav-icon">🚪</span>
                    <span>Log Out</span>
                </div>
            </div>
        </aside>

        <!-- Main Workspace -->
        <main class="main-content">
            
            <!-- DATABASE INITIALIZER MODAL / WIZARD (IF NOT INITIALIZED) -->
            <?php if ($dbNeedsInit): ?>
            <div id="init-db-card" class="glass-card" style="border-color: var(--color-warning); box-shadow: 0 0 30px rgba(245, 158, 11, 0.2);">
                <div class="glass-card-header">
                    <h2 style="color: var(--color-warning);">⚠️ Database Setup Required</h2>
                </div>
                <p style="margin-bottom: 20px; color: var(--text-secondary);">
                    Before using PrepMaster, we need to set up the database tables and seed sample recipes, ingredients, and substitutions.
                </p>
                <div id="setup-alert" class="alert" style="display: none;"></div>
                <button id="run-setup-btn" class="btn btn-primary">
                    <span>Initialize & Seed Database</span>
                    <span class="btn-glow"></span>
                </button>
            </div>
            
            <script>
                document.getElementById('run-setup-btn').addEventListener('click', async () => {
                    const btn = document.getElementById('run-setup-btn');
                    const alertBox = document.getElementById('setup-alert');
                    btn.disabled = true;
                    btn.innerHTML = '<span>Setting up database...</span>';
                    
                    try {
                        const response = await fetch('setup.php');
                        const result = await response.json();
                        
                        alertBox.style.display = 'none';
                        if (response.ok && result.status === 'success') {
                            alertBox.className = 'alert alert-success';
                            alertBox.textContent = result.message;
                            alertBox.style.display = 'block';
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        } else {
                            alertBox.className = 'alert alert-danger';
                            alertBox.textContent = result.message || 'Setup failed.';
                            alertBox.style.display = 'block';
                            btn.disabled = false;
                            btn.innerHTML = '<span>Retry Setup</span>';
                        }
                    } catch (err) {
                        alertBox.className = 'alert alert-danger';
                        alertBox.textContent = 'Server connection error. Please make sure your server is running.';
                        alertBox.style.display = 'block';
                        btn.disabled = false;
                        btn.innerHTML = '<span>Retry Setup</span>';
                    }
                });
            </script>
            <?php endif; ?>

            <!-- ------------------------------------------------
                 1. DASHBOARD TAB
                 ------------------------------------------------ -->
            <div id="dashboard-panel" class="tab-panel active">
                <div class="panel-header">
                    <div class="panel-title">
                        <h1>Culinary Planner</h1>
                        <p>Structured healthy plans matched with your pantry</p>
                    </div>
                </div>
                
                <div id="dashboard-alert" class="alert" style="display: none;"></div>
                <div id="dashboard-loader" style="display: none; text-align: center; padding: 40px; color: var(--text-secondary);">Loading plan...</div>
                
                <!-- No Plan State -->
                <div id="no-plan-box" class="glass-card" style="display: none; text-align: center; padding: 48px 24px;">
                    <div style="font-size: 64px; margin-bottom: 16px;">📅</div>
                    <h2>No Meal Plan for Today</h2>
                    <p style="color: var(--text-secondary); margin-bottom: 24px; max-width: 400px; margin-left: auto; margin-right: auto;">
                        Generate an optimized meal plan that checks your available groceries, structures balanced recipes, and auto-calculates substitutions.
                    </p>
                    <button id="generate-plan-btn" class="btn btn-primary">
                        <span>Auto-Generate Plan</span>
                        <span class="btn-glow"></span>
                    </button>
                </div>
                
                <!-- Active Plan State -->
                <div id="active-plan-box" style="display: none;">
                    <div class="meal-cards" id="meal-cards-container">
                        <!-- Meal cards populated by app.js -->
                    </div>
                    
                    <div class="dashboard-grid">
                        <!-- Left: Tasks and details -->
                        <div>
                            <!-- Substitutions Used Box -->
                            <div id="dashboard-subs-box" class="glass-card" style="display: none;">
                                <div class="glass-card-header">
                                    <h3>🔄 Smart Substitutions Made</h3>
                                </div>
                                <div id="dashboard-subs-items"></div>
                            </div>
                            
                            <!-- Shopping List Box -->
                            <div id="dashboard-shopping-list" class="glass-card" style="display: none;">
                                <div class="glass-card-header">
                                    <h3>🛒 Missing Items Shopping List</h3>
                                </div>
                                <div id="dashboard-shop-items"></div>
                                <p style="font-size: 11px; color: var(--text-secondary); margin-top: 12px; font-style: italic;">
                                    * Prices are estimated averages.
                                </p>
                            </div>
                            
                            <!-- To-Do Checklist -->
                            <div class="glass-card">
                                <div class="glass-card-header">
                                    <h3>📝 Prep & Cooking To-Do Checklist</h3>
                                </div>
                                <div class="todo-list" id="todo-list-container">
                                    <!-- Populated by app.js -->
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right: Stats & Tools -->
                        <div>
                            <!-- Nutrition stats -->
                            <div class="glass-card">
                                <div class="glass-card-header">
                                    <h3>📊 Daily Nutrition Aggregates</h3>
                                </div>
                                <div class="nutrition-stats-widget">
                                    <div class="nutrition-bars">
                                        <div class="progress-bar-container">
                                            <div class="progress-bar-header">
                                                <span>Calories</span>
                                                <span id="cal-pct-text">0 / 2000 kcal</span>
                                            </div>
                                            <div class="progress-bar-bg">
                                                <div id="cal-bar" class="progress-bar-fill fill-calories" style="width: 0%"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="progress-bar-container">
                                            <div class="progress-bar-header">
                                                <span>Protein</span>
                                                <span id="prot-pct-text">0 / 75g</span>
                                            </div>
                                            <div class="progress-bar-bg">
                                                <div id="prot-bar" class="progress-bar-fill fill-protein" style="width: 0%"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="progress-bar-container">
                                            <div class="progress-bar-header">
                                                <span>Carbohydrates</span>
                                                <span id="carb-pct-text">0 / 250g</span>
                                            </div>
                                            <div class="progress-bar-bg">
                                                <div id="carb-bar" class="progress-bar-fill fill-carbs" style="width: 0%"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="progress-bar-container">
                                            <div class="progress-bar-header">
                                                <span>Fat</span>
                                                <span id="fat-pct-text">0 / 70g</span>
                                            </div>
                                            <div class="progress-bar-bg">
                                                <div id="fat-bar" class="progress-bar-fill fill-fat" style="width: 0%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <p style="font-size: 11px; color: var(--text-muted); line-height: 1.3;">
                                        * Nutritional limits are calculated based on standard 2000-calorie daily recommendations.
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Danger zone / Clear Plan -->
                            <div class="glass-card" style="border-color: rgba(244,63,94,0.15);">
                                <button id="delete-plan-btn" class="btn btn-secondary btn-block btn-sm" style="color: var(--color-danger); border-color: rgba(244,63,94,0.2);">
                                    Discard Today's Meal Plan
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ------------------------------------------------
                 2. PANTRY TAB
                 ------------------------------------------------ -->
            <div id="pantry-panel" class="tab-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <h1>Pantry Inventory</h1>
                        <p>Manage ingredients you have in stock</p>
                    </div>
                    <button class="btn btn-primary" onclick="document.getElementById('add-pantry-card').style.display = document.getElementById('add-pantry-card').style.display === 'none' ? 'block' : 'none'">
                        <span>Add Pantry Item</span>
                        <span class="btn-glow"></span>
                    </button>
                </div>
                
                <div id="pantry-alert" class="alert" style="display: none;"></div>
                <div id="pantry-loader" style="display: none; text-align: center; padding: 40px; color: var(--text-secondary);">Loading pantry...</div>
                
                <!-- Add Pantry Card Toggle form -->
                <div id="add-pantry-card" class="glass-card" style="display: none; border-color: var(--color-primary);">
                    <div class="glass-card-header">
                        <h3>Add New Ingredient</h3>
                    </div>
                    <form id="add-pantry-form" class="grid-cols-2">
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="pantry-add-name">Ingredient Name</label>
                            <input type="text" id="pantry-add-name" placeholder="e.g. Salmon fillet, Eggs, Milk" required>
                        </div>
                        <div class="form-group">
                            <label for="pantry-add-qty">Quantity</label>
                            <input type="number" step="any" id="pantry-add-qty" value="1" required>
                        </div>
                        <div class="form-group">
                            <label for="pantry-add-unit">Unit</label>
                            <select id="pantry-add-unit">
                                <option value="pcs">pcs (individual)</option>
                                <option value="slices">slices</option>
                                <option value="g">g (grams)</option>
                                <option value="kg">kg (kilograms)</option>
                                <option value="ml">ml (milliliters)</option>
                                <option value="L">L (liters)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="pantry-add-cat">Category</label>
                            <select id="pantry-add-cat">
                                <option value="Vegetables">Vegetables</option>
                                <option value="Proteins">Proteins</option>
                                <option value="Dairy">Dairy</option>
                                <option value="Grains">Grains</option>
                                <option value="Fruit">Fruit</option>
                                <option value="Pantry">Pantry</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="pantry-add-price">Price ($)</label>
                            <input type="number" step="0.01" id="pantry-add-price" placeholder="e.g. 12.50">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="pantry-add-expiry">Expiry Date</label>
                            <input type="date" id="pantry-add-expiry">
                        </div>
                        <div style="grid-column: span 2; display: flex; gap: 12px; justify-content: flex-end;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('add-pantry-card').style.display='none'">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm">Add Item</button>
                        </div>
                    </form>
                </div>
                
                <div class="pantry-grid" id="pantry-grid-container">
                    <!-- Pantry cards populated by app.js -->
                </div>
            </div>

            <!-- ------------------------------------------------
                 3. SCANNER TAB
                 ------------------------------------------------ -->
            <div id="scanner-panel" class="tab-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <h1>Receipt OCR Parser</h1>
                        <p>Upload a bill photo or paste items to stock your pantry</p>
                    </div>
                </div>
                
                <div class="scanner-grid">
                    <!-- Left: Upload/Input Area -->
                    <div>
                        <div class="glass-card">
                            <div class="glass-card-header">
                                <h3>Upload Grocery Bill</h3>
                            </div>
                            
                            <!-- File input (hidden) -->
                            <input type="file" id="receipt_file" style="display: none;" accept="image/*,text/plain">
                            
                            <div class="dropzone-container" id="dropzone">
                                <div id="dropzone-prompt">
                                    <div class="dropzone-icon">📸</div>
                                    <h3>Upload Grocery Bill</h3>
                                    <p>Drag and drop receipt image or text file here, or click to browse.</p>
                                </div>
                                <div class="scanner-preview-wrapper" id="preview-wrapper">
                                    <img src="" id="preview-img" class="scanner-preview-img" alt="Receipt Preview">
                                    <div class="scanner-laser-line" id="scanner-laser"></div>
                                </div>
                            </div>
                            
                            <button id="upload-scanner-btn" class="btn btn-primary btn-block" style="margin-top: 16px;">
                                <span>Scan Receipt File</span>
                                <span class="btn-glow"></span>
                            </button>
                        </div>
                        
                        <!-- Manual text paste scanner -->
                        <div class="glass-card">
                            <div class="glass-card-header">
                                <h3>Paste Receipt Items (Text)</h3>
                            </div>
                            <p style="font-size: 12px; color: var(--text-secondary);">
                                Paste bill text format: <code>2 pc avocado $4.00</code> or <code>salmon fillet 400g $19.00</code> on separate lines.
                            </p>
                            <textarea id="receipt_paste_area" class="receipt-text-textarea" placeholder="Paste ingredients list here...&#10;1kg Chicken breast $6.50&#10;Oats 500g $2.20"></textarea>
                            
                            <button id="paste-scanner-btn" class="btn btn-secondary btn-block" style="margin-top: 12px;">
                                <span>Parse Text Receipt</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Right: Verified scan list -->
                    <div id="scanner-verify-box" style="display: none;">
                        <div class="glass-card">
                            <div class="glass-card-header">
                                <h3>Verify Extracted Items</h3>
                            </div>
                            <p style="color: var(--text-secondary); font-size: 12px; margin-bottom: 12px;">
                                Review the quantities and prices scanned. Double-click fields to correct mistakes before importing.
                            </p>
                            
                            <div class="verify-table-container">
                                <table class="verify-table">
                                    <thead>
                                        <tr>
                                            <th>Item Name</th>
                                            <th>Qty</th>
                                            <th>Unit</th>
                                            <th>Category</th>
                                            <th>Price ($)</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="verify-table-body">
                                        <!-- Scanned rows injected by app.js -->
                                    </tbody>
                                </table>
                            </div>
                            
                            <button id="import-scanner-btn" class="btn btn-primary btn-block" style="margin-top: 20px;">
                                Confirm & Import to Pantry
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ------------------------------------------------
                 4. BUDGET TAB
                 ------------------------------------------------ -->
            <div id="budget-panel" class="tab-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <h1>Budget Planner & Stats</h1>
                        <p>Monitor kitchen expenses and spending distribution</p>
                    </div>
                </div>
                
                <div id="budget-alert" class="alert" style="display: none;"></div>
                <div id="budget-loader" style="display: none; text-align: center; padding: 40px; color: var(--text-secondary);">Loading charts...</div>
                
                <div class="budget-grid">
                    <!-- Left: Budget circular visualizer -->
                    <div class="glass-card" style="display: flex; flex-direction: column; align-items: center;">
                        <div class="glass-card-header" style="width: 100%;">
                            <h3>Weekly Budget Utilization</h3>
                        </div>
                        
                        <div class="budget-progress-container" style="position: relative;">
                            <svg class="budget-ring-svg" width="220" height="220">
                                <defs>
                                    <linearGradient id="budget-grad" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%" stop-color="var(--color-primary)" />
                                        <stop offset="100%" stop-color="var(--color-secondary)" />
                                    </linearGradient>
                                </defs>
                                <circle class="budget-ring-circle-bg" cx="110" cy="110" r="90" stroke-width="12"></circle>
                                <circle id="budget-ring-circle" class="budget-ring-circle-fill" cx="110" cy="110" r="90" stroke-width="12"></circle>
                            </svg>
                            
                            <div class="budget-percentage-text">
                                <h2 id="budget-pct-label">0%</h2>
                                <span id="budget-status-badge" class="meal-badge" style="margin-top: 4px;">Healthy</span>
                            </div>
                        </div>
                        
                        <div class="budget-stats">
                            <div>
                                <p style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Spent</p>
                                <span class="budget-stat-val spent" id="budget-spent-val">$0.00</span>
                            </div>
                            <div style="border-left: 1px solid rgba(255,255,255,0.05); padding-left: 20px;">
                                <p style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Weekly Limit</p>
                                <span class="budget-stat-val" id="budget-limit-val">$150.00</span>
                            </div>
                            <div style="border-left: 1px solid rgba(255,255,255,0.05); padding-left: 20px;">
                                <p style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Remaining</p>
                                <span class="budget-stat-val remaining" id="budget-remain-val">$150.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right: Expenditures breakdown -->
                    <div>
                        <!-- Pantry Breakdown list -->
                        <div class="glass-card">
                            <div class="glass-card-header">
                                <h3>Spending by Pantry Category</h3>
                            </div>
                            <div class="budget-cats-list" id="budget-breakdown-list">
                                <!-- Breakdowns injected by app.js -->
                            </div>
                        </div>
                        
                        <!-- Meal costs stats -->
                        <div class="glass-card">
                            <div class="glass-card-header">
                                <h3>Meal Plan Costs</h3>
                            </div>
                            <div class="nutrition-stats-widget">
                                <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.03);">
                                    <span>Cumulative Meal Plan Cost:</span>
                                    <strong id="budget-meal-total">$0.00</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.03);">
                                    <span>Plan Period:</span>
                                    <span id="budget-meal-days">0 days planned</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 8px 0; font-size: 16px; font-weight: 700; color: var(--color-success);">
                                    <span>Average Cost per Day:</span>
                                    <span id="budget-meal-avg">$0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- ------------------------------------------------
         RECIPE EXPANDABLE DETAIL MODAL
         ------------------------------------------------ -->
    <div class="recipe-modal" id="recipe-modal">
        <div class="recipe-modal-content">
            <span class="modal-close-btn" id="modal-close">✕</span>
            <h2 id="modal-title" style="margin-bottom: 4px;">Recipe Title</h2>
            <div class="meal-meta" style="margin-bottom: 24px;">
                <span id="modal-time">⏱ 0 mins</span>
                <span id="modal-cost">🏷 $0.00 estimated</span>
            </div>
            
            <div class="grid-cols-2" style="margin-bottom: 24px;">
                <!-- Nutrients -->
                <div>
                    <h3 style="font-size: 16px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px;">Macronutrients</h3>
                    <div class="nutrient-strip" style="flex-direction: column; gap: 8px;">
                        <div class="flex-between">
                            <span>Calories:</span>
                            <strong id="modal-calories" style="color: var(--color-accent);">0 kcal</strong>
                        </div>
                        <div class="flex-between">
                            <span>Protein:</span>
                            <strong id="modal-protein" style="color: var(--color-secondary);">0g</strong>
                        </div>
                        <div class="flex-between">
                            <span>Carbs:</span>
                            <strong id="modal-carbs" style="color: var(--color-warning);">0g</strong>
                        </div>
                        <div class="flex-between">
                            <span>Fat:</span>
                            <strong id="modal-fat" style="color: var(--color-primary-hover);">0g</strong>
                        </div>
                    </div>
                </div>
                
                <!-- Ingredients Checklist -->
                <div>
                    <h3 style="font-size: 16px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px;">Ingredients Check</h3>
                    <ul id="modal-ingredients-list" style="list-style: none; padding: 0;">
                        <!-- List entries injected by app.js -->
                    </ul>
                </div>
            </div>
            
            <div>
                <h3 style="font-size: 16px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px;">Cooking Directions</h3>
                <div id="modal-instructions" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); padding: 20px; border-radius: var(--radius-sm); font-size: 15px; line-height: 1.6; white-space: pre-line;">
                    <!-- Directions injected by app.js -->
                </div>
            </div>
        </div>
    </div>

    <!-- Core App logic -->
    <script src="js/app.js"></script>
</body>
</html>
