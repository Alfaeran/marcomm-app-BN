<?php
require_once 'config/database.php';

// Jika user tidak login, tendang ke halaman login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$app_name = get_setting($mysqli, 'app_name');
$app_logo = get_setting($mysqli, 'app_logo');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Password - <?php echo strip_tags($app_name); ?></title>
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="w-full max-w-md bg-white rounded-lg shadow-md p-8">
        <div class="text-center mb-6">
            <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo" class="mx-auto h-12 mb-4">
            <h2 class="text-2xl font-bold text-gray-800">Ubah Password Anda</h2>
            <p class="text-gray-500 mt-2">Untuk keamanan, Anda harus membuat password baru.</p>
        </div>
        
        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <p><?php echo $_SESSION['error_message']; ?></p>
        </div>
        <?php unset($_SESSION['error_message']); endif; ?>

        <form action="process/force_change_password_process.php" method="post">
            <div class="mb-4 relative">
                <label for="new_password" class="block text-gray-700 text-sm font-bold mb-2">Password Baru</label>
                <input type="password" name="new_password" id="new_password" required class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                <span class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5 cursor-pointer" style="top: 1.375rem;" onclick="togglePasswordVisibility('new_password')">
                    <i class="fa fa-eye" id="toggle-password-icon-new_password"></i>
                </span>
            </div>
            <div class="mb-6 relative">
                <label for="confirm_password" class="block text-gray-700 text-sm font-bold mb-2">Konfirmasi Password Baru</label>
                <input type="password" name="confirm_password" id="confirm_password" required class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 mb-3 leading-tight focus:outline-none focus:shadow-outline">
                <span class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5 cursor-pointer" style="top: 1.375rem;" onclick="togglePasswordVisibility('confirm_password')">
                    <i class="fa fa-eye" id="toggle-password-icon-confirm_password"></i>
                </span>
            </div>
            <div class="flex items-center justify-between">
                <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline w-full">
                    Simpan Password Baru
                </button>
            </div>
        </form>
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
