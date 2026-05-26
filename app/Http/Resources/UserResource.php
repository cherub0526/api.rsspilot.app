<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public ?string $wrap = null;

    /**
     * Transform the resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id'      => strval($this->resource->id),
            'name'    => strval($this->resource->name),
            'email'   => strval($this->resource->email),
            'account' => strval($this->resource->account),
            'avatar'  => $this->resource->avatar
                ? rtrim(config('app.cdn_url'), '/') . '/' . $this->resource->avatar
                : null,
            'setting' => new SettingResource($this->whenLoaded('setting')),
        ];
    }
}
