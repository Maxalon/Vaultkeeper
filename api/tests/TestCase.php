<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Override call() to reset the JWT guard's user cache before every
     * HTTP test request. Without this, the JWT guard (a singleton within
     * the auth manager's guard cache) retains the resolved user from the
     * previous request, causing the second request in a two-user test to
     * appear as the first user even when a different Bearer token is sent.
     *
     * Calling auth()->forgetGuards() clears the AuthManager's guard cache
     * so the 'api' guard is re-instantiated for each request and will parse
     * the user fresh from the token in the Authorization header.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // Reset the JWT auth state before every HTTP test request.
        //
        // Problem: the tymon/jwt-auth JWTGuard is cached inside Laravel's
        // AuthManager (in $guards). When two requests in the same test use
        // different Bearer tokens (e.g. alice's token then bob's token), the
        // second request silently gets the guard from the first request — which
        // already has $user set to alice. The guard's user() method short-circuits
        // on `$this->user !== null` and returns alice regardless of the token.
        //
        // Additionally, the JWT singleton (tymon.jwt) caches the parsed token
        // string in $this->token. A new JWTGuard instance still reads alice's
        // token from the JWT singleton if we only call forgetGuards().
        //
        // Fix: clear both the guard cache and the JWT token cache before each
        // test request so every request resolves the user from scratch.
        auth()->forgetGuards();
        app('tymon.jwt')->unsetToken();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
