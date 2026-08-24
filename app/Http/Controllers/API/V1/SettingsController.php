<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\Validators\SettingValidator;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;

class SettingsController extends AbstractController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Put(
        path: '/v1/settings',
        operationId: 'api.v1.settings.update',
        summary: 'Update user AI and locale settings',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            description: 'Partial update — send `ai`, `locale`, or both. At least one is required.',
            content: new OAT\JsonContent(
                properties: [
                    new OAT\Property(
                        property: 'ai',
                        required: ['language'],
                        properties: [
                            new OAT\Property(
                                property: 'language',
                                description: 'ISO 639-1 language code for AI output',
                                type: 'string',
                                example: 'en'
                            ),
                        ],
                        type: 'object'
                    ),
                    new OAT\Property(
                        property: 'locale',
                        description: 'UI locale, one of config(\'app.available_locales\')',
                        type: 'string',
                        enum: ['en', 'zh-TW', 'zh-CN'],
                        example: 'zh-TW'
                    ),
                ]
            )
        ),
        tags: ['Settings'],
        responses: [
            new OAT\Response(ref: HttpOk::class, response: 200),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function update(Request $request)
    {
        $params = $request->only(['ai', 'locale']);

        $v = new SettingValidator($params);
        $v->setUpdateRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $setting = $request->user()->setting()->firstOrCreate(
            ['user_id' => $request->user()->id],
            ['data' => []]
        );

        $setting->update([
            'data' => array_merge($setting->data ?? [], $params),
        ]);

        return response()->make(self::RESPONSE_OK);
    }
}
