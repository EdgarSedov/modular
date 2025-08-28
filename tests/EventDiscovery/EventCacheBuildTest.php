<?php

namespace InterNACHI\Modular\Tests\EventDiscovery;

use InterNACHI\Modular\Support\Facades\Modules;
use InterNACHI\Modular\Tests\Concerns\PreloadsAppModules;
use InterNACHI\Modular\Tests\TestCase;

class EventCacheBuildTest extends TestCase
{
    use PreloadsAppModules;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app-modules.should_discover_events', true);
    }

    public function test_event_cache_builds_are_deterministic(): void
    {
        $this->artisan('optimize:clear');

        $event = 'Modules\\TestModule\\Events\\TestEvent';
        $listener = 'Modules\\TestModule\\Listeners\\TestEventListener@handle';

        $originalArgv = $_SERVER['argv'] ?? [];

        try {
            $_SERVER['argv'] = ['artisan', 'event:cache'];

            $this->refreshApplication();
            Modules::reload();
            $this->artisan('event:cache');
            $cache1 = require $this->app->getCachedEventsPath();
            $this->assertTrue($this->cacheHasListener($cache1, $event, $listener));

            $this->refreshApplication();
            Modules::reload();
            $this->artisan('event:cache');
            $cache2 = require $this->app->getCachedEventsPath();
            $this->assertTrue($this->cacheHasListener($cache2, $event, $listener));

            $this->assertEquals($cache1, $cache2);
        } finally {
            $_SERVER['argv'] = $originalArgv;
            $this->artisan('event:clear');
        }
    }

    private function cacheHasListener(array $cache, string $event, string $listener): bool
    {
        foreach ($cache as $events) {
            if (isset($events[$event]) && in_array($listener, $events[$event], true)) {
                return true;
            }
        }

        return false;
    }
}

class EventCacheBuildDisabledTest extends TestCase
{
    use PreloadsAppModules;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app-modules.should_discover_events', true);
        $app['config']->set('app-modules.discover_events_during_cache_builds', false);
    }

    public function test_event_cache_build_can_shrink_when_disabled(): void
    {
        $this->artisan('optimize:clear');

        $event = 'Modules\\TestModule\\Events\\TestEvent';
        $listener = 'Modules\\TestModule\\Listeners\\TestEventListener@handle';

        $originalArgv = $_SERVER['argv'] ?? [];

        try {
            $_SERVER['argv'] = ['artisan', 'event:cache'];

            $this->refreshApplication();
            Modules::reload();
            $this->artisan('event:cache');
            $cache1 = require $this->app->getCachedEventsPath();
            $this->assertTrue($this->cacheHasListener($cache1, $event, $listener));

            $this->refreshApplication();
            Modules::reload();
            $this->artisan('event:cache');
            $cache2 = require $this->app->getCachedEventsPath();
            $this->assertFalse($this->cacheHasListener($cache2, $event, $listener));
        } finally {
            $_SERVER['argv'] = $originalArgv;
            $this->artisan('event:clear');
        }
    }

    private function cacheHasListener(array $cache, string $event, string $listener): bool
    {
        foreach ($cache as $events) {
            if (isset($events[$event]) && in_array($listener, $events[$event], true)) {
                return true;
            }
        }

        return false;
    }
}
