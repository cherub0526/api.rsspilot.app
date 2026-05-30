## Why

前端採用 `@stripe/stripe-js` Embedded Checkout UI，需要 Checkout Session（`ui_mode: embedded`）的 `client_secret` 才能掛載付款表單，原有流程直接建立 Stripe Subscription（`default_incomplete`）並回傳 PaymentIntent 的 `client_secret`，與 Embedded Checkout 的初始化方式不相容。

## What Changes

- `POST /v1/subscriptions`（Stripe 流程）：改為建立 Checkout Session（`ui_mode=embedded`、`mode=subscription`），`stripes` 表改存 Checkout Session ID（`cs_xxx`），回傳 session 的 `client_secret` 供前端 `initEmbeddedCheckout()` 使用
- `POST /v1/webhook/stripe`：新增 `checkout.session.completed` 事件處理，將 `stripes` 表的 `stripe_id` 從 `cs_xxx` 更新為 `sub_xxx`，並啟用訂閱
- `GET /v1/subscriptions/checkout-session`：新增端點，前端在 `return_url` 頁面查詢 Checkout Session 狀態（`open` / `complete` / `expired`）

## Capabilities

### New Capabilities

- `stripe-checkout-session-status`: 查詢 Stripe Checkout Session 狀態的端點，供前端結帳回傳頁使用

### Modified Capabilities

- `stripe-subscription`: 結帳流程從直接建立 Stripe Subscription 改為建立 Embedded Checkout Session；新增 `checkout.session.completed` webhook 處理邏輯

## Impact

- `app/Services/StripeClient.php`：新增 `checkoutSessions()` 方法
- `app/Services/StripeSubscriptionService.php`：`createCheckout()` 改用 Checkout Session；新增 `retrieveCheckoutSession()` 與 `handleCheckoutSessionCompleted()`
- `app/Http/Controllers/API/V1/SubscriptionsController.php`：新增 `checkoutSession()` action
- `app/Http/Controllers/API/V1/Webhook/StripeController.php`：分派 `checkout.session.completed` 事件
- `routes/v1.php`：新增 `GET /subscriptions/checkout-session` 路由
- `.env.example`：新增 `STRIPE_RETURN_URL` 環境變數
