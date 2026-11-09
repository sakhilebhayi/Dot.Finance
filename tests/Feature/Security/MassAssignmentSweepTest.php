<?php

namespace Tests\Feature\Security;

use App\Models\Team;
use App\Models\User;
use Database\Seeders\TaxHolidaysSeeder;
use Database\Seeders\TaxModuleRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Concerns\RunsRouteProbes;
use Tests\Concerns\SeedsSentinelLedgers;
use Tests\TestCase;

/**
 * R-44 security sweep, part 2: mass assignment.
 *
 * The owner of one company calls EVERY write route with the ownership and state columns of the ledger tables added to the
 * body (user_id, team_id, id, status, number, posted_at, voided_at, gl_entry_id, created_by, role...). Afterwards:
 *   - no existing row anywhere changed owner;
 *   - every row the request created belongs to the caller's company and to no person;
 *   - no row of any other ledger changed at all.
 * A second check reads the models: every ledger model that lists user_id/team_id as fillable is only ever filled by
 * server code (it must never be created from raw request input), which the first check proves behaviourally.
 */
class MassAssignmentSweepTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;
    use RunsRouteProbes;
    use SeedsSentinelLedgers;

    private User $me;

    private Team $mine;

    private Team $theirs;

    private User $stranger;

    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(TaxModuleRulesSeeder::class);
        $this->seed(TaxHolidaysSeeder::class);
        $this->me = $this->user();
        $this->stranger = $this->user();
        $this->mine = $this->company($this->me, 'Mine Ltd');
        $this->theirs = $this->company($this->stranger, 'Theirs Ltd');
        $this->ids = $this->seedContext('mine', ['user_id' => null, 'team_id' => $this->mine->id], company: $this->mine);
        $this->seedContext('theirs', ['user_id' => null, 'team_id' => $this->theirs->id], company: $this->theirs);
    }

    private function hostileExtras(): array
    {
        return [
            'user_id' => $this->stranger->id, 'team_id' => $this->theirs->id, 'id' => 1, 'status' => 'paid', 'number' => 'HACKED-1', 'posted_at' => '2020-01-01 00:00:00', 'voided_at' => '2020-01-01 00:00:00',
            'approved_at' => '2020-01-01 00:00:00', 'gl_entry_id' => 1, 'created_by' => $this->stranger->id, 'owner_id' => $this->stranger->id, 'is_system' => 1, 'deleted_at' => null,
            'attachable_type' => 'x', 'path' => '../../etc/passwd', 'disk' => 'public', 'sha256' => str_repeat('a', 64), 'token_hash' => str_repeat('b', 64), 'locked_at' => '2020-01-01 00:00:00',
            'paid_cents' => 99999999, 'balance_cents' => 99999999, 'total_cents' => 1, 'tax_cents' => 0, 'books_closed_through' => '2099-01-01', 'accounting_enabled_at' => '2020-01-01 00:00:00',
        ];
    }

    /** @return array<string, array<int, mixed>> table => [id => md5 of "user_id|team_id"] */
    private function owners(): array
    {
        $out = [];
        foreach ($this->ledgerTables() as $table) {
            if (! Schema::hasColumns($table, ['user_id', 'team_id']) || ! Schema::hasColumn($table, 'id')) {
                continue;
            }
            $out[$table] = DB::table($table)->orderBy('id')->get(['id', 'user_id', 'team_id'])->mapWithKeys(fn ($r) => [$r->id => $r->user_id.'|'.$r->team_id])->all();
        }

        return $out;
    }

    public function test_hostile_ownership_and_state_columns_in_any_write_never_move_or_misown_a_row(): void
    {
        $problems = [];
        $tried = 0;
        $theirsBefore = null;

        foreach ($this->appRoutes() as $route) {
            if (! $this->isWrite($route) || $route->getName() === null) {
                continue;
            }
            $name = $route->getName();
            if (in_array($name, ['ecosystem.auth', 'api.ecosystem.membership', 'api.integrations.emall.sales', 'tax.payroll-import', 'csp.report'], true)) {
                continue;
            }
            $params = $this->paramsFor($route, $this->ids);
            if ($params === null) {
                continue;
            }

            $ownersBefore = $this->owners();
            $theirsBefore = $this->fingerprint($this->theirs->id);
            $maxIds = array_map(fn ($rows) => $rows === [] ? 0 : max(array_keys($rows)), $ownersBefore);

            [$response, $changed] = $this->probe(function () use ($route, $params, &$created, $ownersBefore, &$moved, &$theirsAfter) {
                $files = [];
                $body = $this->bodyFor($route, $this->ids, $this->hostileExtras());
                foreach ($body as $k => $v) {
                    if ($v instanceof UploadedFile) {
                        $files[$k] = $v;
                        unset($body[$k]);
                    }
                }
                $response = $this->actAs($this->me, $this->mine)->call($this->methodOf($route), route($route->getName(), $params, false), $body, [], $files);

                $now = $this->owners();
                $moved = [];
                $created = [];
                foreach ($now as $table => $rows) {
                    foreach ($rows as $id => $owner) {
                        if (! isset($ownersBefore[$table][$id])) {
                            if ($owner !== '|'.$this->mine->id) {
                                $created[] = "{$table}#{$id} owned by [{$owner}]";
                            }
                        } elseif ($ownersBefore[$table][$id] !== $owner) {
                            $moved[] = "{$table}#{$id} {$ownersBefore[$table][$id]} -> {$owner}";
                        }
                    }
                }
                $theirsAfter = $this->fingerprint($this->theirs->id);

                return $response;
            });
            $tried++;

            $label = $this->methodOf($route).' /'.ltrim($route->uri(), '/');
            if ($moved !== []) {
                $problems[] = "{$label} MOVED ROWS: ".implode('; ', array_slice($moved, 0, 3));
            }
            if ($created !== []) {
                $problems[] = "{$label} CREATED ROWS FOR THE WRONG OWNER: ".implode('; ', array_slice($created, 0, 3));
            }
            if ($theirsAfter !== $theirsBefore) {
                $problems[] = "{$label} CHANGED ANOTHER COMPANY";
            }
            if ($response->getStatusCode() >= 500) {
                $problems[] = "{$label} answered ".$response->getStatusCode().': '.substr((string) $response->exception?->getMessage(), 0, 140);
            }
        }

        $this->assertGreaterThan(120, $tried, 'the sweep should reach most write routes');
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_the_unguarded_models_are_only_created_through_services(): void
    {
        // $guarded = [] models accept anything, so none of them may be built from the raw request. This is the structural half of the
        // behavioural sweep above: no controller hands $request->all() or $request->input() straight to create/fill/update on a model.
        // (A validated() array is an explicit list of rules, and the owner columns are added by the server after it; the sweep above proves it.)
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Http/Controllers'))) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if (preg_match('/::(create|forceCreate|firstOrCreate|updateOrCreate)\(\s*\$request->(all|input|validated)\(\)/', $code)
                || preg_match('/->(fill|forceFill|update)\(\s*\$request->(all|input)\(\)/', $code)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'a controller passes a request array straight into a model: '.implode(', ', $offenders));
    }
}
