# Dokumentasi Teknis Backend Finansialin (Deep Dive)

Dokumen ini menyediakan rincian teknis lengkap mengenai arsitektur, database, dan rincian file backend Finansialin untuk kebutuhan presentasi.

---

## 1. Arsitektur & Tech Stack
*   **Core Framework:** Laravel 11 (PHP 8.2+).
*   **Database Engine:** **Supabase (PostgreSQL)** — Database eksternal yang menjamin skalabilitas, keamanan tinggi, dan ketersediaan data secara cloud.
*   **Caching & Queue:** Database Driver (untuk Jobs/Cache).
*   **API Standard:** RESTful API dengan JSON Response.
*   **AI Integration:** Google Gemini API (v1beta).
*   **OCR Service:** Terintegrasi di `TransactionsController` untuk ekstraksi data struk.

---

## 2. Struktur Folder & Rincian File Utama

### A. Folder `app/Http/Controllers` (Otak Logika API)
Setiap file di sini menangani permintaan (request) spesifik dari frontend:
*   **`AiController.php`**: Mengatur chatbot Gemini, integrasi Function Calling, dan manajemen fallback model AI.
*   **`AuthController.php`**: Menangani sistem pendaftaran, login, logout, dan verifikasi identitas pengguna.
*   **`TransactionsController.php`**: Mengelola input pengeluaran/pemasukan, transfer saldo, dan integrasi scan struk (OCR).
*   **`BudgetsController.php`**: Logika pengaturan limit budget dan pengecekan otomatis sisa anggaran.
*   **`AnalyticsController.php`**: Mengambil data statistik untuk ditampilkan dalam bentuk grafik di dashboard.
*   **`ResourcesController.php`**: Mengelola daftar dompet/rekening (tambah, edit, hapus dompet).
*   **`SecurityController.php`**: Menangani fitur keamanan tingkat tinggi seperti log login dan Two-Factor Authentication (2FA).
*   **`NotificationsController.php`**: Mengatur pesan masuk dan peringatan sistem ke user.

### B. Folder `app/Models` (Representasi Data)
File-file ini adalah jembatan antara kode PHP dan tabel di Supabase:
*   **`User.php`**: Model utama pengguna.
*   **`Transaction.php`**: Mewakili data transaksi keuangan.
*   **`Category.php`**: Mewakili kategori (Makanan, Transportasi, dll).
*   **`Budget.php`**: Mewakili data limit anggaran.
*   **`Resource.php`**: Mewakili data dompet atau sumber dana.

### C. Folder `app/Services` (Logika Khusus)
*   **`FinancialInsightService.php`**: File khusus yang berisi rumus-rumus perhitungan keuangan (seperti menghitung total kekayaan bersih atau tren pengeluaran) yang nantinya dikirim ke AI.

### D. Folder `database/migrations` (Blueprint Database)
*   Berisi instruksi pembuatan tabel-tabel di Supabase. Setiap file mewakili satu perubahan struktur database (misal: tambah kolom baru atau buat tabel baru).

### E. Folder `routes/` (Pintu Masuk API)
*   **`api.php`**: File yang mendaftarkan semua URL endpoint yang bisa dipanggil oleh Frontend (contoh: `/api/ai/chat`).

---

## 3. Skema Database (Supabase PostgreSQL Schema)
Sistem menggunakan database relasional PostgreSQL yang di-host di Supabase dengan relasi antar tabel yang ketat:

### A. Tabel Utama
*   **`users`**: Data kredensial, profil, dan status keamanan (2FA).
*   **`resources`**: Data dompet/rekening dan saldo saat ini.
*   **`transactions`**: Riwayat uang masuk/keluar lengkap dengan kategori dan sumber dananya.
*   **`budgets`**: Limit pengeluaran per kategori per bulan.
*   **`categories`**: Daftar kategori transaksi (Pemasukan vs Pengeluaran).

---

## 4. Sistem OTP (One-Time Password)
Sistem OTP di Finansialin digunakan untuk validasi identitas pada tiga alur utama: **Registrasi**, **Login 2FA**, dan **Reset Password**.

### A. Alur Kerja Teknikal OTP
1.  **Generasi:** Backend menggunakan fungsi `random_int(0, 999999)` untuk menghasilkan 6 digit angka acak yang aman secara kriptografis.
2.  **Penyimpanan Aman:** Kode OTP tidak disimpan dalam bentuk teks mentah. Sistem menggunakan **SHA-256 Hashing** untuk menyimpan kode di tabel `security_otps` atau `pending_registrations`, sehingga jika database bocor, kode OTP tetap aman.
3.  **Masa Berlaku (TTL):** Setiap kode OTP memiliki kolom `expiresAt` (biasanya 10 menit). Setelah waktu tersebut lewat, kode otomatis dianggap tidak valid.
4.  **Limit Percobaan:** Untuk mencegah *brute-force*, sistem membatasi percobaan verifikasi (maksimal 5 kali). Jika salah lebih dari 5 kali, data OTP akan dihapus dan user harus meminta kode baru.

### B. Implementasi di Berbagai Alur
*   **Registrasi:** Data user (password, email, nama) disimpan sementara di tabel `pending_registrations`. Akun baru benar-benar dibuat di tabel `users` **hanya setelah** OTP berhasil diverifikasi.
*   **Login 2FA:** Jika fitur 2FA aktif, setelah login berhasil (email/password benar), sistem tidak langsung memberikan akses, melainkan mengirim OTP dan meminta verifikasi tambahan.
*   **Reset Password:** OTP dikirim ke email untuk membuktikan kepemilikan akun sebelum user diizinkan mengubah password mereka.

---

## 5. Fitur Unggulan & Logika Bisnis

### A. Alur Transaksi & Automasi Saldo
Saat transaksi dibuat, sistem secara otomatis menjalankan logika:
1.  Mengurangi saldo pada tabel `resources` (jika pengeluaran).
2.  Menambah angka penggunaan budget pada tabel `budgets`.
3.  Memicu notifikasi jika budget sudah hampir habis (80%).

### B. FinBot Orchestrator (Chatbot AI)
*   **Teknologi:** Google Gemini dengan **Function Calling**.
*   **Cara Kerja:** AI tidak menebak data, tapi menjalankan query ke data Supabase melalui fungsi-fungsi di backend:
    *   `getWalletBalances()` -> Query ke tabel `resources`.
    *   `getSpendingTrend()` -> Agregasi data dari tabel `transactions`.

---

## 5. Penanganan Stabilitas
*   **Multi-Model Fallback:** Jika model AI utama sibuk, backend melakukan *retry* ke model cadangan secara otomatis.
*   **Quota Aware:** Menangkap error kuota API Google (429) agar aplikasi tidak crash dan memberi pesan ramah ke user.

---

**Dibuat oleh:** Tim Finansialin
**Versi:** 1.3 (Dokumentasi Struktur File Lengkap)
