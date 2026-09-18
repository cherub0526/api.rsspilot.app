---
area: framework
kind: topic
---

# framework — 佇列 worker 的參數

<!--
每則規則是 `## heading` + 一行 meta + 內文。記的是讀 code 推不回來的東西。
-->

## 參數速查：每個旗標實際管什麼

`code:` `supervisor/` · `code:` `.railway/railway.ts` → `WORKER_FLAGS` · `code:` `config/queue.php` · `updated:` `2026-09-18` · `status:` `active`

Forge（supervisor）與 Railway 兩條部署路徑跑的是同一份 PHP，但旗標分散在三個地方，
而且其中一個根本不在旗標裡：

| 參數 | 住在哪 | 管什麼 | 現值 |
|---|---|---|---|
| `--queue` | `supervisor/*.conf` / `railway.ts` | 這支 worker 聽哪些佇列，**順序即優先序** | 見兩份 README 的對照表 |
| `--timeout` | 同上 | 單次 job 的時間預算。**超時砍的是 worker 不是 job** | 120 或 300 |
| `--sleep` | 同上 | 沒 job 時的輪詢間隔 | 3 |
| `--memory` | 同上 | 記憶體上限（MB），超過就走 stop() | 256 |
| `--max-time` | — | **已移除**，見〈`--max-time` 是讓 worker 變活死人的開關〉 | 無 |
| `retry_after` | `config/queue.php` ← `DB_QUEUE_RETRY_AFTER` | 佇列多久之後判定「這個 job 沒人在跑」並重新發給別人 | **90（預設值，沒人設過）** |
| `stopwaitsecs` | 只有 supervisor 有 | 部署／重啟時等 job 收尾的寬限期 | 180 或 360 |
| `numprocs` | 只有 supervisor 有 | 同一個佇列幾個 worker process | 2 |
| `numReplicas` | 只有 Railway 有 | 同上 | 1 |

兩條路徑不對稱的地方要記住：**Railway 沒有 `stopwaitsecs` 的對應物**。它送 SIGTERM
之後的寬限期遠短於 300 秒，所以每次部署都可能砍掉在途的長 job——這是 Railway 這側
的已知殘留風險，不是設定錯誤。

## `--timeout` 必須小於 `retry_after`，而 `retry_after` 目前根本沒人設

`code:` `config/queue.php` → `connections.database.retry_after` · `code:` `supervisor/README.md` · `updated:` `2026-09-18` · `status:` `active`

必須成立的關係（supervisor/README.md 早就寫了）：

```
--timeout  <  DB_QUEUE_RETRY_AFTER  <  stopwaitsecs
```

**但 `DB_QUEUE_RETRY_AFTER` 在這個專案裡從來沒有被設定過。**2026-09-18 實測：
Railway 的變數清單沒有、`.env.example` 沒有、`.railway/railway.ts` 的 `ENV_KEYS`
沒有、本機 `.env` 也沒有。於是它一路退回 `config/queue.php` 的預設值：

```php
'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
```

**90 比兩支 worker 的 `--timeout`（120 / 300）都小，也就是不等式目前是破的。**

破掉的後果實際長這樣（2026-09-18，staging）：26 筆 `videotranscriber.start` 被
worker 領走之後，全部以 `MaxAttemptsExceededException` 進 `failed_jobs`。job 還在跑、
佇列就判定它卡住並重新發出，attempts 每輪加一，撞到 `$tries` 上限就判死。**症狀完全
不像設定問題**——看起來像 videotranscriber.ai 一直失敗，實際上外部服務可能好好的。

三件容易誤判的事：

1. **`retry_after` 是連線層級的，不是 per-queue。**一個值要同時大於所有 worker 的
   `--timeout`，所以它必須 ≥ 目前最大的那個（300）。README 建議 360。
2. **改 `--timeout` 時一定要同步檢查它。**它不在 supervisor 設定檔裡，也不在
   `railway.ts` 裡，是唯一一個「不在旗標旁邊」的參數，最容易漏。
3. **兩條部署路徑都中。**這不是 Railway 專屬問題，Forge 的 `.env` 同樣沒設。

怎麼確認現值：

```bash
php artisan tinker --execute="echo config('queue.connections.database.retry_after');"
```

## `--max-time` 是讓 worker 變活死人的開關

`code:` `vendor/hypervel/queue/src/Worker.php` → `stop` · `code:` `.railway/railway.ts` → `WORKER_FLAGS` · `updated:` `2026-09-18` · `status:` `active`

`--max-time=3600` 的本意是「每小時輪替一次 worker，不要等到撞記憶體上限」。在
**hypervel/framework v0.3.17** 上它不會輪替，它會讓 worker 變成還活著但不再工作的東西：

```php
public function stop(int $status = 0, ?WorkerOptions $options = null): int
{
    $this->events->dispatch(new WorkerStopping($status, $options));

    return $status;   // 只 return，沒有 exit
}
```

`WorkCommand` 拿到這個回傳值之後也只是往上回傳，沒有人呼叫 `exit()`。而
`monitorTimeoutJobs()` 用 `Timer::tick` 註冊的 Swoole timer **從頭到尾沒有被 clear**
（`monitorId` 在整個 class 裡只有寫入、沒有清除），event loop 因此還有事可做，
**process 不會結束**。

於是監管機制全部失效：supervisor 看到 process RUNNING、`autorestart` 不觸發；
Railway 看到 PID 1 還在、`restartPolicyType: ALWAYS` 也不觸發。容器顯示健康，
但從第 3601 秒起一筆 job 都不再領。

**`--memory` 走的是同一條路**，所以拿掉 `--max-time` 只是把觸發頻率從「每小時一次」
降到「久久一次」，不是根治。根治要在 `stop()` 退出前 `Timer::clear($this->monitorId)`
並真的結束 process——那是上游的修正。

### 怎麼判斷 worker 是不是已經變活死人

狀態面板一律顯示正常，所以只能量。決定性的指標是 **CPU 有沒有在動**：

```bash
# 取樣兩次，中間隔 20 秒；兩個數字一樣就是沒有在輪詢
railway ssh -s worker-fast -e staging \
  "sh -c 'awk \"{print \\\$14+\\\$15}\" /proc/1/stat; sleep 20; awk \"{print \\\$14+\\\$15}\" /proc/1/stat'"
```

四個互相佐證的訊號（2026-09-18 實測值）：

| 訊號 | 活死人 | 正常閒置 |
|---|---|---|
| 20 秒的 CPU ticks | **0** | 有增加（scheduler 對照組 +10／15 秒） |
| `/proc/1/wchan` | `do_epoll_wait` | 同左（這個單獨看不出來） |
| 容器 log | 只有一行 `Starting Container`，時間是很久以前 | 同左（沒 job 時本來就不印） |
| `jobs` 表 | 有資料列、`attempts` 是 0、`available_at` 很舊 | 空的或正在減少 |

**`--max-time` 在 worker 還沒處理過任何 job 時反而不會觸發**——`.railway/README.md`
有更早的實測：`--max-time=8` 的 worker 閒置五分鐘仍在執行，CPU 約 0.7%。兩者合起來
的意思是：**它只在 worker 真的工作過之後才生效，而一生效就變活死人。**

0.7% 與 0% 是兩個不同的狀態，排查時要分清楚：前者是迴圈還在跑，後者是迴圈已經結束。
