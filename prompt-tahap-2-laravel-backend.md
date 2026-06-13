# PROMPT UNTUK AI AGENT: Tahap 2 Percepatan Backend Laravel Finansialin

Jalankan AI agent di root folder repo `finansialin-backend-laravel`. Salin seluruh isi di bawah garis ini.

---

## PERAN DAN KONTEKS

Kamu adalah senior Laravel engineer yang bertugas mengoptimalkan performa backend **Finansialin**, aplikasi manajemen keuangan pribadi. Stack: Laravel 12, PHP 8.x, PostgreSQL (database eksternal via DATABASE_URL), token-based auth (access + refresh token). Fitur: auth, profil user, kategori, transaksi, budget, savings goals, notifikasi, verifikasi email, reset password.

Backend ini juga melayani AI service eksternal (FastAPI) melalui route group `/api/internal/*` yang berisi endpoint: recent-transactions, balance, budget-status, monthly-analytics, spending-trend, financial-profile, savings-goals, dan financial-context. Endpoint internal ini dipanggil chatbot AI setiap kali user chat, jadi kecepatannya berdampak langsung ke kecepatan chatbot.

Lingkungan development: Windows (php artisan serve). Production direncanakan via Docker (sudah ada Dockerfile di repo).

## TUGAS 0: Audit dan verifikasi kondisi awal

Sebelum mengubah apa pun, lakukan dan laporkan:

1. Verifikasi keberadaan endpoint `GET /api/internal/financial-context` dan middleware `InternalServiceAuth` (header `X-Internal-Token`) dari pekerjaan tahap sebelumnya. Jika belum ada, buat sekarang sesuai spesifikasi: financial-context mengembalikan saldo, 5 transaksi terakhir, status budget bulan berjalan, dan ringkasan analytics bulan berjalan dalam satu response; middleware membandingkan header dengan env `INTERNAL_API_TOKEN` dan membalas 401 jika tidak cocok, dipasang di seluruh route `/api/internal/*`.
2. Baca semua controller di `app/Http/Controllers` dan semua model di `app/Models`. Buat daftar: (a) endpoint yang mengembalikan list tanpa pagination, (b) relasi yang diakses di dalam loop atau di resource tanpa eager loading (potensi N+1), (c) query agregasi (SUM, COUNT, GROUP BY) yang dihitung berulang seperti saldo, total pengeluaran bulanan, pemakaian budget.
3. Baca semua file di `database/migrations` dan buat daftar index yang sudah ada per tabel.
4. Cek driver saat ini di config dan .env.example: CACHE_STORE, QUEUE_CONNECTION, SESSION_DRIVER.

Laporkan hasil audit sebagai daftar temuan SEBELUM lanjut ke Tugas 1. Tugas-tugas berikutnya harus mengacu ke temuan nyata ini, bukan asumsi.

## TUGAS 1: Index database dan perbaikan query

1. Buat satu migration baru berisi index yang dibutuhkan berdasarkan pola query nyata dari audit. Minimal pertimbangkan (sesuaikan nama kolom dengan skema sebenarnya):
   - `transactions`: index komposit `(user_id, date)` dan `(user_id, category_id)`, serta `(user_id, type, date)` jika kolom type (income/expense) sering difilter.
   - `budgets`: index komposit `(user_id, month, year)` atau ekuivalennya sesuai skema.
   - `notifications`: `(user_id, read_at)` atau `(user_id, created_at)`.
   - Tabel token/refresh token: index pada kolom yang dipakai lookup saat autentikasi.
   Jangan membuat index pada kolom yang tidak pernah muncul di WHERE/ORDER BY hasil audit.
2. Perbaiki semua N+1 yang ditemukan di audit dengan eager loading (`with()`, `withCount()`, `withSum()`). Gunakan `withSum`/`withCount` untuk agregasi per relasi alih-alih loop manual.
3. Tambahkan `Model::preventLazyLoading(!app()->isProduction())` di `AppServiceProvider::boot()` agar N+1 langsung ketahuan saat development dan test.
4. Pastikan SEMUA endpoint list (transaksi, notifikasi, kategori jika berpotensi banyak) memakai pagination (`paginate` atau `cursorPaginate` untuk infinite scroll di mobile). Pertahankan struktur response yang sudah dikonsumsi frontend; jika sebelumnya array polos, bungkus dengan meta pagination secara backward-compatible dan dokumentasikan perubahannya.
5. Untuk query SELECT, ambil hanya kolom yang dibutuhkan (`select([...])`) pada endpoint yang berat, jangan `SELECT *` untuk tabel transaksi.

## TUGAS 2: Redis untuk cache dan konfigurasi

1. Tambahkan koneksi Redis di `config/database.php` (gunakan phpredis jika tersedia, fallback predis; tambahkan `predis/predis` via composer agar tidak bergantung ekstensi PHP di Windows).
2. Set di .env.example: `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_URL=redis://127.0.0.1:6379`. Pertahankan fallback yang aman: aplikasi harus tetap bisa boot dengan `CACHE_STORE=file` dan `QUEUE_CONNECTION=sync` untuk developer yang belum menjalankan Redis.
3. Implementasikan caching pada data yang mahal dan sering dibaca, dengan pola cache-aside dan key per user:
   - Saldo user: key `user:{id}:balance`, TTL 300 detik.
   - Monthly analytics bulan berjalan: key `user:{id}:analytics:{year}-{month}`, TTL 300 detik.
   - Status budget bulan berjalan: key `user:{id}:budget-status:{year}-{month}`, TTL 300 detik.
   - Spending trend: key `user:{id}:trend:{months}`, TTL 600 detik.
   - Financial-context (gabungan untuk AI): key `user:{id}:financial-context`, TTL 120 detik.
4. INVALIDASI WAJIB: buat satu service/trait terpusat (misal `app/Services/UserCacheService.php`) dengan method `flushFinancialCache(int $userId)` yang menghapus semua key di atas. Panggil dari event/observer Model Transaction (created, updated, deleted) dan Budget (created, updated, deleted). Jangan tebar `Cache::forget` manual di banyak controller.
5. Cache di level data/service, BUKAN di level HTTP response, supaya logika authorization tetap berjalan normal.
6. Pindahkan rate limiter dan session (jika dipakai API) ke Redis juga agar konsisten.

## TUGAS 3: Pindahkan pekerjaan lambat ke queue

1. Identifikasi dari audit semua pekerjaan synchronous yang lambat di request cycle. Minimal: kirim email verifikasi, email reset password, dan pembuatan notifikasi yang tidak harus dibaca user di response yang sama.
2. Jadikan semua Mailable/Notification tersebut queued (`implements ShouldQueue`), queue name `emails` untuk email dan `default` untuk lainnya.
3. Tambahkan konfigurasi retry yang masuk akal: `tries=3`, backoff bertahap, dan `failed_jobs` table termigrasikan.
4. Tambahkan instruksi menjalankan worker di README: `php artisan queue:work redis --queue=emails,default --tries=3`. Jangan tambahkan Horizon (butuh ekstensi pcntl, tidak jalan di Windows); cukup queue:work biasa, dan beri catatan bahwa Horizon bisa dipasang nanti saat deploy di Linux/Docker.
5. Pastikan response endpoint register dan forgot-password tidak lagi menunggu SMTP: balas sukses segera setelah job ter-dispatch.

## TUGAS 4: Optimasi bootstrap dan konfigurasi Laravel

1. Pastikan production-ready optimization terdokumentasi dan masuk Dockerfile: `php artisan config:cache`, `route:cache`, `event:cache`, `view:cache` (jika ada view), `composer install --no-dev --optimize-autoloader`.
2. Aktifkan OPcache di image Docker: tambahkan konfigurasi opcache (opcache.enable=1, opcache.validate_timestamps=0 untuk production, memory_consumption=128, max_accelerated_files cukup besar). Beri file ini di `docker/php/opcache.ini` dan COPY di Dockerfile.
3. Audit middleware global: pastikan tidak ada middleware berat yang jalan untuk semua route padahal hanya dibutuhkan sebagian.
4. Cek autentikasi token: pastikan lookup token tidak melakukan query berlebihan per request (lihat hasil audit Tugas 0 poin tabel token). Jika setiap request memvalidasi token dengan beberapa query, sederhanakan menjadi satu query terindex. Jangan mengubah format token atau alur refresh yang sudah dikonsumsi frontend.

## TUGAS 5: Observability minimum

1. Pasang `laravel/telescope` sebagai dev-dependency saja, dengan gate yang hanya aktif di environment local. Pastikan service provider Telescope tidak ter-register di production (gunakan `dontDiscover` + register kondisional).
2. Tambahkan logging durasi query lambat: di `AppServiceProvider`, gunakan `DB::listen` untuk log WARNING setiap query di atas 100 ms (threshold via env `SLOW_QUERY_MS`, default 100), hanya saat `APP_DEBUG=true` atau env khusus aktif.
3. Buat endpoint `GET /api/health` (tanpa auth) yang mengecek koneksi database dan Redis, balas `{ status, db, redis, time }`. Endpoint ini akan dipakai docker compose healthcheck dan monitoring.

## TUGAS 6 (OPSIONAL, kerjakan terakhir): Laravel Octane via Docker

Catatan: development dilakukan di Windows, jadi Octane TIDAK boleh menjadi syarat untuk menjalankan project secara lokal. `php artisan serve` harus tetap berfungsi normal.

1. Tambahkan `laravel/octane` dengan FrankenPHP, dikonfigurasi HANYA untuk runtime Docker. Buat Dockerfile target/stage terpisah (misal stage `octane`) sehingga image lama tetap bisa dipakai.
2. Periksa dan perbaiki kode agar Octane-safe: tidak ada state statis yang menyimpan data antar request (singleton yang membawa data user, properti static yang di-mutate, dsb). Laporkan setiap temuan dan perbaikannya.
3. Update docker-compose (atau buat compose untuk backend) dengan service app berbasis Octane + Redis, lengkap healthcheck memakai `/api/health`.
4. Dokumentasikan di README: cara menjalankan mode klasik (artisan serve) untuk development Windows dan mode Octane (docker compose) untuk production-like.

## ATURAN DAN BATASAN

1. JANGAN mengubah kontrak API publik: bentuk JSON response, nama field, dan alur auth yang sudah dikonsumsi frontend dan AI service harus tetap sama. Penambahan meta pagination harus backward-compatible dan didokumentasikan.
2. Jangan menambahkan dependensi besar di luar yang disebut (tanpa Horizon, tanpa Laravel Pulse, tanpa paket cache pihak ketiga).
3. Semua nilai TTL, threshold, dan koneksi lewat env var dengan default yang disebut di atas, tambahkan semuanya ke `.env.example`.
4. Setiap migration baru harus reversible (method down yang benar).
5. Jalankan `php artisan test` setelah setiap tugas. Test yang ada (FrontendContractTest, MigrationParityTest, dan lainnya) tidak boleh gagal. Jika perubahan pagination mempengaruhi test kontrak, update test-nya secara sadar dan jelaskan alasannya.
6. Tambahkan feature test baru minimal untuk: (a) cache invalidation (buat transaksi, pastikan saldo cache berubah), (b) endpoint internal menolak request tanpa header token, (c) endpoint health.
7. Komentar kode dalam Bahasa Indonesia, ringkas.

## DEFINITION OF DONE

1. Hasil audit Tugas 0 terlapor sebagai daftar temuan konkret.
2. Migration index berjalan sukses (`php artisan migrate`) dan bisa di-rollback.
3. Tidak ada lazy loading violation saat menjalankan seluruh test suite (preventLazyLoading aktif di non-production).
4. Dengan Redis aktif: hit kedua ke endpoint balance/analytics/financial-context terbukti dari cache (verifikasi dengan log query: hit kedua tidak menjalankan query agregasi).
5. Membuat transaksi baru langsung mengubah hasil endpoint balance (bukti invalidasi bekerja).
6. Endpoint register membalas dalam waktu cepat tanpa menunggu pengiriman email (email masuk queue, terkirim saat queue:work jalan).
7. `php artisan test` hijau semua, termasuk test baru.
8. `GET /api/health` membalas status db dan redis dengan benar.
9. (Jika Tugas 6 dikerjakan) `docker compose up` menjalankan app mode Octane dan healthcheck passing, sementara `php artisan serve` di Windows tetap berfungsi.

Kerjakan berurutan mulai Tugas 0. Setelah setiap tugas selesai, jalankan test, laporkan ringkasan perubahan file dan hasil verifikasi, lalu lanjut ke tugas berikutnya.
