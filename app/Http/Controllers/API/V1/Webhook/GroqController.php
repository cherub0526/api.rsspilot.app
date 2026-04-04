<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Webhook;

use App\Models\Media;
use App\Models\Caption;
use Hypervel\Http\Request;
use App\Utils\Const\ISO6391;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use App\Validators\GroqValidator;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http404;
use App\OpenApi\Parameters\Path\MediaId;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;

class GroqController extends AbstractController
{
    #[OAT\Post(
        path: '/v1/webhook/groq/{mediaId}',
        operationId: 'api.v1.webhook.groq.store',
        summary: 'Receive Groq transcription webhook callback',
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
                        description: 'Transcription payload. When status=success: language, duration, text, words, segments are required. When status=error: error is required.',
                        properties: [
                            new OAT\Property(property: 'language', type: 'string', example: 'English'),
                            new OAT\Property(property: 'duration', type: 'number', format: 'float', example: 123.45),
                            new OAT\Property(property: 'text', type: 'string', example: 'Hello world.'),
                            new OAT\Property(
                                property: 'words',
                                type: 'array',
                                items: new OAT\Items(type: 'object')
                            ),
                            new OAT\Property(
                                property: 'segments',
                                type: 'array',
                                items: new OAT\Items(type: 'object')
                            ),
                            new OAT\Property(
                                property: 'error',
                                type: 'string',
                                example: 'Transcription failed due to audio quality.'
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

        $v = new GroqValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        if (!$media = Media::query()->find($mediaId)) {
            throw new NotFoundHttpException();
        }

        if ($params['status'] === GroqValidator::STATUS_SUCCESS) {
            $locale = ISO6391::getCodeByName($params['data']['language']);

            $caption = Caption::query()->firstOrCreate([
                'locale'   => $locale,
                'media_id' => $mediaId,
                'primary'  => true,
            ]);

            $caption->fill([
                'text'     => $params['data']['text'],
                'segments' => array_map(function ($segment) {
                    $segment['text'] = trim($segment['text']);
                    return $segment;
                }, $params['data']['segments']),
                'word_segments' => $params['data']['words'] ?? [],
            ])->save();
        }

        $media->fill([
            'status' => match ($params['status']) {
                'success' => Media::STATUS_TRANSCRIBED,
                'error'   => Media::STATUS_TRANSCRIBE_FAILED,
                default   => Media::STATUS_TRANSCRIBING,
            },
        ])->save();

        return response()->make(self::RESPONSE_OK);
    }
}
