<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveDemoDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt as LivewireVolt;

/**
 * Feature-level test of ResolveDemoDatabase actually composing with a real route (insights
 * keeps its real login form, unlike homie's Basic Auth popup) - this is what a real visitor
 * experiences: a normal login, but with their own data isolated behind the scenes.
 */
beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/insights-demo-feature-test-'.uniqid();
    mkdir($this->tempDir, recursive: true);
    $this->templatePath = "{$this->tempDir}/template.sqlite";
    $this->storagePath = "{$this->tempDir}/demo-dbs";

    // Built on its own connection name, deliberately never touching 'sqlite' - that's the
    // connection RefreshDatabase is managing for this test process, and the middleware under
    // test switches `database.default` to yet another dedicated name rather than touching
    // 'sqlite' either (see ResolveDemoDatabase's docblock).
    touch($this->templatePath);
    config(['database.connections.sqlite_demo_template' => [
        'driver' => 'sqlite',
        'database' => $this->templatePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    Artisan::call('migrate', ['--database' => 'sqlite_demo_template', '--force' => true]);
    DB::connection('sqlite_demo_template')->table('users')->insert([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::purge('sqlite_demo_template');

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
    DB::purge(ResolveDemoDatabase::CONNECTION_NAME);

    array_map(unlink(...), glob("{$this->storagePath}/*") ?: []);
    @rmdir($this->storagePath);
    @unlink($this->templatePath);
    @rmdir($this->tempDir);
});

it('gives each visitor their own private database copy on the real login route', function (): void {
    $this->get('/login')->assertStatus(200);

    expect(glob("{$this->storagePath}/*.sqlite"))->toHaveCount(1);

    // A second visitor, with a distinct cookie, gets a second, separate copy rather than
    // sharing the first visitor's file.
    $this->withCookie('demo_instance_id', str_repeat('b', 40))
        ->get('/login')
        ->assertStatus(200);

    expect(glob("{$this->storagePath}/*.sqlite"))->toHaveCount(2);
});

it('logs in with the one shared seeded demo login baked into the template', function (): void {
    // A real GET through the full middleware stack resolves this "visitor" onto their own
    // per-session copy of the template - the same copy the subsequent login attempt below
    // authenticates against, since both run against the same booted application/config.
    $this->get('/login')->assertStatus(200);

    LivewireVolt::test('auth.login')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

it('isolates a second visitor from data created by the first', function (): void {
    $firstId = str_repeat('a', 40);
    $secondId = str_repeat('b', 40);

    $this->withCookie('demo_instance_id', $firstId)->get('/login')->assertStatus(200);
    $this->withCookie('demo_instance_id', $secondId)->get('/login')->assertStatus(200);

    $firstPath = "{$this->storagePath}/{$firstId}.sqlite";
    $secondPath = "{$this->storagePath}/{$secondId}.sqlite";

    // Write a marker directly into the first visitor's own copy, mimicking what a real "create
    // a transaction" request would do - it must never show up in the second visitor's copy.
    config(['database.connections.sqlite_demo_template.database' => $firstPath]);
    DB::purge('sqlite_demo_template');
    DB::connection('sqlite_demo_template')->table('users')->insert([
        'name' => 'Marker', 'email' => 'marker-should-not-leak@example.com',
        'password' => Hash::make('password'), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $firstUserCount = DB::connection('sqlite_demo_template')->table('users')->count();
    DB::purge('sqlite_demo_template');

    config(['database.connections.sqlite_demo_template.database' => $secondPath]);
    DB::purge('sqlite_demo_template');
    $secondUserCount = DB::connection('sqlite_demo_template')->table('users')->count();
    $secondHasMarker = DB::connection('sqlite_demo_template')->table('users')
        ->where('email', 'marker-should-not-leak@example.com')->exists();
    DB::purge('sqlite_demo_template');

    expect($firstUserCount)->toBe($secondUserCount + 1)
        ->and($secondHasMarker)->toBeFalse();
});

it('disables public registration behind demo mode, and hides the sign-up link', function (): void {
    $this->get('/register')->assertNotFound();
    $this->get('/login')->assertOk()->assertDontSee('Sign up');
});

it('leaves registration open when demo mode is off', function (): void {
    config(['app.demo_mode' => false]);

    $this->get('/register')->assertOk();
    $this->get('/login')->assertOk()->assertSee('Sign up');
});
