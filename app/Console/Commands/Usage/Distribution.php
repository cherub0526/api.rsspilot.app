<?php

declare(strict_types=1);

namespace App\Console\Commands\Usage;

use Carbon\Carbon;
use App\Models\Plan;
use App\Models\ChatUsage;
use App\Models\ChatMessage;
use App\Models\Subscription;
use Hypervel\Console\Command;
use Hypervel\Support\Facades\DB;
use App\Services\SubscriptionService;

/**
 * 量真實的使用分布：每日提問數、對話分段、web search 觸發率。
 *
 * 存在的理由寫在 rsspilot.app repo 的 docs/pricing-cost-model.md §8 第 2 點——整份
 * 成本模型的「典型 / 重度 / 極端」三欄是假設出來的，而 Advance 的毛利對這個分布
 * 極度敏感（33% / −97% / −154%）。貼著上限用的人佔 1% 還是 10%，決定了 §5 那三個
 * 成本旋鈕要不要動；在這支指令之前沒有人在量，所以那個決定是在猜。
 *
 * 三段輸出剛好對上模型的三個輸入參數：**用掉多少額度**、**分幾段**、**每題搜幾次**。
 * 最後一段把實測值與模型假設並排印出來，差多少直接看得到。
 *
 * 只讀不寫、不打任何外部服務，可以在正式環境隨時重跑。
 *
 * 四個讀數之前要先知道的限制：
 *
 * 1. **方案是「現在」的方案。** `chat_usages` 刻意不記 plan_id（見那張表的註解——
 *    依方案分桶會讓升級再降級變成重置額度的手段），所以升降級過的帳號，他過去的
 *    用量會被歸到他現在的方案那一欄。
 * 2. **上限也是「現在」的上限。** `plans.chat_limit` 被 migration 改過好幾次
 *    （Pro 20 → 一度降到 20 以下再回來、Advance 50），貼上限的比例要配著當下的
 *    上限讀，所以每一列都把上限一起印出來。
 * 3. **`parts` 為 null 的 AI 回答不算進搜尋的分母。** 那個欄位是 2026-09-01 才加的
 *    （見該 migration 的註解：既有資料列刻意不回填），之前的訊息沒有片段可看。
 *    混進分母會把觸發率稀釋成一個看起來很安全的數字，所以第三段同時印覆蓋率。
 * 4. **搜尋次數是數 tool_call 片段，不是 `usage.server_tool_use.web_search_requests`。**
 *    後者在我們這條管線上拿不到：NeuronAI 的 Usage 只留 token 數（見
 *    vendor/neuron-core/neuron-ai/src/Chat/Messages/Usage.php），而串流那一側連實際
 *    跑了哪個 model 都會被丟掉（見 NeuronChatStreamer 的類別註解）。
 *    `openrouter:web_search` 的每一次呼叫都會以 tool_call 片段落庫，一次呼叫就是
 *    一次搜尋請求，數的是同一件事。片段的 `name` 不寫死，照實分組印出來——這條路上
 *    之後可能還有 MCP 工具（`plans.mcp_enabled`），寫死 `web_search` 會把別的工具
 *    算成搜尋。
 *
 * 聚合刻意在 PHP 做而不是下推 SQL：`parts` 是 JSON 欄位，本機 sqlite 與正式 MySQL
 * 的 JSON 查詢語法不一樣，而這個階段的資料量遠不到需要下推。
 */
class Distribution extends Command
{
    /** 提問數分桶的上界，null 代表無上界（最後一桶）。 */
    private const QUESTION_BUCKETS = [1, 3, 5, 10, 20, 50, null];

    /** 分段數分桶的上界。 */
    private const SEGMENT_BUCKETS = [1, 2, 5, 10, null];

    /** 單次回答裡的工具呼叫次數分桶。 */
    private const CALL_BUCKETS = [0, 1, 2, 3, null];

    /** 模型目前的假設（docs/pricing-cost-model.md §7），用來並排對照。 */
    private const ASSUMED_SEARCH_TRIGGER_PCT = 30.0;

    private const ASSUMED_SEARCH_CALLS = 2.0;

    protected ?string $signature = 'usage:distribution
        {--days=30 : 往回看幾天（含今天，0 = 全部資料）}
        {--json : 輸出 JSON，給試算表或模型用}';

    protected string $description = '量每日提問數、對話分段與 web search 觸發率的實際分布';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $since = $days > 0
            ? Carbon::now($this->timezone())->subDays($days - 1)->startOfDay()
            : null;

        $report = [
            'window' => [
                'days'     => $days === 0 ? null : $days,
                'since'    => $since?->toDateString(),
                'until'    => Carbon::now($this->timezone())->toDateString(),
                'timezone' => $this->timezone(),
            ],
            'daily_questions' => $this->dailyQuestions($since),
            'segments'        => $this->segments($since),
            'web_search'      => $this->webSearch($since),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return 0;
        }

        $this->render($report);

        return 0;
    }

    // ── 1. 每日提問數 ──────────────────────────────────────────────

    /**
     * 每日提問數的分布，依「現在的方案」分組。
     *
     * 一列 `chat_usages` 就是一個「使用者 × 額度日」，沒有用的那一天不會有列——
     * 所以這裡的分母是**活躍日**，不是日曆天。平均值對定價沒有用，要看的是尾巴：
     * `at_limit_days_pct`（當天用到上限的活躍日比例）與 `users_ever_at_limit_pct`
     * （曾經用到上限的使用者比例）。
     *
     * @return array<string, mixed>
     */
    private function dailyQuestions(?Carbon $since): array
    {
        $query = ChatUsage::query()->where('count', '>', 0);

        if ($since !== null) {
            $query->where('quota_date', '>=', $since->toDateString());
        }

        /** @var array<int, array{user_id: string, quota_date: string, count: int}> $rows */
        $rows = $query->get(['user_id', 'quota_date', 'count'])
            ->map(fn (ChatUsage $usage): array => [
                'user_id'    => (string) $usage->getAttribute('user_id'),
                'quota_date' => (string) $usage->getAttribute('quota_date'),
                'count'      => (int) $usage->getAttribute('count'),
            ])
            ->all();

        $plans = $this->plansOf(array_values(array_unique(array_column($rows, 'user_id'))));

        /** @var array<string, array{limit: int, counts: array<int, int>, users: array<string, int>, at_limit_users: array<string, true>}> $groups */
        $groups = [];
        $histogram = $this->emptyBuckets(self::QUESTION_BUCKETS);
        $ratios = [];

        foreach ($rows as $row) {
            $plan = $plans[$row['user_id']] ?? ['title' => '（查不到方案）', 'limit' => 0];
            $title = (string) $plan['title'];
            $limit = (int) $plan['limit'];

            $groups[$title] ??= [
                'limit'          => $limit,
                'counts'         => [],
                'users'          => [],
                'at_limit_users' => [],
            ];

            $groups[$title]['counts'][] = $row['count'];
            $groups[$title]['users'][$row['user_id']] = ($groups[$title]['users'][$row['user_id']] ?? 0) + 1;

            if ($limit > 0 && $row['count'] >= $limit) {
                $groups[$title]['at_limit_users'][$row['user_id']] = true;
            }

            if ($limit > 0) {
                $ratios[] = $row['count'] / $limit;
            }

            $this->bucket($histogram, self::QUESTION_BUCKETS, $row['count']);
        }

        $byPlan = [];

        foreach ($groups as $title => $group) {
            $counts = $group['counts'];
            $limit = $group['limit'];

            $atLimitDays = $limit > 0
                ? count(array_filter($counts, fn (int $count): bool => $count >= $limit))
                : 0;
            $over80Days = $limit > 0
                ? count(array_filter($counts, fn (int $count): bool => $count >= $limit * 0.8))
                : 0;

            $byPlan[] = [
                'plan'                    => $title,
                'limit'                   => $limit,
                'users'                   => count($group['users']),
                'active_days'             => count($counts),
                'questions'               => array_sum($counts),
                'mean'                    => $this->round(array_sum($counts) / max(1, count($counts))),
                'p50'                     => $this->percentile($counts, 50),
                'p90'                     => $this->percentile($counts, 90),
                'p99'                     => $this->percentile($counts, 99),
                'max'                     => $counts === [] ? 0 : max($counts),
                'at_limit_days_pct'       => $this->pct($atLimitDays, count($counts)),
                'over_80pct_days_pct'     => $this->pct($over80Days, count($counts)),
                'users_ever_at_limit_pct' => $this->pct(count($group['at_limit_users']), count($group['users'])),
                'mean_limit_used_pct'     => $limit > 0
                    ? $this->round(array_sum($counts) / max(1, count($counts)) / $limit * 100)
                    : null,
            ];
        }

        // 人多的方案排前面，看的人先看到母數大的那一列。
        usort($byPlan, fn (array $a, array $b): int => $b['active_days'] <=> $a['active_days']);

        return [
            'active_days'         => count($rows),
            'users'               => count($plans),
            'questions'           => array_sum(array_column($rows, 'count')),
            'mean_limit_used_pct' => $ratios === [] ? null : $this->round(array_sum($ratios) / count($ratios) * 100),
            'by_plan'             => $byPlan,
            'histogram'           => $histogram,
        ];
    }

    // ── 2. 對話分段 ────────────────────────────────────────────────

    /**
     * 分段的分布：一段對話幾題、一個人一天分幾段。
     *
     * 「分幾段」是成本模型的第二個輸入：每一輪都要把歷史重送一遍，所以同樣的題數
     * 分得越少段越貴（分 1 段時 Advance 的成本是分 5 段的 1.3 倍）。只有超過歷史
     * 視窗（`ai.chat.history_window`，20 則 = 10 輪）之後才封頂，視窗以內是線性累積。
     *
     * 提問落在哪一天用額度時區換算，與 `chat_usages.quota_date` 對齊；created_at
     * 存的是 app 時區（UTC）。
     *
     * @return array<string, mixed>
     */
    private function segments(?Carbon $since): array
    {
        $query = DB::table('chat_messages')
            ->join('chat_sessions', 'chat_sessions.id', '=', 'chat_messages.session_id')
            ->where('chat_messages.role', ChatMessage::ROLE_USER);

        if ($since !== null) {
            $query->where('chat_messages.created_at', '>=', $this->toAppTime($since));
        }

        $messages = $query
            ->orderBy('chat_messages.created_at')
            ->get([
                'chat_messages.session_id as session_id',
                'chat_messages.created_at as created_at',
                'chat_sessions.user_id as user_id',
            ]);

        /** @var array<string, int> $perSession */
        $perSession = [];
        /** @var array<string, array<string, true>> $sessionsPerUserDay */
        $sessionsPerUserDay = [];
        $beyondWindow = 0;
        $window = (int) config('ai.chat.history_window');
        // 視窗是「訊息數」，一輪是提問加回答兩則，所以能容納的提問數是它的一半。
        $windowRounds = $window > 0 ? intdiv($window, 2) : 0;

        foreach ($messages as $message) {
            $sessionId = (string) $message->session_id;
            $userId = (string) $message->user_id;

            $perSession[$sessionId] = ($perSession[$sessionId] ?? 0) + 1;

            if ($windowRounds > 0 && $perSession[$sessionId] > $windowRounds) {
                ++$beyondWindow;
            }

            $date = $this->quotaDateOf((string) $message->created_at);
            $sessionsPerUserDay[$userId . ' ' . $date][$sessionId] = true;
        }

        $questionsPerSession = array_values($perSession);
        $segmentsPerUserDay = array_map('count', array_values($sessionsPerUserDay));
        $questions = array_sum($questionsPerSession);

        $perSessionHistogram = $this->emptyBuckets(self::QUESTION_BUCKETS);
        foreach ($questionsPerSession as $count) {
            $this->bucket($perSessionHistogram, self::QUESTION_BUCKETS, $count);
        }

        $segmentHistogram = $this->emptyBuckets(self::SEGMENT_BUCKETS);
        foreach ($segmentsPerUserDay as $count) {
            $this->bucket($segmentHistogram, self::SEGMENT_BUCKETS, $count);
        }

        $overWindowSessions = $windowRounds > 0
            ? count(array_filter($questionsPerSession, fn (int $count): bool => $count > $windowRounds))
            : 0;

        return [
            'sessions'              => count($perSession),
            'questions'             => $questions,
            'active_user_days'      => count($segmentsPerUserDay),
            'questions_per_session' => [
                'mean'      => $this->round($questions / max(1, count($questionsPerSession))),
                'p50'       => $this->percentile($questionsPerSession, 50),
                'p90'       => $this->percentile($questionsPerSession, 90),
                'max'       => $questionsPerSession === [] ? 0 : max($questionsPerSession),
                'histogram' => $perSessionHistogram,
            ],
            'segments_per_user_day' => [
                'mean'      => $this->round(array_sum($segmentsPerUserDay) / max(1, count($segmentsPerUserDay))),
                'p50'       => $this->percentile($segmentsPerUserDay, 50),
                'p90'       => $this->percentile($segmentsPerUserDay, 90),
                'max'       => $segmentsPerUserDay === [] ? 0 : max($segmentsPerUserDay),
                'histogram' => $segmentHistogram,
            ],
            'history_window' => [
                'messages'      => $window,
                'rounds'        => $windowRounds,
                'sessions_pct'  => $this->pct($overWindowSessions, count($questionsPerSession)),
                'questions_pct' => $this->pct($beyondWindow, $questions),
            ],
        ];
    }

    // ── 3. web search 觸發率 ───────────────────────────────────────

    /**
     * 搜尋的實際觸發率與每題次數。
     *
     * 這是模型自己標為「最脆弱、影響最大」的假設（§7 第一列）：server tool 由模型
     * 決定要不要搜，而重度 Advance 的成本有 35% 來自搜尋。分母是**有片段可看的
     * AI 回答**，不是全部回答，理由見類別註解第 3 點。
     *
     * @return array<string, mixed>
     */
    private function webSearch(?Carbon $since): array
    {
        $query = ChatMessage::query()->where('role', ChatMessage::ROLE_AI);

        if ($since !== null) {
            $query->where('created_at', '>=', $this->toAppTime($since));
        }

        $total = 0;
        $withParts = 0;
        $withCall = 0;
        $calls = 0;
        /** @var array<string, array{answers: int, calls: int}> $byTool */
        $byTool = [];
        $histogram = $this->emptyBuckets(self::CALL_BUCKETS, 0);

        $query->select(['id', 'parts'])->chunkById(500, function ($chunk) use (
            &$total,
            &$withParts,
            &$withCall,
            &$calls,
            &$byTool,
            &$histogram
        ): void {
            foreach ($chunk as $message) {
                ++$total;

                $parts = $message->getAttribute('parts');

                if (!is_array($parts) || $parts === []) {
                    continue;
                }

                ++$withParts;

                $names = [];

                foreach ($parts as $part) {
                    if (!is_array($part) || ($part['type'] ?? null) !== ChatMessage::PART_TOOL_CALL) {
                        continue;
                    }

                    $names[] = (string) ($part['name'] ?? '(unnamed)');
                }

                $this->bucket($histogram, self::CALL_BUCKETS, count($names), 0);

                if ($names === []) {
                    continue;
                }

                ++$withCall;
                $calls += count($names);

                foreach (array_count_values($names) as $name => $count) {
                    $byTool[$name] ??= ['answers' => 0, 'calls' => 0];
                    ++$byTool[$name]['answers'];
                    $byTool[$name]['calls'] += $count;
                }
            }
        });

        // 呼叫次數多的工具排前面，保留名稱當 key。
        uasort($byTool, fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);

        return [
            'ai_answers'                 => $total,
            'with_parts'                 => $withParts,
            'coverage_pct'               => $this->pct($withParts, $total),
            'answers_with_tool_call_pct' => $this->pct($withCall, $withParts),
            'calls'                      => $calls,
            'calls_per_answer'           => $this->round($calls / max(1, $withParts)),
            'calls_per_searching_answer' => $this->round($calls / max(1, $withCall)),
            'by_tool'                    => $byTool,
            'histogram'                  => $histogram,
            'assumption'                 => [
                'trigger_pct'        => self::ASSUMED_SEARCH_TRIGGER_PCT,
                'calls_per_trigger'  => self::ASSUMED_SEARCH_CALLS,
                'calls_per_question' => self::ASSUMED_SEARCH_TRIGGER_PCT / 100 * self::ASSUMED_SEARCH_CALLS,
            ],
        ];
    }

    // ── 輸出 ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $report */
    private function render(array $report): void
    {
        /** @var array<string, mixed> $window */
        $window = $report['window'];

        $this->info(sprintf(
            '期間：%s ～ %s（%s）',
            $window['since'] ?? '全部資料',
            $window['until'],
            $window['timezone']
        ));

        $this->renderDailyQuestions($report['daily_questions']);
        $this->renderSegments($report['segments']);
        $this->renderWebSearch($report['web_search']);
        $this->renderModelInputs($report);
    }

    /** @param array<string, mixed> $data */
    private function renderDailyQuestions(array $data): void
    {
        $this->newLine();
        $this->info('1. 每日提問數分布');
        $this->line(sprintf(
            '   活躍日 %d、使用者 %d、提問 %d；平均用掉 %s%% 額度',
            $data['active_days'],
            $data['users'],
            $data['questions'],
            $this->show($data['mean_limit_used_pct'])
        ));

        if ($data['active_days'] === 0) {
            $this->warn('   這段期間沒有任何提問。');

            return;
        }

        // 不排成欄位對齊的表格：方案名稱與中文標題是全形，padding 補的是字元數而
        // 不是顯示寬度，對齊只會更亂。每個方案兩行，讀的人不必數欄位。
        foreach ($data['by_plan'] as $row) {
            $this->newLine();
            $this->line(sprintf(
                '   %s（上限 %s）：使用者 %d、活躍日 %d、提問 %d',
                $row['plan'],
                $row['limit'] > 0 ? (string) $row['limit'] : '不限',
                $row['users'],
                $row['active_days'],
                $row['questions']
            ));
            $this->line(sprintf(
                '     每日提問：平均 %s（%s%% 額度）、p50 %d、p90 %d、p99 %d、最大 %d',
                $this->show($row['mean']),
                $this->show($row['mean_limit_used_pct']),
                $row['p50'],
                $row['p90'],
                $row['p99'],
                $row['max']
            ));
            $this->line(sprintf(
                '     尾巴：貼上限的活躍日 %s%%、用掉 ≥80%% 的活躍日 %s%%、曾經貼上限的使用者 %s%%',
                $this->show($row['at_limit_days_pct']),
                $this->show($row['over_80pct_days_pct']),
                $this->show($row['users_ever_at_limit_pct'])
            ));
        }

        $this->newLine();
        $this->line('   每日提問數分桶（活躍日）：');
        $this->renderHistogram($data['histogram'], $data['active_days']);
    }

    /** @param array<string, mixed> $data */
    private function renderSegments(array $data): void
    {
        $this->newLine();
        $this->info('2. 對話分段分布');

        if ($data['sessions'] === 0) {
            $this->warn('   這段期間沒有任何對話。');

            return;
        }

        $perSession = $data['questions_per_session'];
        $perDay = $data['segments_per_user_day'];
        $windowed = $data['history_window'];

        $this->line(sprintf(
            '   對話 %d、提問 %d、活躍人日 %d',
            $data['sessions'],
            $data['questions'],
            $data['active_user_days']
        ));
        $this->line(sprintf(
            '   每段提問數：平均 %s、p50 %d、p90 %d、最大 %d',
            $this->show($perSession['mean']),
            $perSession['p50'],
            $perSession['p90'],
            $perSession['max']
        ));
        $this->line(sprintf(
            '   每人每日分段數：平均 %s、p50 %d、p90 %d、最大 %d',
            $this->show($perDay['mean']),
            $perDay['p50'],
            $perDay['p90'],
            $perDay['max']
        ));
        $this->line(sprintf(
            '   超出歷史視窗（%d 則 = %d 輪）：%s%% 的對話、%s%% 的提問',
            $windowed['messages'],
            $windowed['rounds'],
            $this->show($windowed['sessions_pct']),
            $this->show($windowed['questions_pct'])
        ));

        $this->newLine();
        $this->line('   每段提問數分桶（對話）：');
        $this->renderHistogram($perSession['histogram'], $data['sessions']);

        $this->newLine();
        $this->line('   每人每日分段數分桶（活躍人日）：');
        $this->renderHistogram($perDay['histogram'], $data['active_user_days']);
    }

    /** @param array<string, mixed> $data */
    private function renderWebSearch(array $data): void
    {
        $this->newLine();
        $this->info('3. web search 觸發率');

        if ($data['with_parts'] === 0) {
            $this->warn(sprintf(
                '   這段期間 %d 則 AI 回答都沒有片段資料（parts 為 null），測不到搜尋。',
                $data['ai_answers']
            ));

            return;
        }

        $this->line(sprintf(
            '   AI 回答 %d、有片段 %d（覆蓋 %s%%）',
            $data['ai_answers'],
            $data['with_parts'],
            $this->show($data['coverage_pct'])
        ));
        $this->line(sprintf(
            '   觸發率 %s%%（模型假設 %s%%）；每題 %s 次（假設 %s 次）；有搜尋的回答平均 %s 次（假設 %s 次）',
            $this->show($data['answers_with_tool_call_pct']),
            $this->show($data['assumption']['trigger_pct']),
            $this->show($data['calls_per_answer']),
            $this->show($data['assumption']['calls_per_question']),
            $this->show($data['calls_per_searching_answer']),
            $this->show($data['assumption']['calls_per_trigger'])
        ));

        if ($data['by_tool'] === []) {
            $this->line('   這段期間沒有任何工具呼叫。');
        } else {
            $this->newLine();
            $this->line('   依工具名稱（名稱照資料庫原樣，可能混入 MCP 工具）：');

            foreach ($data['by_tool'] as $name => $tool) {
                $this->line(sprintf(
                    '     %-24s 回答 %d 則、呼叫 %d 次',
                    $name,
                    $tool['answers'],
                    $tool['calls']
                ));
            }
        }

        $this->newLine();
        $this->line('   每則回答的工具呼叫次數分桶：');
        $this->renderHistogram($data['histogram'], $data['with_parts']);
    }

    /**
     * 把三個實測值換成成本模型看得懂的那三個參數。
     *
     * @param array<string, mixed> $report
     */
    private function renderModelInputs(array $report): void
    {
        $this->newLine();
        $this->info('對照 docs/pricing-cost-model.md 的情境參數');
        $this->line(sprintf(
            '   實測：用掉 %s%% 額度、分 %s 段、每題搜 %s 次',
            $this->show($report['daily_questions']['mean_limit_used_pct']),
            $this->show($report['segments']['segments_per_user_day']['mean']),
            $this->show($report['web_search']['calls_per_answer'])
        ));
        $this->line('   模型：典型 30% / 6 段、重度 100% / 5 段、極端 100% / 1 段；搜尋 0.6 次');
        $this->newLine();
        $this->warn('   提醒：方案與上限都取「現在」的值，升降級過的帳號會被歸到現在的方案。');
    }

    /**
     * @param array<string, int> $histogram
     */
    private function renderHistogram(array $histogram, int $total): void
    {
        foreach ($histogram as $label => $count) {
            $share = $this->pct($count, $total);
            $bar = str_repeat('█', (int) round(($share ?? 0) / 4));

            $this->line(sprintf('     %-8s %6d  %5s%%  %s', $label, $count, $this->show($share), $bar));
        }
    }

    // ── 工具函式 ───────────────────────────────────────────────────

    /**
     * 這些使用者現在的方案與對話上限。
     *
     * 一次查完所有人的生效訂閱，而不是逐人呼叫 SubscriptionService——後者是每人兩
     * 個查詢，在正式環境會變成幾千次往返。`active()` 的語意直接沿用 Subscription
     * 的 scope，升降級判斷才不會和扣額度那條路分叉；同一人有多筆時取 start_date
     * 最新的，與 `SubscriptionService::getUserSubscription()` 一致。
     *
     * @param array<int, string> $userIds
     * @return array<string, array{title: string, limit: int}>
     */
    private function plansOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $free = (new SubscriptionService())->getUserSubscriptionPlan(null);
        $fallback = [
            'title' => $free === null ? '（查不到方案）' : (string) $free->getAttribute('title'),
            'limit' => $free === null ? 0 : (int) $free->getAttribute('chat_limit'),
        ];

        $plans = Plan::query()->get(['id', 'title', 'chat_limit'])
            ->keyBy('id')
            ->map(fn (Plan $plan): array => [
                'title' => (string) $plan->getAttribute('title'),
                'limit' => (int) $plan->getAttribute('chat_limit'),
            ])
            ->all();

        $resolved = array_fill_keys($userIds, $fallback);

        Subscription::query()
            ->active()
            ->whereIn('user_id', $userIds)
            // 升序：同一人有多筆時後寫的蓋前面的，留下 start_date 最新的那筆。
            ->orderBy('start_date')
            ->get(['user_id', 'plan_id'])
            ->each(function (Subscription $subscription) use (&$resolved, $plans, $fallback): void {
                $userId = (string) $subscription->getAttribute('user_id');
                $planId = (string) $subscription->getAttribute('plan_id');

                $resolved[$userId] = $plans[$planId] ?? $fallback;
            });

        return $resolved;
    }

    /**
     * `$floor` 是第一桶的下界：題數與分段數最少是 1，工具呼叫次數最少是 0
     * （「這題沒搜」本身就是要看的一桶）。
     *
     * @param array<int, null|int> $bounds
     * @return array<string, int>
     */
    private function emptyBuckets(array $bounds, int $floor = 1): array
    {
        $buckets = [];
        $low = null;

        foreach ($bounds as $bound) {
            $buckets[$this->bucketLabel($low, $bound, $floor)] = 0;
            $low = $bound;
        }

        return $buckets;
    }

    /**
     * @param array<string, int> $buckets
     * @param array<int, null|int> $bounds
     */
    private function bucket(array &$buckets, array $bounds, int $value, int $floor = 1): void
    {
        $low = null;

        foreach ($bounds as $bound) {
            if ($bound === null || $value <= $bound) {
                ++$buckets[$this->bucketLabel($low, $bound, $floor)];

                return;
            }

            $low = $bound;
        }
    }

    private function bucketLabel(?int $low, ?int $high, int $floor): string
    {
        $from = $low === null ? $floor : $low + 1;

        if ($high === null) {
            return $from . '+';
        }

        return $from >= $high ? (string) $high : $from . '-' . $high;
    }

    /**
     * 最近排名法（nearest-rank）的百分位。
     *
     * 不做內插：這裡的值是「幾題」，插出來的 17.5 題不存在，而且尾巴的形狀才是
     * 要看的東西，內插會把它磨平。
     *
     * @param array<int, int> $values
     */
    private function percentile(array $values, int $percentile): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);

        $rank = (int) ceil($percentile / 100 * count($values));

        return $values[max(0, $rank - 1)];
    }

    private function pct(int $part, int $total): ?float
    {
        return $total === 0 ? null : $this->round($part / $total * 100);
    }

    private function round(float $value): float
    {
        return round($value, 1);
    }

    private function show(float|int|null $value): string
    {
        return $value === null ? '—' : (string) $value;
    }

    private function timezone(): string
    {
        $timezone = config('ai.chat.quota_timezone');

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    /** 額度時區的時間點換成資料庫存的 app 時區，才能直接比 created_at。 */
    private function toAppTime(Carbon $moment): string
    {
        return $moment->copy()
            ->setTimezone((string) config('app.timezone', 'UTC'))
            ->toDateTimeString();
    }

    /** created_at（app 時區）落在哪一個額度日。 */
    private function quotaDateOf(string $createdAt): string
    {
        return Carbon::parse($createdAt, (string) config('app.timezone', 'UTC'))
            ->setTimezone($this->timezone())
            ->toDateString();
    }
}
