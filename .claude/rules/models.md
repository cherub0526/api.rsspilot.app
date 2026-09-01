---
paths:
  - "app/Models/**"
  - "app/Observers/**"
  - "app/Relations/**"
---

# Eloquent Model 規則

> 本專案的 Model 在 `app/Models/`（namespace `App\Models`），一律繼承
> `App\Models\Model`；`User` 例外，繼承 `Hypervel\Foundation\Auth\User`。
> ORM 是 Hyperf 的 Eloquent 移植版——**relation 型別要從 `Hyperf\Database\Model\Relations\*` import，
> 不是 `Illuminate\Database\Eloquent\Relations\*`**。

## Import 對照

| 用途 | 本專案 |
|------|--------|
| 基底 Model | `App\Models\Model` |
| SoftDeletes | `Hyperf\Database\Model\SoftDeletes` |
| Relation 型別 | `Hyperf\Database\Model\Relations\{HasOne,HasMany,BelongsTo,BelongsToMany,MorphTo,...}` |
| Factory | `Hypervel\Database\Eloquent\Factories\HasFactory` |
| ULID 主鍵 | `Hypervel\Database\Eloquent\Concerns\HasUlids` |
| Query Builder | `Hyperf\Database\Model\Builder` |

## Model 結構順序

```php
class Media extends Model
{
    use HasUlids;      // 1. Traits
    use SoftDeletes;
    use HasFactory;

    public const string STATUS_CREATED = 'created';  // 2. 常數（狀態、型別）

    public static array $statusMap = [...];          // 3. 常數對照表

    protected ?string $table = 'media';              // 4. 表設定
    protected array $fillable = [...];               // 5. Mass assignment
    protected array $hidden = [...];                 // 6. Hidden fields
    protected array $casts = [...];                  // 7. Casts

    // 8. Relationships
    public function source(): BelongsTo { ... }

    // 9. Scopes
    public function scopeReady(Builder $query): Builder { ... }

    // 10. Accessors / Mutators
    // 11. Business logic methods（輕量）
    public function isReady(): bool { ... }
}
```

## 屬性一律是 typed property

Hyperf 的 Model 把這些宣告成**具型別的屬性**，簽名與 Laravel 不同，覆寫時型別必須一致：

```php
// ✅ 本專案
protected ?string $table = 'media';
protected ?string $connection = null;
protected array $fillable = [...];
protected array $casts = [...];
protected array $hidden = [...];

// ❌ Laravel 寫法，在這裡會踩到型別不相容
protected $table = 'media';
protected $fillable = [...];
```

**沒有 `casts()` 方法**（那是 Laravel 11 的寫法）——一律用 `protected array $casts`。

## Mass Assignment

```php
// ✅ 明確定義 fillable（不用 $guarded = []）
protected array $fillable = [
    'type', 'title', 'description', 'status',
];
```

## Casts 規範

```php
protected array $casts = [
    'duration'     => 'integer',
    'source_id'    => 'string',
    'video_detail' => 'array',      // JSON 欄位
    'published_at' => 'datetime',
    'notify'       => 'boolean',
];
```

> JSON cast 的編碼行為已在 `App\Models\Model::asJson()` 覆寫成
> `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`——中文與網址直接以原字元入庫。
> 新 Model 繼承基底類別即自動生效，**不要**各自再覆寫一次。

## 狀態用 const，不用字面字串

狀態、型別這類列舉值一律定義成 `public const`，並在需要時提供 `public static array $xxxMap`
對照表。Validator 的 `in:` 規則、Service 的判斷、測試的斷言都引用常數，不寫死字串。

```php
public const string STATUS_TRANSCRIBING = 'transcribing';

// 使用
Media::query()->where('status', Media::STATUS_TRANSCRIBING);
```

## Relationships

所有 relationship 方法必須**明確寫出所有參數**，不可省略 foreign key 與 local key，
以便閱讀時直接知道關聯欄位，不需要推斷框架的命名慣例。

```php
// ✅ 明確寫出所有參數
public function captions(): HasMany
{
    return $this->hasMany(Caption::class, 'media_id', 'id');
}

public function source(): BelongsTo
{
    return $this->belongsTo(Source::class, 'source_id', 'id');
}

public function users(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'userables', 'media_id', 'user_id')
        ->withTimestamps();
}

// ❌ 省略參數 — 讀者需要背命名慣例才能理解
public function captions(): HasMany
{
    return $this->hasMany(Caption::class);
}
```

各方法的參數順序：

```
hasOne(Related, foreign_key, local_key)            子 Model / 子表 FK / 當前表 PK
hasMany(Related, foreign_key, local_key)           子 Model / 子表 FK / 當前表 PK
belongsTo(Related, foreign_key, owner_key)         父 Model / 當前表 FK / 父表 PK
belongsToMany(Related, pivot, fk_current, fk_related)
```

```php
// users 表有 id；settings 表有 user_id
public function setting(): HasOne
{
    return $this->hasOne(Setting::class, 'user_id', 'id');
}

// captions 表有 media_id 指向 media.id
public function media(): BelongsTo
{
    return $this->belongsTo(Media::class, 'media_id', 'id');
}

// pivot 表 userables，欄位 media_id / user_id
public function users(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'userables', 'media_id', 'user_id')
        ->withTimestamps();
}
```

### 多型關聯用 `foreign_type` / `foreign_id`

本專案的多型關聯**不用 Laravel 的 `morphTo()` 命名慣例**，而是統一以
`foreign_type` / `foreign_id` 兩欄搭配 `belongsTo($this->foreign_type, 'foreign_id', 'id')`
表達（見 `Paddle`、`Stripe`、`Image` 三個 Model）。新增同類需求時沿用這個形狀。

```php
public function foreign(): BelongsTo
{
    return $this->belongsTo($this->foreign_type, 'foreign_id', 'id');
}

// 反向：加上 foreign_type 過濾
public function stripe(): HasOne
{
    return $this->hasOne(Stripe::class, 'foreign_id', 'id')
        ->where('foreign_type', self::class);
}
```

### 沒有 DB 外鍵約束

`database/migrations/` 全部只建 `foreignUlid()` + `index()`，**不建 FK constraint**。
關聯完整性由應用層負責，寫 Model 或 Service 時不要假設刪除會自動連動。

## Scopes

```php
public function scopeReady(Builder $query): Builder
{
    return $query->where('status', self::STATUS_READY);
}

public function scopeForUser(Builder $query, string $userId): Builder
{
    return $query->where('user_id', $userId);
}

// 使用
Media::ready()->forUser($userId)->get();
```

`Builder` 從 `Hyperf\Database\Model\Builder` import。

## N+1 預防

- 需要 loop 使用 relationship 時，**必須 eager load**
- Controller 回傳 Resource Collection 前先 `with()`；Resource 端用 `whenLoaded()` 呈現
- 本專案**沒有** `Model::preventLazyLoading()`，lazy loading 不會拋錯——N+1 只能靠 review 與測試抓

```php
// ✅ Controller 層 eager load
$medias = Media::query()->with(['source', 'summaries'])->ready()->get();

// ❌ N+1
foreach (Media::all() as $media) {
    echo $media->source->title;   // 每次都發 SQL
}
```

## 協程注意事項

Swoole 是常駐程序，Model 與 Observer 跨請求存活：

- Model **不可**有 static property 儲存 per-request 資料（`static array $xxxMap` 這種
  開機後不再變動的常數對照表不在此限）
- Observer 中不可依賴帶 per-request 狀態的 singleton
- 需要當前使用者時明確取（`Auth::guard('jwt')->user()`），不要快取在類別層級
- Model 的 boot/booted 只執行一次，不要在裡面放請求相關邏輯
