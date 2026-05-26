<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Users;

use Hypervel\Support\Str;
use App\Models\UserAvatar;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\Validators\AvatarValidator;
use App\Http\Resources\UserResource;
use Hypervel\Support\Facades\Storage;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\OpenApi\Schemas\UserResource as UserSchema;

class AvatarController extends AbstractController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/users/avatar',
        operationId: 'api.v1.users.avatar.store',
        summary: 'Upload user avatar',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OAT\Schema(
                    required: ['file'],
                    properties: [
                        new OAT\Property(
                            property: 'file',
                            description: 'Avatar image (jpeg, png, jpg, webp; max 2 MB)',
                            type: 'string',
                            format: 'binary'
                        ),
                    ]
                )
            )
        ),
        tags: ['Users'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Updated user profile with avatar URL',
                content: new OAT\JsonContent(ref: UserSchema::class)
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function store(Request $request): UserResource
    {
        $v = new AvatarValidator($request->only(['file']));
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $user = $request->user();
        $file = $request->file('file');

        $originalFilename = method_exists($file, 'getClientOriginalName')
            ? $file->getClientOriginalName()
            : $file->getClientFilename();

        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $uuid = (string) Str::uuid();
        $path = "avatars/{$user->id}/{$uuid}.{$ext}";

        Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()));

        UserAvatar::create([
            'user_id'  => $user->id,
            'filename' => $originalFilename,
            'path'     => $path,
        ]);

        $user->update(['avatar' => $path]);

        return new UserResource($user->load(['setting']));
    }
}
