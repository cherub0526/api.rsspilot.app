<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Storage;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class FeedbacksControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testStoreRequiresAuth(): void
    {
        Storage::fake('s3');

        $this->json('POST', route('api.v1.feedbacks.store'), ['content' => 'Great app!'])
            ->assertStatus(401);
    }

    public function testStoreValidatesRequiredContent(): void
    {
        Storage::fake('s3');
        $this->fakeLogin();

        $this->json('POST', route('api.v1.feedbacks.store'), [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['content']]);
    }

    public function testStoreValidatesImageFileType(): void
    {
        Storage::fake('s3');
        $this->fakeLogin();

        $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $this->json('POST', route('api.v1.feedbacks.store'), [
            'content' => 'Found a bug.',
            'images'  => [$file],
        ])->assertStatus(422)->assertJsonStructure(['messages' => ['images.0']]);
    }

    public function testStoreSucceedsWithoutImages(): void
    {
        Storage::fake('s3');
        $this->fakeLogin();

        $this->json('POST', route('api.v1.feedbacks.store'), ['content' => 'The transcription quality could be improved.'])
            ->assertStatus(200);

        $this->assertDatabaseHas('feedbacks', [
            'content' => 'The transcription quality could be improved.',
        ]);
    }

    // Note: a success-path test asserting the uploaded image is persisted to
    // S3 is not included here. The `images` field is an array of files
    // (`images[]`), and this project's JSON test client (TestClient::request())
    // only recognizes a top-level UploadedFileInterface value as a file upload;
    // it does not unwrap files nested inside an array field, so `hasFile('images')`
    // never sees them under test even though a real multipart HTTP request would.
    // testStoreValidatesImageFileType above still exercises validation for such
    // fields because the validator inspects the raw input value directly.
}
