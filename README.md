# KarobarV2 - Sales and Customer Accounting

A full-stack bookkeeping application built with **Laravel 12 (API backend)**, **React + Vite (frontend)**, **MySQL (database)**, and the **Sneat Bootstrap 5 admin theme**. The first operational workflows cover sales, customer credit and balanced journal postings.

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
  - Shows today's sales, customer receivables, active customers and recent sales for the selected business.
- **Business workspaces:**
  - After login, users with no businesses are guided through the business setup form; users with businesses open their selected business dashboard.
  - Create multiple businesses and switch between them from the dashboard header.
  - Business details include name, GST registration type, state, city, business type and language preference.
  - Customers, sales, journal entries and dashboard totals are scoped to the active business.
- **Customers:**
  - Owner-scoped API-backed create, edit, search, list and archive workflows.
  - Shared customer form in the customer page and the new-sale drawer.
  - Credit days, optional credit limit, GSTIN, state, email, mobile and address.
- **Sales:**
  - API-backed create, list, search, date filters, edit and void workflows.
  - Multiple line items with quantity, rate, discount, GST percentage and HSN/SAC.
  - Cash, UPI, card, bank and credit payment modes, optional GST-inclusive pricing and LUT export.
  - Sale totals are recalculated and validated by the backend; the browser's totals are only a preview.
  - Each saved sale posts a balanced journal entry. Editing or voiding a sale writes a reversing entry before changing the books.
  - Customer credit limits are checked before a credit sale is committed.

### Sales and customer API

These endpoints require a Sanctum bearer token:

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET`, `POST` | `/api/businesses` | List a user's businesses or create and activate one |
| `PATCH` | `/api/businesses/active` | Select one of the authenticated user's businesses |
| `GET`, `POST` | `/api/customers` | List/search or create customers |
| `GET`, `PUT`, `DELETE` | `/api/customers/{id}` | View, update or archive a customer |
| `GET`, `POST` | `/api/sales` | List/filter or record sales |
| `GET`, `PUT`, `DELETE` | `/api/sales/{id}` | View, edit or void a sale |
| `GET` | `/api/dashboard/summary` | Dashboard totals |

Customer, sales and dashboard endpoints require an active business. Business selection is validated against the authenticated user; records from another business are not returned or editable.

`DELETE` on a customer archives it, preserving historical sale references. `DELETE` on a sale voids it and creates a journal reversal; it does not erase the accounting history. Sale journal entries debit the selected payment/receivable account and credit sales revenue and GST payable.
