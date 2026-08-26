<?php
// Handle AJAX API Requests for DB Schema
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $host = $_GET['host'] ?? 'localhost';
    $user = $_GET['user'] ?? 'root';
    $pass = $_GET['pass'] ?? '';
    
    try {
        $mysqli = new mysqli($host, $user, $pass);
        if ($mysqli->connect_error) {
            throw new Exception("Connection failed: " . $mysqli->connect_error);
        }

        if ($action === 'connect') {
            $result = $mysqli->query("SHOW DATABASES");
            $dbs = [];
            while ($row = $result->fetch_array()) {
                if (!in_array($row[0], ['information_schema', 'mysql', 'performance_schema', 'sys'])) {
                    $dbs[] = $row[0];
                }
            }
            echo json_encode(['success' => true, 'databases' => $dbs]);
            exit;
        }

        if ($action === 'get_tables' && !empty($_GET['db'])) {
            $db = $mysqli->real_escape_string($_GET['db']);
            $mysqli->select_db($db);
            $result = $mysqli->query("SHOW TABLES");
            $tables = [];
            while ($row = $result->fetch_array()) {
                $tables[] = $row[0];
            }
            echo json_encode(['success' => true, 'tables' => $tables]);
            exit;
        }

        if ($action === 'get_cols' && !empty($_GET['db']) && !empty($_GET['table'])) {
            $db = $mysqli->real_escape_string($_GET['db']);
            $table = $mysqli->real_escape_string($_GET['table']);
            $mysqli->select_db($db);
            $result = $mysqli->query("SHOW COLUMNS FROM `$table`");
            $cols = [];
            while ($row = $result->fetch_array()) {
                $cols[] = $row[0];
            }
            echo json_encode(['success' => true, 'columns' => $cols]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

function mysql_password($password) {
    return "*" . strtoupper(sha1(sha1($password, true)));
}

$results = [];
$input = '';
$table_name = 'users';
$user_col = 'username';
$pass_col = 'password';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST['passwords'] ?? '';
    $table_name = $_POST['table_name_manual'] ?? ($_POST['table_name'] ?? 'users');
    $user_col = $_POST['user_col_manual'] ?? ($_POST['user_col'] ?? 'username');
    $pass_col = $_POST['pass_col_manual'] ?? ($_POST['pass_col'] ?? 'password');
    
    $passwords = explode(';', $input);
    $results = []; // make sure it's fresh
    
    foreach ($passwords as $pwd) {
        $pwd = trim($pwd);
        if ($pwd === '') continue;
        
        $results[] = [
            'original' => $pwd,
            'md5' => md5($pwd),
            'sha1' => sha1($pwd),
            'mysql_legacy' => mysql_password($pwd),
            'bcrypt' => password_hash($pwd, PASSWORD_DEFAULT)
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Password Hasher - API Enabled</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=JetBrains+Mono&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <h1>Bulk Hasher</h1>
        <p class="subtitle">Securely hash your passwords and generate MySQL queries.</p>
        
        <!-- Database Connection Panel -->
        <div class="db-panel">
            <div class="db-header" onclick="toggleDBPanel()">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>
                    Live Database Connection (Optional)
                </div>
                <span id="db-status-badge" class="badge-offline">Offline</span>
            </div>
            <div class="db-body" id="db-body">
                <div class="db-grid">
                    <div>
                        <label>Host</label>
                        <input type="text" id="db_host" value="localhost" class="textarea input-small">
                    </div>
                    <div>
                        <label>Username</label>
                        <input type="text" id="db_user" value="root" class="textarea input-small">
                    </div>
                    <div>
                        <label>Password</label>
                        <input type="password" id="db_pass" placeholder="(empty)" class="textarea input-small">
                    </div>
                    <div style="display: flex; align-items: flex-end;">
                        <button type="button" class="btn-secondary" onclick="connectDB()" id="btn-connect" style="width: 100%; height: 42px;">Connect</button>
                    </div>
                </div>
                <div id="db-error" class="db-error" style="display: none;"></div>
            </div>
        </div>

        <form method="POST" id="hasherForm">
            <div class="secondary-inputs">
                <!-- Dropdown Mode (hidden by default) -->
                <div class="form-group db-select-mode" style="display: none;">
                    <label>Database</label>
                    <select id="sel_db" class="textarea select-input" onchange="loadTables()">
                        <option value="">Select DB...</option>
                    </select>
                </div>
                <div class="form-group db-select-mode" style="display: none;">
                    <label>Table</label>
                    <select id="sel_table" name="table_name" class="textarea select-input" onchange="loadColumns()">
                        <option value="">Select Table...</option>
                    </select>
                </div>
                <div class="form-group db-select-mode" style="display: none;">
                    <label>User Col</label>
                    <select id="sel_user" name="user_col" class="textarea select-input">
                        <option value="">User Column...</option>
                    </select>
                </div>
                <div class="form-group db-select-mode" style="display: none;">
                    <label>Pass Col</label>
                    <select id="sel_pass" name="pass_col" class="textarea select-input">
                        <option value="">Password Column...</option>
                    </select>
                </div>

                <!-- Manual Mode (visible by default) -->
                <div class="form-group manual-mode">
                    <label>SQL Table Name</label>
                    <input type="text" id="man_table" name="table_name_manual" value="<?php echo htmlspecialchars($table_name); ?>" placeholder="users" class="textarea input-small">
                </div>
                <div class="form-group manual-mode">
                    <label>Username Column</label>
                    <input type="text" id="man_user" name="user_col_manual" value="<?php echo htmlspecialchars($user_col); ?>" placeholder="username" class="textarea input-small">
                </div>
                <div class="form-group manual-mode">
                    <label>Password Column</label>
                    <input type="text" id="man_pass" name="pass_col_manual" value="<?php echo htmlspecialchars($pass_col); ?>" placeholder="password" class="textarea input-small">
                </div>
            </div>

            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <label for="passwords" style="margin-bottom: 0;">Input Passwords (separate with ;)</label>
                    <button type="button" class="btn-secondary" onclick="generateRandomPasswords()">🎲 Generate Random</button>
                </div>
                <textarea name="passwords" id="passwords" onkeyup="checkStrength(this.value)" placeholder="password123;admin123;secret_key..."><?php echo htmlspecialchars($input); ?></textarea>
                <div class="strength-meter">
                    <div id="strength-bar" class="strength-bar"></div>
                </div>
                <div id="strength-text" class="strength-text">Enter passwords...</div>
            </div>

            <div style="display: flex; gap: 12px;">
                <button type="submit" style="flex: 1;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                    Generate Hashes
                </button>
                <button type="button" class="btn-secondary" style="background: rgba(239, 68, 68, 0.2); color: #fca5a5; border-color: rgba(239, 68, 68, 0.3);" onclick="resetForm()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6"/></svg>
                    Reset
                </button>
            </div>
        </form>

        <?php if (!empty($results)): ?>
        <div class="result-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h2 style="font-size: 1.5rem; color: #818cf8;">Results</h2>
                <button type="button" class="btn-secondary btn-export" onclick="exportCSV()">📥 Export to CSV</button>
            </div>
            
            <?php foreach ($results as $item): ?>
            <div class="result-item">
                <div class="result-header">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <?php echo htmlspecialchars($item['original']); ?>
                </div>
                <div class="hash-grid">
                    <div class="hash-box" onclick="copyToClipboard(this)">
                        <label>MD5</label>
                        <div class="hash-value"><?php echo $item['md5']; ?></div>
                        <span class="copy-badge">COPIED</span>
                    </div>
                    <div class="hash-box" onclick="copyToClipboard(this)">
                        <label>SHA1</label>
                        <div class="hash-value"><?php echo $item['sha1']; ?></div>
                        <span class="copy-badge">COPIED</span>
                    </div>
                    <div class="hash-box" onclick="copyToClipboard(this)">
                        <label>MySQL Legacy</label>
                        <div class="hash-value"><?php echo $item['mysql_legacy']; ?></div>
                        <span class="copy-badge">COPIED</span>
                    </div>
                    <div class="hash-box" onclick="copyToClipboard(this)">
                        <label>PHP BCRYPT</label>
                        <div class="hash-value"><?php echo $item['bcrypt']; ?></div>
                        <span class="copy-badge">COPIED</span>
                    </div>
                    <div class="sql-box" onclick="copyToClipboard(this, true)">
                        UPDATE `<?php echo htmlspecialchars($table_name); ?>` SET `<?php echo htmlspecialchars($pass_col); ?>` = '<?php echo $item['bcrypt']; ?>' WHERE `<?php echo htmlspecialchars($user_col); ?>` = 'USER_ID_HERE';
                        <span class="copy-badge">SQL COPIED</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
    let isDbConnected = false;
    let currentDbCache = {};

    function toggleDBPanel() {
        const body = document.getElementById('db-body');
        body.style.display = body.style.display === 'none' || body.style.display === '' ? 'block' : 'none';
    }

    async function fetchAPI(action, params = {}) {
        const url = new URL(window.location.href);
        url.search = new URLSearchParams({ 
            action, 
            host: document.getElementById('db_host').value,
            user: document.getElementById('db_user').value,
            pass: document.getElementById('db_pass').value,
            ...params 
        });
        
        const res = await fetch(url);
        return await res.json();
    }

    async function connectDB() {
        const btn = document.getElementById('btn-connect');
        const err = document.getElementById('db-error');
        const badge = document.getElementById('db-status-badge');
        
        btn.innerText = 'Connecting...';
        btn.disabled = true;
        err.style.display = 'none';

        try {
            const data = await fetchAPI('connect');
            if (data.success) {
                isDbConnected = true;
                badge.className = 'badge-online';
                badge.innerText = 'Connected';
                btn.innerText = 'Connected';
                
                // Show Selectors, Hide Manual
                document.querySelectorAll('.db-select-mode').forEach(el => el.style.display = 'block');
                document.querySelectorAll('.manual-mode').forEach(el => el.style.display = 'none');
                
                populateSelect('sel_db', data.databases, 'Select Database...');
                document.getElementById('db-body').style.display = 'none'; // Auto close panel
            } else {
                throw new Error(data.message);
            }
        } catch (error) {
            err.innerText = error.message;
            err.style.display = 'block';
            btn.innerText = 'Connect';
            btn.disabled = false;
            badge.className = 'badge-offline';
            badge.innerText = 'Offline';
            isDbConnected = false;
        }
    }

    async function loadTables() {
        const db = document.getElementById('sel_db').value;
        if (!db) return;
        
        try {
            const data = await fetchAPI('get_tables', { db });
            if (data.success) {
                populateSelect('sel_table', data.tables, 'Select Table...');
                document.getElementById('sel_user').innerHTML = '<option value="">User Column...</option>';
                document.getElementById('sel_pass').innerHTML = '<option value="">Password Column...</option>';
            }
        } catch (e) { console.error(e); }
    }

    async function loadColumns() {
        const db = document.getElementById('sel_db').value;
        const table = document.getElementById('sel_table').value;
        if (!db || !table) return;
        
        try {
            const data = await fetchAPI('get_cols', { db, table });
            if (data.success) {
                populateSelect('sel_user', data.columns, 'User Column...');
                populateSelect('sel_pass', data.columns, 'Password Column...');
            }
        } catch (e) { console.error(e); }
    }

    function populateSelect(id, items, defaultText) {
        const sel = document.getElementById(id);
        sel.innerHTML = `<option value="">${defaultText}</option>`;
        items.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item;
            opt.innerText = item;
            sel.appendChild(opt);
        });
    }

    function checkStrength(input) {
        if (!input) {
            updateBar(0, 'Enter passwords...', '#94a3b8');
            return;
        }
        
        // Take the last item for single-pass strength check
        const parts = input.split(';');
        const pwd = parts[parts.length - 1].trim();
        
        if (!pwd) {
            updateBar(0, 'Next item...', '#94a3b8');
            return;
        }

        let strength = 0;
        if (pwd.length >= 8) strength++;
        if (pwd.length >= 12) strength++;
        if (/[A-Z]/.test(pwd)) strength++;
        if (/[0-9]/.test(pwd)) strength++;
        if (/[^A-Za-z0-9]/.test(pwd)) strength++;

        const colors = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#10b981'];
        const labels = ['Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];
        
        const score = Math.min(strength, 4);
        updateBar((score + 1) * 20, labels[score], colors[score]);
    }

    function updateBar(width, text, color) {
        const bar = document.getElementById('strength-bar');
        const txt = document.getElementById('strength-text');
        bar.style.width = width + '%';
        bar.style.backgroundColor = color;
        txt.innerText = text;
        txt.style.color = color;
    }

    function generateRandomPasswords() {
        const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+";
        let out = [];
        for (let i = 0; i < 5; i++) {
            let pass = "";
            for (let j = 0; j < 12; j++) pass += chars.charAt(Math.floor(Math.random() * chars.length));
            out.push(pass);
        }
        document.getElementById('passwords').value = out.join(';');
        checkStrength(out[4]);
    }

    function exportCSV() {
        const items = document.querySelectorAll('.result-item');
        let csvContent = "data:text/csv;charset=utf-8,Original,MD5,SHA1,MySQL,BCRYPT\n";
        
        items.forEach(item => {
            const original = item.querySelector('.result-header').innerText.trim();
            const hashes = [...item.querySelectorAll('.hash-value')].map(h => h.innerText);
            csvContent += `"${original}","${hashes[0]}","${hashes[1]}","${hashes[2]}","${hashes[3]}"\n`;
        });

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "hashed_passwords.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function copyToClipboard(element, isSql = false) {
        const text = isSql ? element.innerText.replace('SQL COPIED', '').trim() : element.querySelector('.hash-value').innerText;
        navigator.clipboard.writeText(text).then(() => {
            const badge = element.querySelector('.copy-badge');
            badge.style.opacity = '1';
            setTimeout(() => {
                badge.style.opacity = '0';
            }, 1000);
        });
    }

    function resetForm() {
        document.getElementById('hasherForm').reset();
        document.getElementById('passwords').value = '';
        checkStrength('');
        
        const resultsContainer = document.querySelector('.result-container');
        if (resultsContainer) {
            resultsContainer.style.display = 'none';
        }
        
        // Reset DB connection UI to manual mode
        isDbConnected = false;
        document.getElementById('db-status-badge').className = 'badge-offline';
        document.getElementById('db-status-badge').innerText = 'Offline';
        document.getElementById('btn-connect').innerText = 'Connect';
        document.getElementById('btn-connect').disabled = false;
        document.getElementById('db_pass').value = '';
        
        document.querySelectorAll('.db-select-mode').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.manual-mode').forEach(el => el.style.display = 'block');
        
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    </script>
</body>
</html>
