<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use Hypervel\Http\Request;
use App\Utils\Const\ISO6391;
use OpenApi\Attributes as OAT;
use App\OpenApi\Parameters\Path;
use App\Utils\AI\Completion;
use App\OpenApi\Responses\Http400;
use App\Services\Prompts\TemplateFactory;
use App\Validators\CustomPromptValidator;
use App\Exceptions\InvalidRequestException;
use App\Services\Prompts\TemplateCompletionManager;

class CustomPromptController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/media/{mediaId}/custom-prompt',
        operationId: 'api.v1.media.custom-prompt.store',
        summary: 'Run a custom prompt against a media caption',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['prompt'],
                properties: [
                    new OAT\Property(
                        property: 'prompt',
                        type: 'string',
                        example: '影片中出現了哪些食物？'
                    ),
                ]
            )
        ),
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: Path\MediaId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Custom prompt result',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'text',
                            type: 'string',
                            example: '影片中出現了拉麵、壽司和天婦羅。'
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    public function store(Request $request, string $mediaId)
    {
        $params = $request->only(['prompt']);

        $v = new CustomPromptValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        if (!$media = $request->user()->media()->find($mediaId)) {
            throw new InvalidRequestException(['media' => [__('validators.controllers.media.not_found')]]);
        }

        $template = TemplateFactory::create('customPrompt', [
            'system_prompt'    => $params['prompt'],
            'user_prompt'      => $media->captions()->orderByDesc('primary')->first()->text ?? '',
            'respond_language' => ISO6391::getNameByCode($request->user()->setting()->first()->data['ai']['language']),
        ]);

        $openai = new TemplateCompletionManager(Completion::make(), $template);
        $response = $openai->complete('');

        return response()->json([
            'text' => json_decode($response['choices'][0]['message']['content'], true)[''],
        ]);
    }
}
