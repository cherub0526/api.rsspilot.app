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
import { defineRailway, project, service } from "railway/iac";

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

/** worker 共用的旗標，差別只在 --queue 與 --timeout。 */
const WORKER_FLAGS = "--sleep=3 --max-time=3600 --memory=256";

export default defineRailway((ctx) => {
  const api = service("api.rsspilot.app", {
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
      restartPolicyType: "ALWAYS",
      numReplicas: 1,
    },
  });

  // media.info、media.caption、media.youtube-data-caption 暫停中，
  // 恢復時加回 --queue 清單即可，順序即優先序。
  const workerFast = service("worker-fast", {
    build,
    deploy: {
      startCommand:
        `${ARTISAN} queue:work database ` +
        `--queue='videotranscriber.start,videotranscriber.fetch' ` +
        `--timeout=120 ${WORKER_FLAGS}`,
      // 必須是 ALWAYS：worker 因 --max-time 自我了結時退出碼是 0，
      // ON_FAILURE 不會把它拉起來，service 會顯示部署成功但永久停擺。
      restartPolicyType: "ALWAYS",
      numReplicas: 1,
    },
  });

  // rss.sync、media.summary 暫停中。
  const workerSlow = service("worker-slow", {
    build,
    deploy: {
      startCommand:
        `${ARTISAN} queue:work database ` +
        `--queue='videotranscriber.smart-summary' ` +
        `--timeout=300 ${WORKER_FLAGS}`,
      restartPolicyType: "ALWAYS",
      numReplicas: 1,
    },
  });

  // 常駐，不是 cron。Hypervel 的 schedule:run 自帶 while 迴圈，
  // 每 100ms 檢查一次到期任務；設 cronSchedule 會讓容器永遠不退出。
  //
  // 名稱刻意沿用 Railway 上既有的（含空格）——資源是以名稱配對的，
  // 改名等同於「刪掉舊的、建一個新的」。
  const scheduler = service("api.rsspilot.app scheduler", {
    build,
    deploy: {
      startCommand: `${ARTISAN} schedule:run`,
      restartPolicyType: "ALWAYS",
      numReplicas: 1,
    },
  });

  // ctx.projectName 的型別是 string | undefined，必須給 fallback。
  // plan 是對「已 link 的環境」做 diff，名稱不會用來配對既有專案。
  return project(ctx.projectName ?? "api.rsspilot.app", {
    resources: [api, workerFast, workerSlow, scheduler],
  });
});
