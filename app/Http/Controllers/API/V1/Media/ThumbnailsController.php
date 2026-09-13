<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\OpenApi\Responses\Http422;
use App\Services\ThumbnailService;
use App\Validators\ThumbnailValidator;
use App\OpenApi\Parameters\Path\Second;
use Psr\Http\Message\ResponseInterface;
use App\OpenApi\Parameters\Path\MediaId;
use App\Exceptions\NotFoundHttpException;
use App\Http\Resources\ThumbnailResource;
use App\OpenApi\Parameters\Path\Checksum;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Http\Controllers\Concerns\ResolvesUserPlan;
use App\Http\Controllers\API\V1\Media\Chat\ResolvesMedia;
use App\OpenApi\Schemas\ThumbnailResource as ThumbnailSchema;

/**
 * 影片畫面截圖：使用者在播放器按下截圖後，把該秒的畫面存成可以餵給 AI 的圖片。
 *
 * 圖片以內容定址：key 是 (second, checksum)，而真正識別畫面的是 checksum。一秒有
 * 24–60 幀，只用秒數當 key 會讓同一秒的不同畫面互相頂替（見 ThumbnailService）。
 *
 * GET 因此要帶 checksum，也就是說前端必須先截圖、編碼、算完 hash 才問得出來——
 * 這一步省不掉了，能省的只有上傳那 150KB。相對地，內容定址換到一個更重要的性質：
 * **沒有人能替換掉別人在某一秒看到的畫面**，因為 key 是從內容推導出來的。
 */
class ThumbnailsController extends AbstractController
{
    use ResolvesMedia;
    use ResolvesUserPlan;

    public function __construct(private ThumbnailService $thumbnails)
    {
    }

    /**
     * GET /v1/media/{mediaId}/thumbnails/{second}.
     *
     * 取這一秒已存在的截圖。沒有就是 404，前端據此決定要不要上傳。
     *
     * @throws NotFoundHttpException
     */
    #[OAT\Get(
        path: '/v1/media/{mediaId}/thumbnails/{second}/{checksum}',
        operationId: 'api.v1.media.thumbnails.show',
        summary: 'Check whether this exact frame is already stored',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
            new OAT\Parameter(ref: Second::class),
            new OAT\Parameter(ref: Checksum::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'This frame is already stored; upload can be skipped',
                content: new OAT\JsonContent(ref: ThumbnailSchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(
                ref: Http404::class,
                response: 404,
                description: 'Media not accessible, or this frame has not been stored yet'
            ),
        ]
    )]
    public function show(
        Request $request,
        string $mediaId,
        string $second,
        string $checksum
    ): ResponseInterface {
        $media = $this->resolveMedia($request, $mediaId);
        $key = (string) $media->getKey();
        $offset = (int) $second;

        if (!$this->thumbnails->exists($key, $offset, $checksum)) {
            throw new NotFoundHttpException();
        }

        return response()->json(new ThumbnailResource([
            'media_id' => $key,
            'second'   => $offset,
            'checksum' => $checksum,
            'url'      => $this->thumbnails->url($key, $offset, $checksum),
        ]));
    }

    /**
     * POST /v1/media/{mediaId}/thumbnails.
     *
     * 存下這張截圖。同樣的內容已經有了就直接回傳既有的 URL，不再寫一次。
     *
     * checksum 由前端算、由這裡**重算驗證**：路徑是 checksum 決定的，採信客戶端
     * 自己說的值等於讓它把任意內容擺到任意 key 上，內容定址的保證就沒了。
     *
     * 截圖是 Advance 方案的功能（plans.screenshot_enabled）。GET 不設這道閘門——
     * 它只是「這張存過了嗎」的查詢，而且要先有 checksum 才問得出來。
     *
     * @throws InvalidRequestException
     * @throws NotFoundHttpException
     */
    #[OAT\Post(
        path: '/v1/media/{mediaId}/thumbnails',
        operationId: 'api.v1.media.thumbnails.store',
        summary: 'Upload the screenshot for one second of the video',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OAT\Schema(
                    required: ['file', 'second', 'checksum'],
                    properties: [
                        new OAT\Property(
                            property: 'file',
                            description: 'JPEG frame, max 2 MB',
                            type: 'string',
                            format: 'binary'
                        ),
                        new OAT\Property(
                            property: 'second',
                            description: 'Whole-second offset. Must not exceed the video duration, '
                                . 'nor 999999 — the GET route only addresses up to 6 digits. '
                                . 'Used for ordering and readability, not for identity.',
                            type: 'integer',
                            example: 125
                        ),
                        new OAT\Property(
                            property: 'checksum',
                            description: 'Lowercase hex SHA-256 of the uploaded bytes. Recomputed and '
                                . 'compared server-side; a mismatch is rejected with 422.',
                            type: 'string',
                            example: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
                        ),
                    ]
                )
            )
        ),
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
        ],
        responses: [
            new OAT\Response(
                response: 201,
                description: 'Stored',
                content: new OAT\JsonContent(ref: ThumbnailSchema::class)
            ),
            new OAT\Response(
                response: 200,
                description: 'These exact bytes are already stored; the existing URL is returned',
                content: new OAT\JsonContent(ref: ThumbnailSchema::class)
            ),
            new OAT\Response(
                ref: Http422::class,
                response: 422,
                description: 'Validation failed, the checksum does not match the file, '
                    . 'or the current plan does not include screenshots'
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    public function store(Request $request, string $mediaId): ResponseInterface
    {
        $media = $this->resolveMedia($request, $mediaId);
        $this->assertScreenshotEnabled($request);
        $key = (string) $media->getKey();

        $v = new ThumbnailValidator($request->only(['file', 'second', 'checksum']));
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $second = (int) $request->input('second');

        if (!$this->thumbnails->isWithinDuration($media, $second)) {
            throw new InvalidRequestException([
                'second' => [__('validators.controllers.thumbnails.out_of_range')],
            ]);
        }

        $sourcePath = (string) $request->file('file')->getRealPath();
        $checksum = (string) $request->input('checksum');

        if (!hash_equals($this->thumbnails->checksumOf($sourcePath), $checksum)) {
            throw new InvalidRequestException([
                'checksum' => [__('validators.controllers.thumbnails.checksum_mismatch')],
            ]);
        }

        $created = false;

        if (!$this->thumbnails->exists($key, $second, $checksum)) {
            $this->thumbnails->put($key, $second, $checksum, $sourcePath);
            $created = true;
        }

        return response()->json(
            new ThumbnailResource([
                'media_id' => $key,
                'second'   => $second,
                'checksum' => $checksum,
                'url'      => $this->thumbnails->url($key, $second, $checksum),
            ]),
            $created ? 201 : 200
        );
    }
}
