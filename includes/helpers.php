<?php
// includes/helpers.php
// FIXED: Helper functions untuk mengurangi code duplication

/**
 * Redirect dengan pesan session
 * @param string $location URL tujuan redirect
 * @param string $message Pesan yang akan ditampilkan
 * @param string $type Tipe pesan: 'success', 'error', 'warning', 'info'
 */
function redirect_with_message($location, $message, $type = 'success') {
    $session_key = $type . '_message';
    $_SESSION[$session_key] = $message;
    header("location: " . $location);
    exit;
}

/**
 * Validasi panjang string
 * @param string $value Nilai yang akan divalidasi
 * @param int $max_length Panjang maksimal
 * @param string $field_name Nama field untuk pesan error
 * @return bool|string True jika valid, string error message jika tidak
 */
function validate_length($value, $max_length, $field_name = 'Field') {
    if (strlen($value) > $max_length) {
        return "{$field_name} terlalu panjang. Maksimal {$max_length} karakter.";
    }
    return true;
}

/**
 * Validasi required fields
 * @param array $fields Array of field names to validate from $_POST
 * @return array Empty array jika valid, array of error messages jika tidak
 */
function validate_required_fields($fields) {
    $errors = [];
    foreach ($fields as $field => $label) {
        if (empty($_POST[$field] ?? '')) {
            $errors[] = "{$label} wajib diisi.";
        }
    }
    return $errors;
}

/**
 * Sanitize input string
 * @param string $value Input value
 * @param int $max_length Optional max length
 * @return string Sanitized value
 */
function sanitize_input($value, $max_length = null) {
    $value = trim($value);
    $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    if ($max_length !== null && strlen($value) > $max_length) {
        $value = substr($value, 0, $max_length);
    }
    return $value;
}

/**
 * Check if user is logged in and has specific role
 * @param string $required_role 'admin' or 'user'
 * @param string $redirect_url URL to redirect if not authorized
 */
function require_auth($required_role = null, $redirect_url = 'login.php') {
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        $_SESSION['error_message'] = "Silakan login terlebih dahulu.";
        header("location: " . $redirect_url);
        exit;
    }
    
    if ($required_role !== null && $_SESSION["role"] !== $required_role) {
        $_SESSION['error_message'] = "Akses ditolak. Anda tidak memiliki izin untuk mengakses halaman ini.";
        header("location: " . $redirect_url);
        exit;
    }
}

/**
 * Format file size untuk display
 * @param int $bytes File size in bytes
 * @return string Formatted file size
 */
function format_file_size($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * Validate file upload
 * @param array $file $_FILES array element
 * @param array $allowed_types Array of allowed MIME types
 * @param int $max_size Maximum file size in bytes
 * @return array ['success' => bool, 'error' => string|null]
 */
function validate_file_upload($file, $allowed_types, $max_size) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload gagal.'];
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'error' => 'Tipe file tidak diizinkan.'];
    }
    
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'Ukuran file melebihi batas ' . format_file_size($max_size) . '.'];
    }
    
    return ['success' => true, 'error' => null];
}

/**
 * Clean up uploaded file
 * @param string $file_path Path to file
 * @return bool Success status
 */
function cleanup_file($file_path) {
    if (file_exists($file_path)) {
        return @unlink($file_path);
    }
    return true;
}

/**
 * Generate unique filename
 * @param string $original_filename Original filename
 * @param string $prefix Optional prefix
 * @return string Unique filename
 */
function generate_unique_filename($original_filename, $prefix = '') {
    $extension = pathinfo($original_filename, PATHINFO_EXTENSION);
    return $prefix . time() . '_' . uniqid() . '.' . $extension;
}

/**
 * Display flash message
 * @param string $type Message type: 'success', 'error', 'warning', 'info'
 * @return string HTML for flash message or empty string
 */
function display_flash_message($type = null) {
    $types = $type ? [$type] : ['success', 'error', 'warning', 'info'];
    $output = '';
    
    foreach ($types as $msg_type) {
        $session_key = $msg_type . '_message';
        if (isset($_SESSION[$session_key])) {
            $message = $_SESSION[$session_key];
            unset($_SESSION[$session_key]);
            
            $bg_color = [
                'success' => 'bg-green-100 border-green-400 text-green-700',
                'error' => 'bg-red-100 border-red-400 text-red-700',
                'warning' => 'bg-yellow-100 border-yellow-400 text-yellow-700',
                'info' => 'bg-blue-100 border-blue-400 text-blue-700'
            ][$msg_type] ?? 'bg-gray-100 border-gray-400 text-gray-700';
            
            $output .= "<div class='border-l-4 p-4 mb-4 {$bg_color}' role='alert'>";
            $output .= "<p>" . htmlspecialchars($message) . "</p>";
            $output .= "</div>";
        }
    }
    
    return $output;
}
