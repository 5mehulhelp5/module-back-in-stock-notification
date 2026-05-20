<?php

declare(strict_types=1);

namespace ETechFlow\BackInStockNotification\Model\Performance;

/**
 * Tideways / Blackfire span helper.
 *
 * Same shape as the Profiler in every other ETechFlow module. No-op when
 * neither profiler is loaded — calling code never has to check. Use as:
 *
 *   $span = Profiler::start('ETechFlow_BISN_EnqueueNotifications');
 *   try {
 *       // … hot code …
 *   } finally {
 *       Profiler::stop($span);
 *   }
 *
 * Span name convention: all BISN spans start with `ETechFlow_BISN_` so
 * they group cleanly in Tideways's "Top callees" view.
 */
final class Profiler
{
    /**
     * Start a named span. Returns an opaque handle to pass to stop().
     *
     * @param string $name
     * @return mixed
     */
    public static function start(string $name)
    {
        if (\function_exists('tideways_span_create')) {
            $id = \tideways_span_create('etechflow');
            \tideways_span_annotate($id, ['title' => $name]);
            return $id;
        }
        if (\function_exists('blackfire_span_open')) {
            return \blackfire_span_open($name);
        }
        return null;
    }

    /**
     * Close a span previously returned by start().
     */
    public static function stop($handle): void
    {
        if ($handle === null) {
            return;
        }
        if (\function_exists('tideways_span_finish')) {
            \tideways_span_finish($handle);
            return;
        }
        if (\function_exists('blackfire_span_close')) {
            \blackfire_span_close($handle);
        }
    }
}
