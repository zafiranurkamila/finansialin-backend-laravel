# 💰 Fitur Gajian (Salary) - Dokumentasi Lengkap

## 📋 Daftar Isi
1. [Database Schema](#database-schema)
2. [Model & Relationships](#model--relationships)
3. [API Endpoints](#api-endpoints)
4. [Flow Sistem](#flow-sistem)
5. [Contoh Penggunaan](#contoh-penggunaan)
6. [Status Management](#status-management)

---

## 🗄️ Database Schema

### Tabel: `salaries`

| Kolom | Tipe | Deskripsi | Nullable |
|-------|------|-----------|----------|
| `idSalary` | BIGINT (Primary Key) | ID unik gajian | ❌ |
| `idUser` | BIGINT (Foreign Key) | ID user penerima gajian | ❌ |
| `amount` | DECIMAL(18,2) | Jumlah gajian | ❌ |
| `salaryDate` | DATE | Tanggal gajian diterima | ❌ |
| `nextSalaryDate` | DATE | Tanggal estimasi gajian berikutnya | ✅ |
| `status` | ENUM | `pending`, `received`, `cancelled` | ❌ |
| `description` | TEXT | Keterangan gajian (Gajian Bulanan, Bonus, dll) | ✅ |
| `source` | VARCHAR(255) | Sumber gajian (Nama Perusahaan/Instansi) | ✅ |
| `autoCreateTransaction` | BOOLEAN | Apakah otomatis membuat transaksi income | ❌ |
| `createdAt` | TIMESTAMP | Waktu record dibuat | ❌ |
| `updatedAt` | TIMESTAMP | Waktu record diupdate | ❌ |

### Indexes
- `idx_salary_user_date`: Kombinasi `(idUser, salaryDate)` - untuk query gajian per user per tanggal
- `idx_salary_status`: Kolom `status` - untuk filter gajian pending/received/cancelled

---

## 🔗 Model & Relationships

### Salary Model
```php
// File: app/Models/Salary.php
class Salary extends Model {
    protected $primaryKey = 'idSalary';
    
    // Relationship
    public function user(): BelongsTo {
        return $this->belongsTo(User::class, 'idUser', 'idUser');
    }
    
    // Scopes
    public function scopeByStatus($query, $status)
    public function scopeThisMonth($query)
    public function scopePending($query)
    public function scopeLatest($query)
}
```

### User Model (Updated)
```php
// Di User model, tambahkan:
public function salaries(): HasMany {
    return $this->hasMany(Salary::class, 'idUser', 'idUser');
}
```

---

## 🔌 API Endpoints

### 1. **GET** `/api/salaries` - Daftar Gajian
**Query Parameters:**
- `status` (optional): Filter by status - `pending`, `received`, `cancelled`
- `month` (optional): Bulan (1-12)
- `year` (optional): Tahun
- `page` (optional): Halaman (default: 1)
- `per_page` (optional): Per halaman (default: 15)

**Response (200):**
```json
{
  "message": "Daftar gajian berhasil diambil",
  "data": {
    "current_page": 1,
    "data": [
      {
        "idSalary": 1,
        "idUser": 5,
        "amount": "5000000.00",
        "salaryDate": "2026-04-01",
        "nextSalaryDate": "2026-05-01",
        "status": "pending",
        "description": "Gajian Bulanan April 2026",
        "source": "PT Maju Jaya Indonesia",
        "autoCreateTransaction": true,
        "createdAt": "2026-04-09T10:00:00.000000Z",
        "updatedAt": "2026-04-09T10:00:00.000000Z"
      }
    ],
    "total": 5,
    "per_page": 15,
    "last_page": 1
  }
}
```

---

### 2. **POST** `/api/salaries` - Tambah Gajian Baru
**Request Body:**
```json
{
  "amount": 5000000,
  "salaryDate": "2026-04-01",
  "nextSalaryDate": "2026-05-01",        // optional, auto-calculated jika kosong
  "description": "Gajian Bulanan April 2026",
  "source": "PT Maju Jaya Indonesia",
  "autoCreateTransaction": true           // default: true
}
```

**Response (201):**
```json
{
  "message": "Gajian berhasil ditambahkan",
  "data": {
    "idSalary": 5,
    "idUser": 5,
    "amount": "5000000.00",
    "salaryDate": "2026-04-01",
    "nextSalaryDate": "2026-05-01",
    "status": "pending",
    "description": "Gajian Bulanan April 2026",
    "source": "PT Maju Jaya Indonesia",
    "autoCreateTransaction": true,
    "createdAt": "2026-04-09T10:15:00.000000Z",
    "updatedAt": "2026-04-09T10:15:00.000000Z"
  }
}
```

---

### 3. **GET** `/api/salaries/{id}` - Detail Gajian
**Response (200):**
```json
{
  "message": "Detail gajian berhasil diambil",
  "data": {
    "idSalary": 1,
    "idUser": 5,
    "amount": "5000000.00",
    "salaryDate": "2026-04-01",
    "nextSalaryDate": "2026-05-01",
    "status": "pending",
    "description": "Gajian Bulanan April 2026",
    "source": "PT Maju Jaya Indonesia",
    "autoCreateTransaction": true,
    "createdAt": "2026-04-09T10:00:00.000000Z",
    "updatedAt": "2026-04-09T10:00:00.000000Z"
  }
}
```

---

### 4. **PUT** `/api/salaries/{id}` - Update Gajian
**Request Body (partial update):**
```json
{
  "amount": 5200000,
  "description": "Gajian Bulanan April 2026 (Revised)",
  "status": "pending"
}
```

**Response (200):**
```json
{
  "message": "Gajian berhasil diperbarui",
  "data": { /* updated salary */ }
}
```

---

### 5. **POST** `/api/salaries/{id}/receive` - Tandai Gajian Diterima ✅
**Deskripsi:** Menandai gajian sebagai sudah diterima dan otomatis membuat transaksi income jika `autoCreateTransaction = true`

**Response (200):**
```json
{
  "message": "Gajian berhasil ditandai sebagai diterima",
  "data": {
    "idSalary": 1,
    "status": "received",
    "salaryDate": "2026-04-01",
    "amount": "5000000.00"
  }
}
```

**Error Cases:**
- 400: Gajian sudah di-mark sebagai received
- 400: Gajian yang dibatalkan tidak bisa ditandai received

---

### 6. **POST** `/api/salaries/{id}/cancel` - Batalkan Gajian ❌
**Deskripsi:** Membatalkan gajian (hanya untuk status `pending`)

**Response (200):**
```json
{
  "message": "Gajian berhasil dibatalkan",
  "data": {
    "idSalary": 1,
    "status": "cancelled"
  }
}
```

**Error Cases:**
- 400: Tidak dapat membatalkan gajian yang sudah diterima

---

### 7. **DELETE** `/api/salaries/{id}` - Hapus Gajian 🗑️
**Deskripsi:** Menghapus record gajian (hanya untuk status `pending` atau `cancelled`)

**Response (200):**
```json
{
  "message": "Gajian berhasil dihapus"
}
```

**Error Cases:**
- 400: Tidak dapat menghapus gajian yang sudah diterima

---

### 8. **GET** `/api/salaries/summary/overview` - Ringkasan Gajian 📊
**Deskripsi:** Mendapatkan overview/summary gajian user

**Response (200):**
```json
{
  "message": "Ringkasan gajian berhasil diambil",
  "data": {
    "totalThisMonth": 5000000,           // Total gajian bulan ini
    "pendingCount": 2,                    // Jumlah gajian pending
    "pendingSalaries": [                  // Detail gajian pending
      {
        "idSalary": 1,
        "amount": "5000000.00",
        "salaryDate": "2026-04-01",
        "status": "pending"
      }
    ],
    "lastSalary": {                       // Gajian terakhir yang diterima
      "idSalary": 2,
      "amount": "5000000.00",
      "salaryDate": "2026-03-01",
      "status": "received"
    },
    "nextSalary": {                       // Gajian pending berikutnya
      "idSalary": 1,
      "amount": "5000000.00",
      "salaryDate": "2026-04-01",
      "status": "pending"
    },
    "totalThisYear": 15000000            // Total gajian tahun ini (received)
  }
}
```

---

## 🔄 Flow Sistem

### **Flow 1: Penambahan Gajian Baru**
```
User membuat gajian baru
        ↓
POST /api/salaries dengan data gajian
        ↓
Backend validasi input
        ↓
Jika nextSalaryDate kosong, auto-hitung (+1 bulan dari salaryDate)
        ↓
Create Salary record dengan status = 'pending'
        ↓
Return data gajian (ID, amount, dates)
        ↓
Frontend tampilkan di list gajian dengan badge "Pending"
```

### **Flow 2: Gajian Diterima + Auto-Create Transaksi**
```
User tandai gajian sebagai diterima
        ↓
POST /api/salaries/{id}/receive
        ↓
Backend cek: status = 'pending' ?
        ↓
Jika bukan pending → Error 400
        ↓
Update Salary: status = 'received'
        ↓
Cek: autoCreateTransaction = true ?
        ↓
Jika true:
  - Cari/buat Category "Gajian" dengan type="income"
  - Create Transaction:
    * idUser = salary.idUser
    * idCategory = salaryCategory.idCategory
    * type = "income"
    * amount = salary.amount
    * date = salary.salaryDate
    * description = salary.description
    * source = salary.source
        ↓
Return success response
        ↓
Frontend:
  - Remove dari "Pending" list
  - Tambah ke "Received" list
  - Update dashboard balance (auto refresh dari GET /api/transactions)
```

### **Flow 3: Dashboard Summary**
```
Frontend load dashboard
        ↓
GET /api/salaries/summary/overview
        ↓
Backend return:
  - Total gajian bulan ini (untuk cash-on-hand estimate)
  - Count/list gajian pending
  - Last salary received date & amount
  - Next salary expected date
  - Total gajian tahun ini (untuk comparison)
        ↓
Frontend display:
  - Card: "Gajian Bulan Ini: Rp 5.000.000"
  - Card: "Gajian Pending: 2" dengan link ke detail
  - Card: "Gajian Terakhir: Rp 5.000.000 (Received on Mar 1, 2026)"
  - Card: "Total Tahun Ini: Rp 15.000.000"
```

### **Flow 4: Batalkan Gajian**
```
User batalkan gajian (only pending)
        ↓
POST /api/salaries/{id}/cancel
        ↓
Backend cek: status != 'received' ?
        ↓
Update Salary: status = 'cancelled'
        ↓
Return success
        ↓
Frontend:
  - Remove dari pending list
  - Optionally show di "Cancelled" history
```

---

## 💡 Contoh Penggunaan

### **Postman: Add Salary**
```
Method: POST
URL: {{baseUrl}}/api/salaries
Headers:
  - Authorization: Bearer {{accessToken}}
  - Content-Type: application/json

Body (JSON):
{
  "amount": 5000000,
  "salaryDate": "2026-04-01",
  "description": "Gajian Bulanan April 2026",
  "source": "PT Maju Jaya Indonesia",
  "autoCreateTransaction": true
}
```

### **Postman: Mark Salary as Received**
```
Method: POST
URL: {{baseUrl}}/api/salaries/1/receive
Headers:
  - Authorization: Bearer {{accessToken}}

Body: (empty)

Response:
{
  "message": "Gajian berhasil ditandai sebagai diterima",
  "data": { /* salary data */ }
}
→ Automatically creates income transaction
```

### **JavaScript/React Frontend Example**
```javascript
// Add salary
const addSalary = async (salaryData) => {
  const response = await fetch(`${API_BASE}/salaries`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(salaryData)
  });
  return response.json();
};

// Get salary summary
const getSalarySummary = async () => {
  const response = await fetch(`${API_BASE}/salaries/summary/overview`, {
    headers: {
      'Authorization': `Bearer ${accessToken}`
    }
  });
  return response.json();
};

// Mark salary as received
const receiveSalary = async (salaryId) => {
  const response = await fetch(`${API_BASE}/salaries/${salaryId}/receive`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${accessToken}`
    }
  });
  return response.json();
};

// Usage
const newSalary = await addSalary({
  amount: 5000000,
  salaryDate: '2026-04-01',
  description: 'Gajian Bulanan',
  source: 'PT Maju Jaya'
});

const summary = await getSalarySummary();
console.log(`Gajian pending: ${summary.data.pendingCount}`);
console.log(`Gajian bulan ini: Rp ${summary.data.totalThisMonth}`);

// Mark as received (also creates transaction automatically)
await receiveSalary(newSalary.data.idSalary);
```

---

## 📊 Status Management

| Status | Deskripsi | Bisa Diubah Ke | Bisa Dihapus |
|--------|-----------|-----------------|--------------|
| **pending** | Gajian belum diterima | received, cancelled | ✅ Ya |
| **received** | Gajian sudah diterima | ❌ Tidak | ❌ Tidak |
| **cancelled** | Gajian dibatalkan | ❌ Tidak | ✅ Ya |

---

## 🚀 Frontend Integration Checklist

- [ ] Create Salary form (amount, date, description, source)
- [ ] Add Salary button di dashboard
- [ ] List Salaries with filters (status, month)
- [ ] Salary card component showing amount, date, status badge
- [ ] "Mark as Received" button (with confirmation modal)
- [ ] Salary summary card di dashboard (total this month, pending count, etc)
- [ ] Auto-refresh transactions list setelah mark salary received
- [ ] Display salary schedule/timeline (expected next salary)
- [ ] Edit Salary form (for pending salaries only)
- [ ] Cancel Salary confirmation
- [ ] Delete Salary confirmation (pending/cancelled only)

---

## 🔐 Security Notes

✅ All endpoints require `token.auth` middleware
✅ User hanya bisa akses salary data miliknya (idUser validation)
✅ Status transitions validate (pending → received, pending → cancelled)
✅ Transactions created automatically are marked with source

---

## 📝 Database Commands

```bash
# Run migration
php artisan migrate

# Run seeder (untuk test data)
php artisan db:seed --class=SalarySeeder

# Rollback migration
php artisan migrate:rollback

# Fresh database (delete & recreate)
php artisan migrate:fresh --seed
```

---

**Last Updated:** 2026-04-09
**Version:** 1.0.0
