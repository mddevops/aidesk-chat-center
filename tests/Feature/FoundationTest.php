<?php

use Inertia\Testing\AssertableInertia as Assert;

it('serves the Russian Inertia application without exposing environment secrets', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->where('appName', 'AIDesk Chat Center')
        ->missing('env')
        ->missing('reverbSecret'));
});

it('provides the framework liveness endpoint', function () {
    $this->get('/up')->assertOk();
});
