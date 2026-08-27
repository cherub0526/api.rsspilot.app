# Railway 部署設定

Forge（VPS + supervisor）與 Railway 並行，兩邊**不共用建置檔**：

| | Forge / 本機 | Railway |
|---|---|---|
| 建置 | `docker/Dockerfile` | `docker/Dockerfile.railway` |
| 常駐程序 | `supervisor/*.conf` | 每個 queue 群組一個 service |
| 排程 | 系統 cron → `schedule:run` | 常駐 service（**不是** cron） |

改動 `app/`、`config/` 這類共用程式碼時兩邊都會受影響；改動 `docker/Dockerfile`
或 `supervisor/*.conf` 只影響 Forge，改動本目錄與 `docker/Dockerfile.railway`
只影響 Railway。

## Service 對照

每個 Railway service 都指向同一個 repo，差別在 **Settings → Config-as-code**
指到哪一份設定檔。

根目錄另有一份 `railway.json`，Railway 在 service 沒有指定 config 路徑時會
自動讀它。那份**刻意只寫 build 區塊**，不含 `startCommand` 也不含
`preDeployCommand`：

- 好處：任何 service 就算忘了設定 config 路徑，至少仍會用對 Dockerfile，
  不會退回自動偵測（見下方「build 失敗」段）。
- 為什麼不把 api 的設定放進去：根設定是**所有** service 的預設值，若含
  `preDeployCommand`，四個 service 每次部署都會各跑一次 migration。
  漏設路徑的 service 退化成「多開一個 API」是可接受的失誤，四路並發跑
  migration 不是。

`railway/*.json` 每一份都各自帶完整的 build 區塊，所以不論 Railway 對指定
路徑的處理是「取代」還是「與根設定合併」，結果都一樣 —— 這是刻意的，
省掉去確認它到底是哪一種。

| Service | 設定檔 | 說明 |
|---|---|---|
| `api` | `railway/api.json` | HTTP。`preDeployCommand` 跑 migration，只有這一個 service 跑 |
| `worker-fast` | `railway/worker-fast.json` | `--timeout=120` 的 queue（部分暫停中，見下方） |
| `worker-slow` | `railway/worker-slow.json` | `--timeout=300` 的 queue（部分暫停中，見下方） |
| `scheduler` | `railway/scheduler.json` | 常駐 `schedule:run`，自帶迴圈，不要設 cron |

八個 supervisor program 合併成兩個 service，是照 timeout 值切的——一個
`queue:work` 只能有一個 `--timeout`，所以 120 與 300 不能混在同一個 service。
`--queue` 的順序即優先序。

## 環境變數

```
# Port——Railway 動態注入 PORT，但 config/server.php:19 只讀 HTTP_SERVER_PORT
HTTP_SERVER_PORT=${{PORT}}

# 必填，不是選填。config/server.php:32 的 fallback 是 swoole_cpu_num()，
# 而那個函式完全看不到 cgroup 的 CPU 配額，會照宿主機核心數 fork。
# 閒置記憶體實測為 RSS ≈ 155 + 46 × N (MB)，依 service 記憶體選 N：
#   512MB → 4    1GB → 8    2GB → 16
# 完整量測與症狀見 docs/lore/framework/pitfalls.md
SERVER_WORKERS_NUMBER=4

# 用 Railway 的 Postgres plugin 時。DB_CONNECTION 預設是 mysql
# （config/database.php:20），不設就會拿 pgsql 的連線參數去接 mysql driver
DB_CONNECTION=pgsql
DB_HOST=${{Postgres.PGHOST}}
DB_PORT=${{Postgres.PGPORT}}
DB_DATABASE=${{Postgres.PGDATABASE}}
DB_USERNAME=${{Postgres.PGUSER}}
DB_PASSWORD=${{Postgres.PGPASSWORD}}

# 改用 MySQL plugin 的話換成這組
# DB_CONNECTION=mysql
# DB_HOST=${{MySQL.MYSQLHOST}}
# DB_PORT=${{MySQL.MYSQLPORT}}
# DB_DATABASE=${{MySQL.MYSQLDATABASE}}
# DB_USERNAME=${{MySQL.MYSQLUSER}}
# DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

# supervisor/README.md 的不等式仍然成立，且這是全域值，
# 必須大於所有 service 中最大的 --timeout（目前 300）
DB_QUEUE_RETRY_AFTER=360

REDIS_HOST=${{Redis.REDISHOST}}
REDIS_PORT=${{Redis.REDISPORT}}
REDIS_AUTH=${{Redis.REDISPASSWORD}}

# 是 LOG_CHANNEL（單數），不是 .env.example 裡那個 LOG_CHANNELS。
# config/logging.php:58 的 stack channel 把 channels 寫死成 ['single']，
# 不讀環境變數，所以設 LOG_CHANNELS 是 no-op——log 會寫進容器裡的
# storage/logs/hypervel.log，Railway 面板永遠看不到，重啟後檔案也沒了
LOG_CHANNEL=stderr

# 讓 log 以 JSON 輸出，Railway 才能解析成可過濾的欄位（見下方 Logs 面板段）。
# 不設就是純文字行，只能做字串搜尋
LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter
```

`REDIS_AUTH` 是這個專案自己的命名（`config/database.php:110`），照 Laravel
習慣寫成 `REDIS_PASSWORD` 不會有任何效果。詳見下方 NOAUTH 段。

**不要設 `DB_DRIVER`。** mysql 與 pgsql 兩個區塊都讀同一個
`env('DB_DRIVER')`，只是預設值不同（`config/database.php:24` 與 `:44`）。
設了它就會同時套用到兩邊，`DB_CONNECTION` 切換就失效。留空讓各自的
預設生效。

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

`--max-time=3600` 讓 worker 處理完工作後自我了結以避開記憶體累積，
**退出碼是 0**（`Worker::stopIfNecessary()` 回 `EXIT_SUCCESS`）。
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

## build 失敗：`ext-swoole is missing`

```
hypervel/framework v0.3.17 requires ext-swoole ^5.1|^6.0 -> it is missing
Build Failed: composer install --optimize-autoloader --no-scripts --no-interaction
```

這個錯**不是**缺套件，是 Railway 根本沒用到 `docker/Dockerfile.railway` ——
它退回自動偵測的 builder，自己組了一套沒有 swoole 的 PHP 環境。

兩個一眼分辨的指紋：

| | 自動偵測 builder | 本專案的 Dockerfile |
|---|---|---|
| composer 指令 | `install --optimize-autoloader --no-scripts --no-interaction` | `install --no-dev --no-scripts --no-autoloader --prefer-dist` |
| conf.d 路徑 | `/usr/local/etc/php/conf.d/`（Debian 官方 php image） | `/etc/php83/conf.d`（alpine） |

基底 image `hyperf/hyperf:8.3-alpine-v3.19-swoole-v6` 本身就帶 swoole 6.2.2，
走對 Dockerfile 不可能缺。

**修法**：到該 service 的 Settings 確認 Config-as-code 路徑指向
`railway/<service>.json`，或 Builder 設為 Dockerfile 且 Dockerfile Path 指向
`docker/Dockerfile.railway`。

**不要照錯誤訊息的建議加 `--ignore-platform-req=ext-swoole`。** 那只會讓
composer 安裝通過，建出一個沒有 swoole 的 image；Hypervel 整個執行期都建立在
Swoole 上，結果是 build 成功但服務永遠起不來，而且問題被推遲到執行期才爆。

## console 進不去：`bash: fork: retry`

```
bash: fork: retry: Resource temporarily unavailable
```

記憶體耗盡，fork 不出新行程。**第一個要查的是 `SERVER_WORKERS_NUMBER`
有沒有設**——沒設的話 Swoole 會照宿主機核心數開 worker，宿主機是 32 或
64 核時閒置就要 1.6～3.1 GB（實測見上方環境變數段）。

診斷時注意：此刻 `ps`、`top`、`free` 自己也要 fork，同樣跑不起來，
在容器裡下指令只會得到同一行錯誤。改看這兩個地方，都不需要 fork：

1. service 的 **Metrics** 分頁——記憶體是否貼著配額上限
2. service 的 **Variables** 分頁——`SERVER_WORKERS_NUMBER` 是否存在

修法是設好變數後 redeploy。若已經設了仍然發生，才往下查記憶體洩漏或
把配額調大。

## 連線報 `invalid integer value "5432}}"`

```
SQLSTATE[08006] [7] invalid integer value "5432}}" for connection option "port"
```

`${{...}}` 參照沒展開乾淨，多出來的 `}}` 被當成值的一部分帶進連線參數。
通常是括號打成兩層（`${{Postgres.PGPORT}}}}`），或從別處複製後改字時漏刪。

`DB_PORT` 之所以第一個爆，是因為 libpq 會嚴格驗證它，而
`config/database.php:47` 的 `env('DB_PORT', 5432)` **沒有** `(int)` 轉型
——同專案的 `HTTP_SERVER_PORT`、`REDIS_PORT` 都有轉，這裡沒有。

**修好 `DB_PORT` 之後要把整組變數都檢查一遍。** 同一次編輯很可能弄壞了
不只一個，只是其他欄位在連線流程中比較晚才被驗證，或者根本不驗證而是
靜默地連錯地方。

## 用 Railway 的 Logs 面板

### 先讓 log 變成結構化的

`stderr` channel 的 `formatter` 本來就讀 `env('LOG_STDERR_FORMATTER')`
（`config/logging.php:110`），所以切成 JSON **不需要改任何程式碼**，設環境
變數即可。實測兩種輸出：

不設（預設 LineFormatter）：

```
[2026-08-27 01:50:15] local.ERROR: 資料庫連線失敗 {"media_id":42,...}
```

設成 `Monolog\Formatter\JsonFormatter`：

```json
{"message":"資料庫連線失敗","context":{"media_id":42,"queue":"media.summary"},
 "level":400,"level_name":"ERROR","channel":"local","datetime":"2026-08-27T01:50:16+00:00","extra":{}}
```

差別在於後者的每個 key 都能被 Railway 解析成獨立欄位拿去過濾，前者只能做
字串比對。`context` 裡塞的東西（`media_id`、`queue`…）也一併變成可查詢的維度，
這是平常寫 `Log::error($msg, [...])` 就已經在產生、但純文字格式下用不到的資料。

**未驗證的一點**：JsonFormatter 的 `level` 是數字（400），`level_name` 才是
`ERROR`。Railway 的解析器是否認得數字型 level、會不會把嚴重度標對，我沒有
在 Railway 上實測過。若面板的嚴重度顯示不正確，改用 `@level_name:ERROR`
過濾，那個欄位是明確的字串。

### 保留期

Railway 的 log 保留期依方案而定，超過就查不到了。這是內建面板的硬限制——
需要長期保留或跨月比對就得接 log drain（`config/logging.php` 已備好
`papertrail` channel）。實際天數請以你方案的說明為準。

### 建議的分工

- `LOG_LEVEL=info` 起步。設 `debug` 在正式環境會把面板洗掉，也吃保留期額度。
- 四個 service 的 log 是分開的。查問題時先確定是 api 還是某個 worker——
  同一筆 media 的失敗可能只出現在 `worker-slow`。
- 真正要人立刻知道的錯誤，別靠盯面板。`config/logging.php:77` 已有 slack
  channel，設 `LOG_SLACK_WEBHOOK_URL` 就能把 critical 以上推出去。

## Redis 報 `NOAUTH Authentication required`

連線時沒有帶密碼。這個專案有三種寫法會導致它，而且**錯誤訊息一模一樣**，
從訊息本身分不出是哪一種。實測（Redis 開 `requirepass`，只改變數）：

| 變數設定 | 結果 |
|---|---|
| `REDIS_AUTH` 沒設 | `NOAUTH Authentication required.` |
| 設成 `REDIS_PASSWORD` | `NOAUTH Authentication required.` |
| `REDIS_AUTH=(null)` | `NOAUTH Authentication required.` |
| `REDIS_AUTH=s3kr1t` | 成功 |

三個各自的原因：

1. **欄位名不是 `REDIS_PASSWORD`。** `config/database.php:110` 讀的是
   `env('REDIS_AUTH')`。設 `REDIS_PASSWORD` 只是多了一個沒人讀的變數。
2. **`(null)` 是會被轉成 null 的字面值。** `.env.example:42` 出廠就寫著
   `REDIS_AUTH=(null)`，而 `env()` 會把字串 `(null)`、`null`、`(true)`、
   `(false)`、`(empty)` 轉成對應的 PHP 值
   （`vendor/hyperf/support/src/Functions.php:46`）。本機開發沒有密碼時
   這是對的，但整份複製到雲端環境就變成「明明設了卻等於沒設」。
3. 參照寫壞（多餘的 `}}` 之類），與 `DB_PORT` 那一題同源。

**修法**：`REDIS_AUTH=${{Redis.REDISPASSWORD}}`，並確認展開後不含多餘字元。

順帶一提，`config/database.php` 的 redis 區塊之所以生效，是因為
`FoundationServiceProvider:132` 把 `database.redis` 映射到 `redis` config
key —— Hyperf 的 redis 元件讀的是後者。專案裡沒有 `config/redis.php`
是正常的，不要為了這個錯誤去新建一份。

## scheduler 是常駐 service，不是 cron

**Hypervel 的 `schedule:run` 與 Laravel 的語意不同。** Laravel 的版本執行完
到期任務就退出，設計上由系統 cron 每分鐘呼叫一次；Hypervel 的版本是

```php
while (! $this->shouldStop()) {
    $this->runEvents($this->schedule->dueEvents($this->app), Date::now());
    Sleep::usleep(100000);      // 100ms
}
```

（`vendor/hypervel/console/src/Commands/ScheduleRunCommand.php`）——它自己
就是排程器。實測不帶 `--once` 執行，三分鐘後仍在跑，需要外力才會結束。

所以 `railway/scheduler.json` 是**常駐 service**：沒有 `cronSchedule`，
`restartPolicyType` 設 `ALWAYS`。這樣同時解掉幾個問題：不必確認 Railway
cron 的最小間隔、不必每分鐘付一次應用程式的冷啟動成本、`onOneServer()`
的鎖也只需要跟自己競爭。

要一次性行為時用 `schedule:run --once`，實測會在數秒內以退出碼 0 結束。

## worker 的 `--max-time` 在閒置時不會觸發

實測：`--max-time=8` 的 worker，在沒有任何工作進來的情況下，五分鐘後
仍在執行（CPU 約 0.7%，不是忙碌迴圈）。

也就是說**不能把 `--max-time` 當成低流量佇列的記憶體保險**。它在 worker
處理過工作之後才可靠地生效。`--memory=256` 那道防線才是閒置時仍然有效的。

`restartPolicyType: ALWAYS` 的必要性不受影響——重點是 worker 退出時的
退出碼是 0，`ON_FAILURE` 不會把它拉起來。

## 建立步驟

四個 service 都是同一個 repo，差別只在 config 路徑與變數。

### 1. 先把共用變數集中在專案層

Project → **Shared Variables**，把 DB、Redis、第三方 API 金鑰、log 設定
全放這裡，各 service 再引用（`${{shared.<NAME>}}`）。

**不要一個 service 貼一次。** 四份手貼的變數會漂移，而漂移的症狀是
「同一份程式碼在 api 正常、在 worker 半夜失敗」，很難查。

只有兩個變數是 api 專屬、不放共用：`HTTP_SERVER_PORT`、
`SERVER_WORKERS_NUMBER`。

### 2. 逐一建立 service

每個都是 New → GitHub Repo → 選這個 repo，然後在 Settings 設：

| Service | Config 路徑 | Public domain |
|---|---|---|
| `api` | `railway/api.json` | 要 |
| `worker-fast` | `railway/worker-fast.json` | 不要 |
| `worker-slow` | `railway/worker-slow.json` | 不要 |
| `scheduler` | `railway/scheduler.json` | 不要 |

設好 config 路徑後，start command、重啟策略、healthcheck 都由該檔案帶入，
不需要在面板重複填一次。面板上手動填的值會蓋掉檔案裡的，兩邊都改只會
讓「實際跑的是哪個」變得不明確。

worker 與 scheduler **不要開 public domain，也不要設 healthcheck**——
它們不聽 HTTP，healthcheck 必然失敗，會被判定部署不成功而反覆重啟。

### 3. worker 需要的不只是 DB 與 Redis

最常見的漏設：只給了 DB 和 Redis 就以為夠了。實際上 job 會直接呼叫外部
服務——轉錄、摘要、影片資訊、S3 上傳——所以 `GROQ_API_KEY`、
`OPENAI_API_KEY`、`OPENROUTER_API_KEY`、`RAPID_API_KEY`、`YOUTUBE_API_KEY`、
`VIDEOTRANSCRIBER_*`、`AWS_*` 這些在 worker service 上一個都不能少。

漏掉的症狀是 job 進了 failed_jobs 而 api 完全正常，通常要等到有人回報
「影片一直沒有摘要」才會發現。

最省事的做法是共用變數全部引用到四個 service，只有上面那兩個 api 專屬的
例外。多給不會出事，少給會。

### 4. 部署順序

先 `api`——它的 `preDeployCommand` 會跑 migration，把 `jobs`、`failed_jobs`
等資料表建起來。worker 在資料表存在前啟動會一直報錯重啟。

migration 只掛在 `api` 一個 service 上，其餘三個不要加，否則同一次部署會
有四路並發跑 migration。

## 目前暫停中的 queue

`media.info`、`media.caption`、`media.youtube-data-caption`、`rss.sync`
四個 queue 已從 worker 的 `--queue` 清單移除，**沒有任何 worker 會消化它們**。

| Service | 仍在處理 | 已移除 |
|---|---|---|
| `worker-fast` | `videotranscriber.start`, `videotranscriber.fetch` | `media.info`, `media.caption`, `media.youtube-data-caption` |
| `worker-slow` | `media.summary`, `videotranscriber.smart-summary` | `rss.sync` |

兩個 service 都保留，因為各自還有 queue 要跑，`--timeout` 的分組也沒變。
要恢復就是把名字加回 `--queue` 清單，順序即優先序。

### 這四個之中，只有兩個實際上有工作

查過派工端之後：

| Queue | 誰派工 | 暫停的實際影響 |
|---|---|---|
| `media.info` | **無** | 無。`InfoJob` 在 `app/` 內沒有任何 dispatch 點 |
| `media.youtube-data-caption` | **無** | 無。`YoutubeDataCaptionJob` 同上 |
| `media.caption` | `SyncJob:175` → `YoutubeCaptionJob` | 有，但源頭是 `rss.sync` |
| `rss.sync` | `RSSController:199`（使用者訂閱 feed）、`rss:sync` 指令 | 有 |

`InfoJob`、`CaptionJob`、`YoutubeDataCaptionJob` 三個 job 類別在
`app/`、`routes/`、`database/` 底下都找不到 dispatch 點——它們是改用
videotranscriber.ai 之前的遺留物。所以 `media.info` 與
`media.youtube-data-caption` 本來就是空的佇列，移除與否沒有差別。

還有一個連鎖效應：`media.caption` 的工作是由 `SyncJob` 派出的，而 `SyncJob`
跑在已暫停的 `rss.sync` 上。也就是說停掉 `rss.sync` 之後，`media.caption`
連新工作都不會產生。

### 累積與恢復

暫停期間派出的工作**不會遺失**，它們留在 `jobs` 資料表裡等消化。恢復時會
一次湧入，若已累積很多，先確認外部 API 的速率限制撐得住。

注意 `ShouldBeUnique` 的行為：工作在佇列中排隊時仍持有唯一鎖，同一個對象
的重複 dispatch 會被丟棄。所以長期暫停不等於「恢復後把期間所有事件補跑一
遍」，而是每個對象大約留下一筆。
