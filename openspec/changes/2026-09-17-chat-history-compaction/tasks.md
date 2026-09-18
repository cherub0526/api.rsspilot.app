> 全部未勾選：這份 change 尚未核准。階段 1 不列在這裡，它已於 2026-09-17 隨安全性修正完成。

## 1. 先量再做

- [ ] 1.1 量測實際的 session 輪次分布（`chat_messages` 依 session_id 分組計數），確認超過 10 輪的對話佔比
- [ ] 1.2 依分布決定只做階段 2，或連階段 3 一起做

## 2. 階段 2：滑動視窗

- [ ] 2.1 `configs` 新增視窗上限的設定值（沿用 `App\Models\Config` 的 key／value 形式），附 migration 寫入預設值
- [ ] 2.2 `ChatController::historyOf()` 依設定只取最近 N 輪；設定缺漏或為 0 時視為不限制
- [ ] 2.3 Feature test：歷史超過 N 輪時只有最近 N 輪進入 prompt，且序列仍以 user 開頭、嚴格交替
- [ ] 2.4 Feature test：調整設定值即時生效（不需重啟）

## 3. 階段 3：單一滾動總結（條件性，依 1.2 決定）

- [ ] 3.1 Migration：`chat_sessions` 新增 `summary`、`summary_through_message_id`
- [ ] 3.2 新增「更新既有總結」的 prompt 模板——輸入是既有總結 + 新的 N 輪，輸出是同一份結構，明確要求保留數字／名詞／結論，並寫死長度上限
- [ ] 3.3 壓縮 job：`ShouldBeUnique` 綁 session；寫回時以 `summary_through_message_id` 當樂觀鎖，值已被改就整批放棄
- [ ] 3.4 `ChatController::historyOf()` 改為「`summary` + `summary_through_message_id` 之後的原文」；沒有總結時退化成全部原文
- [ ] 3.5 未壓縮原文超過 2N 輪時丟掉最舊的（失敗路徑的硬上限，壞掉的 job 不能讓歷史無限長大）
- [ ] 3.6 某一輪回答完成後，視門檻派工壓縮
- [ ] 3.7 決定總結這個用途的模型來源（是否登記進 `configs.openrouter_models`）
- [ ] 3.8 確認總結請求**不**扣使用者的每日額度
- [ ] 3.9 Feature test：第 N+1 輪起歷史 = 一則總結 + 最近 N 輪原文，且輪數再多也不增長
- [ ] 3.10 Feature test：第二次壓縮會覆寫同一則總結，不會變成兩則
- [ ] 3.11 Feature test：總結尚未產生時退回原文歷史，不阻塞提問
- [ ] 3.12 Feature test：壓縮持續失敗時，未壓縮原文仍被硬上限截斷
- [ ] 3.13 Feature test：`chat_messages` 不因壓縮而減少（前端歷史列表不受影響）

## 4. 收尾

- [ ] 4.1 把最終決定與實測數字回寫 `docs/lore/prompts/`
