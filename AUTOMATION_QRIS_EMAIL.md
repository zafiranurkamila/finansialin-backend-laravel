# Automation: QRIS Email Payment Ingestion

Dokumentasi lengkap untuk mengatur automasi pencatatan otomatis transaksi QRIS via email menggunakan n8n.

## 📌 Overview

Feature ini memungkinkan **automatic expense recording** ketika pengguna menerima email notifikasi QRIS payment. n8n akan:

1. Monitor mailbox untuk email QRIS payment
2. Parse informasi pembayaran (amount, merchant, tanggal)
3. Kirim ke endpoint `/api/integrations/qris/email`
4. Backend akan membuat transaction record otomatis

---

## 🚀 Prerequisites

✅ **Backend**: Laravel server running pada `http://localhost:8000`
✅ **n8n**: Instance berjalan (lokal atau hosted)
✅ **Email Access**: Credentials untuk email account yang menerima QRIS notifications

---

## 🔧 Setup Backend

### Step 1: Verifikasi Environment Variable

Pastikan `.env` memiliki webhook secret:

```bash
N8N_QRIS_WEBHOOK_SECRET=finansialin-rahasia-qris-2026
```

Ini adalah secret key yang akan divalidasi oleh backend setiap kali n8n mengirim request.

### Step 2: Run Laravel Server

```bash
php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

Output yang diharapkan:

```
INFO  Server running on [http://127.0.0.1:8000]
```

### Step 3: Test Endpoint (Optional)

Gunakan Postman/cURL untuk test endpoint:

```bash
curl -X POST http://localhost:8000/api/integrations/qris/email \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: finansialin-rahasia-qris-2026" \
  -d '{
    "email": "user@example.com",
    "amount": "50000",
    "description": "Test QRIS",
    "merchant": "Toko ABC",
    "categoryName": "Makanan"
  }'
```

Expected response (201):

```json
{
    "message": "QRIS email transaction ingested",
    "data": {
        "idTransaction": 123,
        "type": "expense",
        "amount": "50000.00"
    }
}
```

---

## 🤖 Setup n8n Workflow

### Option 1: Lokal (Testing)

1. **Install n8n**:

```bash
npm install -g n8n
n8n
```

Akses di `http://localhost:5678`

2. **Create Workflow**:
    - Add node: **Gmail Trigger** (atau Email trigger yang sesuai)
    - Filter: `label:QRIS` atau sender pattern
    - Add node: **HTTP Request**
        - **Method**: POST
        - **URL**: `http://localhost:8000/api/integrations/qris/email`
        - **Headers**:
            ```
            X-Webhook-Secret: finansialin-rahasia-qris-2026
            ```
        - **Body**: (JSON mode)
            ```json
            {
                "email": "{{ $json.from }}",
                "amount": "{{ $json.amount }}",
                "description": "{{ $json.subject }}",
                "merchant": "{{ $json.merchant }}",
                "paidAt": "{{ $json.date }}",
                "categoryName": "Payment"
            }
            ```
    - Add node: **If** (optional, untuk error handling)
    - Activate workflow

### Option 2: Hosted n8n (Production)

1. **Configure Webhook URL**:
    - Backend URL: `http://<your-public-domain>/api/integrations/qris/email`
    - Pastikan backend sudah di-deploy dan accessible dari public internet

2. **Same workflow setup** seperti lokal, tapi dengan external URL

---

## 📨 Email Parsing Strategy

### Email Source: Bank/Payment Provider

Contoh email QRIS dari BRI/QRIS Provider:

```
From: payment-notification@qris.bri.co.id
Subject: QRIS Payment Received - Rp50,000 from Toko ABC

Body:
Anda menerima pembayaran QRIS
Jumlah: Rp50.000
Dari: Toko ABC
Waktu: 2026-04-28 14:30
```

### Parsing Rules di n8n:

```
- email: Extract dari "From" field
- amount: Extract dari Subject/Body, normalisasi format Indonesian (Rp50.000 → 50000)
- merchant: Extract dari Subject/Body ("from ...")
- paidAt: Extract timestamp dari email
- description: Bisa dari Subject atau custom format
```

---

## 🔐 Security Best Practices

✅ **Always use X-Webhook-Secret header** — jangan lupa include di setiap request dari n8n
✅ **Protect SECRET variable di n8n** — gunakan environment variable n8n, jangan hardcode
✅ **Email validation** — endpoint akan validasi email exist di database
✅ **Amount validation** — hanya amount > 0 yang akan diterima
✅ **HTTPS in production** — jika deployed online, gunakan HTTPS bukan HTTP

### Checklist Keamanan:

- [ ] Change `N8N_QRIS_WEBHOOK_SECRET` ke value yang kuat (not guessable)
- [ ] Simpan secret di n8n environment variables, bukan di workflow definition
- [ ] Gunakan HTTPS untuk production
- [ ] Rotate secret secara berkala
- [ ] Monitor logs untuk unauthorized attempts

---

## 📋 Request/Response Format

### Request Body (Required Fields)

| Field          | Type          | Required | Notes                                                          |
| -------------- | ------------- | -------- | -------------------------------------------------------------- |
| `email`        | string        | ✅ Yes   | User email yang sudah terdaftar di Finansialin                 |
| `amount`       | string/number | ✅ Yes   | Support format: `"50000"`, `"50.000"`, `"50,000"`, `"Rp50000"` |
| `paidAt`       | date-string   | ❌ No    | ISO format; default: current time                              |
| `description`  | string        | ❌ No    | Max 500 chars                                                  |
| `merchant`     | string        | ❌ No    | Max 120 chars; merchant name                                   |
| `source`       | string        | ❌ No    | Max 120 chars; default: `"qris-email-automation"`              |
| `categoryName` | string        | ❌ No    | Expense category; auto-resolve jika tidak ditemukan            |

### Response Codes

| Code    | Condition                                       |
| ------- | ----------------------------------------------- |
| **201** | ✅ Transaction created successfully             |
| **401** | ❌ `X-Webhook-Secret` header missing or invalid |
| **404** | ❌ User email not found in database             |
| **422** | ❌ Invalid payload (validation failed)          |
| **500** | ❌ Server error                                 |

### Success Response (201)

```json
{
    "message": "QRIS email transaction ingested",
    "data": {
        "idTransaction": 123,
        "idUser": 1,
        "idCategory": 5,
        "type": "expense",
        "amount": "50000.00",
        "description": "QRIS payment to Toko ABC",
        "date": "2026-04-28T14:30:00Z",
        "source": "qris-email-automation",
        "category": {
            "idCategory": 5,
            "name": "Food & Beverages"
        }
    }
}
```

### Error Response (401)

```json
{
    "message": "Unauthorized webhook request"
}
```

### Error Response (404)

```json
{
    "message": "User not found for provided email"
}
```

### Error Response (422)

```json
{
    "message": "The amount field is required. (and 2 more errors)"
}
```

---

## 🐛 Troubleshooting

### Error: "The service refused the connection"

**Cause**: Backend server not running atau port 8000 sudah digunakan

**Solution**:

```bash
# Check if port 8000 is in use (Windows)
netstat -ano | findstr :8000

# Kill process on port 8000
taskkill /PID <PID> /F

# Restart Laravel server
php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

### Error: "Unauthorized webhook request"

**Cause**: `X-Webhook-Secret` header tidak match atau tidak dikirim

**Solution**:

1. Pastikan header ada di request: `X-Webhook-Secret: finansialin-rahasia-qris-2026`
2. Pastikan secret di header match dengan `.env` variable
3. Check n8n environment variables sudah set dengan benar

### Error: "User not found for provided email"

**Cause**: Email tidak terdaftar di Finansialin

**Solution**:

1. Verify email di header sesuai dengan registered user
2. Pastikan email case-insensitive match (sistem akan lowercase)

### Error: "Invalid amount"

**Cause**: Amount <= 0 atau format tidak bisa di-parse

**Solution**:

1. Gunakan amount > 0
2. System support format Indonesian: `50000`, `50.000`, `50,000`, `Rp50000`

---

## 📊 How It Works: Under the Hood

```
1. n8n receives email notification
2. Parse QRIS payment details
3. POST request to /api/integrations/qris/email
   ├─ Validate X-Webhook-Secret header
   ├─ Find user by email
   ├─ Normalize amount
   ├─ Resolve expense category (atau ambil default)
   ├─ Create Transaction record
   ├─ Update Resource balance (if source specified)
   └─ Send notification ke user
4. Return 201 + transaction data
```

### Backend Processing Chain

```php
WebhookIntegrationsController::ingestQrisEmail()
├─ Validate secret
├─ Validate request body
├─ Find user by email
├─ Normalize amount (normalizeAmount)
├─ Resolve category (resolveExpenseCategory)
├─ Resolve resource/wallet (resolveResourceId)
├─ Create Transaction
├─ Withdraw from resource balance (optional)
├─ Notify user (notifyTransaction)
└─ Check budget warning (checkBudgetWarning)
```

---

## 🚀 Next Steps

- [ ] Deploy backend ke server (tidak hanya localhost)
- [ ] Setup n8n workflow dengan email trigger
- [ ] Test dengan sample QRIS email
- [ ] Monitor transaction logs
- [ ] Setup budget alerts notification

---

## 📚 Related Files

- Backend Controller: [WebhookIntegrationsController.php](app/Http/Controllers/WebhookIntegrationsController.php)
- Routes: [routes/api.php](routes/api.php#L49)
- API Docs: [API_DOCUMENTATION.md](API_DOCUMENTATION.md#L505)

---

**Last Updated**: 28 Apr 2026  
**Status**: ✅ Ready for Implementation
