# 📊 Diagram Alur Sistem Gajian (Salary)

## 1. Diagram Alur Penambahan Gajian Baru

```
┌─────────────────────────────────────────────────────────────┐
│            USER MEMBUAT GAJIAN BARU                         │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│  POST /api/salaries                                         │
│  {                                                           │
│    amount: 5000000,                                         │
│    salaryDate: "2026-04-01",                                │
│    description: "Gajian Bulanan",                           │
│    source: "PT Maju Jaya"                                   │
│  }                                                           │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│  BACKEND PROSES:                                            │
│  ✓ Validate input                                           │
│  ✓ Hitung nextSalaryDate (+1 bulan jika kosong)             │
│  ✓ Create Salary record (status='pending')                 │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│  RESPONSE 201 Created:                                      │
│  {                                                           │
│    idSalary: 1,                                             │
│    status: "pending",                                       │
│    amount: 5000000,                                         │
│    salaryDate: "2026-04-01",                                │
│    nextSalaryDate: "2026-05-01"                             │
│  }                                                           │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│  FRONTEND:                                                   │
│  ✓ Add ke list gajian                                       │
│  ✓ Tampilkan badge "Pending"                                │
│  ✓ Enable tombol "Terima" & "Batalkan"                      │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. Diagram: Tandai Gajian Diterima + Auto-Create Transaksi

```
┌─────────────────────────────────────────────────────────────┐
│            USER TANDAI GAJIAN DITERIMA                      │
│     (Status: pending → received)                            │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│  POST /api/salaries/{id}/receive                            │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│  BACKEND VALIDASI:                                          │
│  ✓ Cek status = 'pending' ?                                 │
│  ✓ Cek user own this salary?                                │
└─────────────────────────────────────────────────────────────┘
                            ↓
                    ┌─────────────────┐
                    │ Valid? (Y/N)    │
                    └─────────────────┘
                       ↙           ↘
                    NO              YES
                    ↓               ↓
         ┌────────────────────┐  ┌──────────────────────────┐
         │ Return Error 400   │  │ Update Salary:           │
         │ "Sudah diterima"   │  │ status = 'received'      │
         └────────────────────┘  └──────────────────────────┘
                                          ↓
                                ┌──────────────────────────┐
                                │ Cek:                     │
                                │ autoCreateTransaction    │
                                │ == true ?                │
                                └──────────────────────────┘
                                    ↙             ↘
                                  YES             NO
                                    ↓             ↓
                    ┌────────────────────────────┐ │
                    │ CREATE TRANSACTION:        │ │
                    │ - type: 'income'           │ │
                    │ - amount: salary.amount    │ │
                    │ - date: salary.salaryDate  │ │
                    │ - category: 'Gajian'      │ │
                    │ - source: salary.source    │ │
                    └────────────────────────────┘ │
                              ↓                    ↓
                    ┌────────────────────────────┐ ┌──────┐
                    │ Transaction created! ✅    │ │ Skip │
                    └────────────────────────────┘ └──────┘
                              ↓                    ↓
                    └─────────────────┬──────────────┘
                                      ↓
                    ┌────────────────────────────┐
                    │ RESPONSE 200 OK:           │
                    │ {                          │
                    │   message: "success",      │
                    │   data: {                  │
                    │     status: "received",    │
                    │     amount: 5000000        │
                    │   }                        │
                    │ }                          │
                    └────────────────────────────┘
                              ↓
                    ┌────────────────────────────┐
                    │ FRONTEND:                  │
                    │ ✓ Move from pending list   │
                    │ ✓ Update balance (+5M)     │
                    │ ✓ Refresh transactions     │
                    │ ✓ Show toast "Sukses!"     │
                    └────────────────────────────┘
```

---

## 3. Status Transition Diagram

```
                    ┌─────────────┐
                    │   CREATE    │
                    └──────┬──────┘
                           ↓
                  ┌────────────────┐
                  │    PENDING     │  ← Default status
                  │  (siap terima) │
                  └────┬───────┬───┘
                       │       │
            ┌──────────┘       └──────────┐
            │                             │
        RECEIVE                       CANCEL
            │                             │
            ↓                             ↓
     ┌────────────┐              ┌──────────────┐
     │ RECEIVED   │              │ CANCELLED    │
     │ (selesai)  │              │ (dibatalkan) │
     └────────────┘              └──────────────┘
        ✗ FINAL                      ✓ DELETABLE
     (Cannot change)                (CAN DELETE)
```

---

## 4. Database Relationship Diagram

```
┌──────────────┐         1       ∞  ┌──────────────┐
│    USERS     │ ─────────────────→  │  SALARIES    │
│              │                     │              │
│ idUser (PK)  │                     │ idSalary(PK) │
│ name         │                     │ idUser(FK)   │
│ email        │                     │ amount       │
│ ...          │                     │ salaryDate   │
└──────────────┘                     │ status       │
                                     │ ...          │
       ↓                             └──────────────┘
       │                                    ↓
       │                                    │
       │  (When autoCreateTransaction=true) │
       │                                    │
       └────────→ ┌──────────────┐ ←────────┘
                  │ TRANSACTIONS │
                  │              │
                  │ idTransaction│
                  │ idUser (FK)  │
                  │ type: income │
                  │ amount       │
                  │ date         │
                  │ ...          │
                  └──────────────┘
                         │
                         ↓
                  ┌──────────────┐
                  │ CATEGORIES   │
                  │              │
                  │ idCategory   │
                  │ name:"Gajian"│
                  │ type: income │
                  │ ...          │
                  └──────────────┘
```

---

## 5. Dashboard Summary Flow

```
┌───────────────────────────────────┐
│  DASHBOARD SCREEN                 │
│  User requests GET /summary       │
└───────────────────────────────────┘
                ↓
┌───────────────────────────────────────────────┐
│ BACKEND QUERY DATABASE:                       │
│                                               │
│ 1. SELECT SUM(amount) FROM salaries           │
│    WHERE idUser=5 AND                          │
│    YEAR(salaryDate)=2026 AND                   │
│    MONTH(salaryDate)=4                        │
│    → totalThisMonth = 5,000,000               │
│                                               │
│ 2. SELECT COUNT(*) FROM salaries              │
│    WHERE idUser=5 AND                          │
│    status='pending'                            │
│    → pendingCount = 2                         │
│                                               │
│ 3. SELECT * FROM salaries                     │
│    WHERE idUser=5 AND                          │
│    status='pending'                            │
│    ORDER BY salaryDate ASC LIMIT 1             │
│    → nextSalary (next expected)               │
│                                               │
│ 4. SELECT * FROM salaries                     │
│    WHERE idUser=5                              │
│    ORDER BY salaryDate DESC LIMIT 1            │
│    → lastSalary (last received)               │
│                                               │
│ 5. SELECT SUM(amount) FROM salaries           │
│    WHERE idUser=5 AND                          │
│    status='received' AND                       │
│    YEAR(salaryDate)=2026                      │
│    → totalThisYear = 15,000,000               │
└───────────────────────────────────────────────┘
                ↓
┌───────────────────────────────────────────────┐
│ RESPONSE BODY:                                │
│ {                                              │
│   totalThisMonth: 5000000,                    │
│   pendingCount: 2,                            │
│   pendingSalaries: [...],                     │
│   lastSalary: {...},                          │
│   nextSalary: {...},                          │
│   totalThisYear: 15000000                     │
│ }                                              │
└───────────────────────────────────────────────┘
                ↓
┌──────────────────────────────────────┐
│ FRONTEND DISPLAY:                    │
│  ┌────────────────────────────────┐  │
│  │ Gajian Bulan Ini:              │  │
│  │ Rp 5.000.000                   │  │
│  └────────────────────────────────┘  │
│  ┌────────────────────────────────┐  │
│  │ Gajian Pending: 2              │  │
│  │ [Lihat Detail]                 │  │
│  └────────────────────────────────┘  │
│  ┌────────────────────────────────┐  │
│  │ Gajian Terakhir:               │  │
│  │ Rp 5.000.000 (1 Mar 2026)      │  │
│  └────────────────────────────────┘  │
│  ┌────────────────────────────────┐  │
│  │ Total Tahun Ini:               │  │
│  │ Rp 15.000.000                  │  │
│  └────────────────────────────────┘  │
└──────────────────────────────────────┘
```

---

## 6. Complete Feature Interaction Flow

```
                      ┌─────────────┐
                      │ DASHBOARD   │
                      └──────┬──────┘
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
        ↓                    ↓                    ↓
    ┌────────┐        ┌──────────┐        ┌──────────┐
    │ Summary│        │ Add New  │        │   List   │
    │ Card   │        │ Salary   │        │Salaries  │
    └───┬────┘        └────┬─────┘        └────┬─────┘
        │                  │                   │
        │              POST /salaries      GET /salaries
        │                  │                   │
        │                  ↓                   ↓
        │         ┌────────────────┐    ┌───────────┐
        │         │ Create Dialog  │    │ List View │
        │         │ Form Validation│    │ + Filters │
        │         └────────────────┘    └─────┬─────┘
        │                  │                   │
        │                  │             Click Salary
        │                  │                   │
        │                  ↓                   ↓
        │            ┌──────────┐      ┌──────────────┐
        │            │Salary ID │      │ Salary Detail│
        │            │Created   │      │ Dialog/Page  │
        │            └─────┬────┘      └──────┬───────┘
        │                  │                  │
        │      GET /salaries/summary    |  Edit  | Receive | Cancel
        │            /overview          +────┬──────────┘
        │                  │                 │
        │                  ↓                 │
        │         ┌──────────────────┐      │
        │         │SummaryData       │      │
        │         │- total           │      │
        │         │- pending count   │      │
        │         │- next salary     │      │
        │         │- last salary     │      │
        │         └──────────────────┘      │
        │                                    │
        └────────────────────────────────────┤
                                             │
                                             ↓
                    ┌────────────────────────────────────┐
                    │ POST /salaries/{id}/receive        │
                    │ (Auto-create income transaction)   │
                    └───────────┬────────────────────────┘
                                │
                    ┌───────────┴─────────────────┐
                    │                             │
                    ↓                             ↓
            ┌──────────────┐          ┌────────────────────┐
            │Salary.status │          │ Transaction.create │
            │= 'received'  │          │ type='income'      │
            └──────────────┘          │ amount= salary     │
                    │                 │ date=salaryDate    │
                    │                 └────────────────────┘
                    │                             │
                    └──────────────┬──────────────┘
                                   ↓
                        ┌──────────────────┐
                        │ Refresh Frontend:│
                        │ ✓ Remove pending │
                        │ ✓ Update balance │
                        │ ✓ Toast success  │
                        └──────────────────┘
```

---

**Dokumentasi Terkuat:**
✅ Lengkap dengan: Database schema, model relationships, API flow, status transitions, dan user interactions
✅ Visual diagrams untuk mudah dipahami
✅ Cocok untuk presentation & backend development

Last Updated: 2026-04-09
