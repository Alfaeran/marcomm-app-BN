<?php
// login.php
require_once 'config/database.php';

$app_name = get_setting($mysqli, 'app_name') ?: 'MarComm App';
$app_logo = get_setting($mysqli, 'app_logo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    if ($_SESSION["role"] === 'admin') {
        header("location: dashboard_admin.php");
    } else {
        header("location: dashboard_user.php");
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Masuk - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
    <style>
        .login-card {
            width: 100%;
            max-width: 440px;
            animation: fadeIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .input-group:focus-within label {
            color: #3b82f6;
        }
        .input-group:focus-within .input-icon {
            color: #3b82f6;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-6 overflow-hidden">
    <!-- Aurora Background Blobs -->
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>

    <div class="login-card glass-card p-10 lg:p-12">
        <!-- Brand Section -->
        <div class="text-center mb-10">
            <?php if ($app_logo): ?>
                <div class="inline-block p-4 bg-white/50 backdrop-blur-md rounded-3xl shadow-xl shadow-blue-100/20 border border-white/50 mb-6">
                    <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo" class="h-16 w-16 object-contain">
                </div>
            <?php else: ?>
                <div class="h-16 w-16 bg-blue-600 rounded-3xl flex items-center justify-center text-white text-3xl mx-auto mb-6 shadow-xl shadow-blue-200">
                    <i class="fas fa-rocket"></i>
                </div>
            <?php endif; ?>
            <h1 class="text-3xl font-black text-slate-800 tracking-tight mb-2"><?php echo htmlspecialchars($app_name); ?></h1>
            <p class="text-slate-500 font-medium">Selamat datang kembali! Silakan masuk ke akun Anda.</p>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="bg-red-50/50 border border-red-100 p-4 mb-8 rounded-2xl flex items-center gap-3 animate-shake">
                <div class="h-10 w-10 bg-red-100 text-red-600 rounded-xl flex items-center justify-center shrink-0">
                    <i class="fas fa-exclamation-circle text-lg"></i>
                </div>
                <p class="text-sm font-bold text-red-800 leading-tight"><?php echo htmlspecialchars($_SESSION['error_message']); ?></p>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <form action="process/login_process.php" method="POST" class="space-y-6">
            <div class="input-group">
                <label for="username" class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2 px-1 transition-colors">Username</label>
                <div class="relative">
                    <i class="fas fa-user absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm input-icon transition-colors"></i>
                    <input type="text" name="username" id="username" required 
                           class="w-full pl-12 pr-4 py-4 bg-white/50 border border-slate-200 rounded-2xl text-slate-800 font-bold text-sm focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-400 transition-all outline-none placeholder:text-slate-300"
                           placeholder="Masukkan username Anda">
                </div>
            </div>

            <div class="input-group">
                <div class="flex justify-between items-center mb-2 px-1">
                    <label for="password" class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] transition-colors">Password</label>
                    <a href="change_password.php" class="text-[10px] font-black text-blue-600 hover:text-blue-700 uppercase tracking-widest transition-colors">Lupa Password?</a>
                </div>
                <div class="relative">
                    <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm input-icon transition-colors"></i>
                    <input type="password" name="password" id="password" required 
                           class="w-full pl-12 pr-12 py-4 bg-white/50 border border-slate-200 rounded-2xl text-slate-800 font-bold text-sm focus:outline-none focus:ring-4 focus:ring-blue-100 focus:border-blue-400 transition-all outline-none placeholder:text-slate-300"
                           placeholder="••••••••">
                    <button type="button" onclick="togglePasswordVisibility('password')" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors p-2">
                        <i class="fas fa-eye text-sm" id="toggle-password-icon-password"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="w-full py-4 bg-blue-600 text-white font-black text-sm uppercase tracking-widest rounded-2xl hover:bg-blue-700 shadow-xl shadow-blue-200 active:scale-[0.98] transition-all flex items-center justify-center gap-3">
                <i class="fas fa-sign-in-alt"></i>
                Masuk Sekarang
            </button>
        </form>

        <footer class="mt-12 text-center">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">
                &copy; 2026 MarketingJava.ID
                <span class="block mt-1">Sistem Manajemen Marcomm v3.0</span>
            </p>
        </footer>
    </div>

<script>
function togglePasswordVisibility(fieldId) {
    const passwordField = document.getElementById(fieldId);
    const icon = document.getElementById('toggle-password-icon-' + fieldId);
    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        passwordField.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
</script>
</body>
</html>
