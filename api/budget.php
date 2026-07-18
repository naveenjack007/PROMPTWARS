<?php
// cooking-todo-app/api/budget.php

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();
    
    // 1. Calculate total value of groceries currently in the pantry
    $totalValStmt = $pdo->prepare("SELECT SUM(price) as total FROM groceries WHERE user_id = ?");
    $totalValStmt->execute([$userId]);
    $totalValue = floatval($totalValStmt->fetch()['total'] ?? 0.0);
    
    // 2. Fetch category-wise breakdown
    $breakdownStmt = $pdo->prepare("SELECT category, SUM(price) as total_price, COUNT(id) as item_count 
        FROM groceries 
        WHERE user_id = ? 
        GROUP BY category 
        ORDER BY total_price DESC");
    $breakdownStmt->execute([$userId]);
    $breakdown = $breakdownStmt->fetchAll();
    
    // 3. Define budget limits (mock settings for demo purposes, can be extended to user settings)
    $weeklyBudgetLimit = 150.00;
    $remainingBudget = max(0, $weeklyBudgetLimit - $totalValue);
    $percentageUsed = ($weeklyBudgetLimit > 0) ? round(($totalValue / $weeklyBudgetLimit) * 100, 1) : 100;
    
    // 4. Calculate cost details of generated meal plans for current month
    // We can average recipe cost estimates
    $mealPlanCostStmt = $pdo->prepare("SELECT 
        SUM(r_bf.cost_estimate + r_lh.cost_estimate + r_dn.cost_estimate) as total_plan_cost,
        COUNT(mp.id) as plan_days
        FROM meal_plans mp
        LEFT JOIN recipes r_bf ON mp.breakfast_recipe_id = r_bf.id
        LEFT JOIN recipes r_lh ON mp.lunch_recipe_id = r_lh.id
        LEFT JOIN recipes r_dn ON mp.dinner_recipe_id = r_dn.id
        WHERE mp.user_id = ?");
    $mealPlanCostStmt->execute([$userId]);
    $mealPlanCostRow = $mealPlanCostStmt->fetch();
    $totalMealPlanCost = floatval($mealPlanCostRow['total_plan_cost'] ?? 0.0);
    $planDays = intval($mealPlanCostRow['plan_days'] ?? 0);
    $averageDayCost = ($planDays > 0) ? round($totalMealPlanCost / $planDays, 2) : 0.00;
    
    // 5. Gather category list with default colors for the UI charts
    $categoryColors = [
        'Vegetables' => '#10b981', // Emerald green
        'Proteins' => '#f43f5e',   // Rose red
        'Dairy' => '#3b82f6',      // Blue
        'Grains' => '#eab308',      // Amber yellow
        'Fruit' => '#ec4899',       // Pink
        'Pantry' => '#8b5cf6'       // Violet purple
    ];
    
    $processedBreakdown = [];
    foreach ($breakdown as $row) {
        $cat = $row['category'];
        $processedBreakdown[] = [
            'category' => $cat,
            'amount' => floatval($row['total_price']),
            'item_count' => intval($row['item_count']),
            'color' => $categoryColors[$cat] ?? '#6b7280' // default slate gray
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'budget' => [
            'total_spent' => $totalValue,
            'limit' => $weeklyBudgetLimit,
            'remaining' => $remainingBudget,
            'percentage_used' => $percentageUsed,
            'status_label' => ($percentageUsed > 90) ? 'Limit Reached' : (($percentageUsed > 65) ? 'Warning: High Spending' : 'Healthy'),
            'status_color' => ($percentageUsed > 90) ? 'var(--color-danger)' : (($percentageUsed > 65) ? 'var(--color-warning)' : 'var(--color-success)')
        ],
        'breakdown' => $processedBreakdown,
        'meal_costs' => [
            'total_meal_plan_cost' => $totalMealPlanCost,
            'plan_days' => $planDays,
            'average_daily_cost' => $averageDayCost
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
?>
