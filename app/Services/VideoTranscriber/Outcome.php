<?php

declare(strict_types=1);

namespace App\Services\VideoTranscriber;

/**
 * 官方 OpenAPI 的一次回應，對發出請求的 job 代表什麼。
 */
enum Outcome
{
    /** Start：任務已建立。Poll：逐字稿已取回。 */
    case Ok;

    /** 任務還在跑，稍後再查。 */
    case Pending;

    /** 對方忙碌或暫時不可用，這筆影片本身沒有問題。 */
    case Busy;

    /** key 或帳號不被接受，要有人去修設定。 */
    case Unauthorized;

    /** 等下去也不會好，這筆影片到此為止。 */
    case Failed;
}
