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
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Http\Controllers\API\V1\Media\Chat\ResolvesMedia;
use App\OpenApi\Schemas\ThumbnailResource as ThumbnailSchema;

/**
 * 影片畫面截圖：使用者在播放器按下截圖後，把該秒的畫面存成可以餵給 AI 的圖片。
 *
 * 刻意拆成 GET 與 POST 兩支而不是「POST 上去再判斷有沒有重複」：同一個 mediaId 的
 * 畫面內容對所有使用者都一樣，截圖因此是跨使用者共用的、命中率很高，而單一端點的
 * 形狀會逼瀏覽器每次都把整張圖傳完，後端才回「這張已經有了」——省到的只有儲存，
 * 最貴的上傳頻寬照付。前端先 GET 問一次（一個 S3 HEAD），沒有才編碼上傳。
 */
class ThumbnailsController extends AbstractController
{
    use ResolvesMedia;

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
        path: '/v1/media/{mediaId}/thumbnails/{second}',
        operationId: 'api.v1.media.thumbnails.show',
        summary: 'Get the cached screenshot for one second of the video',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
            new OAT\Parameter(ref: Second::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'The screenshot already exists',
                content: new OAT\JsonContent(ref: ThumbnailSchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(
                ref: Http404::class,
                response: 404,
                description: 'Media not accessible, or nothing captured at this second yet'
            ),
        ]
    )]
    public function show(Request $request, string $mediaId, string $second): ResponseInterface
    {
        $media = $this->resolveMedia($request, $mediaId);
        $key = (string) $media->getKey();
        $offset = (int) $second;

        if (!$this->thumbnails->exists($key, $offset)) {
            throw new NotFoundHttpException();
        }

        return response()->json(new ThumbnailResource([
            'media_id' => $key,
            'second'   => $offset,
            'url'      => $this->thumbnails->url($key, $offset),
        ]));
    }

    /**
     * POST /v1/media/{mediaId}/thumbnails.
     *
     * 存下這一秒的截圖。已經有了就原樣回傳既有的那張，**不覆寫**——這張圖是所有
     * 使用者在那一秒共同看到的畫面，允許覆寫等於允許後來的人替換掉它。
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
                    required: ['file', 'second'],
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
                                . 'nor 999999 — the GET route only addresses up to 6 digits.',
                            type: 'integer',
                            example: 125
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
                description: 'Already captured by someone else; the existing image is returned untouched',
                content: new OAT\JsonContent(ref: ThumbnailSchema::class)
            ),
            new OAT\Response(ref: Http422::class, response: 422),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    public function store(Request $request, string $mediaId): ResponseInterface
    {
        $media = $this->resolveMedia($request, $mediaId);
        $key = (string) $media->getKey();

        $v = new ThumbnailValidator($request->only(['file', 'second']));
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

        $created = false;

        if (!$this->thumbnails->exists($key, $second)) {
            $this->thumbnails->put($key, $second, (string) $request->file('file')->getRealPath());
            $created = true;
        }

        return response()->json(
            new ThumbnailResource([
                'media_id' => $key,
                'second'   => $second,
                'url'      => $this->thumbnails->url($key, $second),
            ]),
            $created ? 201 : 200
        );
    }
}
