<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Webhook;

use Hypervel\Http\Request;
use Hyperf\Resource\Json\JsonResource;
use OpenApi\Attributes as OAT;

class YoutubeController
{
    #[OAT\Post(
        path: '/v1/webhook/youtube',
        operationId: 'api.v1.webhook.youtube.store',
        summary: 'Receive YouTube video and audio detail webhook payload',
        tags: ['Webhooks'],
        requestBody: new OAT\RequestBody(
            required: false,
            content: new OAT\JsonContent(
                properties: [
                    new OAT\Property(property: 'video_detail', type: 'object', example: null),
                    new OAT\Property(property: 'audio_detail', type: 'object', example: null),
                ]
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Payload echoed back',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'video_detail', type: 'object'),
                        new OAT\Property(property: 'audio_detail', type: 'object'),
                    ]
                )
            ),
        ]
    )]
    public function store(Request $request)
    {
        $params = $request->only(['video_detail', 'audio_detail']);

        return response()->json($params);
    }
}
