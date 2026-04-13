# 🚀 Quick Start - Fitur Gajian

## Instalasi & Setup Database

### 1. Run Migration (Buat Tabel)
```bash
# Local (Without Docker)
php artisan migrate

# Dengan Docker
docker compose --env-file .env.docker exec backend php artisan migrate
```

### 2. Seed Test Data (Opsional)
```bash
# Tanpa Docker
php artisan db:seed --class=SalarySeeder

# Dengan Docker
docker compose --env-file .env.docker exec backend php artisan db:seed --class=SalarySeeder
```

---

## 📱 API Testing dengan Postman

### Setup Postman Environment
```
Variable: base_url
Value: http://localhost:8000/api

Variable: access_token
Value: (paste dari login response)
```

### Test Cases

#### 1️⃣ **Add New Salary**
```
POST {{base_url}}/salaries

{
  "amount": 5000000,
  "salaryDate": "2026-04-15",
  "description": "Gajian Bulanan April",
  "source": "PT Maju Jaya Indonesia",
  "autoCreateTransaction": true
}

Expected: 201 Created
```

#### 2️⃣ **List All Salaries**
```
GET {{base_url}}/salaries

Query params:
- status: pending (optional)
- month: 4 (optional)
- year: 2026 (optional)

Expected: 200 OK dengan pagination
```

#### 3️⃣ **Get Salary Summary**
```
GET {{base_url}}/salaries/summary/overview

Expected: 200 OK dengan:
- totalThisMonth
- pendingCount
- lastSalary
- nextSalary
- totalThisYear
```

#### 4️⃣ **Get Single Salary Detail**
```
GET {{base_url}}/salaries/1

Expected: 200 OK
```

#### 5️⃣ **Update Salary**
```
PUT {{base_url}}/salaries/1

{
  "amount": 5200000,
  "description": "Gajian Bulanan April (Revised)"
}

Expected: 200 OK
```

#### 6️⃣ **Mark Salary as Received** ✅
```
POST {{base_url}}/salaries/1/receive

Expected: 200 OK
Bonus: Creates automatic income transaction!
```

#### 7️⃣ **Cancel Salary** ❌
```
POST {{base_url}}/salaries/1/cancel

Expected: 200 OK (only for pending status)
```

#### 8️⃣ **Delete Salary** 🗑️
```
DELETE {{base_url}}/salaries/1

Expected: 200 OK (only for pending/cancelled)
```

---

## 🗄️ Database Schema Quick Look

```sql
CREATE TABLE salaries (
  idSalary BIGINT PRIMARY KEY AUTO_INCREMENT,
  idUser BIGINT NOT NULL FOREIGN KEY,
  amount DECIMAL(18,2) NOT NULL,
  salaryDate DATE NOT NULL,
  nextSalaryDate DATE,
  status ENUM('pending', 'received', 'cancelled') DEFAULT 'pending',
  description TEXT,
  source VARCHAR(255),
  autoCreateTransaction BOOLEAN DEFAULT true,
  createdAt TIMESTAMP DEFAULT NOW(),
  updatedAt TIMESTAMP DEFAULT NOW() ON UPDATE NOW()
);
```

---

## 📊 Info Penting

### Auto-Generated Data
Ketika gajian di-mark sebagai **received** dengan `autoCreateTransaction=true`:
- ✅ Secara otomatis membuat **Transaction** dengan:
  - type: `income`
  - amount: sama dengan salary.amount
  - date: salary.salaryDate
  - Category: "Gajian" (auto-created jika belum ada)

### Status Flow
```
pending ──→ received (final)
   ↓
   └──→ cancelled (final)
```

### Validation Rules
- Amount: `required|numeric|min:0`
- salaryDate: `required|date`
- description: `max:255`
- status: hanya bisa `pending`, `received`, `cancelled`

---

## 💻 Frontend Implementation Tips

### React Component Example
```jsx
// Salary List Component
function SalaryList() {
  const [salaries, setSalaries] = useState([]);
  
  useEffect(() => {
    // Fetch salaries
    fetch(`/api/salaries`, {
      headers: { Authorization: `Bearer ${token}` }
    })
    .then(r => r.json())
    .then(d => setSalaries(d.data.data));
  }, []);
  
  const handleReceive = async (id) => {
    const res = await fetch(`/api/salaries/${id}/receive`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}` }
    });
    if (res.ok) {
      // Refresh list atau update state
    }
  };
  
  return (
    <div>
      {salaries.map(salary => (
        <div key={salary.idSalary} className={`card status-${salary.status}`}>
          <h3>{salary.description}</h3>
          <p>Rp {salary.amount.toLocaleString('id-ID')}</p>
          <p>{new Date(salary.salaryDate).toLocaleDateString('id-ID')}</p>
          {salary.status === 'pending' && (
            <button onClick={() => handleReceive(salary.idSalary)}>
              Mark as Received
            </button>
          )}
        </div>
      ))}
    </div>
  );
}
```

---

## ⚠️ Common Issues & Solutions

| Issue | Solution |
|-------|----------|
| Migration tidak berjalan | Pastikan database sudah connected, cek `.env` DATABASE_URL |
| Add salary return 401 | Pastikan `access_token` valid via header Authorization |
| Status update error | Cek status rules (received/cancelled bukan final state untuk dirubah) |
| Transaction tidak otomatis dibuat | Pastikan `autoCreateTransaction=true` saat create salary |

---

**Next Steps:**
1. ✅ Run migration
2. ✅ Test API di Postman
3. ✅ Build frontend UI
4. ✅ Integrate ke dashboard

---

Last Updated: 2026-04-09
