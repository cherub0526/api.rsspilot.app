---
area: framework
kind: pitfalls
---

# framework — Pitfalls

## 讀 null 的屬性不是 warning，是 500

`code:` `vendor/hypervel/foundation/src/ConfigProvider.php` · `code:` `vendor/hyperf/exception-handler/src/Listener/ErrorExceptionHandler.php` · `updated:` `2026-08-14` · `status:` `active`

在這個專案裡，PHP 的 warning 等級錯誤**不是**可以無視的雜訊 —— 它們會變成 500。

`Hyperf\ExceptionHandler\Listener\ErrorExceptionHandler` 監聽 `BootApplication`，在啟動時
`set_error_handler()` 掛上一個「只要 `error_reporting() & $level` 就 `throw new ErrorException`」
的處理器。本專案執行期 `error_reporting()` 是 `22527`（= `E_ALL & ~E_DEPRECATED & ~E_STRICT`），
`E_WARNING` 在其中，所以下列全部會炸成 500 而不是印個警告繼續跑：

- `$maybeNull->prop`（Attempt to read property "x" on null）
- `$null['key']`（Trying to access array offset on null）
- 未定義的陣列索引、未定義變數

**為什麼從專案裡看不出來**：這個 listener 由 `vendor/hypervel/foundation/src/ConfigProvider.php`
自動註冊，`config/` 底下完全沒有它的痕跡，也沒有 `config/autoload/listeners.php` 可翻。
而且實作放在 `vendor/hyperf/`，所以 `grep -r set_error_handler vendor/hypervel/` 會落空 ——
很容易因此得出「這個框架沒有把 warning 轉例外」的錯誤結論。要找請 grep `vendor/hyperf/`。

**怎麼避**：用 `??` 或 `?->`。注意 `??` 是 isset 語意，連「對 null 讀屬性」都會被一併抑制，
所以 `$null->text ?? ''` 是安全的，而少了 `??` 的 `$null->text` 就是 500。

**驗證時的陷阱**：`php artisan tinker` **不適用**這條規則 —— PsySH 會裝自己的 error handler，
同一段程式在 tinker 裡只會印 warning 然後正常回傳。曾經因此誤判「這行不會炸」。要確認請發
真正的 HTTP 請求，或寫成 feature test。

實例見 `git show 9986198`：`ChatController` 直接讀 `setting()->first()->data['ai']['language']`，
對沒有 settings 資料列的帳號回傳 500。

## 部署後噴 undefined method，先懷疑行程沒完整重啟

`code:` `config/app.php` → `scan_cacheable` · `updated:` `2026-08-14` · `status:` `active`

症狀：部署新版後，production 拋

```
Call to undefined method Hyperf\Database\Query\Builder::<你剛加的方法>()
  #0 Query/Builder.php: throwBadMethodCallException()
  #1 Model/Builder.php(165): Query\Builder->__call()
  #2 Model/Model.php(197): Model\Builder->__call()
  #3 app/Http/Controllers/.../XxxController.php(NNN): Model->__call()
```

**訊息會誤導人**：在 model 上呼叫不存在的方法時，Hyperf 的 `Model::__call()` 會一路轉發給
query builder，最後由 `Query\Builder` 報錯。所以錯誤裡出現的是 `Hyperf\Database\Query\Builder`
而不是你的 model，很容易被誤讀成「relation 寫錯了」或「查詢方法拼錯」。實際上是**那個 model
類別根本沒有這個方法**。

原因：Swoole 是長駐行程。`git pull` 換掉磁碟上的檔案，但行程裡的類別定義是**啟動當下**載入的。
沒有完整重啟，跑的就還是舊的類別 —— 而且可能出現「A 檔是新的、B 檔是舊的」這種混合狀態。

**怎麼一眼判斷是不是這個**：比對「呼叫端的行號」與「新方法是哪個 commit 加的」。若新方法在
*較早*的 commit 就加入，而堆疊的行號只有*較晚*的 commit 才會出現，那 git 不可能產生這種組合
（任何含後者的 checkout 必然也含前者）→ 磁碟檔案是對的，是行程沒重啟。

確認指令，直接看磁碟而不是猜：

```bash
grep -c "function <方法名>" app/Models/Xxx.php
# 回 1 → 檔案是新的，重啟／快取問題
# 回 0 → 部署根本沒把檔案送過去，要查的是部署流程
```

解法：

```bash
rm -rf runtime/container        # 見下段
sudo supervisorctl restart <program>   # 完整 restart，不是 reload
```

`runtime/container` 要一起清的原因：`config/app.php` 的 `scan_cacheable` 是
`env('SCAN_CACHEABLE', false)`。該環境若設成 `true`，Hyperf 會沿用 `runtime/container` 下的
`classes.cache` / `scan.cache` 而不重新掃描，只重啟不清快取仍可能吃到舊的類別資訊。

真實案例：2026-08-13，`User::aiLanguageName()` 隨 `9986198` 上線後，production 對
`ChatController.php:135` 噴這個錯；磁碟 `grep` 回 1，清快取 + 完整重啟後恢復 200。
