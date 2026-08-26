# Railway 部署設定

Forge（VPS + supervisor）與 Railway 並行，兩邊**不共用建置檔**：

| | Forge / 本機 | Railway |
|---|---|---|
| 建置 | `docker/Dockerfile` | `docker/Dockerfile.railway` |
| 常駐程序 | `supervisor/*.conf` | 每個 queue 群組一個 service |
| 排程 | 系統 cron → `schedule:run` | Railway cron service |

改動 `app/`、`config/` 這類共用程式碼時兩邊都會受影響；改動 `docker/Dockerfile`
或 `supervisor/*.conf` 只影響 Forge，改動本目錄與 `docker/Dockerfile.railway`
只影響 Railway。

## Service 對照

每個 Railway service 都指向同一個 repo，差別在 **Settings → Config-as-code**
指到哪一份設定檔。

| Service | 設定檔 | 說明 |
|---|---|---|
| `api` | `railway/api.json` | HTTP。`preDeployCommand` 跑 migration，只有這一個 service 跑 |
| `worker-fast` | `railway/worker-fast.json` | 原 supervisor 中 `--timeout=120` 的五個 queue |
| `worker-slow` | `railway/worker-slow.json` | 原 supervisor 中 `--timeout=300` 的三個 queue |
| `scheduler` | `railway/scheduler.json` | cron `* * * * *` → `schedule:run` |

八個 supervisor program 合併成兩個 service，是照 timeout 值切的——一個
`queue:work` 只能有一個 `--timeout`，所以 120 與 300 不能混在同一個 service。
`--queue` 的順序即優先序。

## 環境變數

```
# Port——Railway 動態注入 PORT，但 config/server.php:19 只讀 HTTP_SERVER_PORT
HTTP_SERVER_PORT=${{PORT}}

# 必填，不是選填。config/server.php:32 的 fallback 是 swoole_cpu_num()，
# 而那個函式完全看不到 cgroup 的 CPU 配額（實測見 docs/lore/framework/pitfalls.md），
# 不設會 fork 出遠超配額的 worker，症狀是 OOM 而不是明確錯誤
SERVER_WORKERS_NUMBER=2

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

# supervisor/README.md 的不等式仍然成立，且這是全域值，
# 必須大於所有 service 中最大的 --timeout（目前 300）
DB_QUEUE_RETRY_AFTER=360

REDIS_HOST=${{Redis.REDISHOST}}
REDIS_PORT=${{Redis.REDISPORT}}
REDIS_AUTH=${{Redis.REDISPASSWORD}}

# 容器重啟後檔案就沒了，且 Railway log 面板只收 stdout/stderr
LOG_CHANNELS=stderr
```

`REDIS_AUTH` 是這個專案自己的命名（`config/database.php:110`），照 Laravel
習慣寫成 `REDIS_PASSWORD` 會靜默地連到無密碼模式然後失敗。

其餘 key 照 `.env.example` 補齊。以下這幾個要換成 Railway 網域，並回到各家
後台同步更新：`APP_URL`、`CLIENT_URL`、`FACEBOOK_REDIRECT_URL`、
`GOOGLE_REDIRECT_URL`、Stripe webhook endpoint。

## 三個一定會踩到的地方

### 沒有 `.env` 檔會直接開不起來

`DotenvManager::load()` 呼叫的是 `Dotenv->load()` 而不是 `safeLoad()`
（`vendor/hyperf/support/src/DotenvManager.php:34`），找不到檔案就丟
`InvalidPathException`，在 `artisan` 的 `ClassLoader::init()` 階段中斷，
連 config 都還沒讀。

所以 `Dockerfile.railway` 有一行 `RUN touch .env`。這個檔案**必須保持全空**：
`env()` 的實作就是 `getenv()`（`vendor/hyperf/support/src/Functions.php:46`），
Railway 注入的變數本來就讀得到；repository 建成 `immutable()`，即使 `.env`
有值也不會覆蓋注入值，但空檔能讓「設定到底來自哪裡」只有一個答案。

完整說明見 `docs/lore/framework/pitfalls.md`。

### worker 的 restart policy 必須是 `ALWAYS`

`--max-time=3600` 讓 worker 每小時自我了結一次以避開記憶體累積，**退出碼是 0**。
Railway 的 `ON_FAILURE` 不會重啟正常退出的容器，worker 會在第一個小時後
安靜地永久停擺，而 service 顯示為成功部署。

### 長 job 會在每次部署時被砍

`supervisor/README.md` 靠 `stopwaitsecs > --timeout` 保證部署時不會 SIGKILL
掉正在跑的 job。Railway 沒有對應的旋鈕——它送 SIGTERM 後的寬限期遠短於 300 秒。

也就是說每次部署都可能砍掉執行中的 `media.summary` 或
`videotranscriber.smart-summary`。依 `docs/lore/transcription/pitfalls.md`，
被砍掉的 `ShouldBeUnique` job 不會釋放鎖，該筆 media 的後續 dispatch 會被
靜默丟棄直到 `uniqueFor` 過期。

目前沒有處理，先當成已知代價；要根治得讓這兩個 job 可重入。

## healthcheck 只驗活著

`healthcheckPath` 指向 `/api`（`IndexController@index`），不碰 DB 也不碰 Redis。
這是刻意的——把相依服務納入 healthcheck 會讓 DB 短暫抖動變成整個 API 的
重啟迴圈。要的是 liveness，不是 readiness。

`healthcheckTimeout` 給到 120 秒，因為 Hyperf 開機時要即時產生 DI proxy class
到 `runtime/`，冷啟動比一般 PHP 應用慢。
