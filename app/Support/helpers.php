<?php

use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;

if (! function_exists('fx_route')) {
    /**
     * A route URL that may not exist yet. Returns the URL of the first named route that is registered, or null
     * when none is. The shell and the command bar use it so a destination that is planned but not built yet is
     * drawn as a disabled "coming" item and never as a link that would 404; a destination that IS registered is a
     * normal link. A route that needs parameters it was not given is treated as absent, not as an error.
     *
     * @param  string|list<string>  $names  one route name, or alternatives in order of preference
     * @param  array<string, mixed>  $parameters
     */
    function fx_route(string|array $names, array $parameters = []): ?string
    {
        foreach ((array) $names as $name) {
            if (! Route::has($name)) {
                continue;
            }

            try {
                return route($name, $parameters);
            } catch (UrlGenerationException) {
                continue;
            }
        }

        return null;
    }
}

if (! function_exists('fx_route_exists')) {
    /** @param  string|list<string>  $names */
    function fx_route_exists(string|array $names): bool
    {
        foreach ((array) $names as $name) {
            if (Route::has($name)) {
                return true;
            }
        }

        return false;
    }
}
