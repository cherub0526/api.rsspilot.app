<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Webhook;

use App\Models\Media;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http404;
use App\OpenApi\Parameters\Path\MediaId;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Validators\YoutubeMp3DownloaderValidator;

class YoutubeMp3DownloaderController extends AbstractController
{
    #[OAT\Post(
        path: '/v1/webhook/youtube-mp3-downloader/{mediaId}',
        operationId: 'api.v1.webhook.youtube-mp3-downloader.store',
        summary: 'Receive YouTube MP3 downloader webhook callback',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['status', 'data'],
                properties: [
                    new OAT\Property(
                        property: 'status',
                        type: 'string',
                        enum: ['success', 'error'],
                        example: 'success'
                    ),
                    new OAT\Property(
                        property: 'data',
                        required: ['status', 'link'],
                        properties: [
                            new OAT\Property(
                                property: 'status',
                                type: 'string',
                                enum: ['ok', 'processing', 'fail'],
                                example: 'ok'
                            ),
                            new OAT\Property(
                                property: 'link',
                                type: 'string',
                                format: 'uri',
                                example: 'https://example.com/audio.mp3'
                            ),
                        ],
                        type: 'object'
                    ),
                ]
            )
        ),
        tags: ['Webhook'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
        ],
        responses: [
            new OAT\Response(ref: HttpOk::class, response: 200),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    /**
     * @throws NotFoundHttpException
     * @throws InvalidRequestException
     */
    public function store(Request $request, string $mediaId)
    {
        $params = $request->only(['status', 'data']);

        $v = new YoutubeMp3DownloaderValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        if (!$media = Media::query()->find($mediaId)) {
            throw new NotFoundHttpException();
        }

        $media->fill([
            'audio_detail' => $params['data'],
            'status'       => Media::STATUS_PROGRESS,
        ])->save();

        return response()->make(self::RESPONSE_OK);
    }
}
