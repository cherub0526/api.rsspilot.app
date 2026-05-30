## Purpose

此 capability 提供 `GET /v1/subscriptions/checkout-session` 端點，讓前端在 Stripe Embedded Checkout 完成後的 `return_url` 頁面查詢 Checkout Session 狀態，確認付款是否成功。

## Requirements

### Requirement: Query Checkout Session status
系統 SHALL 提供 `GET /v1/subscriptions/checkout-session?session_id=<cs_xxx>` 端點，已驗證用戶可查詢其 Checkout Session 狀態，系統 SHALL 驗證該 session 屬於當前用戶，並回傳狀態與顧客 email。

#### Scenario: Successful status query
- **WHEN** 已驗證用戶呼叫 `GET /v1/subscriptions/checkout-session?session_id=cs_xxx`
- **WHEN** 該 session 的 `metadata.subscriptionId` 對應的訂閱屬於當前用戶
- **THEN** 回應 HTTP 200，包含 `status`（`open` / `complete` / `expired`）與 `customer_email`

#### Scenario: Session belonging to another user returns 404
- **WHEN** `session_id` 對應的 session metadata 中的訂閱不屬於當前用戶
- **THEN** 回應 HTTP 404

#### Scenario: Missing session_id returns 400
- **WHEN** 呼叫 `GET /v1/subscriptions/checkout-session` 未提供 `session_id`
- **THEN** 回應 HTTP 400，包含 `session_id` 錯誤訊息

#### Scenario: Unauthenticated request returns 401
- **WHEN** 呼叫 `GET /v1/subscriptions/checkout-session` 未提供有效 JWT
- **THEN** 回應 HTTP 401
