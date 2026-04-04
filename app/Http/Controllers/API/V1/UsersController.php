<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use App\Validators\UserValidator;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\Http\Resources\UserResource;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\OpenApi\Schemas\UserResource as UserSchema;

class UsersController extends AbstractController
{
    #[OAT\Get(
        path: '/v1/users',
        operationId: 'api.v1.users.index',
        summary: 'Get current authenticated user profile',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'User profile',
                content: new OAT\JsonContent(ref: UserSchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['setting']));
    }

    #[OAT\Post(
        path: '/v1/users',
        operationId: 'api.v1.users.store',
        summary: 'Update current authenticated user profile (partial)',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: false,
            content: new OAT\JsonContent(
                properties: [
                    new OAT\Property(property: 'name', type: 'string', example: 'John Doe'),
                    new OAT\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                ]
            )
        ),
        tags: ['Users'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Updated user profile',
                content: new OAT\JsonContent(ref: UserSchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function store(Request $request): UserResource
    {
        $params = $request->only(['name', 'email']);

        $user = $request->user();
        $user->fill($params)->save();

        return new UserResource($user);
    }

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Put(
        path: '/v1/users',
        operationId: 'api.v1.users.update',
        summary: 'Update current authenticated user profile',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['name', 'email'],
                properties: [
                    new OAT\Property(property: 'name', type: 'string', example: 'John Doe'),
                    new OAT\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                ]
            )
        ),
        tags: ['Users'],
        responses: [
            new OAT\Response(ref: HttpOk::class, response: 200),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function update(Request $request): ResponseInterface
    {
        $params = $request->only(['name', 'email']);

        $v = new UserValidator($params);
        $v->setUpdateRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $request->user()->fill($params)->save();

        return response()->make(self::RESPONSE_OK);
    }
}
