<?php

declare(strict_types=1);

namespace App\Utils\AI;

use Iterator;
use Generator;
use Throwable;
use App\Models\User;
use Hypervel\Support\Facades\Log;

/**
 * 依方案路由跑一次推論，失敗時退回用途層再試一次。
 *
 * **為什麼需要這層**：方案的路由設定可能整組失效——模型下架、該價格帶當下沒有可用
 * 的供應商、`max_price` 把所有候選都擋掉。OpenRouter 原生的 `models` fallback 陣列
 * 不足以涵蓋：它對無效的 model id 直接回 400 不觸發 fallback，而且曾經因為
 * `openrouter/free` 被靜默跳過而讓「免費優先」變成計費（見
 * docs/lore/prompts/pitfalls.md）。所以重試寫在應用層。
 *
 * 歷史背景：這層原本是為 Free 方案的 `openrouter/free` 而生的。2026-09-16 起沒有
 * 任何方案走免費模型（限流是帳號共用的，撞牆時這裡的退路反而會靜默轉付費），
 * 但「方案這層失效就退回用途層」這條規則本身與免費模型無關，保留。
 *
 * 退路就是**用途層設定**，不另外定義一組：路由的優先順序本來就是「方案覆寫用途」，
 * 方案這一層失效時退回下一層是同一條規則的自然延伸。方案本來就沒覆寫用途時
 * （`equals()` 為真）不重試——再送一次一模一樣的請求只是把同一個錯誤吃兩遍。
 *
 * 串流**只在第一個 token 之前**可以重試：已經吐給前端的內容收不回來，中途換模型
 * 會讓使用者看到兩段接不起來的文字。
 */
final class RoutedInference
{
    /**
     * 非串流。
     *
     * @template T
     * @param class-string|string $class 用途
     * @param callable(RoutingProfile): T $run
     * @return T
     */
    public static function run(string $class, ?User $user, callable $run): mixed
    {
        $primary = RoutingProfile::for($class, $user);
        $fallback = RoutingProfile::forPurpose($class);

        try {
            return $run($primary);
        } catch (Throwable $e) {
            if ($primary->equals($fallback)) {
                throw $e;
            }

            self::logFallback($class, $primary, $fallback, $e);

            return $run($fallback);
        }
    }

    /**
     * 串流。
     *
     * @param class-string|string $class 用途
     * @param callable(RoutingProfile): Generator<int, string> $run
     * @return Generator<int, string>
     */
    public static function stream(string $class, ?User $user, callable $run): Generator
    {
        $primary = RoutingProfile::for($class, $user);
        $fallback = RoutingProfile::forPurpose($class);

        try {
            $stream = self::start($run, $primary);
        } catch (Throwable $e) {
            if ($primary->equals($fallback)) {
                throw $e;
            }

            self::logFallback($class, $primary, $fallback, $e);

            $stream = self::start($run, $fallback);
        }

        while ($stream->valid()) {
            yield $stream->current();
            $stream->next();
        }
    }

    /**
     * 建立串流並前進到第一個元素。
     *
     * `rewind()` 是真正送出請求的那一步——generator 在被迭代之前一行都不會跑，
     * 少了它，連線失敗會延到呼叫端開始迭代時才炸，那時已經離開這裡的 try。
     */
    private static function start(callable $run, RoutingProfile $profile): Iterator
    {
        $stream = $run($profile);
        $stream->rewind();

        return $stream;
    }

    private static function logFallback(
        string $class,
        RoutingProfile $primary,
        RoutingProfile $fallback,
        Throwable $e
    ): void {
        // 一定要記：Free 方案的免費路由器若大規模失效，所有請求會靜靜地改用
        // 付費模型，沒有這行的話你只會在帳單上發現。
        Log::warning('routing profile failed, falling back to the purpose default', [
            'purpose' => $class,
            'from'    => $primary->model,
            'to'      => $fallback->model,
            'error'   => $e->getMessage(),
        ]);
    }
}
