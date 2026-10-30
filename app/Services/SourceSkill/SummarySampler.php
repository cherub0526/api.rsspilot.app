<?php

declare(strict_types=1);

namespace App\Services\SourceSkill;

use App\Models\Media;
use App\Models\Source;
use App\Models\Summary;
use App\Utils\Const\ISO6391;
use Hypervel\Database\Eloquent\Collection;
use Hyperf\Database\Model\Relations\HasMany;

/**
 * 頻道 Skill 的取材：這個頻道最新的 N 支影片，每支挑一筆共用摘要（spec S15）。
 *
 * 共用摘要是 `user_id IS NULL`、`completed`、aiInput() 非空的那筆；自訂摘要屬於個人，
 * 不能流進全站共用的 skill。沒有可用共用摘要的影片不佔名額。
 */
class SummarySampler
{
    /** 一次撈的影片數；多撈是因為「aiInput() 非空」只能在 PHP 判斷，有些影片會被跳過。 */
    private const PAGE_SIZE = 60;

    /**
     * @return list<array{media: Media, summary: Summary}> 依 published_at 由新到舊
     */
    public function sample(Source $source, string $language, int $limit): array
    {
        $samples = [];

        for ($page = 0; count($samples) < $limit; ++$page) {
            $media = $this->page($source, $page);
            foreach ($media as $item) {
                /** @var Collection<int, Summary> $summaries */
                $summaries = $item->getRelation('summaries');
                $summary = $this->pick($summaries->all(), $language);
                if ($summary !== null) {
                    $samples[] = ['media' => $item, 'summary' => $summary];
                }
                if (count($samples) >= $limit) {
                    break;
                }
            }
            if (count($media) < self::PAGE_SIZE) {
                break;
            }
        }

        return $samples;
    }

    /**
     * Postgres 的 DESC 把 NULL 排最前面、SQLite 排最後，所以 NULL 的位置要自己指定；
     * 同一時間發佈的以 id（ULID）較大者優先。
     *
     * @return list<Media>
     */
    private function page(Source $source, int $page): array
    {
        $shared = fn ($query) => $query
            ->whereNull('user_id')
            ->where('status', Summary::STATUS_COMPLETED);

        return Media::query()
            ->where('source_id', $source->getKey())
            ->whereHas('summaries', $shared)
            ->with(['summaries' => fn (HasMany $query) => $shared($query)->orderByDesc('created_at')->orderByDesc('id')])
            ->orderByRaw('published_at IS NULL')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->offset($page * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->get()
            ->all();
    }

    /**
     * skill 語言有對應 locale 就用它，否則用最新的一筆。裸 `zh` 分不出繁簡，正規化後不等於
     * `zh-TW`／`zh-CN`，只會落到「最新的一筆」。
     *
     * @param list<Summary> $summaries 已依 created_at 由新到舊
     */
    private function pick(array $summaries, string $language): ?Summary
    {
        $usable = array_values(array_filter($summaries, fn (Summary $summary) => $summary->aiInput() !== ''));

        // 早期摘要的 locale 是 `zh_tw`，與 skill 語言的 `zh-TW` 字面不相等
        $language = ISO6391::normalize($language);
        foreach ($usable as $summary) {
            if (ISO6391::normalize((string) $summary->getAttribute('locale')) === $language) {
                return $summary;
            }
        }

        return $usable[0] ?? null;
    }
}
