<?php
// components/head_shared.php
// Centralized styling for the premium Aurora theme

// AUTO-CLEANUP TEMP UPLOADS (5% chance per page load to avoid performance hit)
if (rand(1, 100) <= 5) {
    $temp_dir_to_clean = dirname(__DIR__) . '/temp_uploads/';
    if (is_dir($temp_dir_to_clean)) {
        $max_age_seconds_cleanup = 24 * 60 * 60; // 24 hours
        $now_time = time();
        
        // Helper function for cleanup
        if (!function_exists('deleteDirTemp')) {
            function deleteDirTemp($dir) {
                if (!file_exists($dir)) return true;
                if (!is_dir($dir)) return @unlink($dir);
                foreach (scandir($dir) as $item) {
                    if ($item == '.' || $item == '..') continue;
                    if (!deleteDirTemp($dir . DIRECTORY_SEPARATOR . $item)) return false;
                }
                return @rmdir($dir);
            }
        }

        foreach (scandir($temp_dir_to_clean) as $item_clean) {
            if (strpos($item_clean, '.') === 0) continue;
            
            $item_path_clean = $temp_dir_to_clean . $item_clean;
            if (is_dir($item_path_clean) && strpos($item_clean, 'matpro_import_') === 0) {
                $folder_age_clean = $now_time - filemtime($item_path_clean);
                if ($folder_age_clean > $max_age_seconds_cleanup) {
                    deleteDirTemp($item_path_clean);
                }
            }
        }
    }
}

?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="assets/css/tailwind.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    :root {
        --glass-bg: rgba(255, 255, 255, 0.75);
        --glass-border: rgba(255, 255, 255, 0.3);
        --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.07);
    }

    body { 
        font-family: 'Inter', sans-serif; 
        background-color: #f1f5f9;
        min-height: 100vh;
        position: relative;
        overflow-x: hidden;
    }

    /* Optimized Aurora Background Blobs */
    .bg-blob {
        position: fixed;
        width: 600px;
        height: 600px;
        filter: blur(60px); /* Reduced blur for better performance */
        border-radius: 50%;
        z-index: -1;
        opacity: 0.25; /* Lower opacity for subtle look */
        /* Animation is slowed down significantly to reduce repaint cost */
        animation: blob-float 60s infinite linear;
        will-change: transform;
        transform: translate3d(0,0,0); /* Force GPU acceleration */
        pointer-events: none; /* Prevention: Never block clicks */
    }
    .blob-1 { background: #dcfce7; top: -150px; right: -150px; }
    .blob-2 { background: #dbeafe; bottom: -150px; left: -150px; animation-delay: -20s; }
    .blob-3 { background: #fef9c3; top: 30%; right: 20%; animation-delay: -40s; }

    @keyframes blob-float {
        0% { transform: translate3d(0, 0, 0) scale(1); }
        33% { transform: translate3d(50px, -80px, 0) scale(1.1); }
        66% { transform: translate3d(-40px, 40px, 0) scale(0.9); }
        100% { transform: translate3d(0, 0, 0) scale(1); }
    }

    .glass-card { 
        background: var(--glass-bg);
        /* Backdrop filter is expensive, keep blur minimal */
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        border: 1px solid var(--glass-border);
        box-shadow: var(--glass-shadow);
        border-radius: 24px;
        /* Performance hint */
        contain: content;
    }

    .sidebar { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    
    .nav-link { 
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        border-radius: 12px;
        color: #64748b;
        transition: all 0.2s;
        font-weight: 500;
    }
    .nav-link:hover { background-color: rgba(15, 23, 42, 0.05); color: #0f172a; }
    .nav-link.active { background-color: #3b82f6; color: white; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.5); }

    .stat-card {
        padding: 1.5rem;
        border-radius: 1.5rem;
        transition: transform 0.2s, box-shadow 0.2s;
        contain: paint;
    }
    .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }

    /* Custom scrollbar */
    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

    .pulse-red { animation: pulse-red 2s infinite; }
    @keyframes pulse-red {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }

    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        opacity: 1;
        transition: opacity 0.3s ease;
    }
    .modal-overlay.hidden {
        opacity: 0;
        pointer-events: none;
        display: flex; /* Keep flex to allow transition, but disable pointer events */
    }
</style>

