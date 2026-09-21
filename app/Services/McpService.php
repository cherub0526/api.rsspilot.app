<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use Carbon\Carbon;
use JsonException;
use App\Models\User;
use App\Models\Media;
use InvalidArgumentException;

/**
 * /mcp 端點背後的工具。
 *
 * 全部唯讀，而且**一律從 `$user` 的關聯出發**（`$user->media()`、`$user->sources()`），
 * 不是先查資料再比對持有者——後者少寫一個條件就變成一把 key 讀得到全站的資料。
 *
 * 回傳的都是純資料陣列，JSON-RPC 的包裝留給 controller。
 */
class McpService
{
    /** 一次最多回幾筆。上游是 LLM 的 context，給太多只是浪費 token。 */
    private const int MAX_LIMIT = 50;

    private const int DEFAULT_LIMIT = 20;

    /** 逐字稿片段的預設與上限筆數。 */
    private const int SEGMENT_LIMIT = 200;

    private const int SEGMENT_MAX_LIMIT = 500;

    /** format=text 時的字數上限。超過就截斷並請對方改用時間區間。 */
    private const int TRANSCRIPT_MAX_CHARS = 12000;

    /** 大綱裡每節的預覽字數。 */
    private const int PREVIEW_CHARS = 120;

    /**
     * 這把 key 能用的工具清單（MCP 的 tools/list）。
     *
     * description 是寫給模型看的，不是寫給人看的：它要能讓模型判斷「什麼時候該
     * 呼叫這個工具」，所以講的是用途與限制，不是欄位說明。
     *
     * @return array<int, array<string, mixed>>
     */
    public function tools(): array
    {
        return [
            [
                'name'        => 'list_sources',
                'title'       => 'List subscribed sources',
                'description' => 'List the YouTube channels and playlists this RSSPilot user follows. '
                    . 'Use it to find a source_id before calling list_videos.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name'        => 'list_videos',
                'title'       => 'List recent videos',
                'description' => "List the user's most recent videos, newest first. "
                    . 'Optionally filter to one source. Returns ids you can pass to get_summary.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'source_id' => [
                            'type'        => 'string',
                            'description' => 'Only videos from this source (from list_sources).',
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'How many videos to return (1-50, default 20).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'search_videos',
                'title'       => 'Search videos by title',
                'description' => "Find the user's videos whose title contains the given text. "
                    . 'Use it when the user refers to a video by name rather than by id.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Text to look for in the title.'],
                        'limit' => ['type' => 'integer', 'description' => 'How many to return (1-50, default 20).'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name'        => 'get_summary',
                'title'       => 'Get a video summary',
                'description' => 'Get the AI-generated summary of one video: the short version, the full '
                    . 'markdown write-up, and its key points. This is the main way to read a video without '
                    . 'watching it. Pass a section index to read just one section of a long summary '
                    . '(see get_summary_outline).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'media_id' => [
                            'type'        => 'string',
                            'description' => 'Video id from list_videos or search_videos.',
                        ],
                        'section' => [
                            'type'        => 'integer',
                            'description' => 'Return only this section (index from get_summary_outline).',
                        ],
                    ],
                    'required' => ['media_id'],
                ],
            ],
            [
                'name'        => 'get_summary_outline',
                'title'       => 'Outline a video summary',
                'description' => 'List the sections of a video summary with a short preview of each. '
                    . 'Use it on long videos to decide which section to read, then call get_summary with '
                    . 'that section index instead of pulling the whole write-up.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'media_id' => [
                            'type'        => 'string',
                            'description' => 'Video id from list_videos or search_videos.',
                        ],
                    ],
                    'required' => ['media_id'],
                ],
            ],
            [
                'name'        => 'get_transcript',
                'title'       => 'Get a video transcript',
                'description' => 'Get what was actually said in a video, as time-stamped segments '
                    . '(default) or as one block of text. Use it when the summary is not enough — to quote '
                    . 'someone verbatim, or to find the moment something was said. Transcripts are long, so '
                    . 'narrow it with start_second/end_second when you already know roughly where to look.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'media_id' => [
                            'type'        => 'string',
                            'description' => 'Video id from list_videos or search_videos.',
                        ],
                        'format' => [
                            'type'        => 'string',
                            'enum'        => ['segments', 'text'],
                            'description' => 'segments = time-stamped lines (default); text = plain text.',
                        ],
                        'start_second' => [
                            'type'        => 'integer',
                            'description' => 'Only segments at or after this point in the video.',
                        ],
                        'end_second' => [
                            'type'        => 'integer',
                            'description' => 'Only segments before this point in the video.',
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'How many segments to return (1-500, default 200).',
                        ],
                    ],
                    'required' => ['media_id'],
                ],
            ],
        ];
    }

    /**
     * 執行一個工具，回傳給模型看的純文字。
     *
     * 找不到工具時丟 InvalidArgumentException，由 controller 轉成 JSON-RPC 錯誤。
     *
     * @param array<string, mixed> $arguments
     */
    public function call(User $user, string $tool, array $arguments): string
    {
        return match ($tool) {
            'list_sources'        => $this->encode($this->listSources($user)),
            'list_videos'         => $this->encode($this->listVideos($user, $arguments)),
            'search_videos'       => $this->encode($this->searchVideos($user, $arguments)),
            'get_summary'         => $this->encode($this->getSummary($user, $arguments)),
            'get_summary_outline' => $this->encode($this->getSummaryOutline($user, $arguments)),
            'get_transcript'      => $this->encode($this->getTranscript($user, $arguments)),
            default               => throw new InvalidArgumentException("Unknown tool: {$tool}"),
        };
    }

    /** @return array<string, mixed> */
    private function listSources(User $user): array
    {
        $sources = $user->sources()->get()->map(fn ($source): array => [
            'source_id' => (string) $source->getKey(),
            'title'     => (string) $source->getAttribute('title'),
            'type'      => (string) $source->getAttribute('type'),
        ])->all();

        return ['sources' => $sources];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function listVideos(User $user, array $arguments): array
    {
        $query = $user->media()->getQuery()->with('source');

        if (($sourceId = (string) ($arguments['source_id'] ?? '')) !== '') {
            $query->where('media.source_id', $sourceId);
        }

        $media = $query->orderByDesc('media.published_at')
            ->limit($this->limit($arguments))
            ->get();

        return ['videos' => $media->map(fn (Media $m): array => $this->videoRow($m))->all()];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function searchVideos(User $user, array $arguments): array
    {
        $keyword = trim((string) ($arguments['query'] ?? ''));

        if ($keyword === '') {
            return ['videos' => []];
        }

        // like 的萬用字元要逃逸，否則使用者搜 "100%" 會變成比對任意字串。
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);

        $media = $user->media()->getQuery()
            ->with('source')
            ->where('media.title', 'like', "%{$escaped}%")
            ->orderByDesc('media.published_at')
            ->limit($this->limit($arguments))
            ->get();

        return ['videos' => $media->map(fn (Media $m): array => $this->videoRow($m))->all()];
    }

    /**
     * 一支影片的摘要。
     *
     * 摘要取用的規則與站內 `/summaries` 端點一致（使用者自己的 > 同語系共用的），
     * 而且只取已完成的——重跑摘要時會先建一筆空殼，回傳那個等於回傳空白。
     *
     * @return array<string, mixed>
     */
    private function getSummary(User $user, array $arguments): array
    {
        $media = $this->mediaFor($user, $arguments);

        if (!$media instanceof Media) {
            return $media;
        }

        $summary = $media->summaryFor($user, true);

        if (!$summary) {
            return [
                ...$this->videoRow($media),
                'summary' => null,
                'note'    => 'This video has no completed summary yet.',
            ];
        }

        $text = (array) $summary->getAttribute('text');
        $long = (array) ($text['long_summary'] ?? []);
        $content = (string) ($long['content'] ?? '');

        // 指定章節時只回那一節。長摘要整份塞進 context 很貴，而使用者問的往往只
        // 關係到其中一段（先用 get_summary_outline 看大綱再挑）。
        if (isset($arguments['section'])) {
            $sections = $this->splitSections($content);
            $index = (int) $arguments['section'];

            if (!isset($sections[$index])) {
                return [
                    'error'     => 'Section not found',
                    'available' => count($sections),
                ];
            }

            return [
                ...$this->videoRow($media),
                'section' => [
                    'index'   => $index,
                    'heading' => $sections[$index]['heading'],
                    'content' => $sections[$index]['content'],
                    'of'      => count($sections),
                ],
            ];
        }

        return [
            ...$this->videoRow($media),
            'summary' => [
                'short'      => $text['short_summary'] ?? null,
                'content'    => $content !== '' ? $content : null,
                'key_points' => $long['key_points'] ?? [],
                'keywords'   => $long['keywords'] ?? [],
                'locale'     => $summary->getAttribute('locale'),
            ],
        ];
    }

    /**
     * 摘要的章節大綱。
     *
     * long_summary.content 是一份帶 markdown 標題的長文，章節就藏在那些標題裡。
     * 先給大綱再讓模型挑一節來讀，比整份丟過去便宜得多。
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function getSummaryOutline(User $user, array $arguments): array
    {
        $media = $this->mediaFor($user, $arguments);

        if (!$media instanceof Media) {
            return $media;
        }

        $summary = $media->summaryFor($user, true);

        if (!$summary) {
            return [...$this->videoRow($media), 'sections' => [], 'note' => 'No completed summary yet.'];
        }

        $text = (array) $summary->getAttribute('text');
        $long = (array) ($text['long_summary'] ?? []);
        $content = (string) ($long['content'] ?? '');
        $sections = $this->splitSections($content);

        return [
            ...$this->videoRow($media),
            'sections' => array_map(fn (array $section, int $i): array => [
                'index'   => $i,
                'heading' => $section['heading'],
                'preview' => mb_substr(trim($section['content']), 0, self::PREVIEW_CHARS),
                'length'  => mb_strlen($section['content']),
            ], $sections, array_keys($sections)),
        ];
    }

    /**
     * 影片的逐字稿。
     *
     * 預設回帶時間軸的片段：模型要引用原話時能一併說出「第幾分幾秒講的」，而
     * `limit` 與時間區間讓它不必一次吞下整份（實測一份可以到兩萬字）。
     * `format=text` 則是純文字，適合「整體讀一遍」的用途。
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function getTranscript(User $user, array $arguments): array
    {
        $media = $this->mediaFor($user, $arguments);

        if (!$media instanceof Media) {
            return $media;
        }

        // primary 優先，其次任何一份——字幕的語系是轉錄當下決定的，這裡不做挑選，
        // 直接把 locale 回給呼叫端判斷。
        $caption = $media->captions()->orderByDesc('primary')->first();

        if (!$caption) {
            return [...$this->videoRow($media), 'transcript' => null, 'note' => 'No transcript yet.'];
        }

        $locale = (string) $caption->getAttribute('locale');

        if ((string) ($arguments['format'] ?? 'segments') === 'text') {
            $full = (string) $caption->getAttribute('text');
            $truncated = mb_strlen($full) > self::TRANSCRIPT_MAX_CHARS;

            return [
                ...$this->videoRow($media),
                'locale'    => $locale,
                'truncated' => $truncated,
                'note'      => $truncated
                    ? 'Truncated. Use format=segments with start_second to read a specific part.'
                    : null,
                'text' => mb_substr($full, 0, self::TRANSCRIPT_MAX_CHARS),
            ];
        }

        $segments = $caption->getAttribute('segments');
        $segments = is_array($segments) ? $segments : [];

        $start = isset($arguments['start_second']) ? (float) $arguments['start_second'] : null;
        $end = isset($arguments['end_second']) ? (float) $arguments['end_second'] : null;

        $window = array_values(array_filter(
            $segments,
            fn (array $segment): bool => ($start === null || (float) ($segment['end'] ?? 0) >= $start)
                && ($end === null || (float) ($segment['start'] ?? 0) < $end)
        ));

        $limit = max(1, min(self::SEGMENT_MAX_LIMIT, (int) ($arguments['limit'] ?? self::SEGMENT_LIMIT)));
        $page = array_slice($window, 0, $limit);
        $more = count($window) > $limit;

        return [
            ...$this->videoRow($media),
            'locale'         => $locale,
            'total_segments' => count($segments),
            'returned'       => count($page),
            // 還有下一頁時給個續讀的起點，模型不必自己算。
            'next_start_second' => $more ? (float) ($window[$limit]['start'] ?? 0) : null,
            // 只留 start / end / text——原始資料還有 whisper 的 tokens 與
            // logprob，那些對讀逐字稿的人毫無用處，卻佔掉大量 context。
            'segments' => array_map(fn (array $segment): array => [
                'start' => round((float) ($segment['start'] ?? 0), 2),
                'end'   => round((float) ($segment['end'] ?? 0), 2),
                'text'  => trim((string) ($segment['text'] ?? '')),
            ], $page),
        ];
    }

    /**
     * 把 markdown 長文依標題切成章節。
     *
     * 標題之前的開場白（沒有標題的那一段）也算一節，heading 給 null——丟掉它會
     * 讓大綱的第一節跳掉一段內容。
     *
     * @return array<int, array{heading: null|string, content: string}>
     */
    private function splitSections(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $sections = [];
        $current = ['heading' => null, 'content' => ''];

        // **`u` 修飾符不能省。** 沒有它時 `\R` 是位元組層級的比對，會匹配單一
        // 位元組 0x85（Unicode 的 NEL），而中文的 UTF-8 編碼裡到處都是這個位元組
        // ——「內」是 E5 85 A7，會被從中間切成兩半，整份內容就變成無效的 UTF-8，
        // 最後在 json_encode 靜默失敗。英文測資完全看不出這個問題。
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            if (preg_match('/^\s*#{1,6}\s+(.+?)\s*$/u', $line, $matches) === 1) {
                if (trim($current['content']) !== '' || $current['heading'] !== null) {
                    $sections[] = $current;
                }

                $current = ['heading' => $matches[1], 'content' => ''];

                continue;
            }

            $current['content'] .= $line . "\n";
        }

        if (trim($current['content']) !== '' || $current['heading'] !== null) {
            $sections[] = $current;
        }

        return array_map(fn (array $section): array => [
            'heading' => $section['heading'],
            'content' => trim($section['content']),
        ], $sections);
    }

    /**
     * 取出這位使用者的某支影片；找不到時回一個錯誤陣列讓呼叫端直接回傳。
     *
     * 回傳型別刻意混著 Media 與陣列：四個工具都要做同一件事，各自抄一次 if 只會
     * 讓「只讀得到自己的資料」這條規則多三個可能寫錯的地方。
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|Media
     */
    private function mediaFor(User $user, array $arguments): array|Media
    {
        $mediaId = (string) ($arguments['media_id'] ?? '');

        if ($mediaId === '') {
            return ['error' => 'media_id is required'];
        }

        /** @var null|Media $media */
        $media = $user->media()->getQuery()->with('source')->where('media.id', $mediaId)->first();

        return $media ?? ['error' => 'Video not found in this account'];
    }

    /** @return array<string, mixed> */
    private function videoRow(Media $media): array
    {
        return [
            'media_id' => (string) $media->getKey(),
            'title'    => (string) $media->getAttribute('title'),
            // published_at 沒有被 cast 成日期（見 Media::$casts），拿到的是字串——
            // 直接呼叫 toIso8601String() 會當場炸掉。
            'published_at' => $this->iso($media->getAttribute('published_at')),
            'duration'     => $media->getAttribute('duration'),
            'url'          => $media->getAttribute('resource_id')
                ? 'https://www.youtube.com/watch?v=' . $media->getAttribute('resource_id')
                : null,
            'source' => $media->getAttribute('source')?->getAttribute('title'),
        ];
    }

    /** 日期字串轉 ISO 8601，空值或格式壞掉時回 null。 */
    private function iso(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $arguments */
    private function limit(array $arguments): int
    {
        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);

        return max(1, min(self::MAX_LIMIT, $limit ?: self::DEFAULT_LIMIT));
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        // json_encode 失敗時回 false，`(string)` 之後就是空字串——模型會收到一個
        // 空回應，看不出是壞掉還是真的沒資料。寧可把原因講出來。
        try {
            return json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            return '{"error": "Could not encode the result: ' . addslashes($e->getMessage()) . '"}';
        }
    }
}
