<?php

use MLE\Examples\Tests\TestCase;

// in() resolves relative to THIS file's directory (tests/), so the target
// is __DIR__ — writing ->in('tests') here would look for tests/tests and
// silently apply no TestCase binding (a real bug we hit: 35 failing tests
// with "A facade root has not been set").
//
// The pest() helper exists only in Pest 3 (resolved for Laravel 11+);
// the Laravel 10 matrix cell resolves Pest 2, where uses() is the API.
if (function_exists('pest')) {
    pest()->extend(TestCase::class)->in(__DIR__);
} else {
    uses(TestCase::class)->in(__DIR__);
}
