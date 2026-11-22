# Café X - Laravel Multi-Tenant Backend

Production-ready Multi-Tenant backend for the Café X Hotel & Restaurant Management System, built with **Laravel 13**, **Sanctum**, and **Single-Database Tenant Scoping**.

---

## 🏛 Multi-Tenant Architecture

Each tenant represents an individual hotel property, resort, or restaurant branch (e.g. *Milan Downtown*, *Grand Roma*, *Alpine Resort*).

### How Multi-Tenancy Works:
1. **Tenant Identification**: Handled by `App\Http\Middleware\IdentifyTenant`:
   - Inspects `X-Tenant-ID` or `X-Tenant` header (ID or slug)
   - Checks authenticated user's active branch (`current_tenant_id`)
   - Supports query parameter `?tenant={slug}`
   - Falls back to the primary default branch (`milan`)
2. **Automatic Data Isolation**:
   - All tenant-owned models use the `App\Tenancy\Traits\BelongsToTenant` trait.
   - Automatically scopes all queries with global `TenantScope` (`WHERE tenant_id = ?`).
   - Automatically injects the active `tenant_id` when creating new records.
3. **Multi-Tenant User Roles**:
   - Users can hold different roles per tenant via the `tenant_user` pivot table (`admin`, `manager`, `kitchen_staff`, `cashier`, `customer`).
   - Customers can book tables and place orders across multiple branches with a single account.

---

## 🚀 Quick Start

### Option A: Using Docker (Recommended, Zero PHP installation required)

```bash
# 1. Start the Laravel server
cd backend-laravel
docker compose up -d

# 2. Run migrations and seed data
./artisan-docker migrate --seed
```
The API is live at **http://localhost:8000/api**!

### Option B: Native PHP (If PHP 8.2+ & Composer are installed on host)

```bash
cd backend-laravel
composer install
php artisan migrate --seed
php artisan serve --port=8000
```

To install PHP and Composer on Ubuntu / Pop!_OS:
```bash
sudo apt update
sudo apt install -y php php-cli php-fpm php-sqlite3 php-pgsql php-curl php-mbstring php-xml php-zip unzip composer
```

---

## 🔑 Pre-Seeded Test Accounts

| Role | Email | Password | Pre-assigned Branches |
|------|-------|----------|----------------------|
| **Admin** | `admin@cafex.com` | `admin123` | Milan, Rome, Alpine |
| **Kitchen** | `kitchen@cafex.com` | `kitchen123` | Milan, Rome, Alpine |
| **Customer** | `customer@cafex.com` | `customer123` | Milan, Rome, Alpine |

---

## 📡 API Endpoints Summary

### Branches & Multi-Tenancy
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/tenants` | List all active hotel / restaurant branches |
| GET | `/api/tenants/{idOrSlug}` | Get details and settings for a branch |
| POST | `/api/tenants` | Onboard a new branch (Admin) |

### Authentication
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/signup` | Register a new user & associate with active tenant |
| POST | `/api/auth/login` | Sign in, returns Sanctum token + tenant roles |
| GET | `/api/auth/me` | Current authenticated user profile |
| POST | `/api/auth/logout` | Revoke current API token |
| POST | `/api/auth/switch-tenant` | Switch user's active branch |

### Menu & Categories
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/menu` | List dishes (scoped to active tenant) |
| GET | `/api/menu/categories` | List categories for active tenant |
| POST | `/api/menu` | Create a new menu item |
| PATCH | `/api/menu/{item}/availability` | Toggle dish stock / availability |

### Tables & Reservations
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/tables` | List floor tables across zones (Window, Patio, VIP) |
| POST | `/api/tables` | Add a table |
| PATCH | `/api/tables/{table}/status` | Update table status (`available`, `occupied`, etc.) |
| GET | `/api/reservations` | List reservations for active branch |
| POST | `/api/reservations` | Book a table with automated conflict checking |
| PATCH | `/api/reservations/{id}/status` | Update reservation status |

### Orders & Kitchen KDS
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/orders` | List orders for active tenant |
| POST | `/api/orders` | Place a new order with items |
| PATCH | `/api/orders/{order}/status` | Update order progress |
| PATCH | `/api/orders/items/{item}/complete` | Mark item as prepared by kitchen |

### Dashboard & Analytics
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/dashboard/stats` | Executive metrics (orders, revenue, tables) |
| GET | `/api/dashboard/kitchen` | Live kitchen display queue & prep times |
