# Finansialin Laravel API Documentation (Draft)

This document is generated from:
- `routes/api.php`
- Controller methods in `app/Http/Controllers/*`

Status: draft (implementation-first), so payload/response fields follow current code behavior.

## Base URL

- Local default: `http://127.0.0.1:3000`
- API prefix: `/api`

Example full endpoint:
- `POST http://127.0.0.1:3000/api/auth/login`

## Authentication

Protected routes use middleware `token.auth` and expect:

```http
Authorization: Bearer <accessToken>
```

Token format is custom (`auth_tokens` table), not Sanctum/JWT package.

## Common Response Patterns

- Validation error: `422` with body:

```json
{ "message": "<first validation error>" }
```

- Permission error usually `403`.
- Not found usually `404`.

## Auth Endpoints

### POST `/api/auth/register`
Create new user and issue tokens.

Body:
- `email` (required, email, unique)
- `password` (required, min 6)
- `name` (optional)
- `phone` (optional, unique, normalized to `+62...`)

Success `201`:
- `accessToken`, `refreshToken`
- `access_token`, `refresh_token` (compat alias)
- `expiresIn`
- `message`
- `user`

### POST `/api/auth/login`
Login with email/password.

Body:
- `email` (required)
- `password` (required)

Success `200`:
- same token fields as register
- `user`

Error:
- `401` invalid credentials

### POST `/api/auth/refresh`
Refresh access token pair.

Body:
- `refresh_token` or `refreshToken` (required)

Success `200`:
- new token pair

Error:
- `401` invalid/expired refresh token

### POST `/api/auth/forgot-password`
Request password reset token and send reset email.

Body:
- `email` (required)

Success `200`:
- always returns generic success message
- in local/testing/debug mode may include `reset.token` and `reset.email`
- may include `mailWarning` if token created but mail fails

### POST `/api/auth/reset-password`
Reset password using broker token.

Body:
- `email` (required)
- `token` (required)
- `password` (required, min 6)

Success `200`:

```json
{ "message": "Password reset successful", "success": true }
```

Error:
- `400` invalid/expired token

### GET `/api/auth/profile` (protected)
Get authenticated user profile.

Success `200` includes:
- `id`, `idUser`, `email`, `phone`, `name`, `createdAt`
- `emailVerifiedAt`, `isEmailVerified`
- `phoneVerifiedAt`, `isPhoneVerified`
- nested `user`

### POST `/api/auth/logout` (protected)
Revoke current bearer token.

Success `200`:

```json
{ "message": "Logout successful" }
```

## User Endpoints (Protected)

### PATCH `/api/users/name`
Body:
- `name` (required)

### PUT `/api/users/profile`
Body:
- `name` (optional)
- `email` (optional, must be unique across other users)

### PATCH `/api/users/password`
Body:
- `oldPassword` (required)
- `newPassword` (required, min 6, must differ from old)

Success:
- message or updated user payload depending on endpoint

## Category Endpoints (Protected)

### GET `/api/categories`
List default + user categories.

### GET `/api/categories/suggest`
Suggest category based on text and history.

Query:
- `type` (`income|expense`, default `expense`)
- `description` (optional)
- `source` (optional)

Response fields:
- `suggested` (`idCategory`, `name`, `type`) or `null`
- `confidence` (0..1)
- `reason`

### POST `/api/categories`
Body:
- `name` (required)
- `type` (optional, default `expense`)

Error:
- `409` if duplicate category (name+type scope)

### GET `/api/categories/{id}`
Get category by id if accessible.

### PUT `/api/categories/{id}`
Update custom category.

Body:
- `name` (required)
- `type` (optional)

Error:
- `403` for default category or not owner

### DELETE `/api/categories/{id}`
Delete custom category.

Error:
- `403` for default category or not owner

## Funding Source Endpoints (Protected)

### GET `/api/funding-sources`
List funding sources with computed `availableBalance`.

### POST `/api/funding-sources`
Body:
- `name` (required)
- `initialBalance` (optional, default 0)

### PUT `/api/funding-sources/{id}`
Body:
- `name` (optional)
- `initialBalance` (optional)

### DELETE `/api/funding-sources/{id}`
Delete source if not referenced by any transaction.

Error:
- `409` if source already used in transactions

## Transaction Endpoints (Protected)

### GET `/api/transactions`
List all user transactions (desc by date).

### GET `/api/transactions/month/{year}/{month}`
List user transactions in month window.

Error:
- `400` invalid year/month

### POST `/api/transactions`
Create transaction.

Body:
- `idCategory` (optional)
- `type` (required: `income|expense`)
- `amount` (required, numeric)
- `description` (optional)
- `date` (optional, date/datetime; default now)
- `source` (optional funding source name)
- `receiptImage` (optional file: jpg/jpeg/png/webp max 4MB)

Business validations:
- expense cannot exceed global current balance
- if `source` is set, must exist and expense cannot exceed source balance

### GET `/api/transactions/{id}`
Get transaction detail.

### PUT `/api/transactions/{id}`
Update transaction.

Body (all optional):
- `idCategory`, `type`, `amount`, `description`, `date`, `source`
- `receiptImage` (file)
- `removeReceiptImage` (boolean)

### DELETE `/api/transactions/{id}`
Delete transaction (also removes receipt file if present).

## Budget Endpoints (Protected)

### GET `/api/budgets`
List user budgets.

### POST `/api/budgets`
Create budget.

Body:
- `idCategory` (optional)
- `period` (optional: `day|daily|week|weekly|monthly|year|yearly`, normalized)
- `periodStart` (required)
- `periodEnd` (required, `>= periodStart`)
- `amount` (required)

### GET `/api/budgets/{id}`
Get budget detail.

### PUT `/api/budgets/{id}`
Update budget fields.

### DELETE `/api/budgets/{id}`
Delete budget.

### GET `/api/budgets/{id}/usage`
Usage summary for a budget.

Response:
- `used`
- `total`
- `percent`

### GET `/api/budgets/filter`
Filter budgets by period/date/category.

Query:
- `period` (default `monthly`)
- `date` (optional)
- `idCategory` (optional)

### GET `/api/budgets/goals`
Budget vs spending analysis by category.

Query:
- `period` (default `monthly`)
- `type` (`expense|income`, default `expense`)
- `date` (optional)
- `idCategory` (optional)

Response includes:
- `period` (`start`, `end`, `period`)
- `totals` (`totalBudget`, `totalSpent`, `remaining`, `percent`)
- `data[]` per category (`budgetAmount`, `spent`, `percent`, `overBudget`, `remaining`)

### GET `/api/budgets/predictive`
30/60/90-day trend projection and category warnings.

Response includes:
- `summary`
- `trends[]`
- `categoryWarnings[]`

## Notification Endpoints (Protected)

### GET `/api/notifications`
List all notifications.

### GET `/api/notifications/unread`
List unread notifications.

### GET `/api/notifications/unread/count`
Unread count.

Response:

```json
{ "count": 3 }
```

### PATCH `/api/notifications/{id}/read`
Mark one notification as read.

### PATCH `/api/notifications/read-all`
Mark all unread notifications as read.

## Insights Endpoints (Protected)

### GET `/api/insights/assistant`
Generate finance summary + assistant text.

Query:
- `prompt` (default `summary`)
- `message` (optional; if filled, system uses `free_text` mode)

Supported prompt modes from controller:
- `summary`
- `saving_tips`
- `what_to_cut`
- `budget_alerts`
- `free_text` (auto when `message` is provided)

Response includes:
- `summary`
- `assistantReply`
- `quickPrompts[]`

### POST `/api/insights/receipt-ocr`
Extract receipt data using OCR pipeline.

Body (multipart/form-data):
- `receiptImage` (required file: jpg/jpeg/png/webp, max 6MB)

Response includes:
- parsed fields (`merchant`, `date`, `total`, `items[]`)
- `meta` with confidence and OCR diagnostics
- `suggested` transaction draft (`type`, `description`, optional `idCategory`)

Possible error:
- `503` when OCR engine unavailable

## Subscription Endpoint (Protected)

### GET `/api/subscriptions/dashboard`
Detect recurring monthly-like expenses.

Query:
- `lookbackDays` (optional, min 30, max 365, default 120)

Response:
- `summary` (`subscriptionCount`, `estimatedMonthlyTotal`, `dueSoonCount`)
- `items[]` (`label`, `amount`, `avgIntervalDays`, `nextDueAt`, etc.)

## Webhook Endpoint

### POST `/api/integrations/qris/email`
Ingest QRIS payment email payload as expense transaction.

Headers:
- `X-Webhook-Secret: <secret>` (must match `N8N_QRIS_WEBHOOK_SECRET`)

Body:
- `email` (required)
- `amount` (required; supports Indonesian number format normalization)
- `paidAt` (optional date)
- `description` (optional)
- `source` (optional)
- `categoryName` (optional)
- `merchant` (optional)

Success `201`:
- message + created transaction in `data`

Errors:
- `401` unauthorized secret
- `404` user by email not found
- `422` invalid payload/amount

## Notes for Frontend Integration

- Token response intentionally includes camelCase and snake_case keys.
- User identifier in many payloads is `idUser`.
- Some error keys are `message`, but a few older paths may still return `error`.
- Date values are handled in UTC by controller logic.

## Suggested Next Improvements

- Add OpenAPI spec (`openapi.yaml`) and auto-generate this document.
- Add per-endpoint request/response examples from feature tests.
- Add explicit error code matrix for each endpoint.
