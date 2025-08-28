<?php

namespace InterNACHI\Modular\Support;

use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use ReflectionProperty;
use Symfony\Component\Finder\SplFileInfo;

class ModularEventServiceProvider extends ServiceProvider
{
	public function register()
	{
		// We need to do this in the App::booting hook to ensure that it registers
		// events before the EventServiceProvider::booting callback triggers. It's
		// necessary to modify the existing EventServiceProvider's $listen array,
		// rather than just register our own EventServiceProvider subclass, because
		// Laravel behaves differently if the non-default provider is registered.
		$this->app->booting(function() {
			$events = $this->getEvents();
			$provider = Arr::first($this->app->getProviders(EventServiceProvider::class));
			
			if (! $provider || empty($events)) {
				return;
			}
			
			$listen = new ReflectionProperty($provider, 'listen');
			$listen->setAccessible(true);
			$listen->setValue($provider, array_merge_recursive($listen->getValue($provider), $events));
		});
	}
	
       public function getEvents(): array
       {
               // If events are cached we'll skip discovery unless we're currently building
               // the events cache. The cache-building commands need to re-run discovery so
               // that repeated cache builds produce identical manifests.
               $discover_during_cache = config('app-modules.discover_events_during_cache_builds', true);

               if (
                       $this->app->eventsAreCached()
                       && (! $discover_during_cache || ! $this->isCacheBuildProcess())
               ) {
                       return [];
               }

               // If event discovery has been disabled, let the normal service provider handle
               // the event loading.
               if (! $this->shouldDiscoverEvents()) {
                       return [];
               }

               return $this->discoverEvents();
       }

       /**
        * Determine if the current process is actively building Laravel's caches.
        *
        * We conservatively check the CLI arguments for "event:cache" or
        * "optimize" to avoid relying on environment variables or other state.
        */
        protected function isCacheBuildProcess(): bool
        {
               if (PHP_SAPI !== 'cli') {
                       return false;
               }

               $argv = $_SERVER['argv'] ?? [];

               return in_array('event:cache', $argv, true)
                       || in_array('optimize', $argv, true);
        }
	
	public function shouldDiscoverEvents(): bool
	{
		return config('app-modules.should_discover_events')
			?? $this->appIsConfiguredToDiscoverEvents();
	}
	
	public function discoverEvents()
	{
		$modules = $this->app->make(ModuleRegistry::class);
		
		return $this->app->make(AutoDiscoveryHelper::class)
			->listenerDirectoryFinder()
			->map(fn(SplFileInfo $directory) => $directory->getPathname())
			->reduce(function($discovered, string $directory) use ($modules) {
				$module = $modules->moduleForPath($directory);
				return array_merge_recursive(
					$discovered,
					DiscoverEvents::within($directory, $module->path('src'))
				);
			}, []);
	}
	
	public function appIsConfiguredToDiscoverEvents(): bool
	{
		return collect($this->app->getProviders(EventServiceProvider::class))
			->filter(fn(EventServiceProvider $provider) => $provider::class === EventServiceProvider::class
				|| str_starts_with(get_class($provider), $this->app->getNamespace()))
			->contains(fn(EventServiceProvider $provider) => $provider->shouldDiscoverEvents());
	}
}
