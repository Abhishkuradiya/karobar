# Karobar API Reference

Version: 2026-11-26

## API conventions

Base URL: `/api`

All endpoints in this document require a Sanctum bearer token unless marked public:

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

Every business endpoint uses the authenticated user and their active business. Clients do not send `user_id` or `business_id`; the server derives both from the authenticated session and rejects records outside the active business.

Successful writes return the created or updated resource. Deletes return `204 No Content`. Validation failures return `422` with an `errors` object. Missing or foreign records return `404`.

## Customers

### Fetch all customers

`GET /api/customers?search=raj&per_page=15`

Response `200`:

```json
{
  "data": [{
    "id": 12, "name": "Raj Traders", "mobile": "9876543210",
    "email": "raj@example.com", "credit_days": 30, "credit_limit": "50000.00",
    "outstanding_balance": "12500.00", "sales_count": 4
  }],
  "meta": {"current_page": 1, "last_page": 1, "per_page": 15, "total": 1},
  "summary": {"count": 1, "outstanding": "12500.00"}
}
```

### Add customer

`POST /api/customers`

```json
{"name":"Raj Traders","mobile":"9876543210","credit_days":30,"credit_limit":50000,"gstin":null,"state":"Delhi","email":"raj@example.com","address":"Main market"}
```

Response `201`: the customer resource with `outstanding_balance`.

### View, edit/update, and delete customer

`GET /api/customers/{customer}` returns the customer with outstanding balance and sales count.

`PATCH /api/customers/{customer}` accepts the same body as create and returns `200` with the updated customer.

`DELETE /api/customers/{customer}` returns `204`. The customer is soft-deleted; linked sales remain part of the business history.

## Expenses

### Fetch expenses

`GET /api/expenses?from=2026-11-01&to=2026-11-30&search=rent&per_page=15`

Response `200` includes `data`, pagination `meta`, and `summary` with `count`, `total`, and `outstanding`.

### Create expense

`POST /api/expenses`

```json
{
  "expense_date":"2026-11-26", "category":"Rent", "payment_method":"bank",
  "subtotal":10000, "tax_rate":18, "supplier_id":null, "bank_account_id":3,
  "claim_input_tax_credit":true, "amount_paid":11800, "note":"November rent"
}
```

The response `201` contains the calculated tax, total, balance due, expense number, supplier, and scan-bill relationships. The API posts the balanced accounting entry.

### View, edit/update, and delete expense

`GET /api/expenses/{expense}` returns the complete expense.

`PATCH /api/expenses/{expense}` accepts the create body and returns `200`. The old journal entry is reversed and a new balanced entry is posted.

`DELETE /api/expenses/{expense}` returns `204`. The expense is reversed in the journal before deletion.

## Products and stock

### Fetch all products with stock

`GET /api/inventory/products?search=oil&category_id=2&per_page=100`

Response `200`:

```json
{
  "data": [{
    "id": 7, "name": "Mustard Oil", "sku": "OIL-1L", "unit_code":"pcs",
    "gst_rate":"5.00", "purchase_price":"120.00", "selling_price":"145.00",
    "track_stock":true, "current_stock":"42.000", "low_stock_threshold":"10.000",
    "stock_value":"5040.00", "category":{"id":2,"name":"Grocery"}
  }],
  "categories": [],
  "summary": {"products": 1, "low_stock": 0, "stock_value": "5040.00"}
}
```

### Create product/item

`POST /api/inventory/products`

```json
{
  "name":"Mustard Oil", "sku":"OIL-1L", "hsn_sac":"1514", "unit_code":"pcs",
  "gst_rate":5, "purchase_price":120, "selling_price":145, "is_service":false,
  "track_stock":true, "batch_tracking":true, "low_stock_threshold":10,
  "opening_quantity":42, "opening_unit_cost":120, "opening_batch_number":"B-001",
  "opening_expiry_date":"2027-11-26"
}
```

Response `201`: created product, category, current stock, and stock configuration.

### Update and delete product

`PATCH /api/inventory/products/{product}` accepts product fields and returns `200`. Stock quantity is changed separately through `PATCH /api/inventory/products/{product}/stock` with `{ "counted_quantity": 40, "notes": "Physical count" }`.

`DELETE /api/inventory/products/{product}` returns `204`. Products can be archived only after stock reaches zero.

## Bank accounts

### Fetch bank accounts

`GET /api/banking/accounts`

Response `200`: `{ "data": [{"id":3,"name":"Current Account","bank_name":"HDFC","account_last4":"1234","ifsc":"HDFC0001234","upi_id":"store@hdfc","unreconciled_count":2}] }`.

### Create, update, and delete bank account

`POST /api/banking/accounts` and `PATCH /api/banking/accounts/{account}` accept:

```json
{"name":"Current Account","bank_name":"HDFC","account_number":"00001234","ifsc":"HDFC0001234","upi_id":"store@hdfc"}
```

The server stores only the last four account digits. Both methods return the account resource (`201` for create, `200` for update).

`DELETE /api/banking/accounts/{account}` returns `204` and archives the account. Accounts with imported statement history cannot be archived.

## Sales

### Fetch sales

`GET /api/sales?from=2026-11-01&to=2026-11-30&search=SAL&per_page=15`

Response `200` includes paginated `data`, `meta`, and `summary` (`count`, `total`, `outstanding`).

### Create sale

`POST /api/sales`

```json
{
  "sale_date":"2026-11-26", "customer_id":12, "payment_method":"credit",
  "prices_include_gst":false, "export_under_lut":false, "amount_paid":0,
  "notes":"Regular order",
  "items":[{"product_id":7,"description":"Mustard Oil","quantity":2,"unit_rate":145,"discount":0,"gst_rate":5,"hsn_sac":"1514"}]
}
```

Response `201`: sale/invoice number, calculated totals, customer, items, balance due, and journal-linked data.

### View, edit/update, and delete sale

`GET /api/sales/{sale}` returns the sale with customer and items.

`PATCH /api/sales/{sale}` accepts the create body and returns `200`. Existing accounting and stock movements are reversed and reposted safely.

`DELETE /api/sales/{sale}` returns `204` after reversing its accounting and inventory effects. Sales with return notes cannot be deleted.

## Today net sales comparison

`GET /api/dashboard/today-net-sales`

Response `200`:

```json
{
  "date":"2026-11-26", "today_sales":5310.00, "yesterday_sales":4200.00,
  "difference":1110.00, "difference_percent":26.43, "comparison":"increase"
}
```

`difference_percent` is `(today_sales - yesterday_sales) / yesterday_sales * 100`. If yesterday is zero, it is `null` and `comparison` is `new_sales` or `no_change`.

## Recent transactions

`GET /api/dashboard/recent-transactions?limit=10`

Always combines the latest sales, purchases, and expenses for the active business, sorts them by creation time, and returns the newest records:

```json
{
  "data":[
    {"id":18,"type":"sale","title":"Sale","reference":"SAL-20261126-000018","party":"Raj Traders","date":"2026-11-26","amount":1522.50,"created_at":"2026-11-26T10:20:00.000000Z"},
    {"id":9,"type":"purchase","title":"Purchase","reference":"PUR-20261126-000009","party":"ABC Distributors","date":"2026-11-26","amount":900.00,"created_at":"2026-11-26T10:15:00.000000Z"},
    {"id":4,"type":"expense","title":"Expense","reference":"EXP-20261126-000004","party":"Rent","date":"2026-11-26","amount":11800.00,"created_at":"2026-11-26T09:55:00.000000Z"}
  ],
  "meta":{"limit":10,"total":3}
}
```

## Existing related endpoints

`GET /api/dashboard/summary` supplies the dashboard KPI cards. `GET /api/activity` supplies the full accounting audit trail. `GET /api/banking/accounts/{account}/reconciliation` supplies bank matching data. `POST /api/banking/accounts/{account}/statements` imports CSV statements.

## Security and data isolation

All listed endpoints are inside `auth:sanctum` and `EnsureActiveBusiness` middleware. Controllers scope records by both authenticated user and `active_business_id`. Never trust a business ID supplied by a client. Store bearer tokens securely and use HTTPS outside local development.
