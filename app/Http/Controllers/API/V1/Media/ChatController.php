<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use Hypervel\Http\Request;
use App\Utils\Const\ISO6391;
use OpenApi\Attributes as OAT;
use App\Utils\OpenAI\Completion;
use App\Validators\ChatValidator;
use App\OpenApi\Responses\Http400;
use Psr\Http\Message\ResponseInterface;
use App\OpenApi\Parameters\Path\MediaId;
use App\Services\Prompts\TemplateFactory;
use App\Exceptions\InvalidRequestException;
use App\Services\Prompts\TemplateCompletionManager;

class ChatController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/media/{mediaId}/chat',
        operationId: 'api.v1.media.chat.store',
        summary: 'Chat with video content',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['messages'],
                properties: [
                    new OAT\Property(
                        property: 'messages',
                        type: 'array',
                        items: new OAT\Items(
                            required: ['role', 'content'],
                            properties: [
                                new OAT\Property(
                                    property: 'role',
                                    type: 'string',
                                    enum: ['user', 'assistant', 'system'],
                                    example: 'user'
                                ),
                                new OAT\Property(
                                    property: 'content',
                                    type: 'string',
                                    example: 'What is this video about?'
                                ),
                            ]
                        ),
                        minItems: 1
                    ),
                ]
            )
        ),
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Assistant reply',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'role', type: 'string', example: 'assistant'),
                        new OAT\Property(property: 'content', type: 'string', example: 'This video covers...'),
                    ]
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    public function store(Request $request, string $mediaId): ResponseInterface
    {
        $params = $request->only(['messages']);

        $v = new ChatValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        if (!$media = $request->user()->media()->find($mediaId)) {
            throw new InvalidRequestException(['media' => [__('validators.controllers.media.not_found')]]);
        }

        $completion = new Completion(env('OPENAI_API_KEY'));

        $userMessage = collect($params['messages'])->last()['content'] ?? '';

        $template = TemplateFactory::create('assistant', [
            'user_prompt'      => $media->captions()->orderByDesc('primary')->first()->text ?? '',
            'messages'         => array_pop($params['messages']),
            'respond_language' => ISO6391::getNameByCode($request->user()->setting()->first()->data['ai']['language']),
        ]);
        $openai = new TemplateCompletionManager($completion, $template);
        $response = $openai->complete($userMessage, 'gpt-4.1-mini');

        return response()->json([
            'role'    => 'assistant',
            'content' => $response['choices'][0]['message']['content'],
        ]);
    }
}
