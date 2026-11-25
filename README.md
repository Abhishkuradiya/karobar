# KarobarV2 - Sales and Customer Accounting

A full-stack bookkeeping application built with **Laravel 12 (API backend)**, **React + Vite (frontend)**, **MySQL (database)**, and the **Sneat Bootstrap 5 admin theme**. The operational workflows cover sales, purchases, customers, suppliers and balanced journal postings.

---

## 📁 Project Structure

```text
KarobarV2/
├── backend/                  # Laravel 12 API backend
│   ├── app/Http/Controllers/Api/AuthController.php   # Register, Login, User, Logout
│   ├── routes/api.php        # API routes: /api/register, /api/login, /api/user, /api/logout
│   ├── config/cors.php       # CORS configuration for React frontend
│   └── .env                  # MySQL DB configuration (database: karobar_v2)
│
├── frontend/                 # React 19 + Vite frontend
│   ├── public/assets/        # Complete Sneat theme assets (CSS, Fonts, Boxicons, Images)
│   ├── src/
│   │   ├── api/axios.js      # Axios instance with auth token interceptors
│   │   ├── context/AuthContext.jsx # Global auth state (user, token, login, register, logout)
│   │   ├── pages/
│   │   │   ├── Login.jsx     # Sneat-themed Login page
│   │   │   ├── Register.jsx  # Sneat-themed Registration page
│   │   │   └── Dashboard.jsx # Authenticated user dashboard & live API test
│   │   └── components/
│   │       ├── SneatLogo.jsx
│   │       └── ProtectedRoute.jsx
│   └── vite.config.js        # Vite config with API proxy
│
└── sneat-1.0.0/              # Original Sneat theme template files
```

---

## 🚀 How to Run the Project

### 1. Database
Make sure MySQL is running in XAMPP.
- **Database Name:** `karobar_v2`
- **Host:** `127.0.0.1:3306`
- **Username:** `root`
- **Password:** ` ` *(empty)*

### 2. Backend (Laravel API)
Open a terminal in the `backend` folder:
```bash
cd backend
php artisan migrate
php artisan serve --host=0.0.0.0 --port=8000
```
Backend runs on: **`http://127.0.0.1:8000`** and is available on your local network.

### 3. Frontend (React Vite)
Open a second terminal in the `frontend` folder:
```bash
cd frontend
npm run dev -- --host 0.0.0.0
```
Frontend runs on: **`http://localhost:5173`**. To open it from another device on the same network, visit **`http://192.168.1.71:5173`** (replace the IP if your computer's address changes).

The frontend sends API requests through Vite's `/api` proxy, so devices use the frontend URL and do not need to connect directly to the backend port. If Windows Firewall prompts you, allow the development server on your private network.

---

## 🔑 Features

- **Sneat Theme Integration:** Exact styling, Boxicons, typography, form controls, and layout from Sneat.
- **User Registration:**
  - Username, Email, Password, Terms agreement checkbox.
  - Password visibility toggle (eye icon).
  - Instant validation & error alerts.
- **User Login:**
  - Login using **either Email or Username** (as specified in Sneat theme).
  - Password visibility toggle.
  - Remember Me option.
  - Bearer token authentication via Laravel Sanctum stored in `localStorage`.
- **Protected Dashboard:**
  - Shows today's sales, purchases and expenses, customer receivables, supplier payables and active customers for the selected business.
- **Business workspaces:**
  - After login, users with no businesses are guided through the business setup form; users with businesses open their selected business dashboard.
  - Create multiple businesses and switch between them from the dashboard header.
  - Business details include name, GST registration type, state, city, business type and language preference.
  - Customers, sales, journal entries and dashboard totals are scoped to the active business.
- **Customers:**
  - Owner-scoped API-backed create, edit, search, list and archive workflows.
  - Shared customer form in the customer page and the new-sale drawer.
  - Credit days, optional credit limit, GSTIN, state, email, mobile and address.
- **Suppliers:**
  - Business-scoped create, edit, search, list and archive workflows.
  - Supplier details include GSTIN, MSME/Udyam registration and credit terms.
  - Outstanding supplier balances update from credit purchases and unpaid expenses linked to that supplier.
- **Sales:**
  - API-backed create, list, search, date filters, edit and void workflows.
  - Multiple line items with quantity, rate, discount, GST percentage and HSN/SAC.
  - Cash, UPI, card, bank and credit payment modes, optional GST-inclusive pricing and LUT export.
  - Sale totals are recalculated and validated by the backend; the browser's totals are only a preview.
  - Each saved sale posts a balanced journal entry. Editing or voiding a sale writes a reversing entry before changing the books.
  - Customer credit limits are checked before a credit sale is committed.
- **Purchases:**
  - Business-scoped create, list, date filter, edit and void workflows.
  - Multiple line items with quantity, rate, discount, GST percentage and HSN/SAC.
  - Cash, UPI, card, bank and supplier-credit payment methods.
  - Supports GST-inclusive pricing, input tax credit and reverse charge.
  - Purchase totals are recalculated by the backend. Editing or voiding a purchase reverses the prior journal before updating the books.
- **Expenses:**
  - Business-scoped create, list, search, date filter, edit and delete workflows.
  - Common expense categories, supplier association, cash/UPI/bank/card payments or unpaid status.
  - GST is calculated by the API; input tax credit is recorded separately when marked eligible.
  - Saved expenses post balanced journal entries. Editing or deleting an expense reverses its previous entry.
- **Scan bills:**
  - Capture an image from a device camera or upload a JPEG, PNG or PDF up to 10 MB.
  - Bill files are stored privately on the application server and can be previewed, attached to an expense, or deleted.
  - Expense details are manually reviewed before posting. Automatic OCR is not configured, and bill files are not sent to an external AI provider.

### Accounting API

These endpoints require a Sanctum bearer token:

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET`, `POST` | `/api/businesses` | List a user's businesses or create and activate one |
| `PATCH` | `/api/businesses/active` | Select one of the authenticated user's businesses |
| `GET`, `POST` | `/api/customers` | List/search or create customers |
| `GET`, `PUT`, `DELETE` | `/api/customers/{id}` | View, update or archive a customer |
| `GET`, `POST` | `/api/suppliers` | List/search or create suppliers |
| `GET`, `PUT`, `DELETE` | `/api/suppliers/{id}` | View, update or soft-delete a supplier; past purchase history is retained |
| `GET`, `POST` | `/api/sales` | List/filter or record sales |
| `GET`, `PUT`, `DELETE` | `/api/sales/{id}` | View, edit or void a sale |
| `GET`, `POST` | `/api/purchases` | List/filter or record purchases |
| `GET`, `PUT`, `DELETE` | `/api/purchases/{id}` | View, edit or void a purchase |
| `GET`, `POST` | `/api/expenses` | List/filter or record expenses |
| `GET`, `PUT`, `DELETE` | `/api/expenses/{id}` | View, edit or delete an expense |
| `GET`, `POST` | `/api/scan-bills` | List privately stored bill files or upload a bill |
| `GET` | `/api/scan-bills/{id}` | View a bill record |
| `GET` | `/api/scan-bills/{id}/file` | Preview a private bill file |
| `DELETE` | `/api/scan-bills/{id}` | Delete an uploaded bill file |
| `GET` | `/api/dashboard/summary` | Dashboard totals |

Customer, supplier, sales, purchase, expense, scanned bill and dashboard endpoints require an active business. Business selection is validated against the authenticated user; records from another business are not returned or editable. Bill-file responses require authentication and business ownership.

`DELETE` on a customer or supplier archives it, preserving historical transaction references. `DELETE` on a sale, purchase or expense reverses its journal entry and preserves the accounting history. Purchase postings handle input GST credit and reverse-charge liability separately.

Expense requests use `category`, `amount`, `gst_rate` (`0`, `5`, `12`, `18` or `28`), `claim_input_tax_credit`, `payment_method` (`cash`, `upi`, `bank`, `card` or `unpaid`), and `expense_date`. Optional fields are `supplier_id`, `scan_bill_id` and `note`. The API returns the calculated `tax_amount`, `total`, `amount_paid`, `balance_due` and current journal entry reference; clients should not use browser-calculated totals as accounting data. Upload a bill as `multipart/form-data` using the `file` field; accepted types are JPEG, PNG and PDF, up to 10 MB.
