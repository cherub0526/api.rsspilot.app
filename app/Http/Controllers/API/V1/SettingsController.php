<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\HttpOk;
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
        summary: 'Update user AI settings',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['ai'],
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
                ]
            )
        ),
        tags: ['Settings'],
        responses: [
            new OAT\Response(ref: Ok::class, response: 200),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function update(Request $request)
    {
        $params = $request->only(['ai']);

        $v = new SettingValidator($params);
        $v->setUpdateRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $setting = $request->user()->setting()->first();

        $setting->update([
            'data' => array_merge($setting->data, $params),
        ]);

        return response()->make(self::RESPONSE_OK);
    }
}
