/**
 * Railway Infrastructure as Code。
 *
 * 取代原本的 railway.json / railway/*.json——Config as Code 已棄用，2026-12-01
 * 起完全停止讀取，且官方明言「由 railway.json / railway.toml 管理的 service
 * 必須先遷移，IaC 才能接管」，兩者不能並存。
 *
 * 套用流程（在已 link 到該專案的目錄下）：
 *
 *   railway config plan     # 先看 diff，不會改動任何東西
 *   railway config apply    # 套用，破壞性變更會另外確認
 *
 * 需要 Railway CLI 5.42.1 以上。IaC API 目前仍是 beta，欄位可能變動。
 *
 * 刻意不宣告 env：現有變數是在面板上以 Shared Variables 設定的，這裡不列出
 * 就不會被納入管理。移除變數屬於破壞性變更，plan 會標示出來——第一次跑
 * plan 時請確認它沒有提議刪除任何變數，若有再用 preserve() 補上。
 *
 * 同理不宣告 source 與資料庫資源：service 已經接好 repo，Postgres / Redis
 * 也已存在，讓 IaC 只管理 build 與 deploy 設定是風險最低的起點。
 */
import {bucket, defineRailway, github, preserve, project, ref, service} from "railway/iac";
import type {BucketNode, VariableValue} from "railway/iac";

/**
 * 限縮 omit=delete 的作用域，只涵蓋本檔宣告的資源。
 *
 * 沒有這一行時 IaC 會把整個 environment 視為本檔的管轄範圍，凡是這裡沒宣告
 * 的東西都排進刪除清單——實測 plan 曾提議刪掉 Postgres、Redis、mailpit 與
 * 前端的 rsspilot.app service。那些屬於別的 repo，這個 repo 不該擁有它們。
 *
 * 官方把 partial 定位為「separate repositories cannot share that file」時的
 * 最後手段，而這正是這裡的情況。兩個限制：
 *   1. apply 之後不要改這個名字。
 *   2. 若日後把所有 service 併進單一檔案，要把這行拿掉。
 */
export const partial = "api.rsspilot.app";

/** 四個 service 共用同一份 image。as const 讓 builder 收斂成字面值型別。 */
const build = {
    builder: "DOCKERFILE",
    dockerfilePath: "docker/Dockerfile.railway",
} as const;

const ARTISAN = "php /var/www/artisan";

/**
 * worker 共用的旗標，差別只在 --queue 與 --timeout。
 *
 * **刻意沒有 `--max-time`**（2026-09-18 拿掉）。它原本是「每小時輪替一次 worker，
 * 不要等到撞記憶體上限」，但在 hypervel/framework v0.3.17 上它不會輪替，會讓
 * worker 變成活死人：
 *
 *   Worker::stop() 只 dispatch 一個事件然後 `return $status`，沒有 exit；而
 *   monitorTimeoutJobs() 用 `Timer::tick` 註冊的 Swoole timer 從頭到尾沒有被
 *   clear（`monitorId` 全 class 只有寫入、沒有清除）。daemon 迴圈 return 之後
 *   event loop 還有那個 timer，**process 因此不會結束**。
 *
 * 後果是 PID 1 還活著 → Railway 判定服務正常 → restartPolicy 永遠不觸發 →
 * 容器顯示 Online，但從第 3601 秒起一筆 job 都不再領。實測 2026-09-18：兩支
 * worker 都已經這樣「上線但不工作」約 29 小時，videotranscriber.start 積了 26 筆
 * 且 attempts 全是 0，容器只印過一行 Starting Container，20 秒內 CPU 用量是 0
 * （同期 scheduler 是 +10 ticks，對照組正常）。
 *
 * 拿掉之後 worker 會一直跑下去。殘留風險是記憶體超過 `--memory` 時走的是同一條
 * stop() 路徑，一樣會變活死人——但那從「每小時一次」變成「久久一次」。根治要修
 * 上游的 stop()（退出前 Timer::clear 並真的結束 process）。
 */
const WORKER_FLAGS = "--sleep=3 --memory=256";

/**
 * 四個 service 與 Postgres / Redis 必須同區，否則私有網路連不過去。
 *
 * 不宣告的話，IaC 新建的 service 會落在「帳號預設區域」而不是既有服務所在
 * 的區域。實際症狀不是明確的連不上，而是：
 *
 *   SQLSTATE[08006] could not send SSL negotiation packet:
 *   Resource temporarily unavailable
 *
 * TCP 看似連上了、SSL 交握的第一個 send 才 EAGAIN，讀起來像 SSL 或驅動的
 * 問題，跟區域八竿子打不著——這是本專案實際踩過的坑。
 *
 * 區域可隨時更換，不影響網域與私有網路，沒掛 volume 就沒有停機。
 */
const REGION = "asia-southeast1-eqsg3a"; // Southeast Asia (Singapore)

/**
 * 面板上既有的變數名稱。值一律用 preserve() 保留 Railway 上的現值，
 * 不寫進 repo。
 *
 * 沒有這份清單時 plan 會提議刪掉 api 與 scheduler 上的全部變數——實測是
 * 102 個破壞性變更。IaC 的 omit=delete 對變數同樣適用。
 *
 * 新增變數時要同步加進這裡，否則下一次 apply 會把它刪掉。
 *
 * APP_KEY 與 APP_URL 是後來補上的——兩者缺席時都不會有明確的錯誤訊息：
 *   - 少了 APP_KEY，config('app.key') 是 null，而 UrlGenerator::getSignedKey()
 *     宣告回傳 string，忘記密碼那支端點組簽章連結時直接 TypeError → 500。
 *   - 少了 APP_URL，config('app.url') 退回 http://localhost，信件裡的圖片就
 *     指向收件人自己的電腦，靜靜破圖。
 * 兩者都是「設定沒設」而不是程式壞掉，所以不會出現在任何測試裡。
 *
 * 三個 PADDLE_* 是同樣的情況：Paddle 只留給既有訂閱、沒有人在動它，所以它們
 * 一直不在這份清單裡，plan 每次都提議把四個 service 上的那三個變數刪掉。真的
 * apply 下去，既有 Paddle 訂閱的 webhook 會因為少了 PADDLE_WEBHOOK_SECRET_KEY
 * 而驗簽失敗——「不再擴充」不等於「可以刪掉設定」。
 *
 * `PADDLE_WEBHOOK_IP_ALLOWLIST` 與 `PADDLE_WEBHOOK_TRUSTED_PROXY` 是 2026-09-22
 * 切 live 時補的，同樣直接開在面板上，所以也要登記進來才不會被 plan 刪掉。它們
 * **必須成對存在**：這個服務跑在 Railway 的反向代理後面，`remote_addr` 看到的是
 * 代理而不是 Paddle，只留下 IP_ALLOWLIST 會把每一則 webhook 都擋掉，症狀是「付款
 * 成功但訂閱沒生效」。兩個都被刪掉則是靜靜地退回「不檢查來源 IP」，見
 * `PaddleController::assertAllowedIp()`。
 *
 * `DB_QUEUE_RETRY_AFTER` 同樣是後來補的（2026-09-18）。它決定佇列多久之後判定
 * 「這個 job 沒人在跑」並重新發給別人，必須大於所有 worker 的 `--timeout`
 * （目前最大 300，所以設 360）。沒設過的那段期間它是 config 的預設值 90，比兩支
 * worker 的 timeout 都小，實測造成 26 筆 job 以 MaxAttemptsExceededException 收場。
 * 它不在任何旗標旁邊，是最容易被漏掉的一個——被 IaC 刪掉的話會安靜地退回 90，
 * 症狀看起來像外部服務一直失敗。
 *
 * 六個 AWS_* 也是（2026-09-18 補）：S3 是在這個檔案寫完之後才接上的，變數直接
 * 開在面板上，所以 plan 提議把四個 service 上的它們全部刪掉，共 24 個破壞性變更。
 * 刪掉的後果是播放器截圖、頭像上傳與 VideoTranscriberArchiveJob 的歸檔一起壞掉，
 * 而且症狀會是「S3 認證失敗」，看起來像金鑰過期而不是設定被刪。
 * 補進清單前已比對過四個 service 的值完全相同（逐一比 sha256），所以 worker 從
 * 自己的值改成參照 api 的同名變數不會變動任何實際設定。
 *
 * 其中五個在 2026-09-22 之後由 `awsFrom()` 覆蓋成 bucket 的 reference，留在這份
 * 清單裡仍有作用：`AWS_USE_PATH_STYLE_ENDPOINT` 要靠它保住現值，兩個 worker 也
 * 要靠它從 api 鏡射過去。
 */
const ENV_KEYS = [
    "AI_DEFAULT_MODEL", "APP_DEBUG", "APP_ENV", "APP_FALLBACK_LOCALE",
    "APP_KEY", "APP_LOCALE", "APP_NAME", "APP_URL",
    "AWS_ACCESS_KEY_ID", "AWS_BUCKET", "AWS_DEFAULT_REGION", "AWS_ENDPOINT",
    "AWS_SECRET_ACCESS_KEY", "AWS_USE_PATH_STYLE_ENDPOINT",
    "BROADCAST_CONNECTION", "CACHE_DRIVER", "CLIENT_URL",
    "DB_CONNECTION", "DB_DATABASE", "DB_HOST", "DB_PASSWORD", "DB_PORT",
    "DB_QUEUE_RETRY_AFTER", "DB_USERNAME", "GITHUB_TOKEN", "GOOGLE_CLIENT_ID", "GOOGLE_CLIENT_SECRET",
    "GROQ_API_KEY", "JWT_SECRET", "JWT_TTL",
    "LOG_CHANNEL", "LOG_CHANNELS", "LOG_LEVEL", "LOG_STDERR_FORMATTER",
    "MAIL_FROM_ADDRESS", "MAIL_FROM_NAME", "MAIL_HOST", "MAIL_MAILER",
    "MAIL_PASSWORD", "MAIL_PORT", "MAIL_USERNAME", "OPENROUTER_API_KEY",
    "PADDLE_API_KEY", "PADDLE_CLIENT_TOKEN", "PADDLE_SANDBOX",
    "PADDLE_WEBHOOK_IP_ALLOWLIST", "PADDLE_WEBHOOK_SECRET_KEY",
    "PADDLE_WEBHOOK_TRUSTED_PROXY", "QUEUE_CONNECTION", "RAPID_API_KEY",
    "REDIS_AUTH", "REDIS_DB", "REDIS_HOST", "REDIS_PORT",
    "SERVER_WORKERS_NUMBER",
    "SESSION_DOMAIN", "SESSION_DRIVER", "SESSION_ENCRYPT", "SESSION_LIFETIME",
    "SESSION_PATH", "STRIPE_API_KEY", "STRIPE_PUBLISHABLE_KEY",
    "STRIPE_RETURN_URL", "STRIPE_WEBHOOK_SECRET", "VIDEOTRANSCRIBER_EMAIL",
    "VIDEOTRANSCRIBER_PASSWORD", "VIDEOTRANSCRIBER_SECRET_KEY",
    "YOUTUBE_API_KEY",
] as const;

/** 既有 service 用：保留 Railway 上的現值。 */
const preserved = (): Record<string, VariableValue> =>
    Object.fromEntries(ENV_KEYS.map((k) => [k, preserve()]));

/**
 * 兩個 worker 與 scheduler 用：全部參照 api service 的同名變數，不各自存一份。
 *
 * worker 是新建的、本來就沒有現值可保留；scheduler 2026-09-22 從 `preserved()`
 * 改過來——手貼四份必然漂移，而漂移的症狀是「只有某一個 service 壞掉」，最難查。
 *
 * 切換前逐一比對過 api 與 scheduler 的 66 個值（在容器內比 sha256，不印出值）：
 * 64 個完全相同，只有 `GOOGLE_CLIENT_ID` 與 `GOOGLE_CLIENT_SECRET` 在 scheduler
 * 上根本沒設。也就是說這次切換沒有覆寫掉任何既有值，只補上那兩個。
 *
 * AWS_* 因此是兩層參照：worker/scheduler → api → bucket。Railway 會遞迴解析。
 */
const mirrorOf = (
    from: { env: Record<string, VariableValue> },
): Record<string, VariableValue> =>
    Object.fromEntries(ENV_KEYS.map((k) => [k, from.env[k]]));

/**
 * S3 認證改為直接參照 Railway Bucket，不再在面板上另存一份（2026-09-22）。
 *
 * 這五個值原本是從 `railway bucket credentials` 複製出來、手動貼進 Shared
 * Variables 的。複製出來的那一刻它就跟來源脫鉤了：在面板上按下 reset
 * credentials、或把 bucket 換成另一個，面板上的舊金鑰不會跟著變，只會開始回
 * 403——而 `config/filesystems.php` 的 s3 disk 設了 `'throw' => true`，症狀是
 * 上傳直接拋例外，看起來像金鑰過期而不是設定沒同步。
 *
 * 改成 reference 之後 Railway 在部署時才解析，輪替金鑰不必再動這個檔案，也
 * 不必動面板。
 *
 * 左邊是 Laravel 在 `config/filesystems.php` 讀的名字，右邊是 bucket 對外輸出
 * 的名字，兩邊不同名所以不能省略這張對照表。
 *
 * **`AWS_USE_PATH_STYLE_ENDPOINT` 不在這裡**：bucket 沒有對應的輸出，它仍然由
 * `preserved()` 保住面板上的現值。`AWS_URL` 與 `CDN_URL` 同理，而且它們從一開始
 * 就不在 ENV_KEYS 裡，IaC 不管。
 */
const AWS_FROM_BUCKET: Record<string, string> = {
    AWS_ACCESS_KEY_ID: "ACCESS_KEY_ID",
    AWS_SECRET_ACCESS_KEY: "SECRET_ACCESS_KEY",
    AWS_DEFAULT_REGION: "REGION",
    AWS_BUCKET: "BUCKET",
    AWS_ENDPOINT: "ENDPOINT",
};

/** 展開在 `preserved()` 之後，覆蓋掉同名的那幾個 preserve()。 */
const awsFrom = (store: BucketNode): Record<string, VariableValue> =>
    Object.fromEntries(
        Object.entries(AWS_FROM_BUCKET).map(([key, output]) => [key, ref(store, output)]),
    );

/**
 * Redis 連線參數同樣改為參照 redis service，不再各存一份（2026-09-22）。
 *
 * 左邊是 `config/database.php` 讀的名字，右邊是 Railway 的 redis 對外輸出的
 * 名字。**密碼那一列特別容易寫錯**：應用讀的是 `REDIS_AUTH`，redis 那邊叫
 * `REDISPASSWORD`，而 redis 自己另外還有一個 `REDIS_PASSWORD`——設成後者是
 * no-op，連線會以「密碼錯誤」失敗，但訊息跟「沒設密碼」長得一樣。
 *
 * **`REDIS_DB` 不在這裡**：那是要用第幾號資料庫，屬於應用自己的選擇，redis
 * 沒有對應的輸出，仍由 `preserved()` 保住現值（目前是 0）。
 *
 * 為什麼是字面值而不是 `ref()`：`ref()` 會在 graph 裡產生一條指向該資源的
 * edge，而 validateGraph 對「指向未宣告資源的 edge」直接報錯，所以用 ref 就
 * 必須把 redis 一起宣告進來。但 IaC 的 `redis()` helper 預設是
 * `railwayapp/redis:8.2` + 掛載 `/bitnami`，Railway 上這顆實際是 `redis:8.2`
 * + 掛載 `/data`，還帶一段自訂的 `--requirepass` startCommand；宣告下去 plan
 * 會提議把 image 與掛載點一起改掉，等於把資料清空。字面值是面板上填參照時
 * 存下來的同一種東西，Railway 在部署時才解析，不需要宣告那顆資源。
 */
const REDIS_FROM_SERVICE: Record<string, string> = {
    REDIS_HOST: "REDISHOST",
    REDIS_PORT: "REDISPORT",
    REDIS_AUTH: "REDISPASSWORD",
};

/** 同樣展開在 `preserved()` 之後。 */
const redisFrom = (): Record<string, VariableValue> =>
    Object.fromEntries(
        Object.entries(REDIS_FROM_SERVICE).map(
            ([key, output]) => [key, {type: "literal", value: `\${{redis.${output}}}`}],
        ),
    );

export default defineRailway((ctx) => {
    // 同一份檔案會被套用到每個 environment，plan 是對「當下 link 的那個」
    // 做 diff。凡是兩邊該不一樣的東西都必須在這裡分岔，寫死等於把 staging
    // 的設定推到 production。
    const isProduction = ctx.isEnvironment("production");

    /**
     * 必須顯式宣告，否則 plan 會把 source.repo / branch / type 全設為 null——
     * 也就是把 service 與 GitHub 的連結拆掉。
     *
     * 分支要跟著環境走。寫死 develop 會讓 production 改從開發分支部署。
     */
    const source = github("cherub0526/api.rsspilot.app", {
        branch: isProduction ? "main" : "develop",
    });
    /**
     * bucket 早就存在（staging 實測 42 個物件 / 133.9 MB），這裡只是把它納入宣告，
     * 好讓下面的 ref() 有東西可以指——plan 顯示 0 to add，沒有要新建。
     *
     * 套用到新環境前先確認那邊也有同名 bucket：沒有的話 apply 會照這份宣告建一個
     * 空的，然後把 AWS_* 指過去，症狀是「檔案全部不見了」而不是錯誤訊息。
     *
     * 名稱必須跟 Railway 上的一致（`bucket`）——資源是以名稱配對的，改名等同於
     * 「刪掉舊的、建一個新的」，而 bucket 一旦刪掉，裡面的物件跟著沒了。
     *
     * region 顯式寫成建立當時的 `sin`。它在建立後不可變更，寫對的值是為了讓
     * plan 不要把它當成待修改的欄位；寫錯或省略都只會讓 plan 噪音變多。
     */
    const store = bucket("bucket", {region: "sin"});

    const api = service("api", {
        source,
        env: {...preserved(), ...awsFrom(store), ...redisFrom()},
        build,
        deploy: {
            startCommand: `${ARTISAN} start`,
            // 型別是 string[]，不是字串——舊的 railway.json 寫成字串是錯的。
            // migration 只掛在這一個 service，否則四個 service 會並發跑。
            preDeployCommand: [`${ARTISAN} migrate --force`],
            // 路由掛在 /api 前綴下（app/Providers/RouteServiceProvider.php），
            // 根路徑會回 404。這支端點不碰 DB 也不碰 Redis，驗的是活著而不是就緒。
            healthcheckPath: "/api",
            // Hyperf 開機時要即時產生 DI proxy，冷啟動比一般 PHP 應用慢。
            healthcheckTimeout: 120,
            region: REGION,
            restartPolicyType: "ALWAYS",
            numReplicas: 1,
            // 兩個環境都不休眠。
            //
            // production 不能休眠的理由很直接：休眠後的第一個請求要等冷啟動，
            // 而 Hyperf 開機還要產生 DI proxy，webhook 打進來會吃到這段延遲。
            //
            // staging 一度開著休眠以省成本，後來收回——省下的錢遠不及它造成的
            // 誤判。同一個專案裡的 mailpit 就是這樣：它睡著時 SMTP 連不上，
            // 症狀是「寄信失敗」，跟設定錯誤長得一模一樣，查了才知道只是沒醒。
            // 測試環境要能被信任，前提是它的行為跟正式環境一致。
            //
            // 顯式給值而不是省略：不宣告的話 plan 會設成 null，等於順手改掉了
            // 沒人要求改的行為。
            sleepApplication: false
        },
    });

    // media.info、media.caption、media.youtube-data-caption 暫停中，
    // 恢復時加回 --queue 清單即可，順序即優先序。
    //
    // media.notify 一直都漏掉了：Forge 那邊有 supervisor/media-notify.conf，
    // Railway 這邊從來沒宣告，所以 DailyDigestJob 派出去之後沒有人在聽——
    // 靜靜躺在 jobs 表裡，不報錯也不進 failed_jobs（2026-09-18 實測 staging
    // 有 9 筆最舊 9.7 天）。它每天 09:00 一次派完全部使用者、之後整天閒置，
    // 放在 worker-fast 不會排擠到轉錄入口。
    //
    // media.summary-translation 排在最後：它是摘要完成後的加值翻譯，讓它跟
    // 轉錄的入口搶 worker 只會延後新影片開工。單次執行是一個 OpenRouter 請求，
    // 上限就是 Completion 自己的 60 秒 HTTP timeout，塞得進 120 那組。
    const workerFast = service("worker-fast", {
        source,
        env: mirrorOf(api),
        build,
        deploy: {
            startCommand:
                `${ARTISAN} queue:work database ` +
                `--queue='videotranscriber.start,videotranscriber.fetch,media.notify,media.summary-translation' ` +
                `--timeout=120 ${WORKER_FLAGS}`,
            // 必須是 ALWAYS：worker 自我了結時退出碼是 0，ON_FAILURE 不會把它
            // 拉起來，service 會顯示部署成功但永久停擺。
            //
            // 但這道保險救不了 stop() 那條路徑——process 根本不會退出，也就沒有
            // 退出碼可言（見 WORKER_FLAGS）。
            region: REGION,
            restartPolicyType: "ALWAYS",
            numReplicas: 1,
        },
    });

    // media.summary 暫停中。rss.sync 已隨 RSS 管線一併下架。
    //
    // videotranscriber.archive 跟 smart-summary 同放這裡是因為 timeout：它一趟
    // 要抓七個檔案，其中 mp3 動輒十幾 MB，120 秒那組裝不下。job 內另有 180 秒
    // 的預算上限，確保單次執行不會逼近 --timeout=300。
    //
    // media.custom-summary（使用者自訂 AI 摘要）也是一趟 LLM 推論，長度跟
    // smart-summary 同級，放 fast 那組會被 120 秒截斷。
    const workerSlow = service("worker-slow", {
        source,
        env: mirrorOf(api),
        build,
        deploy: {
            startCommand:
                `${ARTISAN} queue:work database ` +
                `--queue='videotranscriber.smart-summary,videotranscriber.archive,media.custom-summary' ` +
                `--timeout=300 ${WORKER_FLAGS}`,
            region: REGION,
            restartPolicyType: "ALWAYS",
            numReplicas: 1,
        },
    });

    // 常駐，不是 cron。Hypervel 的 schedule:run 自帶 while 迴圈，
    // 每 100ms 檢查一次到期任務；設 cronSchedule 會讓容器永遠不退出。
    //
    // 名稱刻意沿用 Railway 上既有的（含空格）——資源是以名稱配對的，
    // 改名等同於「刪掉舊的、建一個新的」。
    const scheduler = service("scheduler", {
        source,
        env: mirrorOf(api),
        build,
        deploy: {
            startCommand: `${ARTISAN} schedule:run`,
            region: REGION,
            restartPolicyType: "ALWAYS",
            numReplicas: 1,
        },
    });

    // ctx.projectName 的型別是 string | undefined，必須給 fallback。
    // plan 是對「已 link 的環境」做 diff，名稱不會用來配對既有專案。
    return project(ctx.projectName ?? "api.rsspilot.app", {
        resources: [store, api, workerFast, workerSlow, scheduler],
    });
});
