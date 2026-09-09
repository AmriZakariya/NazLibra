<?php

namespace Tests\Feature;

use App\Providers\TelescopeServiceProvider;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Tests\TestCase;

/**
 * What Telescope keeps on a deployed install.
 *
 * The filter used to keep only `isFailedRequest()`, which Telescope defines as
 * status >= 500. Every 4xx was silently discarded — so a 422 from a validation
 * mismatch between the app and the API never appeared in
 * /telescope/requests, which is precisely where someone looks for it.
 */
class TelescopeFilterTest extends TestCase
{
    private function request(int $status): IncomingEntry
    {
        return IncomingEntry::make(['response_status' => $status])
            ->type(EntryType::REQUEST);
    }

    public function test_a_validation_failure_is_recorded(): void
    {
        // The case that started this: POST with an unknown connection_type.
        $this->assertTrue(
            TelescopeServiceProvider::shouldRecord($this->request(422), false),
        );
    }

    public function test_every_client_error_is_recorded(): void
    {
        foreach ([400, 401, 403, 404, 409, 419, 422, 429] as $status) {
            $this->assertTrue(
                TelescopeServiceProvider::shouldRecord($this->request($status), false),
                "a $status was discarded",
            );
        }
    }

    public function test_server_errors_are_still_recorded(): void
    {
        foreach ([500, 503] as $status) {
            $this->assertTrue(
                TelescopeServiceProvider::shouldRecord($this->request($status), false),
                "a $status was discarded",
            );
        }
    }

    public function test_successful_traffic_is_discarded_by_default(): void
    {
        // This is the volume that fills a shared host's disk.
        foreach ([200, 201, 204, 302] as $status) {
            $this->assertFalse(
                TelescopeServiceProvider::shouldRecord($this->request($status), false),
                "a $status was kept",
            );
        }
    }

    public function test_record_all_keeps_successful_traffic_too(): void
    {
        // The escape hatch for a request that leaves no error behind.
        $this->assertTrue(
            TelescopeServiceProvider::shouldRecord(
                $this->request(200),
                false,
                recordAll: true,
            ),
        );
    }

    public function test_local_keeps_everything(): void
    {
        $this->assertTrue(
            TelescopeServiceProvider::shouldRecord($this->request(200), true),
        );
    }

    public function test_a_request_with_no_status_is_treated_as_successful(): void
    {
        // Defaults to 200 in Telescope; it must not become a false positive
        // that records everything shapeless.
        $entry = IncomingEntry::make([])->type(EntryType::REQUEST);

        $this->assertFalse(
            TelescopeServiceProvider::shouldRecord($entry, false),
        );
    }

    public function test_a_failed_outgoing_call_is_recorded(): void
    {
        // Telescope's isFailedRequest() covers only INCOMING requests, so a
        // provider refusing OUR call used to leave nothing behind either.
        $entry = IncomingEntry::make(['response_status' => 422])
            ->type(EntryType::CLIENT_REQUEST);

        $this->assertTrue(
            TelescopeServiceProvider::shouldRecord($entry, false),
        );
    }

    public function test_a_successful_outgoing_call_is_discarded(): void
    {
        $entry = IncomingEntry::make(['response_status' => 200])
            ->type(EntryType::CLIENT_REQUEST);

        $this->assertFalse(
            TelescopeServiceProvider::shouldRecord($entry, false),
        );
    }

    public function test_a_non_request_entry_is_not_judged_on_status(): void
    {
        // A query entry has no response_status; the client-error check must
        // not claim it.
        $entry = IncomingEntry::make(['sql' => 'select 1'])
            ->type(EntryType::QUERY);

        $this->assertFalse(
            TelescopeServiceProvider::shouldRecord($entry, false),
        );
    }
}
