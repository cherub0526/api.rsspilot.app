## 1. StripeClient 擴充

- [x] 1.1 新增 `checkoutSessions()` 方法，回傳 `\Stripe\Service\Checkout\SessionService`

## 2. Checkout Session 建立流程

- [x] 2.1 `StripeSubscriptionService::createCheckout()` 改建 Checkout Session（`ui_mode=embedded`、`mode=subscription`）
- [x] 2.2 `return_url` 串接 `STRIPE_RETURN_URL` 環境變數與 `{CHECKOUT_SESSION_ID}` 佔位符
- [x] 2.3 `stripes` 表改存 Checkout Session ID（`cs_xxx`）
- [x] 2.4 回傳 session 的 `client_secret`（非 PaymentIntent 的）
- [x] 2.5 `.env.example` 補 `STRIPE_RETURN_URL`

## 3. checkout.session.completed Webhook 處理

- [x] 3.1 新增 `StripeSubscriptionService::handleCheckoutSessionCompleted()`
- [x] 3.2 從 `session.metadata.subscriptionId` 找到本地訂閱
- [x] 3.3 呼叫 Stripe API 取回 Subscription，更新 `stripes` 表的 `stripe_id`（`cs_xxx` → `sub_xxx`）
- [x] 3.4 設定 `subscription.status=active`、`next_date=current_period_end`
- [x] 3.5 `StripeController` match 加入 `checkout.session.completed` 分派

## 4. Checkout Session 狀態查詢端點

- [x] 4.1 新增 `StripeSubscriptionService::retrieveCheckoutSession()`，驗證用戶擁有權
- [x] 4.2 新增 `SubscriptionsController::checkoutSession()` action（含 OpenAPI 文件）
- [x] 4.3 `routes/v1.php` 新增 `GET /subscriptions/checkout-session` 路由
- [x] 4.4 `lang/en`、`zh_TW`、`zh_CN` 補 `session_id_required` 訊息

## 5. OpenAPI 文件更新

- [x] 5.1 `POST /v1/subscriptions` 回應 schema 移除 Paddle oneOf，更新 `client_secret` 說明為 Checkout Session 用途
