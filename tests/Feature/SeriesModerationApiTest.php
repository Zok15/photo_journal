<?php

namespace Tests\Feature;

use App\Jobs\ModerateSeriesContent;
use App\Models\Photo;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use App\Services\PhotoAutoTagger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeriesModerationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vision.enabled', false);
    }

    public function test_store_public_series_goes_to_pending_moderation(): void
    {
        Queue::fake();
        Storage::fake('local');
        config()->set('filesystems.default', 'local');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->post('/api/v1/series', [
            'title' => 'Needs moderation',
            'is_public' => true,
            'photos' => [$this->fakeImage('one.jpg')],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('publication_status', Series::PUBLICATION_PENDING_MODERATION);
        $response->assertJsonPath('moderation_status', Series::MODERATION_PENDING);

        $this->assertDatabaseHas('series', [
            'title' => 'Needs moderation',
            'is_public' => 0,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Queue::assertPushed(ModerateSeriesContent::class, 1);
    }

    public function test_public_endpoints_exclude_pending_series(): void
    {
        $author = User::factory()->create();

        Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Published',
            'is_public' => true,
            'publication_status' => Series::PUBLICATION_PUBLISHED,
            'moderation_status' => Series::MODERATION_APPROVED,
        ]);

        $pending = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Pending',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        $index = $this->getJson('/api/v1/public/series');
        $index->assertOk();

        $titles = collect($index->json('data'))->pluck('title')->all();
        $this->assertContains('Published', $titles);
        $this->assertNotContains('Pending', $titles);

        $show = $this->getJson("/api/v1/public/series/{$pending->slug}");
        $show->assertNotFound();
    }

    public function test_admin_can_publish_pending_series_without_additional_checks(): void
    {
        $admin = User::factory()->create();
        Role::query()->firstOrCreate(['name' => 'moderator']);
        $admin->assignRole('moderator');

        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Pending publish',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/v1/admin/series');
        $list->assertOk();
        $list->assertJsonPath('data.0.id', $series->id);

        $publish = $this->postJson("/api/v1/admin/series/{$series->slug}/publish", [
            'reason' => 'Approved manually',
        ]);

        $publish->assertOk();
        $publish->assertJsonPath('data.publication_status', Series::PUBLICATION_PUBLISHED);
        $publish->assertJsonPath('data.moderation_status', Series::MODERATION_MANUAL_APPROVED);
        $publish->assertJsonPath('data.is_public', true);

        $this->assertDatabaseHas('series', [
            'id' => $series->id,
            'publication_status' => Series::PUBLICATION_PUBLISHED,
            'moderation_status' => Series::MODERATION_MANUAL_APPROVED,
            'is_public' => 1,
            'moderated_by' => $admin->id,
        ]);
    }

    public function test_admin_series_list_returns_all_public_user_fields_without_sensitive_values(): void
    {
        $admin = User::factory()->create();
        Role::query()->firstOrCreate(['name' => 'moderator']);
        $admin->assignRole('moderator');

        $author = User::factory()->create([
            'journal_title' => 'My Journal',
            'locale' => 'en',
            'email_verified_at' => now(),
        ]);

        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Pending with author payload',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/v1/admin/series');
        $list->assertOk();
        $list->assertJsonPath('data.0.id', $series->id);
        $list->assertJsonPath('data.0.user.id', $author->id);
        $list->assertJsonPath('data.0.user.email', $author->email);
        $list->assertJsonPath('data.0.user.journal_title', 'My Journal');
        $list->assertJsonPath('data.0.user.locale', 'en');
        $list->assertJsonPath('data.0.user.email_verified_at', $author->email_verified_at?->toJSON());
        $list->assertJsonMissingPath('data.0.user.password');
        $list->assertJsonMissingPath('data.0.user.remember_token');
    }

    public function test_admin_can_publish_rejected_series_without_additional_checks(): void
    {
        $admin = User::factory()->create();
        Role::query()->firstOrCreate(['name' => 'moderator']);
        $admin->assignRole('moderator');

        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Rejected publish',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_REJECTED,
            'moderation_status' => Series::MODERATION_REJECTED,
            'moderation_reason' => 'Detected risky labels',
            'moderation_labels' => ['nudity'],
        ]);

        Sanctum::actingAs($admin);

        $publish = $this->postJson("/api/v1/admin/series/{$series->slug}/publish", [
            'reason' => 'Approved manually after review',
        ]);

        $publish->assertOk();
        $publish->assertJsonPath('data.publication_status', Series::PUBLICATION_PUBLISHED);
        $publish->assertJsonPath('data.moderation_status', Series::MODERATION_MANUAL_APPROVED);
        $publish->assertJsonPath('data.is_public', true);
        $publish->assertJsonPath('data.moderation_reason', 'Approved manually after review');

        $this->assertDatabaseHas('series', [
            'id' => $series->id,
            'publication_status' => Series::PUBLICATION_PUBLISHED,
            'moderation_status' => Series::MODERATION_MANUAL_APPROVED,
            'is_public' => 1,
            'moderated_by' => $admin->id,
            'moderation_reason' => 'Approved manually after review',
        ]);
    }

    public function test_non_admin_cannot_use_admin_moderation_endpoints(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $series = Series::query()->create([
            'user_id' => $user->id,
            'title' => 'Draft',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        $this->getJson('/api/v1/admin/series')->assertForbidden();
        $this->postJson("/api/v1/admin/series/{$series->slug}/publish")->assertForbidden();
    }

    public function test_moderation_rejects_series_when_blocked_safety_tag_detected(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Potentially unsafe',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/unsafe.jpg',
            'original_name' => 'unsafe.jpg',
            'size' => 12345,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['femaleBreast', 'sexualContent', 'outdoor']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();

        $this->assertSame(Series::PUBLICATION_REJECTED, $series->publication_status);
        $this->assertSame(Series::MODERATION_REJECTED, $series->moderation_status);
        $this->assertFalse((bool) $series->is_public);
        $this->assertContains('femaleBreast', (array) $series->moderation_labels);
    }

    public function test_moderation_does_not_reject_context_sensitive_tag_without_human_or_support_context(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Night scene',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/night-scene.jpg',
            'original_name' => 'night-scene.jpg',
            'size' => 12345,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['nudity', 'night']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();

        $this->assertSame(Series::PUBLICATION_PUBLISHED, $series->publication_status);
        $this->assertSame(Series::MODERATION_APPROVED, $series->moderation_status);
        $this->assertTrue((bool) $series->is_public);
    }

    public function test_moderation_does_not_auto_approve_when_vision_is_enabled_but_unhealthy(): void
    {
        config()->set('vision.enabled', true);
        config()->set('vision.url', 'http://127.0.0.1:65530/tag');

        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Vision unavailable',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/vision-unhealthy.jpg',
            'original_name' => 'vision-unhealthy.jpg',
            'size' => 12345,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldNotReceive('detectTagsForModeration');
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();

        $this->assertSame(Series::PUBLICATION_PENDING_MODERATION, $series->publication_status);
        $this->assertSame(Series::MODERATION_PENDING, $series->moderation_status);
        $this->assertFalse((bool) $series->is_public);
    }

    public function test_moderation_does_not_reject_direct_contextual_risk_without_support_tags(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Safe series',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/safe.jpg',
            'original_name' => 'safe.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);
        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['sexualContent', 'outdoorScene', 'portrait']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();

        $this->assertSame(Series::PUBLICATION_PUBLISHED, $series->publication_status);
        $this->assertSame(Series::MODERATION_APPROVED, $series->moderation_status);
        $this->assertTrue((bool) $series->is_public);
    }

    public function test_moderation_does_not_reject_non_direct_contextual_risk_tags_in_benign_context(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Suspicious series',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/suspicious-1.jpg',
            'original_name' => 'suspicious-1.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);
        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/suspicious-2.jpg',
            'original_name' => 'suspicious-2.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $riskA = Tag::query()->firstOrCreate(['name' => 'weapon']);
        $riskB = Tag::query()->firstOrCreate(['name' => 'violence']);
        $series->tags()->syncWithoutDetaching([
            $riskA->id => ['source' => 'auto'],
            $riskB->id => ['source' => 'auto'],
        ]);
        $mock->shouldReceive('detectTagsForModeration')
            ->twice()
            ->andReturn(
                ['weapon', 'flower'],
                ['violence', 'plant']
            );
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();

        $this->assertSame(Series::PUBLICATION_PUBLISHED, $series->publication_status);
        $this->assertSame(Series::MODERATION_APPROVED, $series->moderation_status);
        $this->assertTrue((bool) $series->is_public);
    }

    public function test_moderation_does_not_reject_human_required_contextual_risk_without_human_context(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'No evidence',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/no-evidence.jpg',
            'original_name' => 'no-evidence.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['pornography', 'night']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();
        $this->assertSame(Series::PUBLICATION_PUBLISHED, $series->publication_status);
        $this->assertSame(Series::MODERATION_APPROVED, $series->moderation_status);
        $this->assertTrue((bool) $series->is_public);
    }

    public function test_moderation_rejects_human_required_contextual_risk_when_direct_risk_is_present(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Adult risk combo',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/adult-risk-combo.jpg',
            'original_name' => 'adult-risk-combo.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['pornography', 'adultContent']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();
        $this->assertSame(Series::PUBLICATION_REJECTED, $series->publication_status);
        $this->assertSame(Series::MODERATION_REJECTED, $series->moderation_status);
        $this->assertFalse((bool) $series->is_public);
        $this->assertContains('pornography', (array) $series->moderation_labels);
    }

    public function test_moderation_does_not_reject_weapon_without_human_context_even_with_direct_risk_noise(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Weapon noise',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/weapon-noise.jpg',
            'original_name' => 'weapon-noise.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['weapon', 'sexualContent', 'drink']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();
        $this->assertSame(Series::PUBLICATION_PUBLISHED, $series->publication_status);
        $this->assertSame(Series::MODERATION_APPROVED, $series->moderation_status);
        $this->assertTrue((bool) $series->is_public);
    }

    public function test_moderation_is_not_suppressed_by_series_search_tags(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Search tags do not affect moderation',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/manual-tags.jpg',
            'original_name' => 'manual-tags.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);

        $flower = Tag::query()->firstOrCreate(['name' => 'flower']);
        $bird = Tag::query()->firstOrCreate(['name' => 'bird']);
        $series->tags()->syncWithoutDetaching([
            $flower->id => ['source' => 'manual'],
            $bird->id => ['source' => 'manual'],
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['adultContent', 'nude']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();
        $this->assertSame(Series::PUBLICATION_REJECTED, $series->publication_status);
        $this->assertSame(Series::MODERATION_REJECTED, $series->moderation_status);
        $this->assertFalse((bool) $series->is_public);
        $this->assertContains('adultContent', (array) $series->moderation_labels);
    }

    public function test_moderation_does_not_reject_direct_contextual_risk_with_only_closeup_support(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Direct risk',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/direct-risk.jpg',
            'original_name' => 'direct-risk.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['adultContent', 'closeup']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();
        $this->assertSame(Series::PUBLICATION_PUBLISHED, $series->publication_status);
        $this->assertSame(Series::MODERATION_APPROVED, $series->moderation_status);
        $this->assertTrue((bool) $series->is_public);
    }

    public function test_moderation_does_not_reject_weapon_with_human_context(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Violence with people',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/violence.jpg',
            'original_name' => 'violence.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['weapon', 'people', 'street']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();
        $this->assertSame(Series::PUBLICATION_PUBLISHED, $series->publication_status);
        $this->assertSame(Series::MODERATION_APPROVED, $series->moderation_status);
        $this->assertTrue((bool) $series->is_public);
    }

    public function test_sync_tags_rejects_already_published_series_on_hard_nsfw_tag(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Published series',
            'is_public' => true,
            'publication_status' => Series::PUBLICATION_PUBLISHED,
            'moderation_status' => Series::MODERATION_APPROVED,
        ]);

        app(PhotoAutoTagger::class)->syncSeriesTags($series, ['femaleBreast', 'portrait'], true);

        $series->refresh();
        $this->assertSame(Series::PUBLICATION_REJECTED, $series->publication_status);
        $this->assertSame(Series::MODERATION_REJECTED, $series->moderation_status);
        $this->assertFalse((bool) $series->is_public);
        $this->assertContains('femaleBreast', (array) $series->moderation_labels);
    }

    public function test_sync_tags_does_not_override_manual_approved_series(): void
    {
        $admin = User::factory()->create();
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Manual approved series',
            'is_public' => true,
            'publication_status' => Series::PUBLICATION_PUBLISHED,
            'moderation_status' => Series::MODERATION_MANUAL_APPROVED,
            'moderated_by' => $admin->id,
            'moderated_at' => now(),
        ]);

        app(PhotoAutoTagger::class)->syncSeriesTags($series, ['femaleBreast', 'portrait'], true);

        $series->refresh();
        $this->assertSame(Series::PUBLICATION_PUBLISHED, $series->publication_status);
        $this->assertSame(Series::MODERATION_MANUAL_APPROVED, $series->moderation_status);
        $this->assertTrue((bool) $series->is_public);
    }

    public function test_moderation_ignores_context_sensitive_tag_in_wildlife_context(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Birds',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/bird.jpg',
            'original_name' => 'bird.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['femaleBreast', 'bird', 'hawk']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();
        $this->assertSame(Series::PUBLICATION_PUBLISHED, $series->publication_status);
        $this->assertSame(Series::MODERATION_APPROVED, $series->moderation_status);
        $this->assertTrue((bool) $series->is_public);
    }

    public function test_moderation_ignores_context_sensitive_tag_in_flower_context(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Flowers',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/flower.jpg',
            'original_name' => 'flower.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['nude', 'flower', 'plant']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();
        $this->assertSame(Series::PUBLICATION_PUBLISHED, $series->publication_status);
        $this->assertSame(Series::MODERATION_APPROVED, $series->moderation_status);
        $this->assertTrue((bool) $series->is_public);
    }

    public function test_moderation_does_not_ignore_context_sensitive_tag_when_human_context_present(): void
    {
        $author = User::factory()->create();
        $series = Series::query()->create([
            'user_id' => $author->id,
            'title' => 'Human',
            'is_public' => false,
            'publication_status' => Series::PUBLICATION_PENDING_MODERATION,
            'moderation_status' => Series::MODERATION_PENDING,
        ]);

        Photo::query()->create([
            'series_id' => $series->id,
            'path' => 'photos/series/human.jpg',
            'original_name' => 'human.jpg',
            'size' => 1000,
            'mime' => 'image/jpeg',
        ]);

        $mock = \Mockery::mock(PhotoAutoTagger::class);
        $mock->shouldReceive('detectTagsForModeration')
            ->once()
            ->andReturn(['nude', 'person', 'portrait']);
        $this->app->instance(PhotoAutoTagger::class, $mock);

        (new ModerateSeriesContent($series->id))->handle($mock);

        $series->refresh();
        $this->assertSame(Series::PUBLICATION_REJECTED, $series->publication_status);
        $this->assertSame(Series::MODERATION_REJECTED, $series->moderation_status);
        $this->assertFalse((bool) $series->is_public);
        $this->assertContains('nude', (array) $series->moderation_labels);
    }

    private function fakeImage(string $name): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7YfU8AAAAASUVORK5CYII=',
            true
        );

        return UploadedFile::fake()->createWithContent($name, $png);
    }
}
