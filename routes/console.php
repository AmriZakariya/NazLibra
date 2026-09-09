<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Telescope keeps client errors as well as 5xx now, so the table grows faster
// than it used to. Unpruned it would fill a shared host's disk — which takes
// the shop's whole API down, a far worse outage than the blind spot pruning
// costs. Two weeks is long enough to investigate a report from the floor.
Schedule::command('telescope:prune --hours=336')->daily();
