// cooking-todo-app/js/app.js

document.addEventListener('DOMContentLoaded', () => {
    // Current State
    let currentTab = 'dashboard';
    let currentPlan = null;
    let pantryItems = [];
    let scannedItems = [];
    
    // Tab Switching Elements
    const navItems = document.querySelectorAll('.nav-item');
    const tabPanels = document.querySelectorAll('.tab-panel');
    
    // Check Auth and Setup Active States
    initApp();
    
    // Tab Switcher Click Handler
    navItems.forEach(item => {
        item.addEventListener('click', () => {
            const tabId = item.getAttribute('data-tab');
            if (tabId === 'logout') {
                window.location.href = 'logout.php';
                return;
            }
            switchTab(tabId);
        });
    });
    
    function switchTab(tabId) {
        currentTab = tabId;
        
        // Update nav items
        navItems.forEach(n => {
            if (n.getAttribute('data-tab') === tabId) {
                n.classList.add('active');
            } else {
                n.classList.remove('active');
            }
        });
        
        // Update panels
        tabPanels.forEach(panel => {
            if (panel.id === `${tabId}-panel`) {
                panel.classList.add('active');
            } else {
                panel.classList.remove('active');
            }
        });
        
        // Trigger specific tab loads
        if (tabId === 'dashboard') {
            loadDashboard();
        } else if (tabId === 'pantry') {
            loadPantry();
        } else if (tabId === 'scanner') {
            resetScanner();
        } else if (tabId === 'budget') {
            loadBudget();
        }
    }
    
    async function initApp() {
        // Run setup checks or load today's info
        loadDashboard();
    }
    
    /* ----------------------------------------------------
       DASHBOARD LOGIC
       ---------------------------------------------------- */
    async function loadDashboard() {
        showLoader('dashboard-loader', true);
        try {
            const response = await fetch('api/meals.php?action=current');
            const result = await response.json();
            
            if (response.ok && result.status === 'success') {
                if (result.has_plan) {
                    currentPlan = result;
                    renderMealPlan(result);
                    renderNutritionWidget(result.nutrition);
                    renderTodoList(result.tasks);
                    document.getElementById('no-plan-box').style.display = 'none';
                    document.getElementById('active-plan-box').style.display = 'block';
                } else {
                    currentPlan = null;
                    document.getElementById('active-plan-box').style.display = 'none';
                    document.getElementById('no-plan-box').style.display = 'block';
                }
            } else {
                showAlert('dashboard-alert', 'danger', result.message || 'Failed to load meal plan.');
            }
        } catch (err) {
            showAlert('dashboard-alert', 'danger', 'Network error loading meal plan.');
            console.error(err);
        } finally {
            showLoader('dashboard-loader', false);
        }
    }
    
    // Generate Meal Plan Click Handler
    document.getElementById('generate-plan-btn').addEventListener('click', async () => {
        const genBtn = document.getElementById('generate-plan-btn');
        genBtn.disabled = true;
        genBtn.innerHTML = '<span>Planning Meals...</span><span class="btn-glow"></span>';
        
        try {
            const today = new Date().toISOString().split('T')[0];
            const response = await fetch('api/meals.php?action=generate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `date=${today}`
            });
            const result = await response.json();
            
            if (response.ok && result.status === 'success') {
                loadDashboard();
            } else {
                alert(result.message || 'Generation failed. Seed recipes first.');
            }
        } catch (err) {
            alert('Failed to generate meal plan. Check console details.');
            console.error(err);
        } finally {
            genBtn.disabled = false;
            genBtn.innerHTML = '<span>Auto-Generate Plan</span><span class="btn-glow"></span>';
        }
    });
    
    // Render meal cards on dashboard
    function renderMealPlan(plan) {
        const container = document.getElementById('meal-cards-container');
        container.innerHTML = '';
        
        const meals = ['breakfast', 'lunch', 'dinner'];
        meals.forEach(mKey => {
            const meal = plan.meals[mKey];
            if (!meal) return;
            
            const card = document.createElement('div');
            card.className = 'meal-card';
            
            // Build ingredients pills
            let ingPillsHtml = '';
            meal.ingredients.forEach(ing => {
                let badgeClass = 'missing';
                let label = ing.name;
                
                if (ing.status === 'available') {
                    badgeClass = 'available';
                } else if (ing.status === 'substituted') {
                    badgeClass = 'substituted';
                    label = `${ing.name} (Subbed: ${ing.substituted_with})`;
                } else if (ing.status === 'optional_missing') {
                    badgeClass = 'missing';
                    label = `${ing.name} (Optional)`;
                }
                
                ingPillsHtml += `<span class="ing-pill ${badgeClass}">${label}</span> `;
            });
            
            card.innerHTML = `
                <span class="sidebar-badge-type meal-badge ${mKey}">${mKey}</span>
                <h3>${meal.name}</h3>
                <div class="meal-meta">
                    <span>⏱ ${meal.prep_time} mins</span>
                    <span>🏷 $${parseFloat(meal.cost_estimate).toFixed(2)}</span>
                </div>
                <div class="meal-ingredients-preview">
                    <h5>Ingredients</h5>
                    <div style="margin-top: 6px;">${ingPillsHtml}</div>
                </div>
                <div class="nutrient-strip">
                    <span>🔥 ${meal.calories} kcal</span>
                    <span>🥩 ${meal.protein}g P</span>
                    <span>🍞 ${meal.carbs}g C</span>
                </div>
                <button class="btn btn-secondary btn-sm view-recipe-btn" data-meal="${mKey}" style="margin-top: 16px; width: 100%;">View Recipe</button>
            `;
            
            container.appendChild(card);
        });
        
        // Add event listeners to the view recipe buttons
        document.querySelectorAll('.view-recipe-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const mealKey = btn.getAttribute('data-meal');
                openRecipeModal(plan.meals[mealKey]);
            });
        });
        
        // Render Shopping List Summary
        const shopBox = document.getElementById('dashboard-shopping-list');
        const shopListContainer = document.getElementById('dashboard-shop-items');
        shopListContainer.innerHTML = '';
        
        if (plan.shopping_list && plan.shopping_list.length > 0) {
            plan.shopping_list.forEach(item => {
                const li = document.createElement('div');
                li.style.cssText = 'display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,0.03); font-size: 13px;';
                li.innerHTML = `
                    <span>🛒 ${item.name} (${item.quantity} ${item.unit})</span>
                    <span style="color: var(--color-success); font-weight: 500;">+$${parseFloat(item.estimated_cost).toFixed(2)}</span>
                `;
                shopListContainer.appendChild(li);
            });
            shopBox.style.display = 'block';
        } else {
            shopBox.style.display = 'none';
        }
        
        // Render Substitutions summary
        const subBox = document.getElementById('dashboard-subs-box');
        const subListContainer = document.getElementById('dashboard-subs-items');
        subListContainer.innerHTML = '';
        
        if (plan.substitutions && plan.substitutions.length > 0) {
            plan.substitutions.forEach(sub => {
                const div = document.createElement('div');
                div.style.cssText = 'font-size: 13px; padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,0.03); color: #c084fc;';
                div.innerHTML = `🔄 <strong>${sub.meal}</strong>: Replace <em>${sub.original}</em> with <strong>${sub.substituted_with}</strong>`;
                subListContainer.appendChild(div);
            });
            subBox.style.display = 'block';
        } else {
            subBox.style.display = 'none';
        }
    }
    
    // Render progress bars for daily nutrients
    function renderNutritionWidget(nutri) {
        const calPct = Math.min(100, (nutri.calories / 2000) * 100);
        const protPct = Math.min(100, (nutri.protein / 75) * 100);
        const carbPct = Math.min(100, (nutri.carbs / 250) * 100);
        const fatPct = Math.min(100, (nutri.fat / 70) * 100);
        
        document.getElementById('cal-pct-text').textContent = `${nutri.calories} / 2000 kcal`;
        document.getElementById('cal-bar').style.width = `${calPct}%`;
        
        document.getElementById('prot-pct-text').textContent = `${parseFloat(nutri.protein).toFixed(1)} / 75g`;
        document.getElementById('prot-bar').style.width = `${protPct}%`;
        
        document.getElementById('carb-pct-text').textContent = `${parseFloat(nutri.carbs).toFixed(1)} / 250g`;
        document.getElementById('carb-bar').style.width = `${carbPct}%`;
        
        document.getElementById('fat-pct-text').textContent = `${parseFloat(nutri.fat).toFixed(1)} / 70g`;
        document.getElementById('fat-bar').style.width = `${fatPct}%`;
    }
    
    // Render To-Do list
    function renderTodoList(tasks) {
        const container = document.getElementById('todo-list-container');
        container.innerHTML = '';
        
        if (tasks.length === 0) {
            container.innerHTML = '<p style="color: var(--text-secondary); text-align: center;">No tasks generated.</p>';
            return;
        }
        
        tasks.forEach(task => {
            const item = document.createElement('div');
            const completed = parseInt(task.is_completed) === 1;
            item.className = `todo-item ${completed ? 'completed' : ''}`;
            item.setAttribute('data-id', task.id);
            item.setAttribute('data-status', task.is_completed);
            
            item.innerHTML = `
                <div class="todo-checkbox"></div>
                <div class="todo-text">${task.task_description}</div>
            `;
            
            item.addEventListener('click', async () => {
                const currentStatus = parseInt(item.getAttribute('data-status'));
                const newStatus = currentStatus === 1 ? 0 : 1;
                
                try {
                    const response = await fetch('api/meals.php?action=toggle_task', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ task_id: task.id, is_completed: newStatus })
                    });
                    
                    const res = await response.json();
                    if (response.ok && res.status === 'success') {
                        item.setAttribute('data-status', newStatus);
                        if (newStatus === 1) {
                            item.classList.add('completed');
                        } else {
                            item.classList.remove('completed');
                        }
                    }
                } catch (err) {
                    console.error('Failed to toggle task status', err);
                }
            });
            
            container.appendChild(item);
        });
    }
    
    // Delete Plan Handler
    document.getElementById('delete-plan-btn').addEventListener('click', async () => {
        if (!confirm('Are you sure you want to discard today\'s meal plan and prep tasks?')) return;
        
        try {
            const today = new Date().toISOString().split('T')[0];
            const response = await fetch('api/meals.php?action=delete_plan', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `date=${today}`
            });
            const result = await response.json();
            if (response.ok && result.status === 'success') {
                loadDashboard();
            }
        } catch (err) {
            console.error(err);
        }
    });

    /* ----------------------------------------------------
       PANTRY / GROCERIES LOGIC
       ---------------------------------------------------- */
    async function loadPantry() {
        showLoader('pantry-loader', true);
        try {
            const response = await fetch('api/groceries.php');
            const result = await response.json();
            
            if (response.ok && result.status === 'success') {
                pantryItems = result.data;
                renderPantryGrid();
            } else {
                showAlert('pantry-alert', 'danger', 'Failed to retrieve pantry items.');
            }
        } catch (err) {
            showAlert('pantry-alert', 'danger', 'Network error reading pantry.');
            console.error(err);
        } finally {
            showLoader('pantry-loader', false);
        }
    }
    
    function renderPantryGrid() {
        const container = document.getElementById('pantry-grid-container');
        container.innerHTML = '';
        
        if (pantryItems.length === 0) {
            container.innerHTML = `
                <div style="grid-column: 1/-1; text-align: center; padding: 40px; border: 1px dashed rgba(255,255,255,0.1); border-radius: var(--radius-md);">
                    <p style="color: var(--text-secondary); margin-bottom: 16px;">Your pantry is empty!</p>
                    <button class="btn btn-primary btn-sm" onclick="document.getElementById('add-item-modal-toggle').click()">Add First Item</button>
                </div>
            `;
            return;
        }
        
        pantryItems.forEach(item => {
            const card = document.createElement('div');
            card.className = 'pantry-card';
            
            // Check expiry warning
            let expiryHtml = '';
            if (item.expiry_date) {
                const expDate = new Date(item.expiry_date);
                const today = new Date();
                const diffTime = expDate - today;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                
                if (diffDays <= 0) {
                    expiryHtml = `<div class="expiry-warning">⚠️ Expired!</div>`;
                } else if (diffDays <= 3) {
                    expiryHtml = `<div class="expiry-warning">⚠️ Expires in ${diffDays}d</div>`;
                } else {
                    expiryHtml = `<div style="font-size: 11px; color: var(--text-muted); margin-top: 8px;">Expires: ${item.expiry_date}</div>`;
                }
            }
            
            card.innerHTML = `
                <div>
                    <div class="pantry-cat-tag">${item.category}</div>
                    <h4>${item.name}</h4>
                    <p>${parseFloat(item.quantity)} ${item.unit}</p>
                    <p class="price-tag">$${parseFloat(item.price).toFixed(2)}</p>
                    ${expiryHtml}
                </div>
                <div class="pantry-actions">
                    <button class="pantry-actions-btn delete" data-id="${item.id}">🗑️</button>
                </div>
            `;
            
            // Delete button handler
            card.querySelector('.delete').addEventListener('click', async () => {
                if (!confirm(`Remove ${item.name} from pantry?`)) return;
                try {
                    const res = await fetch('api/groceries.php?action=delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: item.id })
                    });
                    const data = await res.json();
                    if (res.ok && data.status === 'success') {
                        loadPantry();
                    }
                } catch (err) {
                    console.error(err);
                }
            });
            
            container.appendChild(card);
        });
    }
    
    // Add Item Form Submit
    document.getElementById('add-pantry-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const payload = {
            name: document.getElementById('pantry-add-name').value,
            quantity: parseFloat(document.getElementById('pantry-add-qty').value),
            unit: document.getElementById('pantry-add-unit').value,
            category: document.getElementById('pantry-add-cat').value,
            price: parseFloat(document.getElementById('pantry-add-price').value || 0.0),
            expiry_date: document.getElementById('pantry-add-expiry').value
        };
        
        try {
            const res = await fetch('api/groceries.php?action=add', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            
            if (res.ok && data.status === 'success') {
                // Clear fields
                document.getElementById('pantry-add-name').value = '';
                document.getElementById('pantry-add-qty').value = '1';
                document.getElementById('pantry-add-price').value = '';
                document.getElementById('pantry-add-expiry').value = '';
                
                // Hide card toggle
                document.getElementById('add-pantry-card').style.display = 'none';
                
                loadPantry();
            } else {
                alert(data.message || 'Failed to add item.');
            }
        } catch (err) {
            console.error(err);
        }
    });

    /* ----------------------------------------------------
       RECEIPT SCANNER LOGIC
       ---------------------------------------------------- */
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('receipt_file');
    const pasteArea = document.getElementById('receipt_paste_area');
    
    // Click dropzone to open input file
    dropzone.addEventListener('click', () => fileInput.click());
    
    // Handle File Drag/Drop
    dropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropzone.style.borderColor = 'var(--color-primary)';
        dropzone.style.background = 'rgba(139, 92, 246, 0.08)';
    });
    dropzone.addEventListener('dragleave', () => {
        dropzone.style.borderColor = 'rgba(139, 92, 246, 0.3)';
        dropzone.style.background = 'rgba(255, 255, 255, 0.01)';
    });
    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropzone.style.borderColor = 'rgba(139, 92, 246, 0.3)';
        dropzone.style.background = 'rgba(255, 255, 255, 0.01)';
        
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            fileInput.files = e.dataTransfer.files;
            handleFileSelect(e.dataTransfer.files[0]);
        }
    });
    
    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files[0]) {
            handleFileSelect(fileInput.files[0]);
        }
    });
    
    function handleFileSelect(file) {
        document.getElementById('dropzone-prompt').style.display = 'none';
        const previewWrapper = document.getElementById('preview-wrapper');
        const previewImg = document.getElementById('preview-img');
        
        // Show image file preview or txt name
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => {
                previewImg.src = e.target.result;
                previewWrapper.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            previewWrapper.style.display = 'none';
            document.getElementById('dropzone-prompt').innerHTML = `
                <div class="dropzone-icon">📄</div>
                <h3>${file.name}</h3>
                <p>Text file selected ready for upload.</p>
            `;
            document.getElementById('dropzone-prompt').style.display = 'block';
        }
    }
    
    // OCR Upload handler
    document.getElementById('upload-scanner-btn').addEventListener('click', async () => {
        const file = fileInput.files[0];
        if (!file) {
            alert('Please select or drag-and-drop a file first!');
            return;
        }
        
        // Show Laser Scanning line
        const laser = document.getElementById('scanner-laser');
        laser.style.display = 'block';
        
        const formData = new FormData();
        formData.append('receipt_file', file);
        
        try {
            // Wait 1.5 seconds for visual scanning aesthetic effect
            await new Promise(r => setTimeout(r, 1500));
            
            const response = await fetch('upload_handler.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            laser.style.display = 'none';
            
            if (response.ok && result.status === 'success') {
                scannedItems = result.data;
                renderVerifyTable();
                document.getElementById('scanner-verify-box').style.display = 'block';
            } else {
                alert(result.message || 'Scanning processing error.');
            }
        } catch (err) {
            laser.style.display = 'none';
            alert('Network error communicating with OCR simulator.');
            console.error(err);
        }
    });
    
    // Paste Text Submit handler
    document.getElementById('paste-scanner-btn').addEventListener('click', async () => {
        const text = pasteArea.value.trim();
        if (empty(text)) {
            alert('Pantry paste area is empty.');
            return;
        }
        
        const formData = new FormData();
        formData.append('receipt_text', text);
        
        try {
            const response = await fetch('upload_handler.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (response.ok && result.status === 'success') {
                scannedItems = result.data;
                renderVerifyTable();
                document.getElementById('scanner-verify-box').style.display = 'block';
                pasteArea.value = ''; // clear
            } else {
                alert(result.message || 'Failed parsing receipt text.');
            }
        } catch (err) {
            console.error(err);
        }
    });
    
    function renderVerifyTable() {
        const tbody = document.getElementById('verify-table-body');
        tbody.innerHTML = '';
        
        if (scannedItems.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No items detected. Check list format.</td></tr>';
            return;
        }
        
        scannedItems.forEach((item, index) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input type="text" value="${item.name}" data-field="name" data-idx="${index}"></td>
                <td><input type="number" step="any" value="${item.quantity}" data-field="quantity" data-idx="${index}" style="width: 70px;"></td>
                <td><input type="text" value="${item.unit}" data-field="unit" data-idx="${index}" style="width: 50px;"></td>
                <td>
                    <select data-field="category" data-idx="${index}">
                        <option value="Vegetables" ${item.category === 'Vegetables' ? 'selected' : ''}>Vegetables</option>
                        <option value="Proteins" ${item.category === 'Proteins' ? 'selected' : ''}>Proteins</option>
                        <option value="Dairy" ${item.category === 'Dairy' ? 'selected' : ''}>Dairy</option>
                        <option value="Grains" ${item.category === 'Grains' ? 'selected' : ''}>Grains</option>
                        <option value="Fruit" ${item.category === 'Fruit' ? 'selected' : ''}>Fruit</option>
                        <option value="Pantry" ${item.category === 'Pantry' ? 'selected' : ''}>Pantry</option>
                    </select>
                </td>
                <td><input type="number" step="0.01" value="${parseFloat(item.price).toFixed(2)}" data-field="price" data-idx="${index}" style="width: 70px;"></td>
                <td><button type="button" class="btn btn-secondary btn-sm remove-scanned-item" data-idx="${index}" style="padding: 4px 8px;">✕</button></td>
            `;
            
            // Add change listener to sync inputs back to memory
            tr.querySelectorAll('input, select').forEach(input => {
                input.addEventListener('change', (e) => {
                    const idx = parseInt(e.target.getAttribute('data-idx'));
                    const field = e.target.getAttribute('data-field');
                    let val = e.target.value;
                    if (field === 'quantity' || field === 'price') {
                        val = parseFloat(val);
                    }
                    scannedItems[idx][field] = val;
                });
            });
            
            tr.querySelector('.remove-scanned-item').addEventListener('click', () => {
                scannedItems.splice(index, 1);
                renderVerifyTable();
            });
            
            tbody.appendChild(tr);
        });
    }
    
    // Import Confirmed items to Pantry
    document.getElementById('import-scanner-btn').addEventListener('click', async () => {
        if (scannedItems.length === 0) return;
        
        const impBtn = document.getElementById('import-scanner-btn');
        impBtn.disabled = true;
        impBtn.textContent = 'Importing to Pantry...';
        
        try {
            const response = await fetch('api/groceries.php?action=import', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items: scannedItems })
            });
            const result = await response.json();
            
            if (response.ok && result.status === 'success') {
                alert(result.message);
                switchTab('pantry');
            } else {
                alert(result.message || 'Import failed.');
            }
        } catch (err) {
            console.error(err);
        } finally {
            impBtn.disabled = false;
            impBtn.textContent = 'Confirm & Import to Pantry';
        }
    });
    
    function resetScanner() {
        fileInput.value = '';
        document.getElementById('preview-wrapper').style.display = 'none';
        document.getElementById('dropzone-prompt').style.display = 'block';
        document.getElementById('dropzone-prompt').innerHTML = `
            <div class="dropzone-icon">📸</div>
            <h3>Upload Grocery Bill</h3>
            <p>Drag and drop receipt image or text file here, or click to browse.</p>
        `;
        document.getElementById('scanner-verify-box').style.display = 'none';
        scannedItems = [];
    }

    /* ----------------------------------------------------
       BUDGET & STATS LOGIC
       ---------------------------------------------------- */
    async function loadBudget() {
        showLoader('budget-loader', true);
        try {
            const response = await fetch('api/budget.php');
            const result = await response.json();
            
            if (response.ok && result.status === 'success') {
                renderBudgetRing(result.budget);
                renderBudgetBreakdown(result.breakdown);
                renderMealCostStats(result.meal_costs);
            } else {
                showAlert('budget-alert', 'danger', 'Failed to retrieve budget settings.');
            }
        } catch (err) {
            showAlert('budget-alert', 'danger', 'Network error reading budget data.');
            console.error(err);
        } finally {
            showLoader('budget-loader', false);
        }
    }
    
    function renderBudgetRing(budget) {
        document.getElementById('budget-pct-label').textContent = `${budget.percentage_used}%`;
        document.getElementById('budget-status-badge').textContent = budget.status_label;
        document.getElementById('budget-status-badge').style.background = `${budget.status_color}33`; // low alpha
        document.getElementById('budget-status-badge').style.color = budget.status_color;
        
        document.getElementById('budget-spent-val').textContent = `$${parseFloat(budget.total_spent).toFixed(2)}`;
        document.getElementById('budget-limit-val').textContent = `$${parseFloat(budget.limit).toFixed(2)}`;
        document.getElementById('budget-remain-val').textContent = `$${parseFloat(budget.remaining).toFixed(2)}`;
        
        // Circular ring math
        const circle = document.getElementById('budget-ring-circle');
        const radius = 90;
        const circumference = 2 * Math.PI * radius; // ~565.48
        
        circle.style.strokeDasharray = `${circumference} ${circumference}`;
        
        // offset calculation
        const offset = circumference - (Math.min(100, budget.percentage_used) / 100) * circumference;
        circle.style.strokeDashoffset = offset;
    }
    
    function renderBudgetBreakdown(breakdown) {
        const container = document.getElementById('budget-breakdown-list');
        container.innerHTML = '';
        
        if (breakdown.length === 0) {
            container.innerHTML = '<p style="color: var(--text-secondary); text-align: center; padding: 20px;">No pantry inventory values.</p>';
            return;
        }
        
        breakdown.forEach(row => {
            const item = document.createElement('div');
            item.className = 'budget-cat-item';
            item.innerHTML = `
                <div class="budget-cat-info">
                    <div class="budget-cat-color-dot" style="background: ${row.color};"></div>
                    <div>
                        <strong style="text-transform: capitalize;">${row.category}</strong>
                        <p style="font-size: 11px; color: var(--text-muted);">${row.item_count} items</p>
                    </div>
                </div>
                <div style="text-align: right;">
                    <strong>$${parseFloat(row.amount).toFixed(2)}</strong>
                </div>
            `;
            container.appendChild(item);
        });
    }
    
    function renderMealCostStats(costs) {
        document.getElementById('budget-meal-total').textContent = `$${parseFloat(costs.total_meal_plan_cost).toFixed(2)}`;
        document.getElementById('budget-meal-days').textContent = `${costs.plan_days} days planned`;
        document.getElementById('budget-meal-avg').textContent = `$${parseFloat(costs.average_daily_cost).toFixed(2)}`;
    }

    /* ----------------------------------------------------
       RECIPE VIEW MODAL
       ---------------------------------------------------- */
    const recipeModal = document.getElementById('recipe-modal');
    const modalClose = document.getElementById('modal-close');
    
    modalClose.addEventListener('click', closeRecipeModal);
    recipeModal.addEventListener('click', (e) => {
        if (e.target === recipeModal) closeRecipeModal();
    });
    
    function openRecipeModal(meal) {
        document.getElementById('modal-title').textContent = meal.name;
        document.getElementById('modal-time').textContent = `⏱ ${meal.prep_time} mins`;
        document.getElementById('modal-cost').textContent = `🏷 $${parseFloat(meal.cost_estimate).toFixed(2)} estimated cost`;
        
        document.getElementById('modal-calories').textContent = `${meal.calories} kcal`;
        document.getElementById('modal-protein').textContent = `${meal.protein}g`;
        document.getElementById('modal-carbs').textContent = `${meal.carbs}g`;
        document.getElementById('modal-fat').textContent = `${meal.fat}g`;
        
        // Render ingredients list
        const ingList = document.getElementById('modal-ingredients-list');
        ingList.innerHTML = '';
        
        meal.ingredients.forEach(ing => {
            const li = document.createElement('li');
            li.style.cssText = 'padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,0.03); display: flex; justify-content: space-between; font-size: 14px;';
            
            let statusText = 'Available';
            let color = 'var(--color-success)';
            
            if (ing.status === 'missing') {
                statusText = 'Missing';
                color = 'var(--color-danger)';
            } else if (ing.status === 'optional_missing') {
                statusText = 'Missing (Optional)';
                color = 'var(--text-muted)';
            } else if (ing.status === 'substituted') {
                statusText = `Substituted with ${ing.substituted_with}`;
                color = 'var(--color-primary-hover)';
            }
            
            li.innerHTML = `
                <span><strong>${parseFloat(ing.quantity)} ${ing.unit}</strong> ${ing.name}</span>
                <span style="color: ${color}; font-size: 12px; font-weight: 500;">${statusText}</span>
            `;
            ingList.appendChild(li);
        });
        
        // Format instructions
        const instructionsBox = document.getElementById('modal-instructions');
        // Split instructions by newlines if escaped
        const formattedInstructions = meal.instructions.replace(/\\n/g, '<br>');
        instructionsBox.innerHTML = formattedInstructions;
        
        recipeModal.classList.add('active');
    }
    
    function closeRecipeModal() {
        recipeModal.classList.remove('active');
    }

    /* ----------------------------------------------------
       GENERAL HELPERS
       ---------------------------------------------------- */
    function showLoader(elementId, isVisible) {
        const el = document.getElementById(elementId);
        if (el) el.style.display = isVisible ? 'block' : 'none';
    }
    
    function showAlert(elementId, type, message) {
        const el = document.getElementById(elementId);
        if (!el) return;
        el.className = `alert alert-${type}`;
        el.textContent = message;
        el.style.display = 'block';
    }
    
    function empty(val) {
        return val === undefined || val === null || val.trim() === '';
    }
});
