<?php
namespace App\Providers;
use App\Models\{CvEntry,ExternalService,ImportRecord,MediaAsset,Membership,Project,ProjectMedia,ProjectTrack,Setting,Track};
use App\Services\DraftPreviewService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void
    {
        config([
            'livewire.temporary_file_upload.rules' => [
                'required',
                'file',
                'max:'.(int) config('cms.media_upload_max_kb'),
            ],
        ]);

        RateLimiter::for('publisher', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('publisher-preview-upload', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('contact', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by(hash('sha256', (string) $request->ip())));
        $invalidate=static fn()=>app(DraftPreviewService::class)->invalidateAll();
        foreach([CvEntry::class,ExternalService::class,ImportRecord::class,MediaAsset::class,Membership::class,Project::class,ProjectMedia::class,ProjectTrack::class,Setting::class,Track::class] as $model){
            $model::saved($invalidate);
            $model::deleted($invalidate);
        }
    }
}
