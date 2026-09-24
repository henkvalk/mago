# Skills Roadmap

Overview of all Magento admin areas, current skill coverage, and planned additions.

## Coverage Overview

| Admin Area | Coverage | Existing Skills |
|-----------|----------|----------------|
| Sales | Partial | `sales_data` (read-only) |
| Catalog | Partial | `product_data` (read-only), `content_generator` |
| Customers | Partial | `customer_data` (read-only) |
| Marketing | None | — |
| Content | Partial | `cms_data` (read + update) |
| Stores | Partial | `config_reader`, `config_writer` |
| System | Partial | `cache_manager`, `indexer_manager` |
| Navigation | Done | `admin_navigator` |

---

## Current Skills (10)

### Read-only
| Skill | Actions |
|-------|---------|
| `sales_data` | revenue_summary, top_products, top_refunded, recent_orders, order_count, lookup_order, search_orders, customer_orders |
| `product_data` | search, get_by_sku, count, low_stock |
| `dead_stock` | obsolete, slow_moving, with CSV export |
| `customer_data` | count, recent_signups, top_spenders, lookup_customer |
| `config_reader` | Read config by path/scope |
| `admin_navigator` | Search admin pages, direct links to entities |

### Write (requires confirmation)
| Skill | Actions |
|-------|---------|
| `config_writer` | Set config value by path/scope |
| `cache_manager` | status, flush, flush_type |
| `indexer_manager` | status, reindex, reindex_all, set_mode |
| `cms_data` | list_pages, get_page, update_page, list_blocks, get_block, update_block |
| `content_generator` | generate_description, generate_meta, generate_short_description |

---

## Missing Skills

### High Priority

#### `coupon_manager`
Cart price rules and coupon code management.

- `list_rules` — list active cart price rules
- `get_rule` — get rule details
- `create_coupon` — generate coupon code for existing rule
- `create_rule` — create new cart price rule with conditions
- `deactivate_rule` — disable a price rule
- **Magento ACL:** `Magento_SalesRule::quote`
- **Use cases:** "Create a 20% off coupon for the summer sale", "Show active discount rules", "Generate 100 unique coupon codes for campaign X"

#### `catalog_price_rules`
Catalog-level price rules (automatic discounts without coupon).

- `list_rules` — list active catalog price rules
- `create_rule` — create new catalog price rule
- `deactivate_rule` — disable a rule
- `apply_rules` — trigger rule application
- **Magento ACL:** `Magento_CatalogRule::promo_catalog`
- **Use cases:** "Set 10% off all products in category Shoes", "Show active catalog discounts"

#### `url_rewrite_manager`
SEO URL management.

- `search` — find URL rewrites by path or target
- `create` — create custom URL rewrite
- `delete` — remove a URL rewrite
- `list_404s` — find broken URLs (if logging enabled)
- **Magento ACL:** `Magento_UrlRewrite::urlrewrite`
- **Use cases:** "Redirect /old-page to /new-page", "Show all custom URL rewrites", "Find rewrites for product SKU-123"

#### `review_manager`
Product review moderation.

- `list_pending` — list reviews awaiting approval
- `list_approved` — list approved reviews
- `approve` — approve a pending review
- `reject` — reject/delete a review
- `get_stats` — review statistics per product
- **Magento ACL:** `Magento_Review::reviews_all`
- **Use cases:** "Show pending reviews", "Approve all 5-star reviews from today", "How many reviews does product X have?"

#### `order_manager`
Order operations beyond read-only.

- `add_comment` — add order comment
- `update_status` — change order status
- `create_shipment` — create shipment with tracking
- `create_invoice` — create invoice
- `create_creditmemo` — create credit memo / refund
- `cancel` — cancel an order
- **Magento ACL:** `Magento_Sales::sales_order`
- **Use cases:** "Ship order #100042 with tracking number XYZ", "Refund order #100043", "Add internal note to order #100044"

---

### Medium Priority

#### `product_manager`
Product CRUD operations.

- `update_attribute` — update product attribute value
- `update_price` — change product price
- `update_stock` — set stock quantity
- `enable` / `disable` — toggle product status
- `assign_category` — add product to category
- **Magento ACL:** `Magento_Catalog::products`
- **Use cases:** "Set price of SKU-123 to 29.99", "Disable all products with zero stock", "Move product X to category Y"

#### `category_data`
Category tree navigation and management.

- `list` — list category tree
- `get` — get category details with products
- `create` — create new category
- `move` — move category in tree
- `update` — update category attributes
- **Magento ACL:** `Magento_Catalog::categories`
- **Use cases:** "Show the category tree", "How many products are in category Sale?", "Create a new subcategory under Clothing"

#### `newsletter_manager`
Newsletter subscriber management.

- `subscriber_count` — count subscribers
- `recent_subscribers` — list recent subscriptions
- `subscriber_search` — find subscriber by email
- `export` — export subscriber list
- **Magento ACL:** `Magento_Newsletter::subscriber`
- **Use cases:** "How many newsletter subscribers do I have?", "Show subscribers from this month"

#### `search_insights`
Search term analytics and synonym management.

- `top_searches` — most searched terms
- `zero_results` — searches that returned no products
- `add_synonym` — create search synonym group
- `add_redirect` — redirect a search term to URL
- **Magento ACL:** `Magento_Search::search`
- **Use cases:** "What are my top search terms?", "Which searches return zero results?", "Add synonym: sneakers = trainers = tennis shoes"

#### `shipping_manager`
Shipping method configuration and table rate management.

- `list_methods` — list all shipping methods with enabled/disabled status
- `get_method` — get method config (price, title, conditions)
- `update_method` — update flat rate price, free shipping threshold, etc.
- `list_table_rates` — list table rate entries (country/region/zip/price)
- `update_table_rate` — add or modify a table rate entry
- `import_table_rates` — import table rates from CSV data
- **Magento ACL:** `Magento_Shipping::shipping`
- **Note:** Basic flat rate / free shipping config can already be read/written via `config_reader`/`config_writer` (e.g. `carriers/flatrate/price`, `carriers/freeshipping/free_shipping_subtotal`). This skill adds table rate management and a more user-friendly interface.
- **Use cases:** "What shipping methods are active?", "Set flat rate shipping to 4.95", "Set free shipping threshold to 50 euro", "Show table rates for the Netherlands", "Add a table rate: NL, 0-5kg = 5.95"

#### `report_data`
Aggregated reporting beyond sales_data.

- `abandoned_carts` — abandoned cart report
- `products_in_cart` — most-added-to-cart products
- `bestsellers` — bestseller report by period
- `tax_report` — tax collected per period
- `coupon_usage` — coupon code usage stats
- **Magento ACL:** `Magento_Reports::report`
- **Use cases:** "Show abandoned carts from this week", "What's my bestseller this month?", "How much tax was collected in Q2?"

---

### Low Priority

#### `customer_group_manager`
Customer group management.

- `list` — list customer groups
- `create` — create customer group
- `assign` — move customer to group
- **Use cases:** "List customer groups", "Create a VIP customer group"

#### `tax_manager`
Tax rules and rates.

- `list_rules` — list tax rules
- `list_rates` — list tax rates
- `create_rate` — create tax rate for region
- **Use cases:** "Show tax rules", "What tax rate applies to California?"

#### `email_template_manager`
Transactional email templates.

- `list` — list email templates
- `get` — get template content
- `update` — modify template
- **Use cases:** "Show the order confirmation email template", "Update the shipping notification email"

#### `store_info`
Store/website/storeview structure.

- `list_stores` — list all store views, websites, stores
- `get_store` — get store details
- **Use cases:** "How many store views do I have?", "Show store structure"

#### `cron_status`
Cron job monitoring.

- `list_running` — currently running cron jobs
- `list_schedule` — upcoming scheduled jobs
- `list_failed` — recently failed jobs
- **Use cases:** "Are there stuck cron jobs?", "Show failed crons from today"

#### `admin_user_manager`
Admin user management.

- `list` — list admin users
- `get` — get user details
- `create` — create admin user
- `reset_password` — reset admin password
- **Use cases:** "List admin users", "Create a new admin account for john@example.com"

---

## Not Planned

| Area | Reason |
|------|--------|
| Backups | High risk, better handled via CLI/hosting tools |
| Data import/export | Too complex for chat UX, use native Magento UI |
| Theme/design management | Requires file system access |
| Integration/OAuth management | Security sensitive, low demand |
| Payment configuration | Blocked by security policy |

---

## Implementation Notes

### Sub-action Pattern
Skills with mixed read/write actions should use `AbstractSkill` with sub-actions implementing `ActionInterface`. This enables per-action `isReadOnlyAction()` checks — read actions execute automatically, write actions require confirmation.

### Naming Convention
- Read-only analytics: `*_data` (e.g. `sales_data`, `product_data`)
- Mixed read/write management: `*_manager` (e.g. `cache_manager`, `coupon_manager`)
- Content generation: `*_generator` (e.g. `content_generator`)
- Navigation: `*_navigator` (e.g. `admin_navigator`)
