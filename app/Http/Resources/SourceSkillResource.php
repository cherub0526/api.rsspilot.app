<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

/**
 * 頻道 Skill 在 GET 回應裡的唯一出口（SR-6）。
 *
 * 不輸出整個 Model：`references` 只走下載；`content` 只有方案開放 skill 時才給，
 * 上鎖時由後端截成 null，不靠前端遮罩。
 */
class SourceSkillResource extends JsonResource
{
    public ?string $wrap = null;

    private bool $locked = true;

    /** @var list<string> */
    private array $outline = [];

    public function locked(bool $locked): self
    {
        $this->locked = $locked;

        return $this;
    }

    /** @param list<string> $outline 由存下來的 content 算出的目錄，見 SourceSkillService::outlineOf() */
    public function outline(array $outline): self
    {
        $this->outline = $outline;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'name'         => strval($this->resource->name ?? ''),
            'description'  => strval($this->resource->description ?? ''),
            'outline'      => $this->outline,
            'content'      => $this->locked ? null : strval($this->resource->content ?? ''),
            'video_count'  => intval($this->resource->video_count),
            'generated_at' => $this->resource->getAttribute('generated_at')?->toIso8601String(),
        ];
    }
}
