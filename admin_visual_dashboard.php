<?php
require_once 'config/database.php';

// Cek hak akses admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.php");
    exit;
}
$app_name = get_setting($mysqli, 'app_name');

// Ambil data untuk dropdown filter
$brands_for_filter = $mysqli->query("SELECT DISTINCT brand FROM branches WHERE brand IS NOT NULL ORDER BY brand");

// Ambil nilai filter dari URL
$selected_brand = $_GET['brand'] ?? '';
$selected_brand = $_GET['brand'] ?? '';
$selected_branch = $_GET['branch_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visual Dashboard - <?php echo strip_tags($app_name); ?></title>
    
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #4F46E5;
            --primary-light: #818CF8;
            --secondary: #10B981;
            --secondary-light: #34D399;
            --accent: #F59E0B;
            --background: #F8FAFC;
        }

        body { 
            font-family: 'Outfit', sans-serif; 
            background-color: var(--background);
            color: #1E293B;
        }

        /* Aurora Background Blobs */
        .bg-blob {
            position: fixed;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            z-index: -1;
            animation: float 20s infinite alternate ease-in-out;
        }
        .blob-1 { background-color: #6366f1; top: -100px; left: -100px; }
        .blob-2 { background-color: #10b981; bottom: -100px; right: -100px; animation-delay: -5s; }
        .blob-3 { background-color: #f59e0b; top: 40%; left: 30%; animation-delay: -10s; }

        @keyframes float {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(50px, 50px) scale(1.1); }
        }

        .glass-card { 
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            box-shadow: 0 8px 32px rgba(31, 38, 135, 0.05);
        }

        .metric-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.05);
        }

        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #E2E8F0; border-radius: 10px; }

        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
        }
    </style>
</head>
<body class="min-h-screen overflow-x-hidden">
    <!-- Background Elements -->
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>

    <div id="sidebarOverlay" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm hidden z-40 lg:hidden" onclick="toggleSidebar()"></div>

    <div class="flex">
        <!-- Sidebar -->
        <?php include 'components/sidebar_admin.php'; ?>

        <!-- Main Content -->
        <main class="flex-grow p-4 lg:p-10 lg:ml-72 min-w-0 min-h-screen flex flex-col">
            <!-- Header -->
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-12">
                <div class="flex items-center gap-5">
                    <button onclick="toggleSidebar()" class="lg:hidden p-3 text-slate-600 glass-card rounded-2xl active:scale-95 transition-transform">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="h-2 w-8 bg-blue-600 rounded-full"></span>
                            <p class="text-[10px] font-black text-blue-600 uppercase tracking-[0.2em]">Insights Engine</p>
                        </div>
                        <h2 class="text-4xl font-black text-slate-900 tracking-tight">Visual Dashboard</h2>
                        <p class="text-slate-500 font-medium text-sm mt-1">Pantau performa operasional MarComm secara real-time.</p>
                    </div>
                </div>

                <!-- Date/Time Display -->
                <div class="hidden xl:flex items-center gap-4 bg-white/40 backdrop-blur-md px-6 py-3 rounded-2xl border border-white/50 shadow-sm">
                    <div class="text-right">
                        <p id="current-date" class="text-xs font-black text-slate-800"></p>
                        <p id="current-time" class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"></p>
                    </div>
                    <div class="h-8 w-px bg-slate-200"></div>
                    <div class="h-10 w-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center">
                        <i class="far fa-calendar-alt"></i>
                    </div>
                </div>
            </header>

            <!-- Filters Section -->
            <div class="glass-card rounded-[2.5rem] p-8 mb-10 border border-white/60 relative overflow-hidden group">
                <div class="absolute top-0 right-0 p-8 opacity-5 group-hover:opacity-10 transition-opacity">
                    <i class="fas fa-filter text-8xl text-blue-900"></i>
                </div>
                
                <form id="filter-form" class="relative z-10 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6 items-end">
                    <div class="space-y-3">
                        <label for="brand_filter" class="text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] ml-1">Brand</label>
                        <div class="relative">
                            <select id="brand_filter" name="brand" class="w-full pl-5 pr-12 py-3.5 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all appearance-none cursor-pointer">
                                <option value="">Semua Brand</option>
                                <?php mysqli_data_seek($brands_for_filter, 0); while($brand = $brands_for_filter->fetch_assoc()): ?>
                                <option value="<?php echo $brand['brand']; ?>" <?php if($selected_brand == $brand['brand']) echo 'selected'; ?>><?php echo $brand['brand']; ?></option>
                                <?php endwhile; ?>
                            </select>
                            <i class="fas fa-tag absolute right-5 top-1/2 -translate-y-1/2 text-slate-300 text-xs pointer-events-none"></i>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <label for="branch_filter" class="text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] ml-1">Wilayah Branch</label>
                        <div class="relative">
                            <select id="branch_filter" name="branch_id" class="w-full pl-5 pr-12 py-3.5 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all appearance-none cursor-pointer disabled:opacity-50" <?php if(empty($selected_brand)) echo 'disabled'; ?>>
                                <option value="">Pilih Brand Dulu</option>
                            </select>
                            <i class="fas fa-map-marker-alt absolute right-5 top-1/2 -translate-y-1/2 text-slate-300 text-xs pointer-events-none"></i>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <label for="start_date" class="text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] ml-1">Dari Tanggal</label>
                        <div class="relative">
                            <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-01'); ?>" class="w-full px-5 py-3.5 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all appearance-none cursor-pointer">
                        </div>
                    </div>
                    <div class="space-y-3">
                        <label for="end_date" class="text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] ml-1">Sampai Tanggal</label>
                        <div class="relative">
                            <input type="date" id="end_date" name="end_date" value="<?php echo date('Y-m-t'); ?>" class="w-full px-5 py-3.5 bg-white/50 border border-slate-200 rounded-2xl text-sm font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all appearance-none cursor-pointer">
                        </div>
                    </div>
                    <button type="submit" class="w-full py-4 bg-slate-900 text-white font-bold text-sm rounded-2xl hover:bg-black shadow-xl shadow-slate-200 active:scale-95 transition-all flex items-center justify-center gap-3">
                        <i class="fas fa-sync-alt"></i> Update Visual
                    </button>
                </form>
            </div>

            <!-- Metrics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 mb-12">
                <div class="metric-card glass-card p-8 rounded-[2rem] border-b-4 border-blue-600 relative overflow-hidden group">
                    <div class="absolute -right-4 -bottom-4 bg-blue-600/5 h-24 w-24 rounded-full group-hover:scale-150 transition-transform duration-700"></div>
                    <div class="flex items-center gap-4 mb-6">
                        <div class="h-12 w-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center text-xl shadow-inner"><i class="fas fa-calendar-check"></i></div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Event</p>
                            <p class="text-xs font-bold text-blue-600">+12% vs last period</p>
                        </div>
                    </div>
                    <h3 id="metric_total_events" class="text-4xl font-black text-slate-900 leading-none counters">0</h3>
                </div>

                <div class="metric-card glass-card p-8 rounded-[2rem] border-b-4 border-indigo-600 relative overflow-hidden group">
                    <div class="absolute -right-4 -bottom-4 bg-indigo-600/5 h-24 w-24 rounded-full group-hover:scale-150 transition-transform duration-700"></div>
                    <div class="flex items-center gap-4 mb-6">
                        <div class="h-12 w-12 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center text-xl shadow-inner"><i class="fas fa-chart-line"></i></div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total QSC</p>
                            <p class="text-xs font-bold text-indigo-600">8.4 Unit / Event</p>
                        </div>
                    </div>
                    <h3 id="metric_total_qsc" class="text-4xl font-black text-slate-900 leading-none counters">0</h3>
                </div>

                <div class="metric-card glass-card p-8 rounded-[2rem] border-b-4 border-emerald-600 relative overflow-hidden group">
                    <div class="absolute -right-4 -bottom-4 bg-emerald-600/5 h-24 w-24 rounded-full group-hover:scale-150 transition-transform duration-700"></div>
                    <div class="flex items-center gap-4 mb-6">
                        <div class="h-12 w-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center text-xl shadow-inner"><i class="fas fa-wallet"></i></div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Revenue</p>
                            <p class="text-xs font-bold text-emerald-600">Growth trajectory</p>
                        </div>
                    </div>
                    <h3 id="metric_total_benefit" class="text-3xl font-black text-slate-900 leading-none">Rp 0</h3>
                </div>

                <div class="metric-card glass-card p-8 rounded-[2rem] border-b-4 border-amber-500 relative overflow-hidden group">
                    <div class="absolute -right-4 -bottom-4 bg-amber-500/5 h-24 w-24 rounded-full group-hover:scale-150 transition-transform duration-700"></div>
                    <div class="flex items-center gap-4 mb-6">
                        <div class="h-12 w-12 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center text-xl shadow-inner"><i class="fas fa-bolt"></i></div>
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Momentum</p>
                            <p class="text-xs font-bold text-amber-600">Daily Trend Analysis</p>
                        </div>
                    </div>
                    <h3 id="metric_daily_avg" class="text-4xl font-black text-slate-900 leading-none">0.0</h3>
                </div>
            </div>

            <!-- Charts Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-6 gap-10 mb-10">
                <!-- Chart 1: Daily Trend (New) -->
                <div class="lg:col-span-6 glass-card rounded-[2.5rem] p-10 border border-white/50">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-4">
                        <div>
                            <h2 id="trend-chart-title" class="text-2xl font-black text-slate-900 tracking-tight">Tren Harian</h2>
                            <p class="text-sm text-slate-500 font-medium">Memantau fluktuasi aktivitas dari hari ke hari</p>
                        </div>
                        <div class="px-4 py-2 bg-slate-100 rounded-full text-[10px] font-black text-slate-500 uppercase tracking-widest">Live Momentum</div>
                    </div>
                    <div class="relative h-[300px]">
                        <canvas id="dailyTrendChart"></canvas>
                    </div>
                </div>

                <!-- Chart 2: Performa Bulanan -->
                <div class="lg:col-span-4 glass-card rounded-[2.5rem] p-10 border border-white/50">
                    <div class="flex items-center justify-between mb-10">
                        <div>
                            <h2 class="text-2xl font-black text-slate-900 tracking-tight">Performa Akumulasi</h2>
                            <p class="text-sm text-slate-500 font-medium">Perbandingan strategis Event, QSC, dan Revenue</p>
                        </div>
                        <div class="hidden md:flex items-center gap-3">
                            <div class="flex items-center gap-2">
                                <span class="h-3 w-3 rounded-full bg-blue-600"></span>
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Event</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="h-3 w-3 rounded-full bg-emerald-500"></span>
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">QSC</span>
                            </div>
                        </div>
                    </div>
                    <div class="relative h-[400px]">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>

                <!-- Chart 3: Distribusi Kategori -->
                <div class="lg:col-span-2 glass-card rounded-[2.5rem] p-10 border border-white/50 flex flex-col">
                    <div class="text-center mb-10">
                         <h2 class="text-2xl font-black text-slate-900 tracking-tight">Distribusi</h2>
                         <p class="text-sm text-slate-500 font-medium tracking-tight">Berdasarkan Kategori Event</p>
                    </div>
                    <div class="relative h-full min-h-[300px] flex-grow">
                        <canvas id="categoryChart"></canvas>
                    </div>
                    <div id="category-insights" class="mt-8 pt-8 border-t border-slate-100 text-center">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Top Category</p>
                        <p id="top-category-name" class="text-lg font-black text-slate-800">-</p>
                    </div>
                </div>
            </div>

            <!-- Footer Meta -->
            <footer class="mt-auto pt-10 text-center text-slate-400 text-[10px] font-bold uppercase tracking-[0.2em]">
                &copy; 2026 MarketingJava.ID
            </footer>
        </main>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const filterForm = document.getElementById('filter-form');
    const brandFilter = document.getElementById('brand_filter');
    const branchFilter = document.getElementById('branch_filter');

    // Display current date/time
    function updateDateTime() {
        const now = new Date();
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        document.getElementById('current-date').textContent = now.toLocaleDateString('id-ID', options);
        document.getElementById('current-time').textContent = now.toLocaleTimeString('id-ID', { hour12: false });
    }
    updateDateTime();
    setInterval(updateDateTime, 1000);

    let performanceChart, categoryChart, dailyTrendChart;

    // Chart Global Defaults
    Chart.defaults.font.family = "'Outfit', sans-serif";
    Chart.defaults.color = "#64748B";
    Chart.defaults.plugins.tooltip.backgroundColor = "rgba(15, 23, 42, 0.9)";
    Chart.defaults.plugins.tooltip.padding = 12;
    Chart.defaults.plugins.tooltip.cornerRadius = 12;
    Chart.defaults.plugins.tooltip.titleFont = { size: 13, weight: 'bold' };
    Chart.defaults.plugins.tooltip.bodyFont = { size: 12 };

    function buildQueryString() {
        const params = new URLSearchParams(new FormData(filterForm));
        return params.toString();
    }

    function animateValue(obj, start, end, duration) {
        let startTimestamp = null;
        const step = (timestamp) => {
            if (!startTimestamp) startTimestamp = timestamp;
            const progress = Math.min((timestamp - startTimestamp) / duration, 1);
            const val = Math.floor(progress * (end - start) + start);
            obj.innerHTML = val.toLocaleString('id-ID');
            if (progress < 1) {
                window.requestAnimationFrame(step);
            }
        };
        window.requestAnimationFrame(step);
    }

    function updateCharts() {
        const formData = new FormData(filterForm);
        const params = new URLSearchParams(formData);
        const queryString = params.toString();

        // Update Trend Chart Title
        const startDate = formData.get('start_date');
        const endDate = formData.get('end_date');
        const trendTitle = document.getElementById('trend-chart-title');
        
        if (startDate && endDate) {
            const options = { day: 'numeric', month: 'short' };
            const d1 = new Date(startDate).toLocaleDateString('id-ID', options);
            const d2 = new Date(endDate).toLocaleDateString('id-ID', options);
            trendTitle.textContent = `Tren Harian (${d1} - ${d2})`;
        } else {
            trendTitle.textContent = "Tren Harian (14 Hari Terakhir)";
        }

        // Update Key Metrics
        fetch(`api_helper.php?action=get_key_metrics&${queryString}`)
            .then(response => response.json())
            .then(data => {
                const eventCounter = document.getElementById('metric_total_events');
                const qscCounter = document.getElementById('metric_total_qsc');
                const benefitCounter = document.getElementById('metric_total_benefit');
                
                animateValue(eventCounter, 0, data.total_events, 1000);
                animateValue(qscCounter, 0, data.total_qsc, 1000);
                
                // For benefit, we use simple text content with currency
                benefitCounter.textContent = 'Rp ' + data.total_benefit.toLocaleString('id-ID');
                
                // Calculate average momentum
                const avg = data.total_events / (data.total_events > 0 ? 30 : 1);
                document.getElementById('metric_daily_avg').textContent = avg.toFixed(1);
            });

        // Update Chart 1: Daily Trend
        fetch(`api_helper.php?action=get_daily_trend&${queryString}`)
            .then(response => response.json())
            .then(data => {
                if (dailyTrendChart) dailyTrendChart.destroy();
                dailyTrendChart = new Chart(document.getElementById('dailyTrendChart'), {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Jumlah Aktivitas',
                            data: data.counts,
                            borderColor: '#4F46E5',
                            backgroundColor: (context) => {
                                const ctx = context.chart.ctx;
                                const gradient = ctx.createLinearGradient(0, 0, 0, 400);
                                gradient.addColorStop(0, 'rgba(79, 70, 229, 0.2)');
                                gradient.addColorStop(1, 'rgba(79, 70, 229, 0)');
                                return gradient;
                            },
                            borderWidth: 4,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointBackgroundColor: '#fff',
                            pointBorderWidth: 3,
                            pointHoverRadius: 6,
                            pointHoverBorderWidth: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false }, datalabels: { display: false } },
                        scales: {
                            y: { beginAtZero: true, grid: { borderDash: [5, 5], color: '#E2E8F0' }, ticks: { stepSize: 1 } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            });

        // Update Chart 2: Performa Bulanan
        fetch(`api_helper.php?action=get_monthly_performance&${queryString}`)
            .then(response => response.json())
            .then(data => {
                if (performanceChart) performanceChart.destroy();
                const ctx = document.getElementById('performanceChart').getContext('2d');
                performanceChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels.map(m => {
                            // If it's a number (1-12), it's a month
                            if (!isNaN(m) && m >= 1 && m <= 12) {
                                const monthNames = ["", "Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
                                return monthNames[m];
                            }
                            // If it's a date string (YYYY-MM-DD), format it to DD MMM
                            if (typeof m === 'string' && m.includes('-')) {
                                const d = new Date(m);
                                return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
                            }
                            return m;
                        }),
                        datasets: [
                            { 
                                label: 'Event', 
                                data: data.event_counts, 
                                backgroundColor: '#4F46E5',
                                borderRadius: 8,
                                barThickness: 20
                            },
                            { 
                                label: 'QSC', 
                                data: data.qsc_counts, 
                                backgroundColor: '#10B981',
                                borderRadius: 8,
                                barThickness: 20
                            },
                            { 
                                label: 'Revenue', 
                                data: data.benefit_totals, 
                                type: 'line',
                                yAxisID: 'revenue',
                                borderColor: '#F59E0B',
                                borderWidth: 3,
                                pointRadius: 0,
                                tension: 0.4,
                                fill: false
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false }, datalabels: { display: false } },
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                grid: { color: '#E2E8F0', borderDash: [5, 5] },
                                ticks: { font: { weight: 'bold' } }
                            },
                            revenue: {
                                position: 'right',
                                grid: { display: false },
                                ticks: {
                                    callback: (val) => val >= 1000000 ? (val / 1000000) + 'M' : val
                                }
                            }
                        }
                    }
                });
            });

        // Update Chart 3: Distribusi Kategori
        fetch(`api_helper.php?action=get_category_distribution&${queryString}`)
            .then(response => response.json())
            .then(data => {
                if (categoryChart) categoryChart.destroy();
                
                // Update insights
                if (data.labels && data.labels.length > 0) {
                    document.getElementById('top-category-name').textContent = data.labels[0];
                }

                categoryChart = new Chart(document.getElementById('categoryChart'), {
                    type: 'doughnut',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            data: data.values,
                            backgroundColor: [
                                '#4F46E5', '#10B981', '#F59E0B', '#F43F5E', '#8B5CF6', 
                                '#06B6D4', '#F97316', '#3B82F6', '#EC4899', '#84CC16',
                                '#14B8A6', '#EF4444', '#A855F7', '#0EA5E9', '#D946EF',
                                '#22C55E', '#6366F1', '#FACC15', '#FB7185', '#2DD4BF'
                            ],
                            borderWidth: 0,
                            hoverOffset: 15
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '75%',
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true, padding: 15, font: { size: 10, weight: 'bold' } } },
                            datalabels: {
                                color: '#fff',
                                font: { weight: '900', size: 10 },
                                formatter: (value, ctx) => {
                                    let sum = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    let percentage = (value * 100 / sum).toFixed(0) + '%';
                                    return percentage > 5 ? percentage : '';
                                }
                            }
                        }
                    }
                });
            });
    }

    function loadBranches(selectedBrand, selectedBranchId = null) {
        if (!selectedBrand) {
            branchFilter.innerHTML = '<option value="">Pilih Brand Dulu</option>';
            branchFilter.disabled = true;
            return;
        }
        fetch(`api_helper.php?action=get_branches_by_brand&brand=${selectedBrand}`)
            .then(response => response.json())
            .then(data => {
                branchFilter.innerHTML = '<option value="">Semua Branch</option>';
                data.forEach(branch => {
                    const option = new Option(branch.nama_branch, branch.id);
                    branchFilter.add(option);
                });
                if (selectedBranchId) {
                    branchFilter.value = selectedBranchId;
                }
                branchFilter.disabled = false;
            });
    }

    brandFilter.addEventListener('change', () => loadBranches(brandFilter.value));
    
    filterForm.addEventListener('submit', (e) => {
        e.preventDefault();
        updateCharts();
    });

    // Initial Load
    const initialBrand = '<?php echo $selected_brand; ?>';
    const initialBranch = '<?php echo $selected_brand ? $selected_branch : ''; ?>';
    if(initialBrand) {
        loadBranches(initialBrand, initialBranch);
    }
    updateCharts();
});

function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('-translate-x-full');
    overlay.classList.toggle('hidden');
}
</script>
</body>
</html>
