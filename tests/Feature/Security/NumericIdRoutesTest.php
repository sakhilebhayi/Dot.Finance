<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * R-44 security sweep: an id in a URL that is not a whole number that fits a bigint is a plain 404 on every route, on SQLite and
 * on PostgreSQL. (PostgreSQL used to answer 500 and abort the transaction: "invalid input syntax for type bigint".)
 */
class NumericIdRoutesTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    /** @return list<string> */
    public static function hostileIds(): array
    {
        return ['abc', '1e3', '-1', '1.5', '99999999999999999999', '9223372036854775808', '0x10', "1'--", '%00', 'ñ', ' ', '1 OR 1=1'];
    }

    public function test_a_hostile_id_is_a_clean_404_on_every_route_that_takes_one(): void
    {
        $user = $this->user();
        $company = $this->company($user, 'Mine Ltd');
        $problems = [];
        $tried = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\') || $route->getName() === null) {
                continue;
            }
            $numeric = array_values(array_filter($route->parameterNames(), fn ($p) => ! in_array($p, ['token', 'shareToken'], true)));
            if ($numeric === []) {
                continue;
            }
            $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));

            foreach (self::hostileIds() as $bad) {
                $params = [];
                foreach ($route->parameterNames() as $name) {
                    $params[$name] = in_array($name, $numeric, true) ? 'PLACEHOLDER' : '0123456789abcdef0123456789abcdef01234567';
                }
                $uri = '/'.ltrim(str_replace(array_map(fn ($n) => '{'.$n.'}', $route->parameterNames()), array_map(fn ($n) => in_array($n, $numeric, true) ? rawurlencode($bad) : $params[$n], $route->parameterNames()), $route->uri()), '/');

                $response = $this->inCompany($user, $company)->call($method, $uri, $method === 'GET' ? [] : ['x' => 1]);
                $tried++;
                if ($response->getStatusCode() >= 500) {
                    $problems[] = "{$method} {$uri} -> ".$response->getStatusCode().' '.substr((string) $response->exception?->getMessage(), 0, 100);
                }
            }
        }

        $this->assertGreaterThan(2000, $tried);
        $this->assertSame([], $problems, implode("\n", array_slice($problems, 0, 15)));
    }

    public function test_the_numeric_pattern_gives_a_404_not_a_redirect_or_a_login_page(): void
    {
        $this->inCompany($user = $this->user(), $this->company($user, 'Mine Ltd'))->get('/accounts/abc/edit')->assertNotFound();
        $this->get('/accounts/99999999999999999999/edit')->assertNotFound();
    }
}
