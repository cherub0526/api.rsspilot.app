---
paths:
  - "app/Http/Resources/**"
---

# API Resource 規則

## 基礎結構

所有 Resource 繼承 `Hypervel\Http\Resources\Json\JsonResource`，扁平放在 `app/Http/Resources/`
（沒有 Group 子目錄），命名為 `{Entity}Resource`。

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class SourceResource extends JsonResource
{
    public ?string $wrap = null;

    public function toArray(): array
    {
        return [ ... ];
    }
}
```

## 兩個與 Laravel 不同的簽名

```php
// ✅ 本專案：toArray() 不收參數
public function toArray(): array

// ❌ Laravel 寫法，在這裡會是型別不相容
public function toArray(Request $request): array
```

需要 request context（例如當前登入者）時直接取，不從參數拿：

```php
$user = Auth::guard('jwt')->user();
```

## `$wrap` 的作用範圍

`JsonResource::$wrap` 預設是 `'data'`。**單筆 Resource 一律宣告 `public ?string $wrap = null;`**，
讓單筆回應直接是物件而不是 `{"data": {...}}`。

集合（`Resource::collection()`）走的是 `AnonymousResourceCollection`，它有自己的 `$wrap`，
**仍然會包在 `data` 底下**——這是刻意的，列表需要 `data` / `meta` / `links` 的分頁形狀。
測試裡的 `assertJsonPath('data.0.id', ...)` 就是依賴這個差異。

## 存取 Model 資料

**一律使用 `$this->resource->property`**，不使用 `$this->property` magic proxy。

```php
// ✅ 明確存取底層 Model
'id' => strval($this->resource->id),

// ❌ 透過魔術方法，型別不明確
'id' => $this->id,
```

## 型別轉換規則

每個欄位回傳值**必須明確轉型**，對應 Model cast 或欄位定義：

| PHP 型別 | 轉型方式 | 範例 |
|---------|---------|------|
| `string` | `strval()` | `strval($this->resource->title)` |
| `int` | `intval()` | `intval($this->resource->duration)` |
| `float` | `floatval()` | `floatval($this->resource->price)` |
| `bool` | `boolval()` / `(bool)` | `(bool) ($this->resource->pivot?->notify ?? true)` |
| datetime | `?->toIso8601String()` | 見下方 |
| `array`（JSON cast） | 直接回傳（已由 cast 轉型） | `$this->resource->video_detail` |

> ULID 主鍵是字串，用 `strval()`，**不要** `intval()`。

**nullable 欄位：**

```php
// nullable string：想保留 null 就明寫
'thumbnail' => $this->resource->thumbnail !== null
    ? strval($this->resource->thumbnail)
    : null,

// 想把 null 收斂成空字串
'name' => strval($this->resource->title ?? ''),
```

## 日期欄位

本專案**沒有** `FormatsDatetime` 這類共用 trait。日期一律以 ISO-8601 輸出，
並用 `getAttribute()` + null-safe 取值——直接 `$this->resource->created_at`
在欄位沒被 select 出來時會是 `null`，`?->` 才不會炸：

```php
// ✅ 標準寫法
'created_at' => $this->resource->getAttribute('created_at')?->toIso8601String(),
'updated_at' => $this->resource->getAttribute('updated_at')?->toIso8601String(),
```

> 既有的 `MediaResource::published_at`、`RSSResource::created_at` 仍用
> `strval()` 輸出框架預設格式。**那是既有契約，不要順手改**——改格式等於改 API 回應。
> 新欄位一律用上面的 ISO-8601 寫法。

## 關聯資料（whenLoaded）

關聯欄位使用 `$this->whenLoaded()`，回傳值同樣套用型別轉換：

```php
// 單筆關聯
'setting' => new SettingResource($this->whenLoaded('setting')),

// 需要額外加工時用 closure（關聯沒載入就不會被執行）
'source' => $this->whenLoaded('source', fn () => new SourceResource($this->resource->source)),

// 集合關聯
'items' => SummaryResource::collection($this->whenLoaded('summaries')),
```

`whenLoaded()` 在關聯未載入時回傳 `MissingValue`，該 key 會整個從輸出中消失——
所以**前端不能假設這個 key 一定存在**，Controller 那端要負責 `with()` 載入。

### 欄位排序：`whenLoaded` 一律放最後

關聯欄位**必須排在所有本體欄位之後**，不可夾在本體欄位中間。

理由：本體欄位每次都存在，關聯欄位則取決於 Controller 有沒有 eager load，時有時無。
混排會讓回傳 JSON 的 key 順序隨查詢條件變動，也讓人無法一眼看出哪些欄位是「一定會有」的。

```php
// ✅ 本體欄位在前，關聯欄位集中在後
return [
    'id'         => strval($this->resource->id),
    'title'      => strval($this->resource->title),
    'created_at' => $this->resource->getAttribute('created_at')?->toIso8601String(),

    // 以下為關聯欄位
    'source'  => $this->whenLoaded('source', fn () => new SourceResource($this->resource->source)),
    'summaries' => SummaryResource::collection($this->whenLoaded('summaries')),
];
```

## 衍生欄位放 private 方法

需要判斷或組裝的欄位（顯示用網址、摘要節錄…）抽成 `private` 方法，
讓 `toArray()` 維持一眼看完的欄位清單：

```php
public function toArray(): array
{
    return [
        'url' => $this->resolveDisplayUrl(),
    ];
}

private function resolveDisplayUrl(): string
{
    return $this->resource->type === Source::TYPE_YOUTUBE_CHANNEL
        ? 'https://www.youtube.com/channel/' . $this->resource->external_id
        : 'https://www.youtube.com/playlist?list=' . $this->resource->external_id;
}
```

## 對應的 OpenAPI Schema

每個 Resource 都要有同名的 Schema 類別在 `app/OpenApi/Schemas/`。
**Schema attribute 不寫在 Resource 類別上**——見 `openapi.md`。

## 不做的事

- **不使用 `$this->property`**（魔術存取，型別不可控）
- **不回傳未轉型的原始值**（除非該欄位已由 Model cast 保證型別）
- **不在 Resource 寫查詢**（會造成 N+1；資料由 Controller 先 `with()` 載好）
- **不把 `whenLoaded` 欄位夾在本體欄位之間**（一律排最後）
- **不在 Resource 建構子注入 Service**（Resource 每筆資料都會建立新實例）
- **不改既有欄位的輸出格式或型別**（那是對外契約，要改需另行確認）
