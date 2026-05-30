## Purpose

This capability covers Stripe Billing integration for subscription management, including checkout session creation, webhook-driven activation/cancellation, and syncing Plans/Prices to Stripe Products/Prices.

## Requirements

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

### Requirement: Stripe subscription activation via webhook
系統 SHALL 透過 Stripe webhook 啟用訂閱。收到 `invoice.paid` 事件時，系統 SHALL 驗證 webhook 簽名，更新訂閱狀態為 `active`，設定 `next_date`，並建立 Transaction 記錄。

#### Scenario: invoice.paid activates subscription
- **WHEN** Stripe 發送 `invoice.paid` webhook，簽名有效
- **THEN** 對應 Subscription `status` 更新為 `active`
- **THEN** `next_date` 依 Stripe subscription 的 `current_period_end` 設定
- **THEN** 建立 Transaction 記錄，`status=paid`，`amount` 來自 invoice 金額
- **THEN** 在 `stripes` 表建立 Transaction 對應的 Invoice 記錄
- **THEN** 回應 HTTP 200

#### Scenario: Invalid webhook signature rejected
- **WHEN** Stripe webhook 簽名驗證失敗（`STRIPE_WEBHOOK_SECRET` 不符）
- **THEN** 回應 HTTP 400，不更新任何資料

#### Scenario: customer.subscription.deleted cancels subscription
- **WHEN** Stripe 發送 `customer.subscription.deleted` webhook，簽名有效
- **THEN** 對應 Subscription `status` 更新為 `canceled`
- **THEN** 回應 HTTP 200

#### Scenario: invoice.payment_failed marks subscription as paying
- **WHEN** Stripe 發送 `invoice.payment_failed` webhook，簽名有效
- **THEN** 對應 Subscription `status` 更新為 `paying`
- **THEN** 回應 HTTP 200

### Requirement: Stripe subscription cancellation
系統 SHALL 支援取消 Stripe 訂閱。呼叫 `DELETE /v1/subscriptions/{id}` 時，若 `payment_method=stripe`，系統 SHALL 呼叫 Stripe API 取消訂閱。

#### Scenario: Cancel active Stripe subscription
- **WHEN** 已驗證用戶呼叫 `DELETE /v1/subscriptions/{id}`，對應訂閱 `payment_method=stripe`
- **THEN** 系統呼叫 Stripe API 取消該 Subscription
- **THEN** 回應 HTTP 200

#### Scenario: Subscription not found returns 404
- **WHEN** `subscriptionId` 不屬於當前用戶
- **THEN** 回應 HTTP 404

### Requirement: Plan and Price synced to Stripe on creation
系統 SHALL 在 Plan 建立時同步建立 Stripe Product，在 Price 建立時同步建立 Stripe Price，並將 Stripe ID 儲存於 `stripes` 表。

#### Scenario: Plan created syncs to Stripe Product
- **WHEN** 新 Plan 被建立（`created` model event）
- **THEN** 系統在 Stripe 建立對應 Product
- **THEN** 在 `stripes` 表建立記錄，`foreign_type=Plan`，`stripe_id=prod_xxx`

#### Scenario: Price created syncs to Stripe Price
- **WHEN** 新 Price 被建立（`created` model event）
- **THEN** 系統在 Stripe 建立對應 Price，金額與計費週期依 `price.price` 與 `price.unit`
- **THEN** 在 `stripes` 表建立記錄，`foreign_type=Price`，`stripe_id=price_xxx`
