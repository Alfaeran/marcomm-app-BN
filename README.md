# Marcomm Apps (Decoupled React.js + PHP API)

Aplikasi Marcomm telah berhasil dimigrasikan dari sistem Monolitik (PHP Native + HTML Server-Side Rendered) menjadi **Decoupled Architecture**:
1. **Frontend:** React.js (Vite) + Tailwind CSS
2. **Backend:** PHP Native API + MySQL

## Struktur Direktori Utama

- `/frontend` : Berisi kode *source* aplikasi React (UI/Frontend).
- `/api` : Berisi endpoint PHP yang akan merespon dengan data berformat JSON.
- `/assets` : Aset-aset CSS dan JS lama serta gambar.
- `index.html` : Entry point untuk SPA React di production (hasil build Vite).

## Cara Menjalankan (Production di XAMPP)

Sistem sudah di-build dan siap digunakan di XAMPP:
1. Pastikan folder `marcomm_apps` berada di dalam folder `htdocs` XAMPP Anda.
2. Jalankan **Apache** dan **MySQL** dari XAMPP Control Panel.
3. Buka browser dan akses: `http://localhost/marcomm_apps/`
4. Aplikasi React akan langsung terbuka dan secara otomatis mengambil data dari backend API PHP.

## Cara Menjalankan (Development Frontend)

Jika Anda ingin melakukan modifikasi pada UI/Frontend:
1. Buka terminal dan masuk ke folder frontend:
   ```bash
   cd frontend
   ```
2. Jalankan development server:
   ```bash
   npm run dev
   ```
3. Buka URL yang diberikan oleh Vite (biasanya `http://localhost:5173`) di browser Anda.
4. Jika sudah selesai melakukan perubahan, jalankan perintah build untuk menimpa file production:
   ```bash
   npm run build
   ```
   Lalu copy seluruh isi file dari `frontend/dist/` ke direktori root `marcomm_apps/`.

## Road to Phase 12 (On-going Migration)
Saat ini proyek telah menyelesaikan migrasi inti hingga **Phase 5**. Sisa halaman admin dan user (*Master Data, Advanced Reports, Event Forms*, dsb.) yang terdiri dari sekitar 60 file PHP lama akan dimigrasikan pada **Phase 6 - Phase 12**. Setelah semuanya selesai, sistem akan mencadangkan (*backup*) dan menghapus total sisa file PHP lama agar menjadi aplikasi *React Decoupled* yang murni dan ringan.

## Styling (CSS)
Aplikasi ini menggunakan **Tailwind CSS**. Desain diupayakan untuk mempertahankan gaya visual dari aplikasi PHP sebelumnya. Semua modifikasi tampilan silakan merujuk pada standar class Tailwind.
