<?php
// cooking-todo-app/setup.php

require_once __DIR__ . '/db.php';

$pdo = getDBConnection();
$engine = $_SESSION['db_engine'] ?? 'Unknown';

header('Content-Type: application/json');

try {
    $is_sqlite = (strpos($engine, 'SQLite') !== false);
    
    // 1. Drop existing tables if they exist to start fresh for setup
    $tables = ['todo_tasks', 'meal_plans', 'recipe_ingredients', 'recipes', 'groceries', 'users'];
    if ($is_sqlite) {
        $pdo->exec("PRAGMA foreign_keys = OFF;");
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $pdo->exec("PRAGMA foreign_keys = ON;");
    } else {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    }

    // 2. Create tables
    if ($is_sqlite) {
        // SQLite Schema
        $pdo->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE groceries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            quantity REAL NOT NULL,
            unit TEXT NOT NULL,
            category TEXT NOT NULL,
            price REAL NOT NULL DEFAULT 0.0,
            expiry_date TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE recipes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            meal_type TEXT NOT NULL, -- breakfast, lunch, dinner
            instructions TEXT NOT NULL,
            prep_time INTEGER NOT NULL,
            calories INTEGER NOT NULL,
            protein REAL NOT NULL,
            carbs REAL NOT NULL,
            fat REAL NOT NULL,
            cost_estimate REAL NOT NULL DEFAULT 0.0
        )");

        $pdo->exec("CREATE TABLE recipe_ingredients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL,
            ingredient_name TEXT NOT NULL,
            quantity REAL NOT NULL,
            unit TEXT NOT NULL,
            is_essential INTEGER DEFAULT 1,
            substitution_suggestion TEXT,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE meal_plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            plan_date TEXT NOT NULL,
            breakfast_recipe_id INTEGER,
            lunch_recipe_id INTEGER,
            dinner_recipe_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (breakfast_recipe_id) REFERENCES recipes(id) ON DELETE SET NULL,
            FOREIGN KEY (lunch_recipe_id) REFERENCES recipes(id) ON DELETE SET NULL,
            FOREIGN KEY (dinner_recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
        )");

        $pdo->exec("CREATE TABLE todo_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            meal_plan_id INTEGER NOT NULL,
            task_description TEXT NOT NULL,
            is_completed INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (meal_plan_id) REFERENCES meal_plans(id) ON DELETE CASCADE
        )");
    } else {
        // MySQL Schema
        $pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE groceries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL,
            unit VARCHAR(20) NOT NULL,
            category VARCHAR(50) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            expiry_date DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE recipes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            meal_type ENUM('breakfast', 'lunch', 'dinner') NOT NULL,
            instructions TEXT NOT NULL,
            prep_time INT NOT NULL,
            calories INT NOT NULL,
            protein DECIMAL(5,2) NOT NULL,
            carbs DECIMAL(5,2) NOT NULL,
            fat DECIMAL(5,2) NOT NULL,
            cost_estimate DECIMAL(10,2) NOT NULL DEFAULT 0.00
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE recipe_ingredients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INT NOT NULL,
            ingredient_name VARCHAR(100) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL,
            unit VARCHAR(20) NOT NULL,
            is_essential TINYINT(1) DEFAULT 1,
            substitution_suggestion VARCHAR(255),
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE meal_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            plan_date DATE NOT NULL,
            breakfast_recipe_id INT,
            lunch_recipe_id INT,
            dinner_recipe_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (breakfast_recipe_id) REFERENCES recipes(id) ON DELETE SET NULL,
            FOREIGN KEY (lunch_recipe_id) REFERENCES recipes(id) ON DELETE SET NULL,
            FOREIGN KEY (dinner_recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE todo_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            meal_plan_id INT NOT NULL,
            task_description VARCHAR(255) NOT NULL,
            is_completed TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (meal_plan_id) REFERENCES meal_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }

    // 3. Seed Recipes
    $recipes = [
        [
            'name' => 'Avocado Egg Toast',
            'meal_type' => 'breakfast',
            'instructions' => '1. Toast the slices of bread until golden brown.\n2. Slice and mash the avocado with salt and black pepper.\n3. Fry the eggs in a pan with a little oil/butter to your preference.\n4. Spread the mashed avocado over toast and top with the fried eggs.',
            'prep_time' => 10,
            'calories' => 380,
            'protein' => 14.0,
            'carbs' => 28.0,
            'fat' => 22.0,
            'cost_estimate' => 3.50,
            'ingredients' => [
                ['name' => 'avocado', 'qty' => 1.0, 'unit' => 'pc', 'essential' => 1, 'sub' => 'hummus'],
                ['name' => 'bread', 'qty' => 2.0, 'unit' => 'slices', 'essential' => 1, 'sub' => 'gluten-free toast'],
                ['name' => 'egg', 'qty' => 2.0, 'unit' => 'pcs', 'essential' => 1, 'sub' => 'tofu scramble'],
                ['name' => 'salt', 'qty' => 1.0, 'unit' => 'pinch', 'essential' => 0, 'sub' => 'soy sauce'],
                ['name' => 'pepper', 'qty' => 1.0, 'unit' => 'pinch', 'essential' => 0, 'sub' => 'chili flakes']
            ]
        ],
        [
            'name' => 'Almond Banana Oatmeal',
            'meal_type' => 'breakfast',
            'instructions' => '1. Bring water or almond milk to a boil in a small saucepan.\n2. Stir in oats and cook on medium heat for 5 minutes.\n3. Remove from heat, stir in sliced banana and a drizzle of honey.\n4. Garnish with chopped almonds on top.',
            'prep_time' => 8,
            'calories' => 320,
            'protein' => 8.0,
            'carbs' => 52.0,
            'fat' => 9.0,
            'cost_estimate' => 2.20,
            'ingredients' => [
                ['name' => 'oats', 'qty' => 50.0, 'unit' => 'g', 'essential' => 1, 'sub' => 'quinoa flakes'],
                ['name' => 'almond milk', 'qty' => 200.0, 'unit' => 'ml', 'essential' => 1, 'sub' => 'milk or water'],
                ['name' => 'banana', 'qty' => 1.0, 'unit' => 'pc', 'essential' => 1, 'sub' => 'apple slices'],
                ['name' => 'honey', 'qty' => 1.0, 'unit' => 'tbsp', 'essential' => 0, 'sub' => 'maple syrup'],
                ['name' => 'almonds', 'qty' => 15.0, 'unit' => 'g', 'essential' => 0, 'sub' => 'walnuts']
            ]
        ],
        [
            'name' => 'Garlic Chicken Quinoa Bowl',
            'meal_type' => 'lunch',
            'instructions' => '1. Rinse and cook quinoa in water (1:2 ratio) for 15 minutes.\n2. Heat olive oil in a skillet, sauté minced garlic, and add cubed chicken breast.\n3. Cook chicken until golden brown and cooked through (about 8-10 mins).\n4. Steam broccoli florets in a separate pot.\n5. Combine quinoa, chicken, and broccoli in a bowl. Season with salt and pepper.',
            'prep_time' => 25,
            'calories' => 510,
            'protein' => 42.0,
            'carbs' => 45.0,
            'fat' => 15.0,
            'cost_estimate' => 5.80,
            'ingredients' => [
                ['name' => 'chicken breast', 'qty' => 150.0, 'unit' => 'g', 'essential' => 1, 'sub' => 'tofu or turkey breast'],
                ['name' => 'quinoa', 'qty' => 60.0, 'unit' => 'g', 'essential' => 1, 'sub' => 'brown rice or couscous'],
                ['name' => 'broccoli', 'qty' => 100.0, 'unit' => 'g', 'essential' => 1, 'sub' => 'spinach or green beans'],
                ['name' => 'olive oil', 'qty' => 1.0, 'unit' => 'tbsp', 'essential' => 0, 'sub' => 'vegetable oil'],
                ['name' => 'garlic', 'qty' => 1.0, 'unit' => 'clove', 'essential' => 0, 'sub' => 'garlic powder']
            ]
        ],
        [
            'name' => 'Greek Chickpea Salad',
            'meal_type' => 'lunch',
            'instructions' => '1. Drain and rinse the canned chickpeas.\n2. Dice cucumber, tomato, and red onion.\n3. Mix chickpeas and vegetables in a salad bowl.\n4. Toss with olive oil, lemon juice, salt, and pepper.\n5. Top with crumbled feta cheese.',
            'prep_time' => 15,
            'calories' => 420,
            'protein' => 12.0,
            'carbs' => 48.0,
            'fat' => 20.0,
            'cost_estimate' => 3.10,
            'ingredients' => [
                ['name' => 'chickpeas', 'qty' => 240.0, 'unit' => 'g', 'essential' => 1, 'sub' => 'white beans or black beans'],
                ['name' => 'cucumber', 'qty' => 0.5, 'unit' => 'pc', 'essential' => 1, 'sub' => 'zucchini'],
                ['name' => 'tomato', 'qty' => 1.0, 'unit' => 'pc', 'essential' => 1, 'sub' => 'bell pepper'],
                ['name' => 'olive oil', 'qty' => 1.0, 'unit' => 'tbsp', 'essential' => 0, 'sub' => 'avocado oil'],
                ['name' => 'feta cheese', 'qty' => 30.0, 'unit' => 'g', 'essential' => 0, 'sub' => 'olives or goat cheese'],
                ['name' => 'lemon juice', 'qty' => 1.0, 'unit' => 'tbsp', 'essential' => 0, 'sub' => 'apple cider vinegar']
            ]
        ],
        [
            'name' => 'Pan-Seared Salmon & Asparagus',
            'meal_type' => 'dinner',
            'instructions' => '1. Pat salmon fillet dry and season with salt, pepper, and lemon juice.\n2. Heat olive oil and butter in a pan, sear salmon skin-side down for 4 minutes, flip and cook another 3 minutes.\n3. In the same pan, cook trimmed asparagus spears in the remaining butter/oil until tender-crisp.\n4. Serve salmon next to asparagus, drizzling pan juices on top.',
            'prep_time' => 20,
            'calories' => 480,
            'protein' => 38.0,
            'carbs' => 8.0,
            'fat' => 34.0,
            'cost_estimate' => 9.50,
            'ingredients' => [
                ['name' => 'salmon fillet', 'qty' => 200.0, 'unit' => 'g', 'essential' => 1, 'sub' => 'tuna steak or chicken breast'],
                ['name' => 'asparagus', 'qty' => 150.0, 'unit' => 'g', 'essential' => 1, 'sub' => 'green beans or broccoli'],
                ['name' => 'lemon', 'qty' => 1.0, 'unit' => 'pc', 'essential' => 0, 'sub' => 'lime'],
                ['name' => 'olive oil', 'qty' => 1.0, 'unit' => 'tbsp', 'essential' => 0, 'sub' => 'avocado oil'],
                ['name' => 'butter', 'qty' => 10.0, 'unit' => 'g', 'essential' => 0, 'sub' => 'margarine or olive oil']
            ]
        ],
        [
            'name' => 'Tofu Vegetable Stir Fry',
            'meal_type' => 'dinner',
            'instructions' => '1. Cook brown rice according to package directions.\n2. Press tofu to remove excess water, then cube and sauté in sesame oil until crispy.\n3. Add sliced bell pepper and carrots to the pan, cook for 5 minutes.\n4. Stir in soy sauce and toss until well coated.\n5. Serve the stir fry over hot brown rice.',
            'prep_time' => 30,
            'calories' => 460,
            'protein' => 16.0,
            'carbs' => 68.0,
            'fat' => 14.0,
            'cost_estimate' => 3.90,
            'ingredients' => [
                ['name' => 'tofu', 'qty' => 150.0, 'unit' => 'g', 'essential' => 1, 'sub' => 'chicken breast or tempeh'],
                ['name' => 'bell pepper', 'qty' => 1.0, 'unit' => 'pc', 'essential' => 1, 'sub' => 'onion'],
                ['name' => 'carrot', 'qty' => 1.0, 'unit' => 'pc', 'essential' => 1, 'sub' => 'broccoli'],
                ['name' => 'soy sauce', 'qty' => 2.0, 'unit' => 'tbsp', 'essential' => 0, 'sub' => 'coconut aminos'],
                ['name' => 'sesame oil', 'qty' => 1.0, 'unit' => 'tbsp', 'essential' => 0, 'sub' => 'olive oil'],
                ['name' => 'brown rice', 'qty' => 80.0, 'unit' => 'g', 'essential' => 1, 'sub' => 'white rice or noodles']
            ]
        ]
    ];

    $recipeStmt = $pdo->prepare("INSERT INTO recipes (name, meal_type, instructions, prep_time, calories, protein, carbs, fat, cost_estimate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $ingStmt = $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, ingredient_name, quantity, unit, is_essential, substitution_suggestion) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($recipes as $r) {
        $recipeStmt->execute([
            $r['name'],
            $r['meal_type'],
            $r['instructions'],
            $r['prep_time'],
            $r['calories'],
            $r['protein'],
            $r['carbs'],
            $r['fat'],
            $r['cost_estimate']
        ]);
        
        $recipeId = $pdo->lastInsertId();
        
        foreach ($r['ingredients'] as $ing) {
            $ingStmt->execute([
                $recipeId,
                $ing['name'],
                $ing['qty'],
                $ing['unit'],
                $ing['essential'],
                $ing['sub']
            ]);
        }
    }

    // 4. Create a default Demo user so they can try it immediately (password is 'demo123')
    $demoPasswordHash = password_hash('demo123', PASSWORD_BCRYPT);
    $userStmt = $pdo->prepare("INSERT OR IGNORE INTO users (id, username, password) VALUES (1, 'demo', ?)");
    if (!$is_sqlite) {
        // MySQL equivalent for INSERT OR IGNORE
        $userStmt = $pdo->prepare("INSERT INTO users (id, username, password) VALUES (1, 'demo', ?) ON DUPLICATE KEY UPDATE username='demo'");
    }
    $userStmt->execute([$demoPasswordHash]);

    // Seed some initial groceries for the demo user
    $groceries = [
        ['name' => 'avocado', 'qty' => 2, 'unit' => 'pc', 'cat' => 'Vegetables', 'price' => 4.00, 'expiry' => date('Y-m-d', strtotime('+4 days'))],
        ['name' => 'bread', 'qty' => 12, 'unit' => 'slices', 'cat' => 'Grains', 'price' => 2.50, 'expiry' => date('Y-m-d', strtotime('+6 days'))],
        ['name' => 'egg', 'qty' => 6, 'unit' => 'pcs', 'cat' => 'Dairy', 'price' => 3.00, 'expiry' => date('Y-m-d', strtotime('+10 days'))],
        ['name' => 'almonds', 'qty' => 100, 'unit' => 'g', 'cat' => 'Pantry', 'price' => 5.00, 'expiry' => date('Y-m-d', strtotime('+30 days'))],
        ['name' => 'banana', 'qty' => 3, 'unit' => 'pc', 'cat' => 'Fruit', 'price' => 1.50, 'expiry' => date('Y-m-d', strtotime('+3 days'))],
        ['name' => 'garlic', 'qty' => 4, 'unit' => 'cloves', 'cat' => 'Vegetables', 'price' => 0.80, 'expiry' => date('Y-m-d', strtotime('+20 days'))],
        ['name' => 'olive oil', 'qty' => 250, 'unit' => 'ml', 'cat' => 'Pantry', 'price' => 6.00, 'expiry' => date('Y-m-d', strtotime('+60 days'))]
    ];

    $groceryStmt = $pdo->prepare("INSERT INTO groceries (user_id, name, quantity, unit, category, price, expiry_date) VALUES (1, ?, ?, ?, ?, ?, ?)");
    foreach ($groceries as $g) {
        $groceryStmt->execute([
            $g['name'],
            $g['qty'],
            $g['unit'],
            $g['cat'],
            $g['price'],
            $g['expiry']
        ]);
    }

    echo json_encode([
        'status' => 'success',
        'message' => "Database setup complete! Seeded recipes, default user 'demo' (password: demo123), and sample pantry ingredients.",
        'engine' => $engine,
        'sqlite_file' => $is_sqlite ? SQLITE_FILE : null
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database setup failed: ' . $e->getMessage()
    ]);
}
?>
