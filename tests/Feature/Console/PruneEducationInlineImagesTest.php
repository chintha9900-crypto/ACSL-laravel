<?php

namespace Tests\Feature\Console;

use App\Models\EducationArticle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\MysqlTestCase;

class PruneEducationInlineImagesTest extends MysqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function image(int $ageHours, string $directory = EducationArticle::INLINE_IMAGE_DIRECTORY): string
    {
        $path = $directory.'/'.strtolower((string) Str::ulid()).'.png';
        Storage::disk('public')->put($path, 'png');
        touch(Storage::disk('public')->path($path), now()->subHours($ageHours)->getTimestamp());

        return $path;
    }

    public function test_only_old_unreferenced_inline_images_are_deleted(): void
    {
        $orphan = $this->image(25);
        $recentOrphan = $this->image(23);
        $usedByDraft = $this->image(48);
        $usedByPublished = $this->image(48);
        $featured = $this->image(48, EducationArticle::FEATURED_IMAGE_DIRECTORY);

        EducationArticle::factory()->create(['content' => '<p><img src="/storage/'.$usedByDraft.'" alt=""></p>']);
        EducationArticle::factory()->published()->create(['content' => '<p><img src="/storage/'.$usedByPublished.'" alt=""></p>']);

        $this->artisan('education:prune-inline-images')->expectsOutput('Deleted 1 unused Education image(s).')->assertSuccessful();

        $disk = Storage::disk('public');
        $disk->assertMissing($orphan);
        $disk->assertExists($recentOrphan);
        $disk->assertExists($usedByDraft);
        $disk->assertExists($usedByPublished);
        $disk->assertExists($featured);

        $this->artisan('education:prune-inline-images')->expectsOutput('Deleted 0 unused Education image(s).')->assertSuccessful();
    }

    public function test_the_cleanup_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'education:prune-inline-images'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }
}
