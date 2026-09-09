<?php

namespace Tests\Feature\Tenancy;

use App\Models\Agency;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Scopes\AgencyScope;
use App\Tenancy\TenantNotResolved;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Throwaway tenant model for the trait tests. */
#[Table('probes')]
#[Unguarded]
class Probe extends Model
{
    use BelongsToAgency, HasUlids;

    public $timestamps = false;
}

class AgencyScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Touch the app connection first so LazilyRefreshDatabase has migrated,
        // then create the table as the owner, outside the test transaction: the
        // app role inherits CRUD through the default privileges and the next
        // migrate:fresh drops it again. No FK, so no lock on agencies.
        $this->platform();
        DB::connection('owner')->statement('create table if not exists probes (id char(26) primary key, agency_id char(26) not null, name text not null)');
    }

    public function test_reads_are_limited_to_the_current_agency(): void
    {
        [$a, $b] = Agency::factory()->count(2)->create();
        Probe::create(['agency_id' => $a->id, 'name' => 'a1']);
        Probe::create(['agency_id' => $a->id, 'name' => 'a2']);
        Probe::create(['agency_id' => $b->id, 'name' => 'b1']);

        $this->withTenant($a);
        $this->assertSame(['a1', 'a2'], Probe::orderBy('name')->pluck('name')->all());

        $this->withTenant($b);
        $this->assertSame(['b1'], Probe::pluck('name')->all());
    }

    public function test_creating_fills_agency_id_from_the_tenant(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);

        $this->assertSame($agency->id, Probe::create(['name' => 'x'])->agency_id);
    }

    public function test_reading_without_a_tenant_throws(): void
    {
        $this->expectException(TenantNotResolved::class);

        Probe::count();
    }

    public function test_unscoped_reads_need_an_explicit_escape_hatch(): void
    {
        Probe::create(['agency_id' => Agency::factory()->create()->id, 'name' => 'x']);

        $this->assertGreaterThanOrEqual(1, Probe::withoutGlobalScope(AgencyScope::class)->count());
    }
}
