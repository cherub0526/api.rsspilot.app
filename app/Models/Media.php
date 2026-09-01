<?php

declare(strict_types=1);

namespace App\Models;

use App\Utils\Const\ISO6391;
use Hyperf\Database\Model\SoftDeletes;
use Hyperf\Database\Model\Relations\HasOne;
use Hyperf\Database\Model\Relations\HasMany;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hyperf\Database\Model\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Factories\HasFactory;

/**
 * @property-read null|Source $source
 */
class Media extends Model
{
    use HasUlids;

    use SoftDeletes;

    use HasFactory;

    public const string STATUS_CREATED = 'created';

    public const string STATUS_PROGRESS = 'progress';

    public const string STATUS_TRANSCRIBING = 'transcribing';

    public const string STATUS_TRANSCRIBED = 'transcribed';
    public const STATUS_TRANSCRIBE_FAILED = 'transcribe_failed';

    public const STATUS_SUMMARIZING = 'summarizing';

    public const STATUS_SUMMARIZED = 'summarized';

    public const STATUS_SUMMARIZE_FAILED = 'summarize_failed';

    public const STATUS_READY = 'ready';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const TYPE_YOUTUBE = 'youtube';

    public const TYPE_SPOTIFY = 'spotify';

    public static array $statusMap = [
        self::STATUS_CREATED           => '已建立',
        self::STATUS_PROGRESS          => '處理中',
        self::STATUS_TRANSCRIBING      => '轉錄中',
        self::STATUS_TRANSCRIBED       => '轉錄完成',
        self::STATUS_TRANSCRIBE_FAILED => '轉錄失敗',
        self::STATUS_SUMMARIZING       => '摘要中',
        self::STATUS_SUMMARIZED        => '摘要完成',
        self::STATUS_SUMMARIZE_FAILED  => '摘要失敗',
        self::STATUS_READY             => '完成',
        self::STATUS_CANCELLED         => '取消',
        self::STATUS_FAILED            => '失敗',
    ];

    public static array $typeMaps = [
        self::TYPE_YOUTUBE => 'YouTube',
        self::TYPE_SPOTIFY => 'Spotify',
    ];

    protected ?string $table = 'media';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'type',
        'resource_id',
        'source_id',
        'title',
        'description',
        'duration',
        'language',
        'thumbnail',
        'published_at',
        'status',
        'video_detail',
        'audio_detail',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'duration'     => 'integer',
        'source_id'    => 'string',
        'language'     => 'string',
        'video_detail' => 'array',
        'audio_detail' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'id');
    }

    public function watchHistories(): HasMany
    {
        return $this->hasMany(WatchHistory::class, 'media_id', 'id');
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class, 'media_id', 'id');
    }

    public function isAccessibleBy(User $user): bool
    {
        return ($this->source?->free ?? false)
            || $this->users()->where('users.id', $user->getKey())->exists();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'userables', 'media_id', 'user_id')->withTimestamps();
    }

    public function captions(): HasMany
    {
        return $this->hasMany(Caption::class, 'media_id', 'id');
    }

    public function summary(): HasOne
    {
        return $this->hasOne(Summary::class, 'media_id', 'id')->orderBy('created_at', 'desc');
    }

    public function summaries(): HasMany
    {
        return $this->hasMany(Summary::class, 'media_id', 'id');
    }

    public function videoTranscription(): HasOne
    {
        return $this->hasOne(VideoTranscription::class, 'media_id', 'id');
    }

    /**
     * 這位使用者該看到的摘要。
     *
     * 一支影片可以有多筆摘要：使用者自己重跑的（`user_id` 有值）與全站共用的
     * （`user_id` 為 null），各語系又各一筆。挑選順序：
     *
     * 1. 自己的摘要，且語系等於介面語系
     * 2. 全站共用的摘要，且語系等於介面語系
     * 3. 全站共用的第一筆
     *
     * 使用者沒有語系設定時（`settings` 資料列尚未建立，或存的值已不在白名單內，
     * 見 `User::uiLocale()`），第 1 順位退化成「只要是自己的就用」——否則自己
     * 產的摘要會因為沒設定語系而拿不到，反而回全站共用那份。
     *
     * `settings.data.locale` 存的是 `zh-TW`，摘要早期沿用字幕的 `zh_tw`，兩者
     * 字面不相等，所以比對前一律過 `ISO6391::normalize()`；寫入端也已改成存
     * 正規化後的值，既有資料由 `normalize_summaries_locale` 遷移洗過。
     *
     * `$completedOnly` 目前每個呼叫端都開著：重跑摘要會先建一筆 `status=created`、
     * `text` 還是 null 的資料列，沒過濾就會挑到空殼——拿去餵 AI 是空的參考資料，
     * 顯示給使用者則是把畫面上原本看得到的摘要清空。留成參數而不是寫死，是因為
     * `SummaryResource` 會把 `status` 回給前端，未來若要讓前端看見「產生中」，
     * 關掉它就是那個行為。
     */
    public function summaryFor(User $user, bool $completedOnly = false): ?Summary
    {
        // 同一組條件仍可能命中多筆（重跑摘要會再建一列），一律取最新的——
        // 沒有排序就是資料庫的插入順序，等於重跑完還是拿到舊內容。
        $query = fn () => $completedOnly
            ? $this->summaries()->where('status', Summary::STATUS_COMPLETED)->orderByDesc('created_at')
            : $this->summaries()->orderByDesc('created_at');

        $locale = $user->uiLocale();

        if ($locale === null) {
            return $query()->where('user_id', $user->getKey())->first()
                ?? $query()->whereNull('user_id')->first();
        }

        $locale = ISO6391::normalize($locale);

        return $query()->where('user_id', $user->getKey())->where('locale', $locale)->first()
            ?? $query()->whereNull('user_id')->where('locale', $locale)->first()
            ?? $query()->whereNull('user_id')->first();
    }
}
