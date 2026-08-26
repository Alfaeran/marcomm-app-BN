<?php
require_once 'config/database.php';

// Cek hak akses (Admin dan User diperbolehkan)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'user'])) {
    header("location: login.php");
    exit;
}

$app_name = get_setting($mysqli, 'app_name');
$app_logo = get_setting($mysqli, 'app_logo');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Peta Sebaran Event - <?php echo strip_tags($app_name); ?></title>
    <?php include 'components/head_shared.php'; ?>
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    
    <style>
        #map { height: 100%; border-radius: 24px; }
        .leaflet-container { background: transparent !important; }
        
        /* Custom Leaflet Styling */
        .leaflet-popup-content-wrapper { border-radius: 20px; padding: 0; overflow: hidden; box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1); }
        .leaflet-popup-content { margin: 0 !important; width: 280px !important; }
        .leaflet-popup-tip-container { display: none; }
        
        .legend {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            padding: 15px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            line-height: 1.8;
            color: #475569;
            font-size: 11px;
            font-weight: 700;
        }
        .legend i {
            width: 10px;
            height: 10px;
            float: left;
            margin-right: 8px;
            margin-top: 5px;
            border-radius: 50%;
        }
    </style>
</head>
<body class="min-h-screen overflow-hidden">
    <!-- Aurora Background Blobs -->
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>

    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php 
        if ($_SESSION["role"] === 'admin') {
            include 'components/sidebar_admin.php';
        } else {
            // We'll create this component next
            if (file_exists('components/sidebar_user.php')) {
                include 'components/sidebar_user.php';
            } else {
                // Fallback for now
                echo '<div class="w-20 lg:w-72"></div>';
            }
        }
        ?>

        <!-- Main Content -->
        <main class="flex-grow p-4 lg:p-8 lg:ml-72 flex flex-col min-w-0">
            <!-- Header with Filters -->
            <header class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 mb-8">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-3 text-slate-600 glass-card relative z-[60] active:scale-95 transition-transform"><i class="fas fa-bars"></i></button>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-800 tracking-tight">Peta Sebaran Event</h2>
                        <p class="text-sm text-slate-500 font-medium">Visualisasi lokasi aktivitas lapangan seluruh tim</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <div class="glass-card flex items-center px-4 py-1">
                        <i class="fas fa-filter text-slate-400 text-xs mr-3"></i>
                        <select id="categoryFilter" class="bg-transparent py-2.5 text-sm font-bold text-slate-700 outline-none cursor-pointer">
                            <option value="all">Semua Kategori</option>
                        </select>
                    </div>

                    <div class="glass-card flex items-center px-4 py-1 flex-grow lg:flex-grow-0">
                        <i class="fas fa-search text-slate-400 text-xs mr-3 transition-colors"></i>
                        <input type="text" id="searchBar" placeholder="Cari nama event/user..." class="bg-transparent py-2.5 w-full lg:w-48 text-sm font-medium outline-none">
                    </div>

                    <button id="applyFilter" class="px-6 py-3 bg-blue-600 text-white font-bold text-sm rounded-2xl hover:bg-blue-700 shadow-lg shadow-blue-200 active:scale-95 transition-all">
                        <i class="fas fa-sync-alt mr-2"></i> Update Map
                    </button>
                </div>
            </header>

            <div class="flex-grow min-h-0 relative glass-card overflow-hidden p-2">
                <div id="map"></div>
                
                <!-- Quick Info Float -->
                <div class="absolute top-6 left-6 z-[1000] pointer-events-none">
                     <div class="glass-card p-4 pointer-events-auto border-white/40">
                         <p class="text-[10px] font-extrabold text-slate-400 uppercase tracking-widest mb-1">TOTAL TITIK</p>
                         <h3 class="text-3xl font-black text-slate-800" id="totalMarkerCount">0</h3>
                     </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
        // Initialize Map
        const map = L.map('map', {
            zoomControl: false,
            preferCanvas: true
        }).setView([-8.9, 119.8], 7);
        
        L.control.zoom({ position: 'bottomleft' }).addTo(map);

        let currentMarkers = L.featureGroup().addTo(map);
        let legendControl;

        // Custom Tile Layer - Modern/Greyscale Style
        L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap - HOT'
        }).addTo(map);

        function fetchAndDisplayEvents(categoryFilter = null, searchQuery = null) {
            currentMarkers.clearLayers();
            
            let apiUrl = 'api_helper.php?action=get_all_events_locations';
            if (categoryFilter && categoryFilter !== 'all') apiUrl += `&category=${categoryFilter}`;
            if (searchQuery) apiUrl += `&search=${encodeURIComponent(searchQuery)}`;

            fetch(apiUrl)
                .then(response => response.json())
                .then(events => {
                    document.getElementById('totalMarkerCount').textContent = events ? events.length : 0;
                    
                    if (!events || events.length === 0) {
                        return;
                    }

                    const legendData = {};
                    const colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#6B7280'];
                    let colorIndex = 0;
                    const bounds = [];

                    events.forEach(event => {
                        const lat = parseFloat(event.lat);
                        const lon = parseFloat(event.lon);

                        if (!isNaN(lat) && !isNaN(lon)) {
                            const latLng = [lat, lon];
                            bounds.push(latLng);

                            const category = event.nama_kategori || 'Lainnya';
                            if (!legendData[category]) {
                                legendData[category] = colors[colorIndex % colors.length];
                                colorIndex++;
                            }
                            const color = legendData[category];

                            const marker = L.circleMarker(latLng, {
                                radius: 8,
                                fillColor: color,
                                color: "#fff",
                                weight: 2,
                                opacity: 1,
                                fillOpacity: 0.9
                            });

                            marker.bindPopup(`
                                <div class="p-2 min-w-[200px]">
                                    <div class="flex items-center gap-2 mb-2">
                                        <div class="h-2 w-2 rounded-full" style="background-color: ${color}"></div>
                                        <span class="text-[10px] font-black uppercase text-slate-400 tracking-tighter">${category}</span>
                                    </div>
                                    <h4 class="font-bold text-slate-800 text-sm leading-snug mb-3">${event.event_name}</h4>
                                    <div class="grid grid-cols-2 gap-3 pb-3 border-b mb-3">
                                        <div>
                                            <p class="text-[9px] font-bold text-slate-400 uppercase">Input By</p>
                                            <p class="text-xs font-semibold text-slate-600">${event.username}</p>
                                        </div>
                                         <div class="text-right">
                                            <p class="text-[9px] font-bold text-slate-400 uppercase">Status</p>
                                            <span class="text-[10px] px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded-full font-bold">Terverfikasi</span>
                                        </div>
                                    </div>
                                    <a href="admin_detail_event.php?id=${event.unique_id}" class="block text-center py-2 bg-slate-900 text-white text-xs font-bold rounded-xl hover:bg-slate-800 transition-all">Lihat Detail Lengkap</a>
                                </div>
                            `);
                            currentMarkers.addLayer(marker);
                        }
                    });

                    // Update Legend
                    if (legendControl) map.removeControl(legendControl);
                    legendControl = L.control({position: 'bottomright'});
                    legendControl.onAdd = function () {
                        const div = L.DomUtil.create('div', 'legend');
                        for (const cat in legendData) {
                            div.innerHTML += `<div><i style="background:${legendData[cat]}"></i>${cat}</div>`;
                        }
                        return div;
                    };
                    legendControl.addTo(map);

                    if (bounds.length > 0) map.fitBounds(bounds, { padding: [80, 80] });
                });
        }

        // Load Categories
        fetch('api_helper.php?action=get_categories')
            .then(r => r.json())
            .then(categories => {
                const select = document.getElementById('categoryFilter');
                categories.forEach(c => {
                    const opt = new Option(c.nama_kategori, c.id);
                    select.add(opt);
                });
            });

        // Event Listeners
        document.getElementById('applyFilter').addEventListener('click', () => {
            fetchAndDisplayEvents(
                document.getElementById('categoryFilter').value,
                document.getElementById('searchBar').value
            );
        });

        document.getElementById('searchBar').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') document.getElementById('applyFilter').click();
        });

        // Initial Load
        fetchAndDisplayEvents();
    });
</script>
</body>
</html>
