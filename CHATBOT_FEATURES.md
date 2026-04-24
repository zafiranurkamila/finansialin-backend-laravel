# Finansialin AI - Chatbot Features & Architecture

Finansialin menyediakan fitur Chatbot AI mutakhir berbasis Google Gemini (2.5-Flash) yang difungsikan sebagai **Asisten Pribadi Virtual Finansial**. AI ini dirancang tidak hanya untuk ngobrol secara teks (LLM), tetapi juga mampu mengeksekusi *Function Calling* yang dapat mengakses metrik finansial riil pengguna di database.

## 🌟 Fitur Utama (Core Capabilities)

1. **Percakapan Luwes & Empatik (Personal Virtual Assistant)**
   AI dilengkapi dengan *System Instruction* yang kuat untuk berperan sebagai asisten kasual (menggunakan *aku/kamu*). AI tidak hanya mampu memberikan saran finansial beralasan dari datamu, tetapi juga lihai dalam menjawab obrolan biasa atau sapaan dengan luwes.
2. **Memori Mengalir (Contextual History)**
   Sistem mendukung pengiriman array `history` obrolan sehingga AI tidak "amnesia". AI mengerti percakapan sebelumnya dan dapat menjawab berdasarkan konteks pembicaraan berantai.
3. **Automated Function Calling (AI-Routing)**
   Mampu memutuskan secara pintar kapan ia perlu mengecek pangkalan data lokal Finansialin dan kapan sekadar menjawab dari pengetahuannya sendiri. Ia memiliki "mata" untuk menengok data melalui daftar *tools* bawaan.

## 🛠️ Daftar Tools (AI Capabilities)

Asisten Virtual Finansialin dipersenjatai fungsi-fungsi berikut yang dapat dia eksekusi kapanpun dia mau secara mandiri:

1. `getWalletBalances`: Mengambil daftar dompet milik pengguna peserta jumlah saldo saat ini.
2. `getMonthlyAnalytics`: Menarik data agregat pengeluaran dan pemasukan bulanan berdasarkan kategori tertentu (bisa menentukan parameter `month` & `year`).
3. `getBudgetStatus`: Memperoleh status sisa batas *budget* yang ditetapkan pengguna. AI bisa mendeteksi kelalaian *overbudgeting*.
4. `getRecentTransactions`: Memeriksa secara detail riwayat mutasi transaksi manual/sistem pengguna dengan urutan terbaru (berguna mendeteksi pembelian yang spesifik).

## ⚙️ Arsitektur & Alur Kerja (The Flow)

Sistem menggunakan alur **Two-Turn Request Loop** karena berfokus pada Function Calling:

1. **User Request**: User di frontend mengirim pesan input + history percakapan `[{role: 'user', text: '...'}, ...]` ke backend endpoint `/api/chatbot`.
2. **Turn 1 (Prompting the LLM)**: Backend merajut Prompt Sistem, Histori Obrolan, dan Deskripsi **Tools** lalu mengirimnya ke API Google Gemini.
3. **AI Decision**: Jika Gemini memutuskan ia perlu data, ia merespons balasan kosong namun menyertakan payload `functionCall` (meminta izin memanggil fungsi).
4. **Backend Resolution**: Laravel menangkap permintaan fungsi (misal: panggilan request ke `getRecentTransactions`), lalu menjalankan fungsi itu di **FinancialInsightService** ke pangkalan data Supabase secara rahasia.
5. **Turn 2 (Feedback)**: Laravel meneruskan kembali balasan asli Gemini (`functionCall`) secara bersamaan dengan Response Payload Data Query ke API Gemini.
6. **Final Output**: Gemini merajut angka-angka hasil Data Query tersebut menjadi bahasa kasual manusia dan dikembalikan ke user.

## 📃 Contoh Skenario Percakapan

**User**: *"Hai Finansialin, kemarin aku ngapain aja ya kok uangku tiris banget?"*
*(Backend meneruskan info. Gemini melihat ini dan meminta pemanggilan fungsi `getRecentTransactions(limit: 5)`)*
*(Backend menjalankan fungsi dan memulangkan datanya ke Gemini)*

**Finansialin AI**: *"Hai! Wah iya nih, kalau aku cek dari riwayat kamu kemarin, kamu bayar tagihan Spotify 55.000 terus sempet beli kopi di Kenangan 35.000. Saldo di dompet e-money kamu juga tinggal 20.000 lho. Mau aku kasih saran cara nekan pengeluaran minggu ini nggak?"*
