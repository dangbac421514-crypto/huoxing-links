<?php

namespace Tests\Feature;

require_once __DIR__.'/UsageMeterTest.php';

/**
 * PHPUnit discovers this filename-matching class; the implementation lives in
 * the support class next to the focused meter tests to keep the task scoped.
 */
final class UsageMeterConcurrencyTest extends UsageMeterConcurrencySupport {}
