<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\Image;
use App\Models\Feedback;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use App\OpenApi\Responses\Http400;
use App\Validators\ImageValidator;
use App\Validators\FeedbackValidator;
use Hypervel\Support\Facades\Storage;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;

class FeedbacksController extends AbstractController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/feedbacks',
        operationId: 'api.v1.feedbacks.store',
        summary: 'Submit user feedback',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OAT\Schema(
                    required: ['content'],
                    properties: [
                        new OAT\Property(
                            property: 'content',
                            description: 'Feedback message content',
                            type: 'string',
                            example: 'The transcription quality could be improved.'
                        ),
                        new OAT\Property(
                            property: 'images[]',
                            type: 'array',
                            items: new OAT\Items(
                                description: 'Image file (jpeg, png, jpg, gif, svg; max 2 MB)',
                                type: 'string',
                                format: 'binary'
                            )
                        ),
                    ]
                )
            )
        ),
        tags: ['Feedbacks'],
        responses: [
            new OAT\Response(ref: HttpOk::class, response: 200),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    public function store(Request $request)
    {
        $params = $request->only(['content']);

        $v = new FeedbackValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $v = new ImageValidator($request->only(['images']));
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $feedback = Feedback::create(['content' => $params['content']]);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $key => $file) {
                $image = Image::create([
                    'filename'     => $file->getClientFilename(),
                    'foreign_type' => Feedback::class,
                    'foreign_id'   => $feedback->id,
                ]);
                $destination = sprintf('feedbacks/%s', $image->id);
                $response = Storage::disk('s3')->put($destination, file_get_contents($file->getRealPath()));

                // 如果上傳成功，就將路徑更新，失敗就刪除。
                $response
                    ? $image->update(['path' => $destination])
                    : $image->forceDelete();
            }
        }

        return response()->make(self::RESPONSE_OK);
    }
}
