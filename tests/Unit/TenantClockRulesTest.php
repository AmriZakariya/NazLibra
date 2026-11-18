<?php

namespace Tests\Unit;

use App\Support\TenantClock;
use Carbon\Carbon;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class TenantClockRulesTest extends TestCase
{
    public function test_rules_have_the_documented_shape_and_cover_the_range(): void
    {
        $at = Carbon::parse('2026-11-18 00:19:00', 'UTC');
        $rules = TenantClock::rules('Europe/Paris', $at);

        $this->assertSame('Europe/Paris', $rules['name']);
        $this->assertSame('2026-11-18T00:19:00Z', $rules['generated_at']);
        $this->assertSame('2027-12-23T00:19:00Z', $rules['valid_until']);
        // First entry starts the range 30 days before (still summer time); Paris
        // then goes to winter time, back to summer and to winter again.
        $this->assertSame('2026-10-19T00:19:00Z', $rules['offsets'][0]['from']);
        $this->assertSame(['CEST', 'CET', 'CEST', 'CET'], array_column($rules['offsets'], 'abbr'));
        $this->assertSame([7200, 3600, 7200, 3600], array_column($rules['offsets'], 'offset_seconds'));
        $this->assertSame('2026-10-25T01:00:00Z', $rules['offsets'][1]['from']);
        $this->assertFalse($rules['offsets'][1]['is_dst']);
        foreach ($rules['offsets'] as $row) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $row['from']);
        }
    }

    public function test_the_offset_in_force_now_is_the_one_php_reports(): void
    {
        $now = Carbon::now('UTC');
        foreach (['Africa/Casablanca', 'Europe/Paris', 'America/New_York', 'UTC'] as $name) {
            $rules = TenantClock::rules($name, $now);
            $inForce = null;
            foreach ($rules['offsets'] as $row) {
                if (Carbon::parse($row['from'])->lte($now)) {
                    $inForce = $row['offset_seconds'];
                }
            }
            $this->assertSame((new DateTimeZone($name))->getOffset($now), $inForce, $name);
        }
    }
}
