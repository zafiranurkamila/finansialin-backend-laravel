# Finansialin AI - Chatbot Features & Architecture

Finansialin menyediakan fitur Chatbot AI mutakhir berbasis **Google Gemini (2.5-Flash)** yang berfungsi sebagai **Asisten Pribadi Virtual Finansial**. AI ini dirancang dengan pendekatan arsitektur *microservice*, di mana kapabilitas *reasoning* (LLM) di- *host* pada service Python mandiri yang dapat mengeksekusi *Function Calling* untuk mengakses metrik finansial pengguna di database Laravel secara seketika (*real-time*).

---

## 🛠️ Tech Stack & Architecture

Arsitektur AI Finansialin menggunakan pemisahan tanggung jawab yang tegas antara Backend (PHP/Laravel) dan AI Service (Python):

- **Frontend**: Next.js (Bereaksi sebagai antarmuka chat).
- **Backend API Gateway**: Laravel 11 (Autentikasi, Proxying, dan Database Query).
- **AI Microservice**: Python FastAPI.
- **AI/LLM Engine**: Google Gemini 2.5 Flash (`langchain-google-genai`).
- **Agent Framework**: LangChain Agents (ReAct Framework) dengan eksekusi *Tools* kustom.

---

## 🌟 Fitur Utama (Core Capabilities)

1. **Percakapan Luwes & Empatik (Personal Virtual Assistant)**
   AI dilengkapi dengan *System Instruction* yang kuat untuk berperan sebagai asisten kasual (menggunakan *aku/kamu*). AI pantang menebak-nebak angka dan selalu memastikan informasi yang diberikan berbasis data.
2. **Contextual Memory (Riwayat Chat)**
   Service Python menyimpan *state* memori obrolan (`store`) menggunakan `session_id`. Ini mencegah "amnesia" sehingga pengguna bisa melakukan percakapan berantai (misal: *"Lalu bagaimana dengan bulan lalu?"*).
3. **Automated Function Calling (AI-Routing)**
   Agen AI dibekali *Tools* yang secara mandiri menentukan kapan ia perlu menelepon API untuk mengambil data finansial lokal, dan kapan sekadar mengobrol biasa.

---

## ⚙️ Alur Kerja Teknis (The Deep Technical Flow)

Bagaimana tepatnya sebuah pesan dari pengguna diproses hingga menjadi balasan cerdas? Berikut adalah siklus penuhnya:

### 1. Inisiasi dari Frontend
Pengguna mengetik pesan (contoh: *"Berapa saldoku sekarang?"*) di antarmuka Next.js. Frontend mengirimkan payload HTTP POST ke Endpoint Laravel: `POST /api/chat` dengan Header `Authorization: Bearer <token>`.

### 2. Validasi & Proxy oleh Laravel Gateway
Di dalam `ChatbotController.php`, Laravel bertindak sebagai *kurir*:
- Memvalidasi token pengguna dan mengekstrak `user_id`.
- Membuat `session_id` unik jika frontend tidak mengirimkannya.
- Meneruskan (*proxying*) pesan tersebut via HTTP Request ke service Python: `POST http://localhost:8001/chat` dengan menyematkan `user_id` dan `session_id`.

### 3. Pemrosesan Agen LangChain (Python Service)
Di dalam `python_ai_service/services/chatbot.py`:
- Sistem mengambil riwayat percakapan (`history`) berdasarkan `session_id`.
- **Injeksi Konteks**: Sistem menyuntikkan instruksi *invisible* ke dalam *prompt*: `[Sistem: Ingat, user_id pengguna yang sedang ngobrol denganmu saat ini adalah {user_id}]`. Ini penting agar AI tahu data siapa yang harus ia ambil tanpa harus bertanya ke pengguna.
- Pesan dikirim ke agen LLM (Gemini).

### 4. Evaluasi Tools & Function Calling
LLM membaca pesan pengguna. Ia mendeteksi niat (intent): *"Oh, pengguna menanyakan saldo"*. LLM memutuskan untuk menghentikan pemrosesan teks sementara dan memanggil *tool* Python yang terdaftar: `get_user_balance(user_id)`.

### 5. Komunikasi Balik (Internal API Call)
Tool `get_user_balance` di Python mengeksekusi HTTP GET request kembali ke Laravel: `GET /api/internal/balance?user_id=...`.
> **Catatan Keamanan:** Endpoint internal ini secara sengaja diletakkan *di luar* middleware JWT Auth di `api.php`, karena dipanggil antar-server secara privat.

### 6. Resolusi Data oleh Laravel
Laravel mengeksekusi logika di `FinancialInsightService`, mengambil total saldo dari tabel `resources` di database MySQL, merajutnya menjadi JSON, dan mengembalikannya ke service Python (contoh respons: `{"total_balance": 1500000, "currency": "IDR"}`).

### 7. Sintesis Jawaban (Final Turn)
Tool Python menerima JSON tersebut dan memberikannya kepada LLM Gemini. LLM kini memiliki data faktual yang ia butuhkan. LLM kemudian meracik jawaban akhir berdasar persona kasualnya: *"Total saldomu saat ini ada Rp1.500.000 ya!"*

### 8. Pengiriman Respons
Service Python mengembalikan string hasil racikan Gemini ke Laravel, dan Laravel meneruskannya kembali ke Frontend Next.js untuk dirender sebagai *chat bubble*.

---

## 🛠️ Daftar Tools yang Dimiliki AI Saat Ini

AI dapat memanggil fungsi Python berikut secara otomatis:

1. **`get_user_balance(user_id: int)`**
   *Kapan digunakan:* Saat pengguna bertanya tentang total saldo, jumlah uang, atau sisa rekening.
   *Aksi:* Memanggil `GET /api/internal/balance`.
2. **`get_recent_transactions(user_id: int, limit: int = 5)`**
   *Kapan digunakan:* Saat pengguna bertanya mengenai riwayat pengeluaran terbaru, pemasukan terakhir, atau *"uangku habis buat beli apa saja"*.
   *Aksi:* Memanggil `GET /api/internal/recent-transactions`.

*(Tools tambahan dapat dengan mudah diregistrasi di dalam `services/chatbot.py` dan ditautkan ke Endpoint Internal Laravel yang baru).*
