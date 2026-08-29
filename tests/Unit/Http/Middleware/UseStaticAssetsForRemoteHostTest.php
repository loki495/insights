<?php

declare(strict_types=1);

use App\Http\Middleware\UseStaticAssetsForRemoteHost;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;

beforeEach(function () {
    config(['app.static_asset_hosts' => ['insights.ac495.net']]);
    config(['session.secure' => false]);
});

it('forces the built manifest and a secure cookie for a configured remote host', function () {
    $middleware = new UseStaticAssetsForRemoteHost;
    $request = Request::create('https://insights.ac495.net/');

    $seenHotFile = null;
    $seenSecure = null;

    $middleware->handle($request, function ($req) use (&$seenHotFile, &$seenSecure) {
        $seenHotFile = app(Vite::class)->hotFile();
        $seenSecure = config('session.secure');

        return response('ok');
    });

    expect($seenHotFile)->toBe(public_path('hot-static'));
    expect($seenSecure)->toBeTrue();
});

it('leaves LAN hosts on the default hot file and existing cookie setting', function () {
    $middleware = new UseStaticAssetsForRemoteHost;
    $request = Request::create('http://insights.dev.local.test/');

    $seenHotFile = null;
    $seenSecure = null;

    $middleware->handle($request, function ($req) use (&$seenHotFile, &$seenSecure) {
        $seenHotFile = app(Vite::class)->hotFile();
        $seenSecure = config('session.secure');

        return response('ok');
    });

    expect($seenHotFile)->toBe(public_path('hot'));
    expect($seenSecure)->toBeFalse();
});

it('restores the original hot file path and session secure flag after the response', function () {
    $middleware = new UseStaticAssetsForRemoteHost;
    $request = Request::create('https://insights.ac495.net/');

    $middleware->handle($request, fn ($req) => response('ok'));

    expect(app(Vite::class)->hotFile())->toBe(public_path('hot'));
    expect(config('session.secure'))->toBeFalse();
});
