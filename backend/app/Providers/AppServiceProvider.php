<?php
namespace App\Providers;
use App\Models\{CvEntry,ExternalService,ImportRecord,MediaAsset,Membership,Project,ProjectMedia,ProjectTrack,Setting,Track};
use App\Services\DraftPreviewService;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void
    {
        $invalidate=static fn()=>app(DraftPreviewService::class)->invalidateAll();
        foreach([CvEntry::class,ExternalService::class,ImportRecord::class,MediaAsset::class,Membership::class,Project::class,ProjectMedia::class,ProjectTrack::class,Setting::class,Track::class] as $model){
            $model::saved($invalidate);
            $model::deleted($invalidate);
        }
    }
}
