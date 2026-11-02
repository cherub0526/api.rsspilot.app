<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Sources;

use ZipArchive;
use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use App\Models\Summary;
use App\Models\SourceSkill;
use App\Models\Subscription;
use App\Models\SourceSkillDownload;
use Hypervel\Support\Facades\Cache;
use App\Services\SourceSkillService;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\Http\TestResponse;

/**
 * channel-skill T06：GET /v1/sources/{sourceId}/skill/download?format=zip|files（plan〈API 契約〉第二段）。
 *
 * 介面約定：路由名稱 `api.v1.sources.skill.download.index`（`auth` + `throttle:30,1`）。
 *   - 存取權同 GET /skill（已訂閱或免費來源；播放清單 404）
 *   - 方案沒開 skill → 422 `messages.plan`（`validators.controllers.source_skill.plan_required`）
 *   - 使用者 AI 語言（不支援的退回 en，同 T05）沒有成功版本（content 為 null）→ 422 `messages.skill`、`code: skill_not_ready`
 *   - zip：`application/zip`、檔名 `<name>.zip`；送完刪暫存檔；記一筆 kind=zip
 *   - files：`{name, files: [{path: SKILL.md}, {path: references/videos.md}]}`；記一筆 kind=install
 *   - `<name>` 一律由系統決定（skillName()），不採用資料列的 name（SR-9）
 *
 * @internal
 * @coversNothing
 */
class SkillDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENT = "---\nname: x\ndescription: y\n---\n\n## Core principles\n\nFULLCONTENTX\n";

    private const REFERENCES = "# Videos\n\n- REFERENCESX\n";

    private Plan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow('2026-10-30 12:00:00');
        config(['features.channel_skill' => true]);
        $this->proPlan = Plan::withoutEvents(fn () => Plan::factory()->create(['skill_enabled' => true]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(bool $pro, string $language = 'zh-TW', ?string $locale = null): User
    {
        $user = User::factory()->create();
        $data = ['ai' => ['language' => $language]];
        if ($locale !== null) {
            $data['locale'] = $locale;
        }
        $user->setting()->updateOrCreate([], ['data' => $data]);

        if ($pro) {
            $price = Price::withoutEvents(fn () => Price::factory()->create([
                'plan_id' => $this->proPlan->id,
                'unit'    => Price::UNIT_MONTHLY,
                'price'   => 12.99,
            ]));
            Subscription::factory()->create([
                'user_id'        => $user->id,
                'plan_id'        => $this->proPlan->id,
                'price_id'       => $price->id,
                'payment_method' => Subscription::PAYMENT_METHOD_CREEM,
                'status'         => Subscription::STATUS_ACTIVE,
                'next_date'      => now()->addMonth(),
            ]);
        }
        $this->actingAs($user);

        return $user;
    }

    private function channel(?User $subscriber, array $attributes = []): Source
    {
        $source = Source::factory()->create(array_merge([
            'type'   => Source::TYPE_YOUTUBE_CHANNEL,
            'status' => Source::STATUS_ACTIVE,
            'free'   => false,
            'title'  => 'Knife Club',
        ], $attributes));
        for ($n = 1; $n <= 3; ++$n) {
            $media = Media::factory()->create(['source_id' => $source->id]);
            $media->summaries()->create([
                'user_id' => null, 'locale' => 'en', 'status' => Summary::STATUS_COMPLETED,
                'text'    => ['short_summary' => 's', 'long_summary' => ['content' => "Body {$n}", 'key_points' => [], 'keywords' => []]],
            ]);
        }
        $subscriber?->sources()->attach($source->id);

        return $source;
    }

    private function skillName(Source $source): string
    {
        return app(SourceSkillService::class)->skillName($source);
    }

    private function version(Source $source, string $language = 'zh-TW', array $attributes = []): SourceSkill
    {
        return SourceSkill::factory()->create(array_merge([
            'source_id'    => $source->id,
            'language'     => $language,
            'name'         => $this->skillName($source),
            'content'      => self::CONTENT,
            'references'   => self::REFERENCES,
            'status'       => SourceSkill::STATUS_COMPLETED,
            'generated_at' => Carbon::parse('2026-10-01'),
        ], $attributes));
    }

    private function download(Source $source, ?string $format = null, array $headers = []): TestResponse
    {
        $query = ['sourceId' => $source->id] + ($format !== null ? ['format' => $format] : []);

        return $this->json('GET', route('api.v1.sources.skill.download.index', $query), [], $headers);
    }

    /** @return array<string, string> */
    private function zipEntries(string $bytes): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa-zip-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'the body is a zip');
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_ends_with($name, '/')) {
                $entries[$name] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        unlink($path);

        return $entries;
    }

    private function downloads(?string $kind = null): int
    {
        return SourceSkillDownload::query()->when($kind !== null, fn ($q) => $q->where('kind', $kind))->count();
    }

    // ── S02 zip ──────────────────────────────────────

    public function testIs404WhileTheFeatureIsOff(): void
    {
        config(['features.channel_skill' => false]);
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source);

        $this->download($source)
            ->assertStatus(404)
            ->assertExactJson(['messages' => 'Resource not found.']);

        $this->assertSame(0, $this->downloads());
    }

    public function testS02ProUsersDownloadTheZip(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $skill = $this->version($source);
        $name = $this->skillName($source);

        $response = $this->download($source)->assertStatus(200);

        $this->assertSame('application/zip', $response->getHeaderLine('Content-Type'), 'binary, no charset');
        $disposition = $response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString("{$name}.zip", rawurldecode($disposition));
        $this->assertSame([
            "{$name}/SKILL.md"             => self::CONTENT,
            "{$name}/references/videos.md" => self::REFERENCES,
        ], $this->zipEntries((string) $response->getContent()));

        $record = SourceSkillDownload::query()->sole();
        $this->assertSame(SourceSkillDownload::KIND_ZIP, $record->kind);
        $this->assertSame($user->id, $record->user_id);
        $this->assertSame($skill->id, $record->source_skill_id);
    }

    public function testFormatZipIsTheSameAsTheDefault(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source);

        $response = $this->download($source, 'zip')->assertStatus(200);

        $this->assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        $this->assertSame(1, $this->downloads(SourceSkillDownload::KIND_ZIP));
    }

    /** T06 DoD：送完刪暫存檔（zip() 寫在 sys_get_temp_dir()）。 */
    public function testTheTemporaryZipIsDeletedAfterTheResponse(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source);
        $before = glob(sys_get_temp_dir() . '/*') ?: [];

        $this->download($source)->assertStatus(200);

        $left = array_values(array_diff(glob(sys_get_temp_dir() . '/*') ?: [], $before));
        $this->assertSame([], $left, 'no file left behind in the temp directory');
    }

    /** SR-9：資料列的 name 被竄改，檔名與資料夾仍是系統算出的名字；header 不能被注入。 */
    public function testATamperedNameColumnCannotChooseTheFileNameOrHeaders(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source, 'zh-TW', ['name' => "evil\r\nSet-Cookie: x=1\"; ../../x"]);
        $name = $this->skillName($source);

        $response = $this->download($source)->assertStatus(200);

        $this->assertSame('', $response->getHeaderLine('Set-Cookie'));
        $disposition = rawurldecode($response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString("{$name}.zip", $disposition);
        $this->assertStringNotContainsString('evil', $disposition);
        $this->assertSame(["{$name}/SKILL.md", "{$name}/references/videos.md"], array_keys($this->zipEntries((string) $response->getContent())));
    }

    // ── S31 files ────────────────────────────────────

    public function testS31FilesFormatReturnsBothFilesAndRecordsAnInstall(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $skill = $this->version($source);

        $this->download($source, 'files')->assertStatus(200)->assertExactJson([
            'name'  => $this->skillName($source),
            'files' => [
                ['path' => 'SKILL.md', 'content' => self::CONTENT],
                ['path' => 'references/videos.md', 'content' => self::REFERENCES],
            ],
        ]);

        $record = SourceSkillDownload::query()->sole();
        $this->assertSame(SourceSkillDownload::KIND_INSTALL, $record->kind);
        $this->assertSame($user->id, $record->user_id);
        $this->assertSame($skill->id, $record->source_skill_id);
    }

    public function testEveryDownloadIsRecorded(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source);

        $this->download($source)->assertStatus(200);
        $this->download($source)->assertStatus(200);
        $this->download($source, 'files')->assertStatus(200);

        $this->assertSame(2, $this->downloads(SourceSkillDownload::KIND_ZIP));
        $this->assertSame(1, $this->downloads(SourceSkillDownload::KIND_INSTALL));
    }

    public function testDownloadsAreNotCached(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source);

        foreach ([null, 'files'] as $format) {
            $cacheControl = $this->download($source, $format)->assertStatus(200)->getHeaderLine('Cache-Control');
            $this->assertStringContainsString('private', $cacheControl, (string) $format);
            $this->assertStringContainsString('no-store', $cacheControl, (string) $format);
        }
    }

    // ── 422：方案（S06、S25、S39）────────────────────────

    public function testS06FreeUsersGet422Plan(): void
    {
        $user = $this->free();
        $source = $this->channel($user);
        $this->version($source);

        $response = $this->download($source)->assertStatus(422)->assertJsonStructure(['messages' => ['plan']]);

        $this->assertStringNotContainsString('FULLCONTENTX', (string) $response->getContent());
        $this->assertSame(0, $this->downloads());
    }

    public function testS25FilesFormatIsGatedTheSameWay(): void
    {
        $user = $this->free();
        $source = $this->channel($user);
        $this->version($source);

        $response = $this->download($source, 'files')->assertStatus(422)->assertJsonStructure(['messages' => ['plan']]);

        $this->assertStringNotContainsString('FULLCONTENTX', (string) $response->getContent());
        $this->assertStringNotContainsString('REFERENCESX', (string) $response->getContent());
        $this->assertSame(0, $this->downloads());
    }

    /** 方案閘門先於版本檢查：免費使用者在沒有版本的頻道也是 plan，不洩漏「有沒有版本」。 */
    public function testThePlanGateComesBeforeTheVersionCheck(): void
    {
        $user = $this->free();
        $source = $this->channel($user);

        $this->download($source)->assertStatus(422)->assertJsonStructure(['messages' => ['plan']]);
    }

    public function testS39ADowngradedUserCannotDownloadAnymore(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source);
        $this->download($source)->assertStatus(200);

        Subscription::query()->where('user_id', $user->id)->update(['status' => Subscription::STATUS_CANCELED, 'next_date' => now()->subDay()]);

        $this->download($source)->assertStatus(422)->assertJsonStructure(['messages' => ['plan']]);
        $this->download($source, 'files')->assertStatus(422)->assertJsonStructure(['messages' => ['plan']]);
        $this->assertSame(1, $this->downloads());
    }

    // ── 422：沒有成功版本（S30、content null 閘門）─────────────

    public function testS30WithoutAVersionIsSkillNotReady(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);

        $this->download($source)->assertStatus(422)
            ->assertJsonStructure(['messages' => ['skill']])
            ->assertJsonPath('code', 'skill_not_ready');
        $this->assertSame(0, $this->downloads());
    }

    /** T06 DoD：generating／failed 但從沒成功過（content 為 null）的列不能打包成空 zip。 */
    public function testRowsWithoutContentAreSkillNotReady(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source, 'zh-TW', [
            'status' => SourceSkill::STATUS_FAILED, 'content' => null, 'references' => null, 'generated_at' => null,
        ]);

        foreach ([null, 'files'] as $format) {
            $this->download($source, $format)->assertStatus(422)->assertJsonPath('code', 'skill_not_ready');
        }
        $this->assertSame(0, $this->downloads());
    }

    /** S13：重產失敗或背景重產中，舊版仍可下載。 */
    public function testThePreviousVersionStaysDownloadableWhileRegeneratingOrAfterAFailure(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $skill = $this->version($source, 'zh-TW', ['status' => SourceSkill::STATUS_FAILED, 'failed_at' => now()]);

        $this->download($source, 'files')->assertStatus(200)->assertJsonPath('files.0.content', self::CONTENT);

        $skill->forceFill(['status' => SourceSkill::STATUS_GENERATING, 'started_at' => now()])->save();
        $this->download($source, 'files')->assertStatus(200)->assertJsonPath('files.0.content', self::CONTENT);
    }

    /** 下載的是自己 AI 語言的版本；別的語言有版本不代替（Pro 沒有退回規則）。 */
    public function testOnlyTheirOwnLanguageIsDownloadable(): void
    {
        $user = $this->pro('ja');
        $source = $this->channel($user);
        $this->version($source, 'zh-TW');

        $this->download($source)->assertStatus(422)->assertJsonPath('code', 'skill_not_ready');
    }

    /** 同 T05 pm 裁定 1：AI 語言不在支援清單時當成 en。 */
    public function testAnUnsupportedAiLanguageDownloadsTheEnglishVersion(): void
    {
        $user = $this->pro('xx');
        $source = $this->channel($user);
        $this->version($source, 'en', ['content' => "---\nname: x\ndescription: y\n---\n\nENGLISHX\n"]);

        $this->download($source, 'files')->assertStatus(200)->assertJsonPath('files.0.content', "---\nname: x\ndescription: y\n---\n\nENGLISHX\n");
    }

    /** @dataProvider locales */
    public function testThe422MessagesFollowTheCopyDeck(string $locale, string $plan, string $notReady): void
    {
        $free = $this->free('zh-TW', $locale);
        $source = $this->channel($free);
        $this->download($source, null, ['Accept-Language' => $locale])->assertStatus(422)->assertJsonPath('messages.plan.0', $plan);

        $pro = $this->pro('zh-TW', $locale);
        $pro->sources()->attach($source->id);
        $this->download($source, null, ['Accept-Language' => $locale])->assertStatus(422)->assertJsonPath('messages.skill.0', $notReady);
    }

    // ── @copy：422 訊息（copy deck §3.3）─────────────────────

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function locales(): array
    {
        return [
            'zh-TW' => ['zh-TW', '下載頻道 Skill 需要升級方案。', '這個 Skill 還沒準備好，產生完成後就能下載。'],
            'zh-CN' => ['zh-CN', '下载频道 Skill 需要升级方案。', '这个 Skill 还没准备好，生成完成后即可下载。'],
            'en'    => ['en', 'Downloading channel skills requires an upgrade.', "This skill isn't ready yet. You can download it once it's generated."],
        ];
    }

    // ── pm 裁定（T06 卡〈pm 裁定〉）────────────────────────

    /** pm 裁定 1：帶了不認得的 format → 422 `messages.format`，不記錄、不帶內文。 */
    public function testAnUnknownFormatIs422AndNotRecorded(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source);

        foreach (['exe', 'ZIP', 'tar', '../files'] as $format) {
            $response = $this->download($source, $format)->assertStatus(422)->assertJsonStructure(['messages' => ['format']]);
            $this->assertStringNotContainsString('FULLCONTENTX', (string) $response->getContent(), $format);
        }
        $this->assertSame(0, $this->downloads());
    }

    /** pm 裁定 2：422 回應也帶 private, no-store（plan、skill_not_ready、format）。 */
    public function testErrorResponsesAreNotCachedEither(): void
    {
        $free = $this->free();
        $source = $this->channel($free);
        $responses = [
            'plan' => $this->download($source)->assertStatus(422),
        ];

        $pro = $this->pro();
        $pro->sources()->attach($source->id);
        $responses['skill_not_ready'] = $this->download($source)->assertStatus(422);
        $responses['format'] = $this->download($source, 'exe')->assertStatus(422);

        foreach ($responses as $case => $response) {
            $cacheControl = $response->getHeaderLine('Cache-Control');
            $this->assertStringContainsString('private', $cacheControl, $case);
            $this->assertStringContainsString('no-store', $cacheControl, $case);
        }
    }

    // ── 認證、存取權、throttle ────────────────────────────

    public function testUnauthenticatedIs401(): void
    {
        $source = $this->channel(null);

        $this->download($source)->assertStatus(401);
    }

    public function testS14NotSubscribedAndNotFreeIs404(): void
    {
        $this->pro();
        $source = $this->channel(null);
        $this->version($source);

        $this->download($source)->assertStatus(404);
        $this->download($source, 'files')->assertStatus(404);
        $this->assertSame(0, $this->downloads());
    }

    public function testAFreeSourceTheyDoNotFollowCanBeDownloaded(): void
    {
        $this->pro();
        $source = $this->channel(null, ['free' => true]);
        $this->version($source);

        $this->download($source, 'files')->assertStatus(200);
    }

    public function testS41APlaylistIs404(): void
    {
        $user = $this->pro();
        $source = $this->channel($user, ['type' => Source::TYPE_YOUTUBE_PLAYLIST]);
        $this->version($source);

        $this->download($source)->assertStatus(404);
    }

    public function testS35ThrottledAtThirtyRequestsAMinute(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source);

        for ($i = 0; $i < 30; ++$i) {
            $this->download($source, 'files')->assertStatus(200);
        }
        $this->download($source, 'files')->assertStatus(429);
        $this->assertSame(30, $this->downloads());
    }

    // ── qa 邊界（T06 r1）─────────────────────────────

    /** @dataProvider formatMessages */
    public function testTheFormatMessageFollowsTheCopyDeck(string $locale, string $message): void
    {
        $user = $this->pro('zh-TW', $locale);
        $source = $this->channel($user);
        $this->version($source);

        $this->download($source, 'exe', ['Accept-Language' => $locale])->assertStatus(422)->assertJsonPath('messages.format.0', $message);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function formatMessages(): array
    {
        return [
            'zh-TW' => ['zh-TW', '不支援這個下載格式。'],
            'zh-CN' => ['zh-CN', '不支持这个下载格式。'],
            'en'    => ['en', "This download format isn't supported."],
        ];
    }

    /** `?format[]=zip` 是陣列不是字串：422，不能被當成 zip。 */
    public function testAnArrayFormatIs422(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source);

        $uri = route('api.v1.sources.skill.download.index', ['sourceId' => $source->id, 'format' => ['zip']]);
        $this->json('GET', $uri)->assertStatus(422)->assertJsonStructure(['messages' => ['format']]);
        $this->assertSame(0, $this->downloads());
    }

    /** 閘門順序 plan → format：免費使用者帶亂寫的 format 仍是 plan。 */
    public function testThePlanGateComesBeforeTheFormatCheck(): void
    {
        $user = $this->free();
        $source = $this->channel($user);
        $this->version($source);

        $this->download($source, 'exe')->assertStatus(422)
            ->assertJsonStructure(['messages' => ['plan']])
            ->assertJsonMissingPath('messages.format');
    }

    /** format 先於版本檢查：沒有版本時，亂寫的 format 回 format，不是 skill_not_ready。 */
    public function testTheFormatCheckComesBeforeTheVersionCheck(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);

        $this->download($source, 'exe')->assertStatus(422)
            ->assertJsonStructure(['messages' => ['format']])
            ->assertJsonMissingPath('messages.skill');
    }

    public function testAnUnknownSourceIdIs404(): void
    {
        $this->pro();
        $source = $this->channel(null);
        $source->delete();

        $this->download($source)->assertStatus(404);
    }

    /** files 格式：references 是 null 的舊列輸出空字串，結構不變。 */
    public function testFilesFormatWithoutReferencesKeepsTheShape(): void
    {
        $user = $this->pro();
        $source = $this->channel($user);
        $this->version($source, 'zh-TW', ['references' => null]);

        $this->download($source, 'files')->assertStatus(200)
            ->assertJsonPath('files.1.path', 'references/videos.md')
            ->assertJsonPath('files.1.content', '');
    }

    private function pro(string $language = 'zh-TW', ?string $locale = null): User
    {
        return $this->user(true, $language, $locale);
    }

    private function free(string $language = 'zh-TW', ?string $locale = null): User
    {
        return $this->user(false, $language, $locale);
    }
}
