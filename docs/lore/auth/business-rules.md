---
area: auth
kind: business-rules
---

# auth — Business rules

<!--
Each rule is a `## heading` + a one-line meta + the body. Capture the "why" code can't show.

## Name of the rule

`code:` `path/to/file.ext` → `symbol` · `updated:` `YYYY-MM-DD` · `status:` `active`

The rule, the reasoning, and edge cases.
-->

## API key 與登入憑證是兩套東西，不共用 guard

`code:` `config/auth.php`、`app/Http/Controllers/API/V1/Users/ApiKeysController.php` · `updated:` `2026-09-21` · `status:` `active`

自家前端（web 與 Electron）走 **jwt** guard：短效、可 refresh。使用者自己產生的
API key 走 **sanctum** guard：長期有效、貼在第三方工具的設定檔裡（見 `/mcp`）。
兩者刻意不互通：

| | 誰在用 | 有效期 | 能做什麼 |
|---|---|---|---|
| jwt | 自家前端 | 短，可 refresh | 全部 API |
| sanctum | 使用者的 AI 工具 | 不過期，只能手動刪 | 只有 `/mcp`，唯讀 |

**產生與刪除 key 的端點自己走 jwt**（`/v1/users/api-keys`）。用一把 key 去產生下一把
的話，一把外洩的 key 就能自我續命，撤銷也就失去意義。

`/mcp` 只認 sanctum（`auth:sanctum`，不是 `auth`）。讓 jwt 也能進去等於鼓勵使用者
把前端的 token 從瀏覽器挖出來貼到第三方工具，那是一把能改帳號設定、能結帳的憑證。

明文只在建立當下回一次，資料庫存的是 sha256——**連我們自己都讀不回來**。這是這類
憑證值得信任的原因，所以前端也不做「再看一次」的功能，只能刪掉重產。

token 帶 `rsp_` 前綴（`config/sanctum.php`）：GitHub 一類的 secret scanning 靠前綴
認出這是一把憑證，使用者不小心 commit 進公開 repo 時會收到通知。注意實際格式是
`<id>|rsp_<random>`，前綴在 `|` 之後而不是整串的開頭。


## Session 沒有絕對上限，只要持續使用就不會過期

`code:` `app/Http/Controllers/API/V1/Auth/RefreshController.php` → `store` · `updated:` `2026-09-16` · `status:` `active`

存取憑證的 TTL 是 `JWT_TTL`（正式站 300 分鐘），前端會在剩餘時間低於 TTL 的 20%
時自動呼叫 `POST /v1/auth/refresh` 換一張新的。換發沒有次數或總時長上限，所以
**一個天天開 app 的使用者永遠不會被登出**，只有連續閒置超過一個 TTL 才需要重新登入。

這是刻意的，不是疏漏。`config/jwt.php` 的 `JWT_REFRESH_TTL=20160`（14 天）看起來像
上限，但 `hypervel/jwt` **沒有任何 validation 在檢查它**——`jwt.validations` 只有
`RequiredClaims` 與 `ExpiredClaim`，前者的 `required_claims` 也沒有把 `exp` 列進去。
我們選擇不自己補這個檢查：換發只在 token 還活著時可行（`/v1/auth/refresh` 掛 `auth`
middleware），憑證外流的曝險窗口仍然是一個 TTL，而硬加 14 天上限換來的是「每兩週被踢出
一次」的體驗倒退。

要改成「有絕對上限」的話，動的地方是 `RefreshController`：它現在用 `login()` 重發，
`iat` 會跟著更新；要有上限就得改回保留原始 `iat` 並自行比對 `refresh_ttl`。

## 登出是純前端行為

`code:` `app/Http/Controllers/API/V1/Auth/LogoutController.php` → `store` · `updated:` `2026-09-16` · `status:` `active`

`POST /v1/auth/logout` 只回 `OK.`，不撤銷任何東西——`jwt.blacklist_enabled` 是 `false`，
撤銷機制根本沒開。實際的登出是前端刪掉 localStorage 裡的 token，而**舊 token 到 `exp`
為止仍然可用**。換發後的舊 token 同理。

接受這件事的前提是 TTL 夠短。要真的能撤銷，得開 `blacklist_enabled`（需要可用的
cache storage），並注意 `blacklist_grace_period=0` 會讓「換發當下正在飛的並行請求」
拿著剛被加進黑名單的舊 token 撞牆。
