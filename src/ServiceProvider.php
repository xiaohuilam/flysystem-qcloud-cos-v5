<?php

namespace Freyo\Flysystem\QcloudCOSv5;

use Freyo\Flysystem\QcloudCOSv5\Plugins\CDN;
use Freyo\Flysystem\QcloudCOSv5\Plugins\CloudInfinite;
use Freyo\Flysystem\QcloudCOSv5\Plugins\GetFederationToken;
use Freyo\Flysystem\QcloudCOSv5\Plugins\GetFederationTokenV3;
use Freyo\Flysystem\QcloudCOSv5\Plugins\GetUrl;
use Freyo\Flysystem\QcloudCOSv5\Plugins\PutRemoteFile;
use Freyo\Flysystem\QcloudCOSv5\Plugins\PutRemoteFileAs;
use Freyo\Flysystem\QcloudCOSv5\Plugins\TCaptcha;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Laravel\Lumen\Application as LumenApplication;
use League\Flysystem\Filesystem;
use Qcloud\Cos\Client;

/**
 * Class ServiceProvider.
 */
class ServiceProvider extends LaravelServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app instanceof LumenApplication) {
            $this->app->configure('filesystems');
        }

        $this->app->make('filesystem')
            ->extend('cosv5', function ($app, $config) {
                $adapter = new Adapter(new Client($config), $config);
                
                return new FilesystemAdapter(
                    new Filesystem($adapter, $config),
                    $adapter,
                    $config
                );
            });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/filesystems.php', 'filesystems'
        );
    }
}
