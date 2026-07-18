<?php
// cooking-todo-app/api/meals.php

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

try {
    $pdo = getDBConnection();
    
    if ($action === 'current') {
        $date = $_GET['date'] ?? date('Y-m-d');
        
        // Fetch meal plan for the date
        $stmt = $pdo->prepare("SELECT mp.*, 
            r_bf.name as bf_name, r_bf.calories as bf_cal, r_bf.protein as bf_prot, r_bf.carbs as bf_carb, r_bf.fat as bf_fat, r_bf.prep_time as bf_time, r_bf.cost_estimate as bf_cost, r_bf.instructions as bf_inst,
            r_lh.name as lh_name, r_lh.calories as lh_cal, r_lh.protein as lh_prot, r_lh.carbs as lh_carb, r_lh.fat as lh_fat, r_lh.prep_time as lh_time, r_lh.cost_estimate as lh_cost, r_lh.instructions as lh_inst,
            r_dn.name as dn_name, r_dn.calories as dn_cal, r_dn.protein as dn_prot, r_dn.carbs as dn_carb, r_dn.fat as dn_fat, r_dn.prep_time as dn_time, r_dn.cost_estimate as dn_cost, r_dn.instructions as dn_inst
            FROM meal_plans mp
            LEFT JOIN recipes r_bf ON mp.breakfast_recipe_id = r_bf.id
            LEFT JOIN recipes r_lh ON mp.lunch_recipe_id = r_lh.id
            LEFT JOIN recipes r_dn ON mp.dinner_recipe_id = r_dn.id
            WHERE mp.user_id = ? AND mp.plan_date = ?");
        $stmt->execute([$userId, $date]);
        $plan = $stmt->fetch();
        
        if (!$plan) {
            echo json_encode(['status' => 'success', 'has_plan' => false]);
            exit;
        }
        
        // Fetch prep todo tasks for this plan
        $taskStmt = $pdo->prepare("SELECT * FROM todo_tasks WHERE user_id = ? AND meal_plan_id = ? ORDER BY id ASC");
        $taskStmt->execute([$userId, $plan['id']]);
        $tasks = $taskStmt->fetchAll();
        
        // Get ingredients details for these recipes to show in UI
        $recipeIds = array_filter([$plan['breakfast_recipe_id'], $plan['lunch_recipe_id'], $plan['dinner_recipe_id']]);
        $ingredients = [];
        if (!empty($recipeIds)) {
            $inQuery = implode(',', array_fill(0, count($recipeIds), '?'));
            $ingStmt = $pdo->prepare("SELECT * FROM recipe_ingredients WHERE recipe_id IN ($inQuery)");
            $ingStmt->execute(array_values($recipeIds));
            $rawIngs = $ingStmt->fetchAll();
            foreach ($rawIngs as $ri) {
                $ingredients[$ri['recipe_id']][] = $ri;
            }
        }
        
        // Get user groceries to show status (available vs missing/subbed)
        $gstmt = $pdo->prepare("SELECT name, quantity, unit FROM groceries WHERE user_id = ?");
        $gstmt->execute([$userId]);
        $groceriesRaw = $gstmt->fetchAll();
        $groceries = [];
        foreach ($groceriesRaw as $g) {
            $groceries[strtolower(trim($g['name']))] = floatval($g['quantity']);
        }
        
        // Process status of each recipe's ingredients for UI
        $mealDetails = [];
        $meals = ['breakfast' => 'breakfast_recipe_id', 'lunch' => 'lunch_recipe_id', 'dinner' => 'dinner_recipe_id'];
        $shoppingList = [];
        $substitutionsUsed = [];
        
        // Track pantry items in memory during matching to deduct quantities
        $tempPantry = $groceries;
        
        foreach ($meals as $type => $col) {
            $rId = $plan[$col];
            if (!$rId) continue;
            
            $rName = $plan[substr($type, 0, 2) . '_name'];
            $rCal = $plan[substr($type, 0, 2) . '_cal'];
            $rProt = $plan[substr($type, 0, 2) . '_prot'];
            $rCarb = $plan[substr($type, 0, 2) . '_carb'];
            $rFat = $plan[substr($type, 0, 2) . '_fat'];
            $rTime = $plan[substr($type, 0, 2) . '_time'];
            $rCost = $plan[substr($type, 0, 2) . '_cost'];
            $rInst = $plan[substr($type, 0, 2) . '_inst'];
            
            $recipeIngs = $ingredients[$rId] ?? [];
            $processedIngs = [];
            
            foreach ($recipeIngs as $ing) {
                $ingName = strtolower(trim($ing['ingredient_name']));
                $qtyNeeded = floatval($ing['quantity']);
                $unit = $ing['unit'];
                $isEssential = intval($ing['is_essential']);
                $subSuggestion = strtolower(trim($ing['substitution_suggestion'] ?? ''));
                
                $status = 'missing';
                $usedSub = null;
                
                if (isset($tempPantry[$ingName]) && $tempPantry[$ingName] >= $qtyNeeded) {
                    $status = 'available';
                    $tempPantry[$ingName] -= $qtyNeeded;
                } elseif (!empty($subSuggestion) && isset($tempPantry[$subSuggestion]) && $tempPantry[$subSuggestion] >= $qtyNeeded) {
                    $status = 'substituted';
                    $usedSub = $ing['substitution_suggestion'];
                    $tempPantry[$subSuggestion] -= $qtyNeeded;
                    $substitutionsUsed[] = [
                        'meal' => $rName,
                        'original' => $ing['ingredient_name'],
                        'substituted_with' => $ing['substitution_suggestion']
                    ];
                } else {
                    // Check if non-essential
                    if ($isEssential === 0) {
                        $status = 'optional_missing';
                    } else {
                        // Put in shopping list
                        $deficit = $qtyNeeded - ($tempPantry[$ingName] ?? 0);
                        if (!isset($shoppingList[$ingName])) {
                            $shoppingList[$ingName] = [
                                'name' => $ing['ingredient_name'],
                                'quantity' => 0,
                                'unit' => $unit,
                                'estimated_cost' => 0.00
                            ];
                        }
                        $shoppingList[$ingName]['quantity'] += $deficit;
                        // Estimate price: approximate values
                        $approxPrices = ['chicken breast' => 1.2, 'salmon fillet' => 2.5, 'avocado' => 2.0, 'bread' => 0.2, 'egg' => 0.4, 'asparagus' => 1.5, 'quinoa' => 0.8, 'broccoli' => 1.0, 'tofu' => 0.9, 'brown rice' => 0.3];
                        $unitPrice = $approxPrices[$ingName] ?? 1.00;
                        $shoppingList[$ingName]['estimated_cost'] += ($deficit * $unitPrice);
                    }
                }
                
                $processedIngs[] = [
                    'name' => $ing['ingredient_name'],
                    'quantity' => $qtyNeeded,
                    'unit' => $unit,
                    'is_essential' => $isEssential,
                    'status' => $status,
                    'substituted_with' => $usedSub
                ];
            }
            
            $mealDetails[$type] = [
                'recipe_id' => $rId,
                'name' => $rName,
                'prep_time' => $rTime,
                'cost_estimate' => $rCost,
                'instructions' => $rInst,
                'calories' => $rCal,
                'protein' => $rProt,
                'carbs' => $rCarb,
                'fat' => $rFat,
                'ingredients' => $processedIngs
            ];
        }
        
        // Compile overall nutrition
        $nutrition = [
            'calories' => intval($plan['bf_cal'] + $plan['lh_cal'] + $plan['dn_cal']),
            'protein' => floatval($plan['bf_prot'] + $plan['lh_prot'] + $plan['dn_prot']),
            'carbs' => floatval($plan['bf_carb'] + $plan['lh_carb'] + $plan['dn_carb']),
            'fat' => floatval($plan['bf_fat'] + $plan['lh_fat'] + $plan['dn_fat'])
        ];
        
        echo json_encode([
            'status' => 'success',
            'has_plan' => true,
            'plan_id' => $plan['id'],
            'date' => $plan['plan_date'],
            'meals' => $mealDetails,
            'tasks' => $tasks,
            'substitutions' => $substitutionsUsed,
            'shopping_list' => array_values($shoppingList),
            'nutrition' => $nutrition
        ]);
        exit;
    }
    
    if ($action === 'generate') {
        $date = $_POST['date'] ?? date('Y-m-d');
        
        // 1. Fetch user's pantry
        $gstmt = $pdo->prepare("SELECT name, quantity, unit FROM groceries WHERE user_id = ?");
        $gstmt->execute([$userId]);
        $groceriesRaw = $gstmt->fetchAll();
        $pantry = [];
        foreach ($groceriesRaw as $g) {
            $pantry[strtolower(trim($g['name']))] = floatval($g['quantity']);
        }
        
        // 2. Fetch all recipes and ingredients
        $recipes = $pdo->query("SELECT * FROM recipes")->fetchAll();
        $ingredients = [];
        $ingRaw = $pdo->query("SELECT * FROM recipe_ingredients")->fetchAll();
        foreach ($ingRaw as $ing) {
            $ingredients[$ing['recipe_id']][] = $ing;
        }
        
        $mealTypes = ['breakfast', 'lunch', 'dinner'];
        $selectedRecipes = [];
        $tempPantry = $pantry; // matching memory
        
        foreach ($mealTypes as $type) {
            $typeRecipes = array_filter($recipes, function($r) use ($type) {
                return $r['meal_type'] === $type;
            });
            
            $bestRecipe = null;
            $bestScore = -1;
            
            foreach ($typeRecipes as $recipe) {
                $recipeId = $recipe['id'];
                $recipeIngs = $ingredients[$recipeId] ?? [];
                
                $totalIngs = count($recipeIngs);
                if ($totalIngs === 0) continue;
                
                $matchCount = 0;
                
                foreach ($recipeIngs as $ing) {
                    $ingName = strtolower(trim($ing['ingredient_name']));
                    $qtyNeeded = floatval($ing['quantity']);
                    $isEssential = intval($ing['is_essential']);
                    $subSuggestion = strtolower(trim($ing['substitution_suggestion'] ?? ''));
                    
                    if (isset($tempPantry[$ingName]) && $tempPantry[$ingName] >= $qtyNeeded) {
                        $matchCount += 1.0;
                    } elseif (!empty($subSuggestion) && isset($tempPantry[$subSuggestion]) && $tempPantry[$subSuggestion] >= $qtyNeeded) {
                        $matchCount += 0.8; // slightly lower score for substitutions
                    } elseif ($isEssential === 0) {
                        $matchCount += 0.5; // partial credit for missing optional items
                    }
                }
                
                $score = ($matchCount / $totalIngs) * 100;
                
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestRecipe = $recipe;
                }
            }
            
            if ($bestRecipe) {
                $selectedRecipes[$type] = $bestRecipe;
                // Deduct matching items from tempPantry
                $recipeId = $bestRecipe['id'];
                $recipeIngs = $ingredients[$recipeId] ?? [];
                foreach ($recipeIngs as $ing) {
                    $ingName = strtolower(trim($ing['ingredient_name']));
                    $qtyNeeded = floatval($ing['quantity']);
                    $subSuggestion = strtolower(trim($ing['substitution_suggestion'] ?? ''));
                    
                    if (isset($tempPantry[$ingName]) && $tempPantry[$ingName] >= $qtyNeeded) {
                        $tempPantry[$ingName] -= $qtyNeeded;
                    } elseif (!empty($subSuggestion) && isset($tempPantry[$subSuggestion]) && $tempPantry[$subSuggestion] >= $qtyNeeded) {
                        $tempPantry[$subSuggestion] -= $qtyNeeded;
                    }
                }
            }
        }
        
        if (count($selectedRecipes) < 3) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Not enough recipes to generate meal plan.']);
            exit;
        }
        
        $bfId = $selectedRecipes['breakfast']['id'];
        $lhId = $selectedRecipes['lunch']['id'];
        $dnId = $selectedRecipes['dinner']['id'];
        
        // Remove existing meal plan for this user on this date
        $delStmt = $pdo->prepare("DELETE FROM meal_plans WHERE user_id = ? AND plan_date = ?");
        $delStmt->execute([$userId, $date]);
        
        // Insert new plan
        $insStmt = $pdo->prepare("INSERT INTO meal_plans (user_id, plan_date, breakfast_recipe_id, lunch_recipe_id, dinner_recipe_id) VALUES (?, ?, ?, ?, ?)");
        $insStmt->execute([$userId, $date, $bfId, $lhId, $dnId]);
        $planId = $pdo->lastInsertId();
        
        // Generate Prep To-Do Tasks
        $tasks = [
            "Wash and prep fresh produce (Avocados, Vegetables, Herbs) for the day.",
            "Meal Prep: Pre-portion ingredients for Breakfast (" . $selectedRecipes['breakfast']['name'] . ").",
            "Cooking Breakfast: Prepare " . $selectedRecipes['breakfast']['name'] . ".",
            "Lunch Prep: Sauté ingredients and cook base for " . $selectedRecipes['lunch']['name'] . ".",
            "Dinner Prep: Marinate/season proteins for " . $selectedRecipes['dinner']['name'] . ".",
            "Cooking Dinner: Assemble and cook " . $selectedRecipes['dinner']['name'] . ".",
            "Kitchen Clean-up: Wash prep utensils and organize leftovers."
        ];
        
        $taskStmt = $pdo->prepare("INSERT INTO todo_tasks (user_id, meal_plan_id, task_description, is_completed) VALUES (?, ?, ?, 0)");
        foreach ($tasks as $task) {
            $taskStmt->execute([$userId, $planId, $task]);
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Meal plan generated successfully!',
            'date' => $date
        ]);
        exit;
    }
    
    if ($action === 'toggle_task') {
        $data = json_decode(file_get_contents('php://input'), true);
        $taskId = intval($data['task_id'] ?? 0);
        $isCompleted = intval($data['is_completed'] ?? 0);
        
        if ($taskId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid Task ID.']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE todo_tasks SET is_completed = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$isCompleted, $taskId, $userId]);
        
        echo json_encode(['status' => 'success', 'message' => 'Task updated.']);
        exit;
    }
    
    if ($action === 'delete_plan') {
        $date = $_POST['date'] ?? date('Y-m-d');
        
        $stmt = $pdo->prepare("DELETE FROM meal_plans WHERE user_id = ? AND plan_date = ?");
        $stmt->execute([$userId, $date]);
        
        echo json_encode(['status' => 'success', 'message' => 'Meal plan deleted.']);
        exit;
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid action or request method.']);
?>
