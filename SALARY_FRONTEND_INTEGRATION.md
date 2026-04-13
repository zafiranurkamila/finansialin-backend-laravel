# 🎨 Frontend Integration Guide - Salary Feature

> Guide lengkap untuk implementasi Salary UI di frontend Next.js/React

---

## 📱 Components Needed

### 1. **SalarySummaryCard** - Dashboard
```jsx
// Lokasi: app/dashboard/components/SalarySummaryCard.jsx
// Purpose: Tampilkan summary di dashboard

function SalarySummaryCard() {
  const [summary, setSummary] = useState(null);
  
  useEffect(() => {
    fetch('/api/salaries/summary/overview', {
      headers: { Authorization: `Bearer ${token}` }
    })
    .then(r => r.json())
    .then(d => setSummary(d.data));
  }, []);

  return (
    <Card className="bg-gradient-green">
      <div className="flex justify-between">
        <div>
          <p className="text-sm text-gray-600">Gajian Bulan Ini</p>
          <h3 className="text-2xl font-bold">
            Rp {summary?.totalThisMonth?.toLocaleString('id-ID')}
          </h3>
        </div>
        <Link href="/salary">
          <Button>Lihat Detail →</Button>
        </Link>
      </div>
      
      <div className="grid grid-cols-2 gap-4 mt-4">
        <div className="bg-white/20 p-3 rounded">
          <p className="text-xs opacity-80">Pending</p>
          <p className="text-xl font-semibold">{summary?.pendingCount}</p>
        </div>
        <div className="bg-white/20 p-3 rounded">
          <p className="text-xs opacity-80">Terakhir Diterima</p>
          <p className="text-sm">{summary?.lastSalary?.salaryDate}</p>
        </div>
      </div>
    </Card>
  );
}

export default SalarySummaryCard;
```

### 2. **SalaryPage** - Main Page
```jsx
// Lokasi: app/salary/page.jsx
// Purpose: Main salary management page

function SalaryPage() {
  const [salaries, setSalaries] = useState([]);
  const [filters, setFilters] = useState({
    status: 'pending',
    month: new Date().getMonth() + 1,
    year: new Date().getFullYear()
  });

  const fetchSalaries = async () => {
    const params = new URLSearchParams(filters);
    const res = await fetch(`/api/salaries?${params}`, {
      headers: { Authorization: `Bearer ${token}` }
    });
    const data = await res.json();
    setSalaries(data.data.data);
  };

  useEffect(() => {
    fetchSalaries();
  }, [filters]);

  return (
    <div className="container">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-3xl font-bold">💰 Gajian</h1>
        <AddSalaryButton onSuccess={fetchSalaries} />
      </div>

      <Filters filters={filters} setFilters={setFilters} />

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {salaries.map(salary => (
          <SalaryCard 
            key={salary.idSalary}
            salary={salary}
            onUpdate={fetchSalaries}
          />
        ))}
      </div>
    </div>
  );
}

export default SalaryPage;
```

### 3. **SalaryCard** - List Item
```jsx
// Lokasi: app/salary/components/SalaryCard.jsx

function SalaryCard({ salary, onUpdate }) {
  const [loading, setLoading] = useState(false);

  const handleReceive = async () => {
    setLoading(true);
    try {
      const res = await fetch(`/api/salaries/${salary.idSalary}/receive`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` }
      });
      if (res.ok) {
        toast.success('Gajian berhasil ditandai diterima!');
        onUpdate();
      } else {
        toast.error('Gagal menandai gajian');
      }
    } finally {
      setLoading(false);
    }
  };

  const handleCancel = async () => {
    if (!confirm('Batalkan gajian ini?')) return;
    
    try {
      const res = await fetch(`/api/salaries/${salary.idSalary}/cancel`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` }
      });
      if (res.ok) {
        toast.success('Gajian dibatalkan');
        onUpdate();
      }
    } catch (err) {
      toast.error('Gagal batalkan gajian');
    }
  };

  return (
    <Card className={`border-l-4 border-${salary.status}-color`}>
      <div className="flex justify-between items-start">
        <div>
          <p className="text-sm text-gray-600">{salary.source || 'Tidak ada sumber'}</p>
          <h3 className="text-lg font-semibold">{salary.description}</h3>
          <p className="text-2xl font-bold mt-2">
            Rp {salary.amount.toLocaleString('id-ID')}
          </p>
          <p className="text-xs text-gray-500 mt-2">
            {new Date(salary.salaryDate).toLocaleDateString('id-ID')}
          </p>
        </div>
        
        <StatusBadge status={salary.status} />
      </div>

      <div className="flex gap-2 mt-4">
        {salary.status === 'pending' && (
          <>
            <Button 
              variant="primary" 
              onClick={handleReceive}
              disabled={loading}
            >
              ✓ Terima
            </Button>
            <Button 
              variant="outline"
              onClick={handleCancel}
              disabled={loading}
            >
              ✗ Batalkan
            </Button>
          </>
        )}
        
        {salary.status === 'received' && (
          <Badge className="bg-green-100 text-green-800">
            ✓ Sudah Diterima
          </Badge>
        )}
      </div>
    </Card>
  );
}
```

### 4. **AddSalaryButton** - Create/Modal
```jsx
// Lokasi: app/salary/components/AddSalaryButton.jsx

function AddSalaryButton({ onSuccess }) {
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [formData, setFormData] = useState({
    amount: '',
    salaryDate: new Date().toISOString().split('T')[0],
    description: 'Gajian Bulanan',
    source: '',
    autoCreateTransaction: true
  });

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);

    try {
      const res = await fetch('/api/salaries', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          amount: parseFloat(formData.amount),
          salaryDate: formData.salaryDate,
          description: formData.description,
          source: formData.source,
          autoCreateTransaction: formData.autoCreateTransaction
        })
      });

      if (res.ok) {
        toast.success('Gajian berhasil ditambahkan!');
        setOpen(false);
        onSuccess();
      } else {
        toast.error('Gagal menambah gajian');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <Button onClick={() => setOpen(true)} className="bg-blue-500">
        + Tambah Gajian
      </Button>

      {open && (
        <Dialog onClose={() => setOpen(false)}>
          <div className="p-6">
            <h2 className="text-2xl font-bold mb-4">Tambah Gajian Baru</h2>
            
            <form onSubmit={handleSubmit} className="space-y-4">
              <InputField
                label="Jumlah Gajian"
                type="number"
                value={formData.amount}
                onChange={(e) => setFormData({...formData, amount: e.target.value})}
                placeholder="5000000"
                required
              />

              <InputField
                label="Tanggal Gajian"
                type="date"
                value={formData.salaryDate}
                onChange={(e) => setFormData({...formData, salaryDate: e.target.value})}
                required
              />

              <InputField
                label="Keterangan"
                value={formData.description}
                onChange={(e) => setFormData({...formData, description: e.target.value})}
                placeholder="Gajian Bulanan"
              />

              <InputField
                label="Sumber Gajian"
                value={formData.source}
                onChange={(e) => setFormData({...formData, source: e.target.value})}
                placeholder="PT Maju Jaya Indonesia"
              />

              <CheckboxField
                label="Auto-buat transaksi income"
                checked={formData.autoCreateTransaction}
                onChange={(e) => setFormData({...formData, autoCreateTransaction: e.target.checked})}
              />

              <div className="flex gap-3 justify-end mt-6">
                <Button variant="outline" onClick={() => setOpen(false)}>
                  Batal
                </Button>
                <Button type="submit" disabled={loading}>
                  {loading ? 'Loading...' : 'Tambah'}
                </Button>
              </div>
            </form>
          </div>
        </Dialog>
      )}
    </>
  );
}
```

### 5. **StatusBadge** - Helper Component
```jsx
// Lokasi: app/salary/components/StatusBadge.jsx

function StatusBadge({ status }) {
  const config = {
    pending: { color: 'yellow', label: '⏳ Menunggu', icon: '⏳' },
    received: { color: 'green', label: '✓ Diterima', icon: '✓' },
    cancelled: { color: 'red', label: '✗ Dibatalkan', icon: '✗' }
  };

  const { color, label } = config[status] || config.pending;

  return (
    <span className={`px-3 py-1 rounded-full text-sm font-semibold bg-${color}-100 text-${color}-800`}>
      {label}
    </span>
  );
}
```

---

## 🔧 API Service

```javascript
// Lokasi: app/services/salaryService.js

class SalaryService {
  async getSalaries(filters = {}) {
    const params = new URLSearchParams(filters);
    const res = await fetch(`/api/salaries?${params}`, {
      headers: { Authorization: `Bearer ${this.token}` }
    });
    return res.json();
  }

  async getSalaryDetail(id) {
    const res = await fetch(`/api/salaries/${id}`, {
      headers: { Authorization: `Bearer ${this.token}` }
    });
    return res.json();
  }

  async getSummary() {
    const res = await fetch('/api/salaries/summary/overview', {
      headers: { Authorization: `Bearer ${this.token}` }
    });
    return res.json();
  }

  async createSalary(data) {
    const res = await fetch('/api/salaries', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${this.token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(data)
    });
    return res.json();
  }

  async receiveSalary(id) {
    const res = await fetch(`/api/salaries/${id}/receive`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${this.token}` }
    });
    return res.json();
  }

  async updateSalary(id, data) {
    const res = await fetch(`/api/salaries/${id}`, {
      method: 'PUT',
      headers: {
        'Authorization': `Bearer ${this.token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(data)
    });
    return res.json();
  }

  async cancelSalary(id) {
    const res = await fetch(`/api/salaries/${id}/cancel`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${this.token}` }
    });
    return res.json();
  }

  async deleteSalary(id) {
    const res = await fetch(`/api/salaries/${id}`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${this.token}` }
    });
    return res.json();
  }
}

export default new SalaryService();
```

---

## 📍 Routes Structure

```
app/
├── dashboard/
│   ├── page.jsx (add SalarySummaryCard here)
│   └── components/
│       └── SalarySummaryCard.jsx (✨ NEW)
│
├── salary/
│   ├── page.jsx (✨ NEW - main salary page)
│   ├── [id]/
│   │   └── page.jsx (✨ NEW - detail/edit page)
│   └── components/
│       ├── SalaryCard.jsx (✨ NEW)
│       ├── SalaryForm.jsx (✨ NEW)
│       ├── AddSalaryButton.jsx (✨ NEW)
│       ├── StatusBadge.jsx (✨ NEW)
│       └── SalaryFilters.jsx (✨ NEW)
│
├── services/
│   ├── salaryService.js (✨ NEW)
│   └── ... (existing services)
│
└── ... (existing pages)
```

---

## 🧠 State Management (Optional - Using Context)

```javascript
// Lokasi: app/context/SalaryContext.jsx

import { createContext, useContext, useState } from 'react';
import salaryService from '../services/salaryService';

const SalaryContext = createContext();

export function SalaryProvider({ children }) {
  const [salaries, setSalaries] = useState([]);
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(false);

  const fetchSalaries = async (filters = {}) => {
    setLoading(true);
    try {
      const data = await salaryService.getSalaries(filters);
      setSalaries(data.data.data);
    } finally {
      setLoading(false);
    }
  };

  const fetchSummary = async () => {
    const data = await salaryService.getSummary();
    setSummary(data.data);
  };

  return (
    <SalaryContext.Provider value={{
      salaries,
      summary,
      loading,
      fetchSalaries,
      fetchSummary
    }}>
      {children}
    </SalaryContext.Provider>
  );
}

export function useSalary() {
  return useContext(SalaryContext);
}
```

---

## 🎨 Styling Guide

### Color Scheme
```css
/* Status colors */
--salary-pending: #eab308 (yellow)
--salary-received: #22c55e (green)
--salary-cancelled: #ef4444 (red)

/* Income colors */
--income-primary: #10b981 (emerald)
--income-light: #d1fae5
--income-dark: #065f46
```

### Component Sizing
```
Card: Full width, max-width on desktop
Badge: px-3 py-1, rounded-full
Buttons: Standard button sizes, min-width 100px
Form fields: Full width
```

---

## ✅ Integration Checklist

### Setup
- [ ] Create `/app/salary` directory structure
- [ ] Create service file `salaryService.js`
- [ ] Setup API helper/interceptor for token

### Components
- [ ] Create `SalarySummaryCard.jsx`
- [ ] Create `SalaryPage` (main page)
- [ ] Create `SalaryCard.jsx`
- [ ] Create `AddSalaryButton.jsx`
- [ ] Create `StatusBadge.jsx`
- [ ] Create `SalaryFilters.jsx`

### Pages
- [ ] Add `/salary` page route
- [ ] Add `/salary/[id]` detail page
- [ ] Update dashboard to show summary card

### Features
- [ ] GET /api/salaries list view
- [ ] POST /api/salaries create
- [ ] POST /api/salaries/{id}/receive with auto-refresh
- [ ] GET /api/salaries/summary/overview dashboard
- [ ] Status badge styling
- [ ] Toast notifications

### Testing
- [ ] Test create salary
- [ ] Test mark as received (verify transaction auto-created)
- [ ] Test list with filters
- [ ] Test cancel salary
- [ ] Test dashboard summary
- [ ] Test responsive design

---

## 🎯 User Flow

```
1. User lihat dashboard
   ↓
2. SalarySummaryCard tampil dengan summary data
   ↓
3. Click "Lihat Detail" → ke /salary page
   ↓
4. Di /salary page:
   - List pending & received salaries
   - Click "Terima" → POST receive endpoint
   - Auto-thread transaction created
   - Toast success
   - List auto-refresh
   ↓
5. Continue di dashboard
   - Summary updated
   - Balance increased
   - Transaction visible di transactions list
```

---

## 🐛 Debugging Tips

### Check Token
```javascript
console.log('Token:', localStorage.getItem('accessToken'));
```

### Check API Response
```javascript
const res = await fetch('/api/salaries/summary/overview', {
  headers: { Authorization: `Bearer ${token}` }
});
console.log(await res.json());
```

### Check Database
```bash
# Di backend terminal
php artisan tinker
>>> Salary::all();
```

---

## 📚 References

- **Backend Docs:** `SALARY_FEATURE.md`
- **API Flows:** `SALARY_FLOW_DIAGRAMS.md`
- **Database:** `SALARY_FEATURE.md` (Database Schema section)
- **Postman:** `Salary_API_Collection.postman_collection.json`

---

**Happy Coding! 🚀**

Last Updated: 2026-04-09
Version: 1.0.0 Frontend Spec
