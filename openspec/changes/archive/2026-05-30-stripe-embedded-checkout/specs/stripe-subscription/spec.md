## MODIFIED Requirements

### Requirement: Stripe checkout session creation
系統 SHALL 支援以 Stripe Billing Embedded Checkout 建立訂閱。呼叫 `POST /v1/subscriptions`（`paymentMethod=stripe` 或預設）時，後端 SHALL 建立/取得 Stripe Customer、建立 Checkout Session（`ui_mode=embedded`、`mode=subscription`），並回傳 `publishable_key` 與 session 的 `client_secret`。`stripes` 表初始儲存 Checkout Session ID（`cs_xxx`），待 `checkout.session.completed` webhook 觸發後更新為 Stripe Subscription ID（`sub_xxx`）。

#### Scenario: New user creates Stripe subscription via Embedded Checkout
- **WHEN** 已驗證用戶呼叫 `POST /v1/subscriptions`，提供有效 `planId`、`priceId`，`paymentMethod` 為 `stripe` 或未提供
- **THEN** 系統在 `stripes` 表建立 User 對應的 Stripe Customer 記錄（若不存在）
- **THEN** 系統建立 Checkout Session（`ui_mode=embedded`、`mode=subscription`）
- **THEN** 系統在 `subscriptions` 表建立記錄，`payment_method=stripe`，`status=paying`
- **THEN** 系統在 `stripes` 表建立記錄，`foreign_type=Subscription`，`stripe_id=cs_xxx`
- **THEN** 回應包含 `stripe.publishable_key` 與 `stripe.client_secret`（Checkout Session 的 client_secret）

#### Scenario: Existing Stripe customer reuses customer ID
- **WHEN** 用戶已有 `stripes` 記錄（`foreign_type=User`）
- **THEN** 系統使用既有 Stripe Customer ID，不重複建立

#### Scenario: Invalid planId returns 400
- **WHEN** 呼叫 `POST /v1/subscriptions` 提供不存在的 `planId`
- **THEN** 回應 HTTP 400，包含 `planId` 錯誤訊息

#### Scenario: Price not belonging to plan returns 400
- **WHEN** `priceId` 存在但不屬於指定 `planId`
- **THEN** 回應 HTTP 400，包含 `priceId` 錯誤訊息

## ADDED Requirements

### Requirement: Stripe subscription activation via checkout.session.completed
系統 SHALL 處理 Stripe `checkout.session.completed` webhook 事件，將 `stripes` 表的 `stripe_id` 從 Checkout Session ID 更新為 Stripe Subscription ID，並啟用對應的本地訂閱，確保後續 `invoice.paid`、`customer.subscription.deleted` 事件能正確對應。

#### Scenario: checkout.session.completed activates subscription
- **WHEN** Stripe 發送 `checkout.session.completed` webhook，簽名有效
- **THEN** 系統從 `session.metadata.subscriptionId` 找到對應本地訂閱
- **THEN** 系統將 `stripes` 表的 `stripe_id` 由 `cs_xxx` 更新為 `sub_xxx`，並更新 `stripe_detail`
- **THEN** 對應 Subscription `status` 更新為 `active`
- **THEN** `next_date` 依 Stripe Subscription 的 `current_period_end` 設定
- **THEN** 回應 HTTP 200

#### Scenario: checkout.session.completed missing metadata is skipped
- **WHEN** `checkout.session.completed` 事件的 `metadata` 不含 `subscriptionId` 或 `subscription` 為空
- **THEN** 系統不執行任何資料更新，回應 HTTP 200
