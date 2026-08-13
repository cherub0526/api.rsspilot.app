# Examples Library

完整範例庫。所有範例皆以繁體中文撰寫，末段保留 Co-Authored-By。

## Example 1 — 單一 bug fix（問題/原因/調整項目）

```bash
git commit -m "$(cat <<'EOF'
fix: 自訂表單新增/編輯頁面，修正離開頁面提醒邏輯

問題：
1. 原程式碼進入新增頁面後，沒做任何動作之下，離開頁面會跳提醒
2. 原程式碼從新增/編輯頁面回到上一頁後，離開頁面會跳提醒

原因：
1. 新增頁面時，頁面自動建立空白題組會調用 sort_item，造成
   初始化 unload 事件處理器。
2. 回到上一頁後，就不需要監聽 unload 事件，應該把 unload
   事件取消。

調整項目：
1. 初始化 unload 事件處理器：排除新增表單時，頁面自動建立
   空白題組調用 sort_item 的情境
2. 回到上一頁後，復原表單被異動狀態且清除 unload 事件處理器

issue #1335

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

## Example 2 — 新功能 feat（因應需求/調整項目）

```bash
git commit -m "$(cat <<'EOF'
feat: message 信件通知功能

因應新需求做調整：
通知和 message 都要寄發每日信件，通知和 message 都用放在同一
封信裡面就好，不然信件太多可能也不會有人想去看。

調整項目：
1. mail_template.php，新增 message 區塊。
2. Send_today_notify_mail.php，新增取得每日 Message 邏輯。
3. Message_model_api.php，新增 \$where 參數，以便取得每日訊息。
4. Message_api.php、Message_group_user_model_api.php，新增
   取得訊息使用者邏輯，以便撈取每日訊息。

issue #863

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

## Example 3 — 含 scope 與 BREAKING CHANGE

```bash
git commit -m "$(cat <<'EOF'
refactor(auth): 重寫 session token 儲存邏輯

為了符合新版合規要求，將 session token 從 cookie 改為由
server-side 加密後寫入 Redis，並提供短期 opaque token 給前端。

調整項目：
1. AuthMiddleware：驗證改為查詢 Redis
2. LoginController：登入成功改發 opaque token
3. 舊版 cookie-based token 全面失效

BREAKING CHANGE: 所有已登入使用者需重新登入；第三方整合若
直接使用舊 token 將回傳 401，需改呼叫 /v1/auth/refresh 取得
新 token。

issue #2045

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

## Example 4 — revert

```bash
git commit -m "$(cat <<'EOF'
revert: feat(openapi): 安裝 swagger-php 並建立文件產生機制 (回覆版本：1e6c61b)

原 commit 於 staging 環境觸發 autoload 衝突，先行回退以
解除阻塞。待 vendor 衝突釐清後會重新引入。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

## Example 5 — 純文件 docs（無 body）

```bash
git commit -m "$(cat <<'EOF'
docs: 補充 CLAUDE.md OpenAPI 使用說明

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

## Example 6 — 依賴升級 chore(deps)

```bash
git commit -m "$(cat <<'EOF'
chore(deps): 升級 zircote/swagger-php 至 6.0

升級原因：
- 取得 PHP 8.4 相容性修正，避免 implicit nullable 警告
- 新版支援 attribute-only workflow，可移除 docblock 相依

相容性檢查：
- composer openapi 正常輸出 openapi.yaml
- 既有 Controller attribute 全部保留不變
- PHPStan 無新增 error

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

## Example 7 — 效能 perf

```bash
git commit -m "$(cat <<'EOF'
perf(course): 消除課程列表的 N+1 查詢

效能瓶頸：
CourseController::index 載入每筆課程時，個別查詢
Lecturer 與 Tag，大量資料下造成 ~300 次 query。

改善方式：
在 Course::with(['lecturer', 'tags']) 加入 eager loading，
並於 Course model 補上關聯定義。

效果：
- Query 數：305 → 4
- 平均回應：820ms → 180ms

issue #2108

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

## Example 8 — 只動測試 test

```bash
git commit -m "$(cat <<'EOF'
test(auth): 補 LoginController 失敗路徑測試

補足以下情境：
- 空密碼
- 帳號不存在
- 超過每分鐘 5 次嘗試

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

## Example 9 — 一個會話切多個 commit

情境：一次會話完成了「安裝套件 + 新功能 + 文件」。

執行順序：

```bash
# 1. 依賴變動先 commit
git add composer.json composer.lock
git commit -m "$(cat <<'EOF'
chore(deps): 安裝 zircote/swagger-php

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"

# 2. 功能本體
git add app/OpenApi/ app/Controller/IndexController.php
git commit -m "$(cat <<'EOF'
feat(openapi): 建立 OpenAPI 元件目錄與首頁端點文件

- 新增 app/OpenApi/{Schemas,Responses} 元件庫
- IndexController 加入 OAT attribute

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"

# 3. 文件最後
git add CLAUDE.md
git commit -m "$(cat <<'EOF'
docs: 補充 OpenAPI 使用說明與目錄結構

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```
