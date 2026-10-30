<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Sources;

use App\Models\Source;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\OpenApi\Responses\Http429;
use App\Services\SourceSkillService;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\OpenApi\Parameters\Path\SourceId;
use App\Services\SourceSkill\SkillStatus;
use App\Http\Resources\SourceSkillResource;
use App\Http\Controllers\AbstractController;
use App\Services\SourceSkill\SkillStatusResolver;
use App\OpenApi\Schemas\SourceSkillResource as SourceSkillSchema;

class SkillController extends AbstractController
{
    #[OAT\Get(
        path: '/v1/sources/{sourceId}/skill',
        operationId: 'api.v1.sources.skill.show',
        description: 'Status and content of the channel skill in the user AI language. '
            . 'May queue a generation as a side effect (at most 10 per user per UTC day). Playlists are 404.',
        summary: 'Get the channel skill',
        security: [['bearerAuth' => []]],
        tags: ['Sources'],
        parameters: [new OAT\Parameter(ref: SourceId::class)],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Skill status; `reason` is only set when `status` is `not_generated`',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'status', type: 'string', enum: [
                            SkillStatus::READY, SkillStatus::GENERATING, SkillStatus::FAILED,
                            SkillStatus::INSUFFICIENT, SkillStatus::NOT_GENERATED,
                        ]),
                        new OAT\Property(property: 'reason', type: 'string', nullable: true, enum: [
                            SkillStatus::REASON_UPGRADE_REQUIRED, SkillStatus::REASON_SUBSCRIBE_REQUIRED, SkillStatus::REASON_DAILY_LIMIT,
                        ]),
                        new OAT\Property(property: 'locked', type: 'boolean'),
                        new OAT\Property(property: 'language', description: 'Language of the returned skill', type: 'string', example: 'zh-TW'),
                        new OAT\Property(property: 'min_videos', type: 'integer', example: SourceSkillService::MIN_SHARED_SUMMARIES),
                        new OAT\Property(property: 'summarized_count', type: 'integer', example: 12),
                        new OAT\Property(property: 'skill', ref: SourceSkillSchema::class, nullable: true),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
            new OAT\Response(ref: Http429::class, response: 429),
        ]
    )]
    public function show(Request $request, string $sourceId): ResponseInterface
    {
        $user = $request->user();

        // 存取權同 SourcesController@show：已訂閱，或免費來源
        $source = $user->sources()->find($sourceId);
        $subscribed = $source !== null;
        $source ??= Source::query()->where('id', $sourceId)->where('free', true)->first();

        // v1 只支援頻道（規則 1）
        if ($source === null || $source->getAttribute('type') !== Source::TYPE_YOUTUBE_CHANNEL) {
            throw new NotFoundHttpException();
        }

        $status = app(SkillStatusResolver::class)->resolve($user, $source, $subscribed);

        return response()->json([
            'status'           => $status->status,
            'reason'           => $status->reason,
            'locked'           => $status->locked,
            'language'         => $status->language,
            'min_videos'       => SourceSkillService::MIN_SHARED_SUMMARIES,
            'summarized_count' => $status->summarizedCount,
            'skill'            => $status->skill === null ? null : (new SourceSkillResource($status->skill))
                ->locked($status->locked)
                ->outline(app(SourceSkillService::class)->outlineOf((string) $status->skill->getAttribute('content')))
                ->resolve(),
        ])->withHeader('Cache-Control', 'private, no-store');
    }
}
