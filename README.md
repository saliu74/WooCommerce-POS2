# WooCommerce POS Pro

A multi-branch, multi-register point-of-sale system built directly on top of WooCommerce. Cashiers ring up in-person sales from a dedicated terminal screen, while every product, stock level, customer, and order stays in perfect sync with your existing WooCommerce store — no separate POS catalog to maintain.

**Current version:** 1.6.4

---

## Features

- **POS Terminal** — a fast, touch/mobile-friendly checkout screen (`/pos`) with product search, category filters, variation picking, a live cart, and parked/resumable sales.
- **Multi-branch & multi-register** — model multiple physical locations, assign registers to branches, and optionally track stock per branch (opt-in per product; everything else falls back to the shared store-wide WooCommerce stock count).
- **Shift management** — every register requires an open shift before it can process a sale. Closing a shift calculates expected vs. actual cash and the difference automatically.
- **Inventory protection** — out-of-stock items are blocked from the cart server-side (not just visually disabled), stock is deducted atomically with row-level locking to prevent overselling, and variable-product stock is resolved from its variations rather than the (often unmanaged) parent product.
- **Payments** — Cash, Card, and Split payment modes, with a cash-tendered/change-due calculator and split-balance validation.
- **Discounts with real authorization** — applying a discount requires PIN confirmation at the terminal, and independently requires the `override_wc_pos_prices` capability server-side.
- **PIN security** — quick-access PIN login with attempt throttling (5 failed attempts locks the account for 5 minutes).
- **Reporting suite** (`POS → Reports`) — Sales Summary, Shift History/Cash Reconciliation, Top Products, Cashier Performance, and Branch Comparison, each with date-range/branch filtering and CSV export.
- **Mobile-responsive terminal** — below ~1024px, the layout switches to a mobile flow: full-screen product grid, a full-screen cart view opened via a floating button, a collapsible nav sidebar, and a sticky checkout footer that's always reachable without scrolling.
- **Role-based access** — a dedicated `pos_cashier` role plus four custom capabilities (below) let you grant exactly the level of access each staff member needs.

---

## Requirements

- WordPress
- WooCommerce (active) — the plugin will not initialize without it
- PHP 7.4+ (uses namespaces, short array syntax, null coalescing)
- A modern browser for the terminal (Chrome/Safari/Edge/Firefox, desktop or mobile)

---

## Installation

1. Upload the `wc-pos-pro` folder to `wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
2. Activate **WooCommerce POS Pro** from the Plugins screen.
3. On activation, the plugin creates its database tables, registers the `pos_cashier` role and custom capabilities, and adds the `/pos` rewrite rule (a permalink flush happens automatically).
4. Set up the essentials in this order:
   - **POS → Branches** — create at least one branch (a "Main Branch" is seeded automatically for single-location stores).
   - **POS → Registers** — create a register and assign it to a branch.
   - Visit `/pos` on your site, select the branch/register, and open a shift before processing any sale.
5. Optional: **POS → Branch Stock** to allocate per-branch stock counts, **POS → Tax** for POS-specific tax rates, **POS → Receipt Builder** to customize the receipt template.

> **Note:** table creation is self-healing — if any expected table is missing (this can occasionally happen with `dbDelta()` on some hosts), the plugin detects and recreates it automatically the next time any admin page loads, without requiring a deactivate/reactivate.

---

## Architecture

```
wc-pos-pro/
├── wc-pos-pro.php                     # Bootstrap: activation/deactivation, hooks, rewrite rules
├── uninstall.php                      # Full data cleanup (opt-in via a setting)
├── includes/
│   ├── Autoloader.php                 # PSR-4-style autoloader for the WCPOS\ namespace
│   ├── API/
│   │   ├── REST_Server.php            # Core REST API: products, customers, orders, shifts, tax, PIN
│   │   └── Branches_Controller.php    # REST API for branches + per-branch stock
│   ├── Admin/
│   │   ├── AdminMenu.php              # All wp-admin screens (Branches, Registers, Branch Stock,
│   │   │                              #   Reports, Tax, Receipt Builder, Settings)
│   │   └── Permissions.php            # Registers the pos_cashier role + custom capabilities
│   ├── Database/
│   │   └── Tables.php                 # dbDelta schema for all custom tables
│   ├── Orders/
│   │   └── SalesEngine.php            # Order creation: stock pre-checks, shift enforcement,
│   │                                  #   discount authorization, atomic deduction, order meta
│   └── POS/
│       └── Inventory.php              # Atomic, row-locked stock deduction (global + per-branch)
└── templates/
    └── pos-terminal.php                # The entire terminal UI: HTML/Tailwind/vanilla JS, single file
```

The terminal (`pos-terminal.php`) is a single self-contained template — no build step, no bundler. It loads Tailwind via CDN and talks to the REST API directly with `fetch()`.

---

## REST API

Namespace: `wc-pos/v1`

| Method | Route | Purpose |
|---|---|---|
| GET | `/health` | Basic connectivity check |
| GET | `/products` | Product list (supports `s`, `category`, `branchId` params) |
| GET | `/categories` | Product categories |
| GET | `/customers` | Customer search (`s` param) |
| POST | `/customers` | Create a customer |
| GET | `/orders` | Order history |
| POST | `/orders` | Create a POS sale (`SalesEngine::create_pos_order()`) |
| POST | `/registers/shift` | Open or close a register shift |
| GET | `/registers` | List registers (optionally by `branchId`) |
| GET/POST | `/tax-rates` | List / create POS tax rates |
| POST/DELETE | `/tax-rates/{id}` | Update / deactivate a tax rate |
| GET/POST | `/receipt-config` | Receipt template settings |
| POST | `/pin/verify` | Verify a cashier PIN (rate-limited) |
| POST | `/pin/set` | Set/change a PIN |
| GET/POST | `/branches` | List / create branches |
| POST/DELETE | `/branches/{id}` | Update / delete a branch |
| GET/POST | `/branches/{id}/stock` | Read / write a branch's per-product stock allocation |

Most routes require the `process_wc_pos_sales` capability (or `manage_woocommerce`); mutating routes for branches/tax/registers require `manage_wc_pos_branches` or `manage_wc_pos` respectively (or `manage_woocommerce`).

---

## Roles & Capabilities

| Capability | Grants |
|---|---|
| `process_wc_pos_sales` | Terminal access: browse products, take payment, open/close shifts |
| `manage_wc_pos` | General POS administration: settings, tax rates, receipt builder, reports |
| `manage_wc_pos_branches` | Create/edit/delete branches, registers, and branch stock allocations |
| `override_wc_pos_prices` | Apply discounts at checkout |

`administrator` and `shop_manager` receive all four automatically. A dedicated `pos_cashier` role is created on activation with `process_wc_pos_sales` and `read_private_shop_orders` — enough to run the till without full store-admin access. Assign the other three capabilities to any role that should also manage settings, branches, or discounts.

---

## Database Tables

All prefixed with your site's table prefix (e.g. `wp_`):

- `wc_pos_branches` — physical locations
- `wc_pos_branch_stock` — optional per-branch stock allocations (product/variation → branch → quantity)
- `wc_pos_registers` — tills, each assigned to a branch
- `wc_pos_shifts` — every shift opened/closed, with cash reconciliation figures
- `wc_pos_inventory_logs` — immutable audit log of every stock change made through the POS
- `wc_pos_transfers` — stock transfer records between branches (schema present; not yet exposed in the UI)
- `wc_pos_tax_rates` — POS-specific tax rates

Orders themselves remain standard WooCommerce orders (`wp_posts` + related tables), tagged with POS-specific meta (`_wc_pos_register_id`, `_wc_pos_branch_id`, `_wc_pos_cashier_id`, `_wc_pos_cashier_name`, `_wc_pos_payments`, `_wc_pos_terminal_reference`) and `created_via = 'wc_pos_pro'`.

---

## Known Limitations / Roadmap

- Stock transfers between branches (`wc_pos_transfers`) have a database schema but no admin UI yet.
- No dedicated "void/cancel this sale" action at the terminal — cancellations currently go through WooCommerce's normal admin-side order tools.
- Receipts print via the browser's native print dialog; there's no bespoke in-app "Download PDF" button — on devices without a connected printer, staff use the browser's own "Save as PDF" print destination instead.
- Reports currently query orders directly (no caching/pre-aggregation) — fine at typical small-to-mid store volumes; a very high order volume may eventually warrant a summary table.

---

## Support / Development Notes

This plugin is actively maintained. Please bump `Version:` in the plugin header and `WC_POS_VERSION` in `wc-pos-pro.php` together on every change, however small — several parts of the plugin (table self-healing, cache-busting) key off this value matching.
