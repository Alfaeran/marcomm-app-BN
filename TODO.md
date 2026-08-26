# To-Do List Migrasi React.js + PHP API

Berikut adalah daftar tugas untuk merombak arsitektur `marcomm_apps` dari monolitik PHP menjadi SPA React + PHP API. Centang (`[x]`) jika sudah selesai.

## Phase 1: Setup Frontend (React + Vite + Tailwind)
- [x] Inisialisasi Vite React project di folder `frontend/`.
- [x] Install dan konfigurasi Tailwind CSS (PostCSS & Autoprefixer).
- [x] Install `react-router-dom` untuk routing.
- [x] Bersihkan file boilerplate Vite dan buat struktur folder (`src/components`, `src/pages`, `src/services`).

## Phase 2: Setup Authentication (Backend & Frontend)
- [x] Buat file `api/login.php` yang mengembalikan JSON (status sukses/gagal & token/info user).
- [x] Buat file `api/check_session.php` untuk validasi status login.
- [x] Buat `Login.jsx` di React dengan desain Tailwind CSS menyerupai halaman login lama.
- [x] Integrasikan `Login.jsx` dengan `api/login.php`.
- [x] Buat mekanisme Protected Route di React Router.

## Phase 3: Layouting & Komponen Utama
- [x] Buat komponen `SidebarAdmin.jsx` berdasarkan `components/sidebar_admin.php`.
- [x] Buat komponen `SidebarUser.jsx`.
- [x] Buat komponen `Header.jsx` / Navbar.
- [x] Buat Layout wrapper (`AdminLayout`, `UserLayout`) untuk menampung routing halaman bersarang (*nested routes*).

## Phase 4: Migrasi Halaman (Pages)
- [x] Buat `AdminDashboard.jsx` (konversi dari `dashboard_admin.php`).
- [x] Buat `api/dashboard_stats.php` (API untuk ambil angka-angka dashboard).
- [x] Buat `UserDashboard.jsx` (konversi dari `dashboard_user.php`).
- [x] Buat `MatproInject.jsx` (konversi form dari `web_inject_matpro.php`).
- [x] Buat API endpoint untuk handle submit form matpro.

## Phase 5: Migrasi Laporan & Fitur Lainnya
- [x] Laporan Matpro (UI + API).
- [x] Kelola User (UI + API).
- [x] Kelola Event (UI + API).
- [x] Deployment Production ke XAMPP (`run_production.bat`).

---

## Phase 6: Master Data Lokasi (Admin)
- [x] Buat `api/master_locations.php` (menangani API Branch, Cluster, Site, Outlet).
- [x] Konversi `admin_manage_branches.php` menjadi `ManageBranches.jsx`.
- [x] Konversi `admin_manage_micro_clusters.php` menjadi `ManageMicroClusters.jsx`.
- [x] Konversi `admin_manage_sites.php` menjadi `ManageSites.jsx`.
- [x] Konversi `admin_manage_outlets.php` menjadi `ManageOutlets.jsx`.
- [x] Buat komponen `UniversalImport.jsx` untuk menggantikan semua `admin_import_*.php` di Master Data.

## Phase 7: Master Data Event & Matpro (Admin)
- [x] Buat `api/master_configs.php`.
- [x] Konversi `admin_manage_event_categories.php` menjadi `ManageEventCategories.jsx`.
- [x] Konversi `admin_manage_matpro_types.php` menjadi `ManageMatproTypes.jsx`.
- [x] Konversi `admin_manage_matpro_projects.php` menjadi `ManageMatproProjects.jsx`.
- [x] Konversi `admin_manage_matpro_stocks.php` menjadi `ManageMatproStocks.jsx` (+ Import).

## Phase 8: Core Input Forms (User & Admin)
- [x] Buat `api/core_inputs.php`.
- [x] Konversi `input_form.php` menjadi `EventInject.jsx`.
- [x] Konversi `bulk_import_event.php` menjadi `BulkImportEvent.jsx`.

## Phase 9: Portal User (User Pages)
- [x] Buat `api/user_portal.php`.
- [x] Konversi `history.php` / `user_events.php` menjadi `UserReports.jsx` dsb.
- [x] Konversi halaman laporan user lainnya (`user_matpro_*.php`, `user_sites.php`, dsb).
- [x] Konversi informasi Site/Outlet milik User.

## Phase 10: Laporan Lanjutan & Visualisasi (Admin)
- [ ] Buat `api/advanced_reports.php`.
- [ ] Konversi `admin_visual_dashboard.php` menjadi `AdminVisualDashboard.jsx` (gunakan Chart.js).
- [ ] Konversi `admin_map_view.php` menjadi `AdminMapView.jsx`.
- [ ] Konversi Laporan MSISDN dan Allocate/Receive.

## Phase 11: Pengaturan Sistem & Keamanan
- [ ] Buat `api/system_settings.php`.
- [ ] Konversi `change_password.php` dan `force_change_password.php`.
- [ ] Konversi `admin_activity_log.php`.
- [ ] Konversi `admin_settings.php`.
- [ ] Halaman Panduan (`guide_*.php`).

## Phase 12: Backup & Ultimate Cleanup
- [ ] Backup versi PHP lama ke folder `Marcomm_apps PHP`.
- [ ] Hapus seluruh file `*.php` lama secara permanen dari *root* folder.
- [ ] Hapus seluruh file *assets* lama.
- [ ] *Build production* terakhir (`npm run build`) untuk memastikan sistem berjalan lancar di `index.html`.
