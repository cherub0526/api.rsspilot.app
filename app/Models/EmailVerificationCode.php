<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Hypervel\Database\Eloquent\Builder;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;

/**
 * 註冊 email 驗證的一次性憑證。
 *
 * code（信中顯示的 6 位數）與 token（信件連結）指向同一筆，任一被使用即整筆作廢——
 * 這是「用掉一個另一個就失效」的實作位置。
 *
 * @property string $id
 * @property string $user_id
 * @property string $code
 * @property string $token
 * @property int $attempts
 * @property \Carbon\Carbon $expires_at
 * @property null|\Carbon\Carbon $consumed_at
 * @property \Carbon\Carbon $created_at
 * @property User $user
 */
class EmailVerificationCode extends Model
{
    use HasUlids;

    /** 碼的有效期（分鐘）。 */
    public const int TTL_MINUTES = 10;

    /** 同一組碼可以輸錯幾次，超過即作廢，必須重寄。 */
    public const int MAX_ATTEMPTS = 5;

    /** 兩次重寄之間的最短間隔（秒）。 */
    public const int RESEND_COOLDOWN_SECONDS = 60;

    /** 碼的長度，與 6 位純數字的產生規則綁在一起。 */
    public const int CODE_LENGTH = 6;

    protected ?string $table = 'email_verification_codes';

    protected array $fillable = [
        'user_id',
        'code',
        'token',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    protected array $hidden = [
        'code',
        'token',
    ];

    protected array $casts = [
        'attempts'    => 'integer',
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 尚可使用的憑證：未被用掉、未過期、錯誤次數未達上限。
     *
     * 刻意做成靜態查詢而不是 scope——本專案的 scope 是動態解析的，PHPStan 認不得，
     * 呼叫端會多帶一個永遠不會被修掉的錯誤。
     */
    public static function usableQuery(): Builder
    {
        $query = static::query();

        $query->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now())
            ->where('attempts', '<', self::MAX_ATTEMPTS);

        return $query;
    }

    public function isExpired(): bool
    {
        return $this->consumed_at !== null
            || $this->expires_at->isPast()
            || $this->attempts >= self::MAX_ATTEMPTS;
    }

    /** 還可以再試幾次；已作廢時為 0。 */
    public function attemptsLeft(): int
    {
        return max(0, self::MAX_ATTEMPTS - $this->attempts);
    }

    /** 距離可以重寄還有幾秒；0 表示現在就能重寄。 */
    public function resendCooldownLeft(): int
    {
        $ready = $this->created_at->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        return max(0, Carbon::now()->diffInSeconds($ready, false));
    }
}
