## Context

`sources` API 群組目前只有列表端點（`GET /v1/sources`），對已驗證使用者回傳六個基本欄位的所有已訂閱來源。前端的單一來源詳細檢視需要取得完整資訊，包含 `description` 與 `subscriber_count`，而不需載入整份清單。這兩個欄位已儲存於 `Source` 模型（`description` 欄位與 `metadata['subscriber_count']`），但目前沒有任何端點揭露它們。

目前 controller：`app/Http/Controllers/API/V1/SourcesController.php`  
目前 resource：`app/Http/Resources/SourceResource.php`（由 `index()` 使用）

## Goals / Non-Goals

**Goals:**
- 新增 `GET /v1/sources/{sourceId}`，回傳單一來源的所有詳細欄位
- 強制存取控制：免費來源（`Source::free = true`）或使用者已訂閱的來源才可存取，否則回傳 404
- 遵循現有 OAT 註解模式撰寫 OpenAPI 文件
- 新 resource 類別獨立於 `SourceResource`（不繼承）

**Non-Goals:**
- 修改 `index()` 以包含 `description` 或 `subscriber_count`
- 建立可重用的獨立 `SourceDetail` OpenAPI schema 類別
- 變更 `metadata` 的填充或同步方式

## Decisions

### 1. 獨立的 `SourceDetailResource`（不繼承 `SourceResource`）

**決策**：建立 `app/Http/Resources/SourceDetailResource.php`，擁有自己的 `toArray()` 實作。

**理由**：`SourceResource` 與 `SourceDetailResource` 在 8 個欄位中只共用 5 個。繼承後再覆寫不僅不能減少程式碼，還會耦合兩個類別——未來任何對 `SourceResource` 的修改都可能靜默影響詳細回應。共用邏輯極少，此處的重複是可接受的。

**考慮過的替代方案**：繼承 `SourceResource` 並在 `toArray()` 新增欄位——因耦合過緊而拒絕。

### 2. 透過「免費來源 OR 已訂閱來源」進行存取控制

**決策**：來源可存取的條件為 `Source::free = true` 或已驗證使用者已訂閱該來源；兩者皆否則回傳 404。

**理由**：免費來源對所有已驗證使用者公開（與訂閱模型一致）。對未訂閱且非免費的來源，選擇回傳 404 而非 403，以避免向未訂閱使用者洩漏來源是否存在（資訊隱藏原則）。

### 3. 在 `show()` 註解中使用 inline OpenAPI schema

**決策**：在 `show()` 的 `#[OAT\Get(...)]` 註解中直接定義回應 schema，而非建立獨立的 schema 類別。

**理由**：此 schema 僅被這個端點使用。建立獨立 schema 類別只會增加檔案開銷而無重用價值。與設計規格「此範疇不需要獨立 schema 類別」一致。

### 4. `{sourceId}` 路由參數的 ULID 正規表示式限制

**決策**：對 `sourceId` 路由參數套用正規表示式 `[0-7][0-9a-hjkmnp-tv-z]{25}`。

**理由**：與 `sources` 群組中其他路由一致。避免任意字串傳入 controller，格式無效時直接回傳乾淨的 404（路由不匹配）。

## Risks / Trade-offs

- **`metadata` 欄位缺失** → `subscriber_count` 對頻道來源退回 `0`（非 `null`）；播放清單來源應回傳 `null`，因為此欄位在語義上無意義。這個不對稱性必須在 resource 的 `toArray()` 註解中說明。
- **免費來源可見性** → 免費來源對所有已驗證使用者可見，與訂閱狀態無關。若業務規則變更（例如免費來源改為付費），`show()` 中的存取控制邏輯必須獨立於 `index()` 進行更新。
- **未檢查 `Source::status`** → 端點不依 `status` 過濾。只要通過存取控制，非啟用或草稿狀態的來源也會被回傳。此行為依設計規格為刻意設計，但可能將使用者尚無法使用的來源暴露出去。
