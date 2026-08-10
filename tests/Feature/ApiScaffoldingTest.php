<?php

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;

/**
 * The resolved middleware stack for every route in routes/api.php.
 *
 * @return array<string, list<string>>
 */
function apiRouteMiddleware(): array
{
    $router = app(Router::class);

    return collect($router->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->mapWithKeys(fn ($route) => [$route->uri() => $router->gatherRouteMiddleware($route)])
        ->all();
}

test('api routes exist to carry the middleware group', function () {
    expect(apiRouteMiddleware())->not->toBeEmpty();
});

test('every api route throttles once and substitutes bindings once', function () {
    foreach (apiRouteMiddleware() as $uri => $middleware) {
        expect(collect($middleware)->filter(fn (string $name) => str_starts_with($name, ThrottleRequests::class)))
            ->toHaveCount(1, "{$uri} must carry throttle:api exactly once")
            ->and(collect($middleware)->filter(fn (string $name) => $name === SubstituteBindings::class))
            ->toHaveCount(1, "{$uri} must carry SubstituteBindings exactly once");
    }
});

test('the api rate limiter is defined', function () {
    expect(app(RateLimiter::class)->limiter('api'))->not->toBeNull();
});

test('the api rate limiter allows sixty requests a minute keyed on the user', function () {
    $limit = app(RateLimiter::class)->limiter('api')(
        tap(Request::create('/api/user'), fn (Request $request) => $request->setUserResolver(
            fn () => User::factory()->create(['id' => 7]),
        )),
    );

    expect($limit->maxAttempts)->toBe(60)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe(7);
});

test('the api rate limiter falls back to the ip address for guests', function () {
    $limit = app(RateLimiter::class)->limiter('api')(Request::create('/api/user'));

    expect($limit->key)->toBe('127.0.0.1');
});

test('an authenticated request reaches an api route', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/user')
        ->assertOk();
});

test('a sanctum token authenticates an api request', function () {
    $user = User::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.$user->createToken('test', ['*'])->plainTextToken)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

test('an api request without an accept header is refused as json', function () {
    $this->get('/api/user')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

test('exceeding the rate limit returns 429 without throttling other users', function () {
    $user = User::factory()->create();

    for ($request = 1; $request <= 60; $request++) {
        $this->actingAs($user)->getJson('/api/user')->assertOk();
    }

    $this->actingAs($user)
        ->getJson('/api/user')
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    $this->actingAs(User::factory()->create())
        ->getJson('/api/user')
        ->assertOk();
});
