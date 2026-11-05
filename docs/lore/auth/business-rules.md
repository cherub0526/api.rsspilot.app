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
| sanctum | 使用者的 AI 工具 | 不過期，只能手動刪；重設密碼、刪除帳號時一併刪除 | 只有 `/mcp`，唯讀 |

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
一次」的體驗倒退。被偷的 token 雖然能一直換發下去，使用者重設密碼就能切斷整條換發鏈
（見〈重設密碼、刪除帳號會作廢這個使用者所有的登入憑證〉），所以絕對上限可以晚一點再決定。

要改成「有絕對上限」的話，動的地方是 `RefreshController`：它現在用 `login()` 重發，
`iat` 會跟著更新；要有上限就得改回保留原始 `iat` 並自行比對 `refresh_ttl`。

## 登出是純前端行為

`code:` `app/Http/Controllers/API/V1/Auth/LogoutController.php` → `store` · `updated:` `2026-11-05` · `status:` `active`

`POST /v1/auth/logout` 只回 `OK.`，不撤銷任何東西——`jwt.blacklist_enabled` 是 `false`，
撤銷機制根本沒開。實際的登出是前端刪掉 localStorage 裡的 token，而**舊 token 到 `exp`
為止仍然可用**。換發後的舊 token 同理。

接受這件事的前提是 TTL 夠短，**而且帳號被盜時有別的方法切斷**：重設密碼會作廢這個使用者
所有的 JWT 與 API key（見下一條）。單一裝置的登出仍然不撤銷。

要讓「登出」也能撤銷，得開 `blacklist_enabled`（需要可用的 cache storage），並注意
`blacklist_grace_period=0` 會讓「換發當下正在飛的並行請求」拿著剛被加進黑名單的舊 token
撞牆。黑名單只能作廢「被送進來的那一張」，所以它解決不了「伺服器不知道攻擊者手上是哪一張」
的情況，那是下一條的工作。

## 重設密碼、刪除帳號會作廢這個使用者所有的登入憑證

`code:` `app/Auth/JwtGuard.php` → `user` · `code:` `app/Models/User.php` → `invalidateTokens`、`acceptsTokenIssuedAt` · `updated:` `2026-11-05` · `status:` `active`

密碼常常就是因為外洩才重設的，所以重設之前發出的憑證都要失效。做法是每個使用者一個
失效時間點 `users.tokens_invalid_before`，不是黑名單：

- **JWT**：jwt guard 換成 `App\Auth\JwtGuard`（在 `AppServiceProvider` 用 `Auth::extend('jwt')`
  註冊），解析使用者時多比對 `iat < tokens_invalid_before` 就當作沒登入，回一樣的 401。
  `/v1/auth/refresh` 也掛 `auth`，所以舊 token 換不到新的，換發鏈就此斷掉。
- **API key**：sanctum 的 key 不會過期，比 JWT 更長效，直接刪掉，使用者要重新產生。
- **照秒比，同一秒放行**：`iat` 只到秒。重設回應裡發的新 token 和失效時間同一秒，必須能用；
  代價是同一秒內、在重設之前簽發的舊 token 也會被放行，security 接受。
- **觸發點**：`ForgotPasswordController::update`（重設成功之後；只申請重設信不算，任何人都能
  替別人申請）、`UserObserver::deleted`（soft delete 本來就擋得住，這裡是讓帳號被還原時舊憑證
  不會復活）、`security:revoke-social-access --apply`（被冒用的帳號，攻擊者手上可能還有登入憑證）。
  目前沒有「登入狀態下修改密碼」的端點；以後加這個、或加「登出所有裝置」，都要呼叫
  `User::invalidateTokens()`。
- **刪 API key 要逐筆刪**，不要用 `tokens()->delete()` 批次刪：sanctum 只在 `PersonalAccessToken`
  的 deleting 事件裡清 token 快取，批次刪除不會觸發。`SANCTUM_CACHE_ENABLED` 現在是關的，一旦
  打開，被刪的 key 會繼續有效到 `SANCTUM_CACHE_TTL`（預設一小時）。
- `users:purge-unverified` 用 query 批次刪除，不會觸發 observer；未驗證帳號拿不到 JWT、
  也建不了 API key，所以沒有要作廢的東西。
- `App\Auth\JwtGuard::user()` 照抄父類別再加一道檢查，**升級 `hypervel/auth` 時要對照**。

## Google 登入只走 OAuth callback，不可新增相信客戶端身分的登入端點

`code:` `app/Services/SocialAccountService.php` → `resolveUser()` · `code:` `routes/v1.php`（`oauth` 群組） · `updated:` `2026-11-04` · `status:` `active`

Google 登入唯一的入口是 `POST /v1/oauth/{provider}/callback`：後端拿 authorization code 跟 Google 換身分，`provider_id` 與 email 都是 Google 驗證過的值，
`SocialAccountService` 同時把憑證寫進 `oauths`。

以前另有一條舊端點，直接收客戶端送來的 `provider_id`、email、name 就登入或建帳號，從來不跟 Google 確認（security-hotfix-1 的 C1，2026-11-04 刪除）：

- 知道某人的 Google `sub` 就能登入他的帳號。
- 可以用別人的 email 預先建帳號、埋進自己的 `provider_id`，等本人經 OAuth 登入、以 email 合併後，攻擊者仍能登入。

規則：

- 任何「用社群身分登入」都必須經過 provider 的伺服器端驗證（OAuth code 交換或驗簽的 ID token），不可以只相信 request body。
- 前端自 2026-08-30（`bfdc6ab`）起就只走 OAuth；舊端點留著沒人用，就成了攻擊面。不用的登入路徑要刪，不要留著「以防萬一」。
- 判斷帳號是否經過舊端點：只有 OAuth callback 會寫 `oauths`，所以 google 帳號沒有 google 的 `oauths` 資料列，就代表沒走過 callback。
  但 2026-08-30 前正常註冊、之後沒再登入的使用者也長這樣，清查時要按 `created_at` 分開看。撤銷用 `security:revoke-social-access`（預設 dry-run；`--apply` 會登出該帳號的所有裝置、刪除 API key）。
