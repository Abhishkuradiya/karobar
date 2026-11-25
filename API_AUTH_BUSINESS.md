# Registration, Login, and Add Business API

## Base URL

```text
http://192.168.1.71:8000/api
```

Use this address from a device connected to the same network as the computer
running the backend. If the computer's IP address changes, replace
`192.168.1.71` with its current LAN IP.

All requests use JSON. Set these headers:

```http
Accept: application/json
Content-Type: application/json
```

Registration and login are public endpoints. Adding a business requires a
Bearer token returned by registration or login.

## 1. Register

**Method:** `POST`  
**URL:** `http://192.168.1.71:8000/api/register`

### Request body

```json
{
  "username": "sam",
  "name": "Sam",
  "email": "sam@example.com",
  "password": "secret123"
}
```

| Field | Required | Rules |
|---|---|---|
| `username` | Yes | String, 3–50 characters, unique |
| `name` | No | String, up to 100 characters; defaults to `username` |
| `email` | Yes | Valid email, up to 255 characters, unique |
| `password` | Yes | String, at least 6 characters |
| `terms` | No | Boolean |

### Success response — `201 Created`

```json
{
  "status": "success",
  "message": "User registered successfully!",
  "user": {
    "id": 1,
    "name": "Sam",
    "username": "sam",
    "email": "sam@example.com",
    "email_verified_at": null,
    "active_business_id": null,
    "created_at": "2026-11-25T09:00:00.000000Z",
    "updated_at": "2026-11-25T09:00:00.000000Z"
  },
  "token": "<issued-token>",
  "token_type": "Bearer"
}
```

Save the returned `token` and send it as a Bearer token when calling the
Add Business endpoint.

## 2. Login

**Method:** `POST`  
**URL:** `http://192.168.1.71:8000/api/login`

### Request body

```json
{
  "login": "sam@example.com",
  "password": "secret123"
}
```

`login` accepts either the account's email address or username.

### Success response — `200 OK`

```json
{
  "status": "success",
  "message": "Login successful!",
  "user": {
    "id": 1,
    "name": "Sam",
    "username": "sam",
    "email": "sam@example.com",
    "email_verified_at": null,
    "active_business_id": null,
    "created_at": "2026-11-25T09:00:00.000000Z",
    "updated_at": "2026-11-25T09:00:00.000000Z"
  },
  "token": "<issued-token>",
  "token_type": "Bearer"
}
```

### Invalid credentials — `401 Unauthorized`

```json
{
  "status": "error",
  "message": "Invalid email/username or password."
}
```

## 3. Add a business

**Method:** `POST`  
**URL:** `http://192.168.1.71:8000/api/businesses`

### Headers

```http
Authorization: Bearer <issued-token>
Accept: application/json
Content-Type: application/json
```

### Request body

```json
{
  "name": "My Business",
  "gst_registration_type": "regular",
  "gstin": "27AAPFU0939F1ZV",
  "gst_return_frequency": "monthly",
  "business_type": "retail_shop",
  "language": "en",
  "state": "Maharashtra",
  "city": "Pune"
}
```

| Field | Required | Allowed values / rules |
|---|---|---|
| `name` | Yes | String, up to 150 characters |
| `gst_registration_type` | Yes | `regular`, `composition`, `not_registered` |
| `gstin` | Required for `regular` and `composition` | Valid 15-character GSTIN; omitted/null for `not_registered` |
| `gst_return_frequency` | Required for `regular` only | `monthly` or `quarterly`; omitted/null for other registration types |
| `business_type` | Yes | `retail_shop`, `wholesale_distribution`, `manufacturing`, `services`, `restaurant_food`, `other` |
| `language` | Yes | `en`, `hi`, `bn`, `mr`, `te`, `ta` |
| `state` | Yes | String, up to 100 characters |
| `city` | No | String, up to 100 characters, or `null` |

### Success response — `201 Created`

```json
{
  "business": {
    "id": 1,
    "user_id": 1,
    "name": "My Business",
    "gst_registration_type": "regular",
    "gstin": "27AAPFU0939F1ZV",
    "gst_return_frequency": "monthly",
    "business_type": "retail_shop",
    "language": "en",
    "state": "Maharashtra",
    "city": "Pune",
    "created_at": "2026-11-25T09:05:00.000000Z",
    "updated_at": "2026-11-25T09:05:00.000000Z"
  }
}
```

The new business is automatically selected as the account's active business.

## 4. Get the user's businesses

**Method:** `GET`  
**URL:** `http://192.168.1.71:8000/api/businesses`

### Headers

```http
Authorization: Bearer <issued-token>
Accept: application/json
```

This returns the businesses belonging to the authenticated user and identifies
the currently active business.

### Success response — `200 OK`

```json
{
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "name": "My Business",
      "gst_registration_type": "regular",
      "gstin": "27AAPFU0939F1ZV",
      "gst_return_frequency": "monthly",
      "business_type": "retail_shop",
      "language": "en",
      "state": "Maharashtra",
      "city": "Pune",
      "created_at": "2026-11-25T09:05:00.000000Z",
      "updated_at": "2026-11-25T09:05:00.000000Z"
    }
  ],
  "active_business": {
    "id": 1,
    "user_id": 1,
    "name": "My Business",
    "gst_registration_type": "regular",
    "gstin": "27AAPFU0939F1ZV",
    "gst_return_frequency": "monthly",
    "business_type": "retail_shop",
    "language": "en",
    "state": "Maharashtra",
    "city": "Pune",
    "created_at": "2026-11-25T09:05:00.000000Z",
    "updated_at": "2026-11-25T09:05:00.000000Z"
  }
}
```

If the user has not added any businesses, `data` is an empty array and
`active_business` is `null`.

## Common validation and authentication errors

Missing or invalid request fields return **`422 Unprocessable Content`**.
The response includes the failing fields:

```json
{
  "message": "The email field is required.",
  "errors": {
    "email": [
      "The email field is required."
    ]
  }
}
```

The Add Business endpoint returns **`401 Unauthorized`** if the Bearer token
is missing or invalid.
