<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Webhook;

use App\Models\Media;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http404;
use App\Validators\SummaryValidator;
use App\OpenApi\Parameters\Path\MediaId;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;

class SummariesController extends AbstractController
{
    #[OAT\Post(
        path: '/v1/webhook/summaries/{mediaId}',
        operationId: 'api.v1.webhook.summaries.store',
        summary: 'Receive AI summary webhook callback',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['locale', 'text'],
                properties: [
                    new OAT\Property(property: 'locale', type: 'string', example: 'en'),
                    new OAT\Property(
                        property: 'text',
                        required: ['short_summary', 'long_summary'],
                        properties: [
                            new OAT\Property(
                                property: 'short_summary',
                                type: 'string',
                                example: 'A brief summary of the video.'
                            ),
                            new OAT\Property(
                                property: 'long_summary',
                                required: ['content', 'key_points', 'keywords'],
                                properties: [
                                    new OAT\Property(
                                        property: 'content',
                                        type: 'string',
                                        example: 'A detailed summary of the video content.'
                                    ),
                                    new OAT\Property(
                                        property: 'key_points',
                                        type: 'array',
                                        items: new OAT\Items(type: 'string'),
                                        example: ['Point one', 'Point two']
                                    ),
                                    new OAT\Property(
                                        property: 'keywords',
                                        type: 'array',
                                        items: new OAT\Items(type: 'string'),
                                        example: ['keyword1', 'keyword2']
                                    ),
                                ],
                                type: 'object'
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
        $params = $request->only(['locale', 'text']);

        $v = new SummaryValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        if (!$media = Media::query()->find($mediaId)) {
            throw new NotFoundHttpException();
        }

        $summary = $media->summaries()->firstOrCreate(['locale' => $params['locale']]);

        $summary->fill(['text' => $params['text']])->save();

        $media->fill(['status' => Media::STATUS_SUMMARIZED])->save();

        return response()->make(self::RESPONSE_OK);
    }
}
