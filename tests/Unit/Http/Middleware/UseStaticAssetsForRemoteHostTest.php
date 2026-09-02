<?php

declare(strict_types=1);

use App\Http\Middleware\UseStaticAssetsForRemoteHost;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;

beforeEach(function (): void {
    config(['app.static_asset_hosts' => ['insights.ac495.net']]);
    config(['session.secure' => false]);
});

it('forces the built manifest and a secure cookie for a configured remote host', function (): void {
    $middleware = new UseStaticAssetsForRemoteHost;
    $request = Request::create('https://insights.ac495.net/');

    $seenHotFile = null;
    $seenSecure = null;

    $middleware->handle($request, function ($req) use (&$seenHotFile, &$seenSecure): ResponseFactory|\Illuminate\Http\Response {
        $seenHotFile = app(Vite::class)->hotFile();
        $seenSecure = config('session.secure');

        return response('ok');
    });

    expect($seenHotFile)->toBe(public_path('hot-static'))
        ->and($seenSecure)->toBeTrue();
});

it('leaves LAN hosts on the default hot file and existing cookie setting', function (): void {
    $middleware = new UseStaticAssetsForRemoteHost;
    $request = Request::create('http://insights.dev.local.test/');

    $seenHotFile = null;
    $seenSecure = null;

    $middleware->handle($request, function ($req) use (&$seenHotFile, &$seenSecure): ResponseFactory|\Illuminate\Http\Response {
        $seenHotFile = app(Vite::class)->hotFile();
        $seenSecure = config('session.secure');

        return response('ok');
    });

    expect($seenHotFile)->toBe(public_path('hot'))
        ->and($seenSecure)->toBeFalse();
});

it('restores the original hot file path and session secure flag after the response', function (): void {
    $middleware = new UseStaticAssetsForRemoteHost;
    $request = Request::create('https://insights.ac495.net/');

    $middleware->handle($request, fn ($req): ResponseFactory|\Illuminate\Http\Response => response('ok'));

    expect(app(Vite::class)->hotFile())->toBe(public_path('hot'))
        ->and(config('session.secure'))->toBeFalse();
});
