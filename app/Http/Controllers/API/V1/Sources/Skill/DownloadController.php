<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Sources\Skill;

use App\Models\User;
use App\Models\Source;
use Hypervel\Http\Request;
use App\Models\SourceSkill;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\OpenApi\Responses\Http422;
use App\OpenApi\Responses\Http429;
use App\Models\SourceSkillDownload;
use App\Services\SourceSkillService;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\OpenApi\Parameters\Path\SourceId;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Http\Controllers\Concerns\SendsDownloads;
use App\Http\Controllers\Concerns\ResolvesUserPlan;

class DownloadController extends AbstractController
{
    use ResolvesUserPlan;
    use SendsDownloads;

    public const FORMAT_ZIP = 'zip';

    /** 桌面版安裝用：兩個檔案的內容放在 JSON 裡，由 app 自己寫進 skills 資料夾。 */
    public const FORMAT_FILES = 'files';

    #[OAT\Get(
        path: '/v1/sources/{sourceId}/skill/download',
        operationId: 'api.v1.sources.skill.download.index',
        description: 'Download the channel skill in the user AI language. Every successful download is recorded. Playlists are 404, '
            . 'and so is every request while channel skills are turned off on this server.',
        summary: 'Download the channel skill',
        security: [['bearerAuth' => []]],
        tags: ['Sources'],
        parameters: [
            new OAT\Parameter(ref: SourceId::class),
            new OAT\Parameter(
                name: 'format',
                description: '`zip` (default) returns `<name>.zip`; `files` returns both files as JSON for the desktop install',
                in: 'query',
                required: false,
                schema: new OAT\Schema(type: 'string', default: self::FORMAT_ZIP, enum: [self::FORMAT_ZIP, self::FORMAT_FILES])
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: '`application/zip` for `format=zip`; JSON for `format=files`',
                content: [
                    new OAT\MediaType(mediaType: 'application/zip', schema: new OAT\Schema(type: 'string', format: 'binary')),
                    new OAT\JsonContent(properties: [
                        new OAT\Property(property: 'name', type: 'string', example: 'rsspilot-example-channel-abc123'),
                        new OAT\Property(property: 'files', type: 'array', items: new OAT\Items(properties: [
                            new OAT\Property(property: 'path', type: 'string', enum: ['SKILL.md', 'references/videos.md']),
                            new OAT\Property(property: 'content', type: 'string'),
                        ])),
                    ]),
                ]
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
            new OAT\Response(ref: Http422::class, response: 422, description: '`messages.plan`, `messages.format`, or `messages.skill` with `code: skill_not_ready`'),
            new OAT\Response(ref: Http429::class, response: 429),
        ]
    )]
    public function index(Request $request, string $sourceId): ResponseInterface
    {
        /** @var User $user */
        $user = $request->user();

        // 存取權同 SkillController@show：已訂閱，或免費來源；v1 只支援頻道
        $source = $user->sources()->find($sourceId)
            ?? Source::query()->where('id', $sourceId)->where('free', true)->first();
        if ($source === null || $source->getAttribute('type') !== Source::TYPE_YOUTUBE_CHANNEL) {
            throw new NotFoundHttpException();
        }

        // 422 也不能被快取：方案或版本一變，同一個網址的答案就跟著變
        try {
            return $this->download($request, $user, $source)->withHeader('Cache-Control', 'private, no-store');
        } catch (InvalidRequestException $e) {
            return $e->render()->withHeader('Cache-Control', 'private, no-store');
        }
    }

    /** @throws InvalidRequestException */
    private function download(Request $request, User $user, Source $source): ResponseInterface
    {
        // 方案先於版本：免費使用者不該從回應得知這個頻道有沒有產生過
        $this->assertSkillEnabled($request);

        $format = $request->query('format', self::FORMAT_ZIP);
        if (!in_array($format, [self::FORMAT_ZIP, self::FORMAT_FILES], true)) {
            throw new InvalidRequestException(['format' => [__('validators.controllers.source_skill.format')]]);
        }

        $service = app(SourceSkillService::class);

        // 只看自己語言的成功版本；重產中或重產失敗時，content 仍是上一版
        /** @var null|SourceSkill $skill */
        $skill = SourceSkill::query()
            ->where('source_id', $source->getKey())
            ->where('language', $service->languageFor($user))
            ->whereNotNull('generated_at')
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->first();
        if ($skill === null) {
            throw new InvalidRequestException(
                ['skill' => [__('validators.controllers.source_skill.not_ready')]],
                errorCode: 'skill_not_ready'
            );
        }

        // 名稱一律由系統重算，不採用資料列的 name（SR-9）
        $name = $service->skillName($source);

        if ($format === self::FORMAT_FILES) {
            $response = response()->json([
                'name'  => $name,
                'files' => [
                    ['path' => 'SKILL.md', 'content' => (string) $skill->getAttribute('content')],
                    ['path' => 'references/videos.md', 'content' => (string) $skill->getAttribute('references')],
                ],
            ]);
        } else {
            $path = $service->zip($skill);
            try {
                $response = $this->binaryResponse((string) file_get_contents($path), "{$name}.zip", 'application/zip');
            } finally {
                @unlink($path);
            }
        }

        // 只寫系統值，不吃請求資料
        (new SourceSkillDownload())->forceFill([
            'user_id'         => $user->getKey(),
            'source_skill_id' => $skill->getKey(),
            'kind'            => $format === self::FORMAT_FILES ? SourceSkillDownload::KIND_INSTALL : SourceSkillDownload::KIND_ZIP,
        ])->save();

        return $response;
    }
}
