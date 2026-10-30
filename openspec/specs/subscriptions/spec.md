## Purpose

This capability covers subscription management, including creating and cancelling subscriptions via multiple payment gateways (Creem and Stripe).

## Requirements

### Requirement: Create subscription supports paymentMethod parameter
`POST /v1/subscriptions` SHALL 接受選填的 `paymentMethod` 參數（`creem` 或 `stripe`）。未提供時 SHALL 使用 `PAYMENT_DEFAULT_PROVIDER`；該值沒設或不在允許值內（包括已移除的 `paddle`）時 SHALL 退回 `creem`。系統 SHALL 依 `paymentMethod` 分流至對應的金流服務，回應格式依金流而異。

#### Scenario: Default paymentMethod follows PAYMENT_DEFAULT_PROVIDER
- **WHEN** 呼叫 `POST /v1/subscriptions` 未提供 `paymentMethod`，且 `PAYMENT_DEFAULT_PROVIDER=creem`
- **THEN** 系統以 Creem 流程建立訂閱
- **THEN** 回應包含 `creem.checkout_url` 與 `creem.checkout_id`

#### Scenario: Explicit paymentMethod=stripe uses Stripe flow
- **WHEN** 呼叫 `POST /v1/subscriptions`，`paymentMethod=stripe`
- **THEN** 系統以 Stripe 流程建立訂閱
- **THEN** 回應包含 `stripe.publishable_key` 與 `stripe.client_secret`

#### Scenario: Invalid paymentMethod returns 422
- **WHEN** 呼叫 `POST /v1/subscriptions`，`paymentMethod` 不在允許值（`creem`、`stripe`），例如 `paddle`
- **THEN** 回應 HTTP 422，包含 `paymentMethod` 錯誤訊息，且不建立訂閱紀錄

### Requirement: Legacy Paddle subscriptions stay readable but cannot be cancelled in-app
Paddle 整合已於 2026-10 移除。`payment_method = paddle` 的既有訂閱 SHALL 照常決定使用者的方案，但 app 內取消 SHALL 回 422 `cancel_unavailable`，不得記成已取消。

#### Scenario: Cancelling a legacy Paddle subscription
- **WHEN** 持有生效中 Paddle 訂閱的使用者呼叫 `DELETE /v1/subscriptions/{id}`
- **THEN** 回應 HTTP 422，訊息請使用者聯絡客服
- **THEN** 該訂閱的 `cancellation_date` 維持 null
