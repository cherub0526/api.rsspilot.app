# Stripe 金流整合設計

**日期：** 2026-05-30
**狀態：** 已核准，待實作

## 背景

目前系統使用 Paddle 作為金流。本次新增 Stripe 作為第二個付款選項，並設為預設。Paddle 整合保留不動。目前為開發階段，無需遷移既有訂閱資料。

前端環境為 ElectronJS，因此 Stripe 採用 Payment Elements（embedded）而非 Checkout（redirect），避免處理 deep link。

## 架構總覽

```
POST /v1/subscriptions
  ├── paymentMethod=stripe (預設)  →  StripeSubscriptionService
  └── paymentMethod=paddle          →  PaddleSubscriptionService

DELETE /v1/subscriptions/{id}       →  依 subscription.payment_method 選 service

POST /v1/webhook/stripe             →  Webhook\StripeController  (新增)
POST /v1/webhook/paddle             →  Webhook\PaddleController  (現有，不動)
```

### Stripe Billing + Payment Elements 流程

1. 前端呼叫 `POST /v1/subscriptions`（paymentMethod=stripe）
2. 後端建立/取得 Stripe Customer → 建立 Stripe Subscription（`default_incomplete`）→ 回傳 `client_secret` + `publishable_key`
3. 前端用 Stripe.js Payment Elements + `client_secret` 收卡號 → `confirmPayment({ redirect: 'if_required' })`
4. Stripe 觸發 webhook → 後端啟用訂閱、建立 Transaction

## 資料庫

### 新增 `stripes` 資料表

結構與現有 `paddles` 完全對稱：

```sql
CREATE TABLE stripes (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  foreign_id     VARCHAR(255) NOT NULL,
  foreign_type   VARCHAR(255) NOT NULL,
  stripe_id      VARCHAR(255) NULL,
  stripe_detail  TEXT NULL,
  created_at     TIMESTAMP NULL,
  updated_at     TIMESTAMP NULL,
  INDEX (foreign_id),
  INDEX (foreign_type),
  INDEX (stripe_id),
  INDEX (foreign_id, foreign_type)
);
```

### `stripes` 表覆蓋的 Models

| Model | 儲存內容 | `stripe_id` 範例 |
|-------|---------|-----------------|
| `User` | Stripe Customer | `cus_xxx` |
| `Plan` | Stripe Product | `prod_xxx` |
| `Price` | Stripe Price | `price_xxx` |
| `Subscription` | Stripe Subscription | `sub_xxx` |
| `Transaction` | Stripe Invoice | `in_xxx` |

### `subscriptions` 表（不改結構）

`Subscription` model 新增常數：

```php
public const string PAYMENT_METHOD_STRIPE = 'stripe';
```

## 新增 / 調整檔案

| 類型 | 路徑 | 說明 |
|------|------|------|
| Migration | `database/migrations/..._create_stripes_table.php` | 建立 stripes 表 |
| Model | `app/Models/Stripe.php` | 多型關聯，同 Paddle model |
| Service | `app/Services/StripeClient.php` | Stripe SDK wrapper |
| Service | `app/Services/StripeSubscriptionService.php` | checkout、取消 |
| Service | `app/Services/PaddleSubscriptionService.php` | 從 Controller 抽出現有 Paddle 邏輯 |
| Controller | `app/Http/Controllers/API/V1/Webhook/StripeController.php` | Stripe webhook |
| Observer | `app/Observers/StripePlanObserver.php` | Plan 建立時同步 Stripe Product |
| Observer | `app/Observers/StripePriceObserver.php` | Price 建立時同步 Stripe Price |
| Observer | `app/Observers/StripeUserObserver.php` | User 建立/更新時同步 Stripe Customer |

`SubscriptionsController` 瘦身，只判斷 `payment_method` 並 delegate 給對應 service。

## API 規格

### `POST /v1/subscriptions`

Request body（`paymentMethod` 為新增選填欄位）：
```json
{
  "planId": "01JCXYZ...",
  "priceId": "01JCXYZ...",
  "paymentMethod": "stripe"
}
```

Response（Stripe）：
```json
{
  "stripe": {
    "publishable_key": "pk_live_...",
    "client_secret": "pi_xxx_secret_xxx"
  }
}
```

Response（Paddle）：維持現有格式不變。

### `DELETE /v1/subscriptions/{id}`

不變。後端依 `subscription.payment_method` 自動選擇 service。

### `PUT /v1/subscriptions/{id}`

不變。僅 Paddle 使用（傳 `transaction_id` 確認付款）。Stripe 由 webhook 驅動，前端付款後不需呼叫。

### `POST /v1/webhook/stripe`（新增）

Webhook 驗證：`Stripe\Webhook::constructEvent($rawBody, $stripeSignature, $webhookSecret)`。

處理事件：

| 事件 | 動作 |
|------|------|
| `invoice.paid` | 啟用訂閱（`status=active`）、更新 `next_date`、建立 Transaction |
| `customer.subscription.deleted` | 訂閱標記為 `canceled` |
| `invoice.payment_failed` | 訂閱標記為 `paying`（等待重試） |

## 環境變數

```
STRIPE_API_KEY=sk_live_...
STRIPE_PUBLISHABLE_KEY=pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

## 前端串接摘要

```js
// 1. 取得 client_secret（呼叫 POST /v1/subscriptions）
// 2. 初始化 Stripe.js
const stripe = Stripe(publishable_key);
const elements = stripe.elements({ clientSecret: client_secret });

// 3. Mount Payment Element
const paymentElement = elements.create('payment');
paymentElement.mount('#payment-element');

// 4. 使用者送出
const { error } = await stripe.confirmPayment({
  elements,
  redirect: 'if_required',  // Electron 不 redirect
});

// 5. 付款成功後由 webhook 啟用訂閱，前端 polling GET /v1/subscriptions 確認 status=active
```

## 不在此次範圍內

- Stripe 方案試用期（trial）
- Stripe Customer Portal
- 發票 email 客製化
- 退款流程
