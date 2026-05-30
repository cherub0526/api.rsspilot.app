## 1. 安裝依賴與環境設定

- [x] 1.1 執行 `composer require stripe/stripe-php` 安裝 Stripe PHP SDK
- [x] 1.2 在 `.env` 與 `.env.example` 新增 `STRIPE_API_KEY`、`STRIPE_PUBLISHABLE_KEY`、`STRIPE_WEBHOOK_SECRET`

## 2. 資料庫

- [x] 2.1 建立 migration `create_stripes_table`（欄位：`id`、`foreign_id`、`foreign_type`、`stripe_id`、`stripe_detail`、timestamps，對稱 `paddles` 表）
- [x] 2.2 執行 migration

## 3. Model

- [x] 3.1 建立 `app/Models/Stripe.php`（多型關聯，結構對稱 `Paddle` model）
- [x] 3.2 在 `User` model 新增 `stripe(): HasOne` 關聯
- [x] 3.3 在 `Plan` model 新增 `stripe(): HasOne` 關聯
- [x] 3.4 在 `Price` model 新增 `stripe(): HasOne` 關聯
- [x] 3.5 在 `Subscription` model 新增 `stripe(): HasOne` 關聯與 `PAYMENT_METHOD_STRIPE` 常數
- [x] 3.6 在 `Transaction` model 新增 `stripe(): HasOne` 關聯

## 4. StripeClient Service

- [x] 4.1 建立 `app/Services/StripeClient.php`，包裝 Stripe PHP SDK（`customers()`、`subscriptions()`、`products()`、`prices()`、`invoices()` 方法），讀取 `STRIPE_API_KEY`

## 5. Observers

- [x] 5.1 建立 `app/Observers/StripePlanObserver.php`：`created` 事件呼叫 Stripe API 建立 Product，儲存至 `stripes` 表
- [x] 5.2 建立 `app/Observers/StripePriceObserver.php`：`created` 事件呼叫 Stripe API 建立 Price（金額 × 100，依 `unit` 設定計費週期），儲存至 `stripes` 表
- [x] 5.3 建立 `app/Observers/StripeUserObserver.php`：`updated` 事件若有 stripe 記錄則更新 Stripe Customer 名稱與 email
- [x] 5.4 在 `AppServiceProvider`（或對應 Provider）註冊三個 Stripe observers

## 6. PaddleSubscriptionService（重構）

- [x] 6.1 建立 `app/Services/PaddleSubscriptionService.php`，將 `SubscriptionsController::store`（Paddle 分支）、`update`、`destroy` 中的 Paddle 邏輯搬入對應方法

## 7. StripeSubscriptionService

- [x] 7.1 建立 `app/Services/StripeSubscriptionService.php`，實作 `createCheckout(User $user, Plan $plan, Price $price): array`：建立/取得 Stripe Customer → 建立 `default_incomplete` Subscription → 回傳 `publishable_key` 與 `client_secret`
- [x] 7.2 實作 `cancel(Subscription $subscription): void`：呼叫 Stripe API 取消 Subscription
- [x] 7.3 實作 `handleInvoicePaid(array $event): void`：更新訂閱狀態為 `active`、設定 `next_date`、建立 Transaction 與 stripes 記錄
- [x] 7.4 實作 `handleSubscriptionDeleted(array $event): void`：更新訂閱狀態為 `canceled`
- [x] 7.5 實作 `handleInvoicePaymentFailed(array $event): void`：更新訂閱狀態為 `paying`

## 8. SubscriptionsController 重構

- [x] 8.1 `store` 方法新增 `paymentMethod` 參數驗證（允許值：`stripe`、`paddle`，預設 `stripe`）
- [x] 8.2 `store` 依 `paymentMethod` delegate 至 `StripeSubscriptionService` 或 `PaddleSubscriptionService`
- [x] 8.3 `destroy` 依 `subscription->payment_method` delegate 至對應 service
- [x] 8.4 `update` 維持不動（僅 Paddle 使用）
- [x] 8.5 更新 OpenAPI 文件：`store` 新增 `paymentMethod` 參數與 Stripe response schema

## 9. Stripe Webhook Controller

- [x] 9.1 建立 `app/Http/Controllers/API/V1/Webhook/StripeController.php`，實作 `store` 方法：取得 raw request body → 驗證 Stripe 簽名（`STRIPE_WEBHOOK_SECRET`）→ 依 event type dispatch 至 StripeSubscriptionService
- [x] 9.2 在 `routes/v1.php` 新增 `POST /webhook/stripe` 路由（無 auth middleware）
- [x] 9.3 確認 Hypervel 框架支援取得 raw request body（驗簽需要），若需要則加入對應 middleware 或設定

## 10. 驗收測試

- [x] 10.1 建立 `tests/Feature/API/V1/Webhook/StripeControllerTest.php`：測試 `invoice.paid`、`customer.subscription.deleted`、`invoice.payment_failed` 事件處理，以及簽名驗證失敗回傳 400
- [x] 10.2 補充 `SubscriptionsControllerTest.php`：測試 `paymentMethod=stripe` 預設、`paymentMethod=paddle` 分流、無效 `paymentMethod` 回傳 400
