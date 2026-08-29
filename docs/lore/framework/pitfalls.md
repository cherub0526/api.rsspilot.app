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

## 沒有 `.env` 檔不是「用預設值」，是開機直接中斷

`code:` `vendor/hyperf/support/src/DotenvManager.php` → `load()` · `code:` `vendor/hypervel/foundation/src/ClassLoader.php:55` · `updated:` `2026-08-27` · `status:` `active`

容器化部署時很自然會想「設定全用環境變數注入，不放 `.env` 檔」。這個專案**不行** ——
少了 `.env` 檔，應用程式在讀到任何 config 之前就死了。

`DotenvManager::load()` 呼叫的是 `Dotenv->load()` 而**不是** `safeLoad()`。兩者的差別只在
後者會 catch `InvalidPathException`：

```php
// vendor/vlucas/phpdotenv/src/Dotenv.php
public function safeLoad() {
    try { return $this->load(); }
    catch (InvalidPathException $e) { return []; }   // 抑制例外
}
```

呼叫點在 `ClassLoader::init()`（`artisan` 第 21 行就執行），所以檔案不存在時例外從
`artisan` 頂層拋出，config、logging、exception handler 全都還沒建立起來 —— 錯誤訊息只有一行
裸露的 stack trace，看不出跟 `.env` 有關。

**解法是放一個空檔**，不是把設定寫回去：

- `env()` 的實作就是 `getenv()`（`vendor/hyperf/support/src/Functions.php:46`），注入到 process
  env 的變數本來就讀得到，不需要經過 `.env`。
- repository 建成 `immutable()`（`DotenvManager::getDotenv()`），`.env` 裡的值**不會**覆蓋已經
  存在的環境變數。所以即使檔案有內容也不影響注入值 —— 但保持全空能讓「設定來自哪裡」只有
  一個答案，省掉日後對著兩份來源 debug。

實例：`docker/Dockerfile.railway` 的 `RUN touch .env`。

## `swoole_cpu_num()` 看不到容器的 CPU 配額

`code:` `config/server.php` → `Constant::OPTION_WORKER_NUM` · `updated:` `2026-08-27` · `status:` `active`

`config/server.php` 的 worker 數預設是 `env('SERVER_WORKERS_NUMBER', swoole_cpu_num())`。
在容器裡跑時，那個 fallback **不能信** —— `swoole_cpu_num()` 回報的是核心可見的 CPU 數，
與 cgroup 的 CPU 配額無關。

實測（同一個 image，只改 `--cpus`）：

| 容器設定 | `/sys/fs/cgroup/cpu.max` | `swoole_cpu_num()` |
|---|---|---|
| 無限制 | `max 100000` | 2 |
| `--cpus=1` | `100000 100000` | 2 |
| `--cpus=0.5` | `50000 100000` | 2 |

配額改了三種，回報值一次都沒動。在配額 0.5 CPU 的環境上它仍會叫 Swoole 開 2 個 worker；
換到核心可見數更大的宿主機（雲端共用機器動輒 32、64 核）就是幾十個 worker 擠在一份小配額裡，
每個都吃一份記憶體。症狀是 OOM 或整體延遲暴增，而不是明確的錯誤。

**任何容器環境都要顯式設定 `SERVER_WORKERS_NUMBER`**，把它當必填而不是選填。
Forge 那種獨佔 VPS 的部署不受影響，fallback 在那裡是對的。

### 要設多少：實測的記憶體成本

同一個 image 只改 `SERVER_WORKERS_NUMBER`，啟動後立即量測（尚未接流量）：

| workers | 容器內行程數 | 總 RSS |
|---|---|---|
| 2 | 7 | 248 MB |
| 16 | 22 | 878 MB |
| 64 | 70 | 3104 MB |

三點吻合 **RSS ≈ 155 + 46 × N (MB)** —— 約 155 MB 固定成本，每個 worker
再加約 46 MB。行程數是 `N + 6`（master、manager 與其他常駐行程）。

這是**閒置**值，實際還要留出接流量後每個 coroutine 的配置空間，所以照配額
除以 46 算出的上限要再打折。粗略的起點：

| 容器記憶體 | 建議 N | 閒置 RSS |
|---|---|---|
| 512 MB | 4 | 約 339 MB |
| 1 GB | 8 | 約 523 MB |
| 2 GB | 16 | 約 878 MB |

**症狀長什麼樣**：記憶體耗盡時最先失敗的通常不是應用程式本身，而是
「再開一個行程」這件事。從 console 連進容器會看到

```
bash: fork: retry: Resource temporarily unavailable
```

這是 fork 失敗，不是 shell 壞掉 —— 而且此時 `ps`、`top` 這類診斷指令
自己也要 fork，同樣跑不起來，很容易誤判成「機器掛了」。要確認請改看
平台的記憶體圖表與環境變數設定，不要試圖在容器裡下指令。

## Mailable 的 public 屬性會蓋掉 `view()` 傳進去的同名資料

`code:` `vendor/hypervel/mail/src/Mailable.php` → `buildViewData()` · `code:` `app/Mail/DailyDigestMail.php` · `updated:` `2026-08-29` · `status:` `active`

Mailable 有兩個管道可以把資料送進版型，而它們**不是對等的**：

```php
public function buildViewData(): array
{
    $data = $this->viewData;                       // ← ->view($view, [...]) 傳進來的
    // ...
    foreach ((new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $data[$property->getName()] = $property->getValue($this);   // ← public 屬性後寫，蓋過上面
    }
    return $data;
}
```

public 屬性（含建構子 promoted 的 `public readonly`）在後面才寫進 `$data`，所以**同名時是屬性贏**，
`->view('...', ['videos' => $compiledArray])` 傳的值會被靜靜換掉。

**症狀離原因很遠**：版型不會報「變數被覆蓋」，只會在型別不合的地方炸。實例是
`DailyDigestMail` —— 建構子收 `public readonly Collection $videos`（Media 模型集合），
`build()` 又把整理成陣列的清單以 `videos` 傳給版型。版型 `@foreach ($videos as $video)` 拿到的
其實是模型集合，`$video['title']` 因為 Media 有這個欄位所以照樣印得出來，一路要到
`@foreach ($video['keyPoints'] ...)` 才因為 null 而 fatal。看起來像「摘要資料沒帶到」，
實際上是變數整個被換掉了。

**怎麼避**：只給版型用的中繼資料一律走 `->view()`，Mailable 自己要留的狀態就別放 public ——
`protected readonly` 一樣能被 `SerializesModels` 序列化進 queue。要留 public 當測試用的 API 也可以，
但名字絕不能跟版型變數撞。

**測試抓不到**：`Mail::fake()` 只記錄 mailable、不渲染版型，所以斷言 `$mail->videos` 的測試會全綠而信
一寄就爆。信件的測試至少要有一個案例真的呼叫 `$mail->render()`。

## `co-phpunit` 會關掉每測試一協程的隔離，別拿它跑這個專案的測試

`code:` `vendor/hyperf/testing/co-phpunit` · `code:` `vendor/hypervel/foundation/src/Testing/Concerns/RunTestsInCoroutine.php` → `runTest()` · `updated:` `2026-08-30` · `status:` `active`

`vendor/bin/co-phpunit` 看起來像是「協程版的測試指令」，在這個專案跑它會得到 **38 個失敗**，
而且失敗的樣子非常誤導：清一色是斷言 401 的測試拿到 200／204——像是認證整個壞掉。
實際上一個 bug 都沒有，是測試隔離被那支指令關掉了。

因果鏈：

1. `co-phpunit` 把**整個 PHPUnit 應用程式**包進單一協程：

   ```php
   Swoole\Coroutine\run(function () use (&$code) {
       $code = (new PHPUnit\TextUI\Application())->run($_SERVER['argv']);
   });
   ```

2. `RunTestsInCoroutine::runTest()` 靠「我還在非協程環境」來決定要不要替**每一個測試**
   各包一次 `run()`：

   ```php
   if (Coroutine::getCid() === -1 && $this->enableCoroutine) { ... }
   ```

   已經在協程裡，cid 不是 -1，條件為 false ⇒ 每測試一協程的包裝**完全不執行**。

3. 502 個測試共用同一個協程 ⇒ 共用同一份 `Context`（`Hypervel\Context\Context` 底層是
   `Coroutine::getContextFor($coroutineId)`，以協程 ID 索引）。

4. `actingAs()` → `be()` → `guard()->setUser()` 與 `shouldUse()`，兩者都寫進 `Context`
   （`JwtGuard::setUser()` 是 `Context::set($this->getContextKey(), $user)`）。於是**任何一個測試
   呼叫過 `fakeLogin()` 之後，後面所有測試都繼承那個登入狀態**——所有「未登入應回 401」的
   測試因此全數失敗。

**這不是 Hypervel 特有的衝突**：`Hyperf\Testing\TestCase` 自己也 `use Concerns\RunTestsInCoroutine`，
守衛條件一模一樣（多一個 `extension_loaded('swoole')`）。兩者是同一個問題的兩代解法，
設計上互斥——外層先開協程，內層的守衛就永遠不成立。co-phpunit 早於 trait 出現，
現在多半是遺留用法。

**第二個後果，而且是靜默的**：`invokeSetupInCoroutine()` / `invokeTearDownInCoroutine()`
只在 `runTestsInCoroutine()` 內被呼叫。那個方法不執行 ⇒ `setUpInCoroutine()` /
`tearDownInCoroutine()` 兩個 hook 永遠不會觸發，且不報錯。本專案目前沒用到這兩個 hook。

**不要試圖讓 co-phpunit 變綠。** 最直覺的解法是在 `Tests\TestCase::tearDown()` 加一行
`Context::destroyAll()`，但那會傷到真正在用的 runner：正常 `phpunit` 下 `setUp()` /
`tearDown()` 是跑在**非協程環境**的（包裝只涵蓋 `runTest()`，PHPUnit 的順序是
setUp → runTest → tearDown），而 `destroyAll()` 在 cid < 0 時是

```php
if ($coroutineId < 0) {
    static::$nonCoContext = [];   // 整個清掉
    return;
}
```

——會抹掉每個測試的協程賴以初始化的 `$nonCoContext`（`Context::copyFromNonCoroutine()`
的來源）。為了一個沒人使用的入口，去動共用基礎設施，不划算。

**唯一入口是 `composer test`**（= `phpunit -c phpunit.xml.dist`）。專案內沒有任何地方引用
co-phpunit，`composer.lock` 提到它只是因為 `hyperf/testing` 宣告了這個 bin。

**順帶釐清一個容易反過來想的點**：co-phpunit 反映的**不是**正式環境。正式環境是每個 worker
process 常駐、但**每個請求一個協程**，`Context` 隨協程銷毀——兩個請求永遠不共用協程 Context。
所以「一個測試 ≙ 一個請求」的 `phpunit` 才是正確類比。正式環境真正會跨請求殘留的是
singleton / static / 容器層的狀態，那一層兩個 runner 條件相同，換 runner 沒有任何幫助。
