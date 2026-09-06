<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveDemoDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/insights-demo-test-'.uniqid();
    mkdir($this->tempDir, recursive: true);

    $this->templatePath = $this->tempDir.'/template.sqlite';
    file_put_contents($this->templatePath, 'fake-sqlite-bytes');

    $this->storagePath = $this->tempDir.'/demo-dbs';

    $this->originalDefaultConnection = config('database.default');

    config([
        'app.demo_mode' => true,
        'app.demo_db_template_path' => $this->templatePath,
        'app.demo_db_storage_path' => $this->storagePath,
    ]);
});

afterEach(function (): void {
    config([
        'app.demo_mode' => false,
        'database.default' => $this->originalDefaultConnection,
    ]);
    // Deliberately never touches the real 'sqlite' connection RefreshDatabase manages for the
    // rest of the suite — only the dedicated demo connection this middleware creates. See
    // ResolveDemoDatabase's docblock for why purging 'sqlite' itself corrupted unrelated tests.
    DB::purge(ResolveDemoDatabase::CONNECTION_NAME);

    array_map(unlink(...), glob($this->storagePath.'/*') ?: []);
    @rmdir($this->storagePath);
    array_map(unlink(...), glob($this->tempDir.'/*') ?: []);
    @rmdir($this->tempDir);
});

it('does nothing when demo mode is off', function (): void {
    config(['app.demo_mode' => false]);
    $beforeDefault = config('database.default');

    $response = (new ResolveDemoDatabase)->handle(Request::create('/'), fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok')
        ->and(config('database.default'))->toBe($beforeDefault)
        ->and(Cookie::hasQueued('demo_instance_id'))->toBeFalse();
});

it('creates a new demo id cookie and copies the template on first visit', function (): void {
    (new ResolveDemoDatabase)->handle(Request::create('/'), fn (): Response => new Response('ok'));

    expect(Cookie::hasQueued('demo_instance_id'))->toBeTrue();

    $demoId = Cookie::queued('demo_instance_id')->getValue();

    expect($demoId)->toMatch('/^[a-zA-Z0-9]{40}$/')
        ->and(file_exists("{$this->storagePath}/{$demoId}.sqlite"))->toBeTrue()
        ->and(file_get_contents("{$this->storagePath}/{$demoId}.sqlite"))->toBe('fake-sqlite-bytes')
        ->and(config('database.default'))->toBe(ResolveDemoDatabase::CONNECTION_NAME)
        ->and(config('database.connections.'.ResolveDemoDatabase::CONNECTION_NAME.'.database'))
        ->toBe("{$this->storagePath}/{$demoId}.sqlite");
});

it('reuses the existing per-session file for a visitor with a valid cookie already', function (): void {
    $demoId = str_repeat('a', 40);
    mkdir($this->storagePath, recursive: true);
    $existingPath = "{$this->storagePath}/{$demoId}.sqlite";
    file_put_contents($existingPath, 'already-has-data');

    $request = Request::create('/');
    $request->cookies->set('demo_instance_id', $demoId);

    (new ResolveDemoDatabase)->handle($request, fn (): Response => new Response('ok'));

    expect(file_get_contents($existingPath))->toBe('already-has-data')
        ->and(Cookie::hasQueued('demo_instance_id'))->toBeFalse()
        ->and(config('database.connections.'.ResolveDemoDatabase::CONNECTION_NAME.'.database'))
        ->toBe($existingPath);
});

it('treats a malformed cookie value as a new visitor rather than trusting it', function (): void {
    $request = Request::create('/');
    $request->cookies->set('demo_instance_id', '../../etc/passwd');

    (new ResolveDemoDatabase)->handle($request, fn (): Response => new Response('ok'));

    expect(Cookie::hasQueued('demo_instance_id'))->toBeTrue()
        ->and(Cookie::queued('demo_instance_id')->getValue())->toMatch('/^[a-zA-Z0-9]{40}$/');
});

it('aborts with a server error when the template file is missing', function (): void {
    config(['app.demo_db_template_path' => "{$this->tempDir}/does-not-exist.sqlite"]);

    (new ResolveDemoDatabase)->handle(Request::create('/'), fn (): Response => new Response('ok'));
})->throws(HttpException::class);
