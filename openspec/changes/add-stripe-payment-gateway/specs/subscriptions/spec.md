## MODIFIED Requirements

### Requirement: Create subscription supports paymentMethod parameter
`POST /v1/subscriptions` SHALL 接受選填的 `paymentMethod` 參數（`stripe` 或 `paddle`），預設值為 `stripe`。系統 SHALL 依 `paymentMethod` 分流至對應的金流服務，回應格式依金流而異。

#### Scenario: Default paymentMethod is stripe
- **WHEN** 呼叫 `POST /v1/subscriptions` 未提供 `paymentMethod`
- **THEN** 系統以 Stripe 流程建立訂閱
- **THEN** 回應包含 `stripe.publishable_key` 與 `stripe.client_secret`

#### Scenario: Explicit paymentMethod=paddle uses Paddle flow
- **WHEN** 呼叫 `POST /v1/subscriptions`，`paymentMethod=paddle`
- **THEN** 系統以 Paddle 流程建立訂閱
- **THEN** 回應格式維持現有 Paddle 格式（`paddle.client_token`、`items`、`customer`、`customData`）

#### Scenario: Invalid paymentMethod returns 400
- **WHEN** 呼叫 `POST /v1/subscriptions`，`paymentMethod` 不在允許值（`stripe`、`paddle`）
- **THEN** 回應 HTTP 400，包含 `paymentMethod` 錯誤訊息
