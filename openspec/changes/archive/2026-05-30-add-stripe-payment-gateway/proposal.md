## Why

目前系統僅支援 Paddle 金流，但 Paddle 在某些市場的支援度與使用者熟悉度不足。新增 Stripe 作為預設付款選項，提供更廣泛的信用卡支援，並與 ElectronJS 前端的 embedded 付款體驗整合。

## What Changes

- 新增 Stripe Billing 訂閱付款流程（Payment Elements embedded，適用 ElectronJS）
- `POST /v1/subscriptions` 新增選填參數 `paymentMethod`，預設值改為 `stripe`
- 新增 `POST /v1/webhook/stripe` 處理 Stripe 訂閱事件
- Paddle 整合完整保留，可透過 `paymentMethod=paddle` 繼續使用
- 新增 `stripes` 資料表儲存 Stripe 外部 ID 與詳細資料（多型，對稱 `paddles` 表）
- `SubscriptionsController` 重構：Paddle 邏輯抽出至 `PaddleSubscriptionService`，Stripe 邏輯放入 `StripeSubscriptionService`

## Capabilities

### New Capabilities

- `stripe-subscription`: 使用 Stripe Billing 建立、啟用、取消訂閱，並透過 webhook 同步狀態

### Modified Capabilities

- `subscriptions`: `POST /v1/subscriptions` 新增 `paymentMethod` 參數，支援雙金流選擇

## Impact

- **新增依賴**：`stripe/stripe-php` composer 套件
- **新增環境變數**：`STRIPE_API_KEY`、`STRIPE_PUBLISHABLE_KEY`、`STRIPE_WEBHOOK_SECRET`
- **新增資料表**：`stripes`
- **調整路由**：`routes/v1.php` 新增 `POST /webhook/stripe`
- **調整 Model**：`User`、`Plan`、`Price`、`Subscription`、`Transaction` 新增 `->stripe()` 關聯
- **調整 Observer 註冊**：`StripePlanObserver`、`StripePriceObserver`、`StripeUserObserver`
