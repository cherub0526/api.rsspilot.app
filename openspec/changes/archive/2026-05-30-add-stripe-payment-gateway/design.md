## Context

目前系統使用 Paddle 作為唯一金流，整合深度高：`paddles` 多型資料表、PlanObserver / PriceObserver / UserObserver 在 model 事件時同步至 Paddle，SubscriptionsController 直接耦合 Paddle SDK，webhook 處理 `transaction.completed`。

Paddle 整合完整保留。本次在現有架構旁新增 Stripe，前端環境為 ElectronJS（不適合 redirect 流程）。

## Goals / Non-Goals

**Goals:**
- 新增 Stripe Billing 訂閱付款（建立、啟用、取消）
- Stripe 設為 `POST /v1/subscriptions` 預設金流
- Paddle 整合完整保留，不破壞現有行為
- SubscriptionsController 瘦身，Paddle 邏輯抽入 PaddleSubscriptionService

**Non-Goals:**
- Stripe Customer Portal
- 訂閱試用期（trial）
- 退款流程
- 遷移既有 Paddle 資料至 Stripe

## Decisions

### 1. Service 分層而非 Gateway 介面

**決定**：建立 `StripeSubscriptionService` 和 `PaddleSubscriptionService`，Controller 依 `paymentMethod` delegate。

**理由**：只有兩個金流，Strategy pattern 的介面抽象帶來不必要複雜度。Service class 足夠清楚且 YAGNI。

**替代方案**：`PaymentGatewayInterface` + 各自實作 → 過度設計。

### 2. `stripes` 獨立資料表（對稱 `paddles`）

**決定**：新增 `stripes` 資料表，schema 與 `paddles` 完全對稱，不合併為通用 `payment_gateway_details`。

**理由**：改動最小，不需搬移既有 Paddle 資料，兩套完全獨立、互不影響。

**替代方案**：通用表加 `provider` 欄位 → 需 migrate 既有資料，增加風險。

### 3. Stripe Billing + Payment Elements（非 Checkout redirect）

**決定**：使用 Stripe Subscription API（`default_incomplete`）+ Payment Elements，回傳 `client_secret`，前端 embedded 收卡。

**理由**：ElectronJS 環境下 redirect 流程需要實作 deep link（custom protocol），複雜且易出錯。Payment Elements 嵌在 renderer process，付款不離開 app，體驗與現有 Paddle modal 一致。

`confirmPayment({ redirect: 'if_required' })` 對卡片付款不觸發 redirect，Electron 無需處理 return URL。

### 4. Webhook 驅動訂閱啟用（非前端回呼）

**決定**：訂閱啟用完全由 Stripe webhook 驅動，前端付款後 polling `GET /v1/subscriptions` 確認狀態。

**理由**：webhook 是 Stripe 的可靠事件來源，不依賴前端網路狀況；`PUT /v1/subscriptions/{id}` 維持現有 Paddle 用途不變動。

### 5. Observer 各自獨立

**決定**：建立 `StripePlanObserver`、`StripePriceObserver`、`StripeUserObserver`，與現有 Paddle observers 並列註冊。

**理由**：關注點分離，Stripe 同步失敗不影響 Paddle 邏輯。

## Risks / Trade-offs

- **Webhook 未到達** → Stripe 有自動重試機制（最多 3 天），影響有限；前端可顯示「處理中」狀態
- **Observer 雙寫失敗** → Plan / Price 建立時若 Stripe API 失敗，`stripes` 表無資料，checkout 時需 fallback 建立；目前 Paddle observer 亦有同樣問題，維持靜默失敗模式
- **Raw body 驗簽** → Stripe webhook 驗證需要原始 request body（不能 JSON parse），需確認 Hypervel 框架支援取得 raw body

## Migration Plan

1. 部署資料庫 migration（新增 `stripes` 表）
2. 部署應用程式（新路由、新 service、新 observers）
3. 在 Stripe dashboard 設定 webhook endpoint：`POST /v1/webhook/stripe`，訂閱事件：`invoice.paid`、`customer.subscription.deleted`、`invoice.payment_failed`
4. 設定環境變數：`STRIPE_API_KEY`、`STRIPE_PUBLISHABLE_KEY`、`STRIPE_WEBHOOK_SECRET`
5. rollback：移除新路由、回滾 migration、不影響 Paddle 功能

## Open Questions

- Hypervel 是否支援 `$request->getContent()` 或等效方式取得 raw body（Stripe 驗簽需要）？需實作前確認。
