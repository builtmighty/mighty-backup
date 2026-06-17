<?php
/**
 * Tests for Mighty_Backup_Scheduler — the weekly-recurrence registration and
 * calculate_next_run's weekday honoring (the two PR3 audit fixes).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class SchedulerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( '__' )->returnArg( 1 );
        if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
            define( 'WEEK_IN_SECONDS', 604800 );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invoke( object $instance, string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( $instance, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $instance, $args );
    }

    public function test_register_weekly_recurrence_adds_weekly_entry(): void {
        $scheduler = new Mighty_Backup_Scheduler();
        $result    = $scheduler->register_weekly_recurrence( [
            'hourly'     => [ 'interval' => 3600, 'display' => 'Every Hour' ],
            'twicedaily' => [ 'interval' => 43200, 'display' => 'Twice Daily' ],
            'daily'      => [ 'interval' => 86400, 'display' => 'Daily' ],
        ] );

        $this->assertArrayHasKey( 'weekly', $result );
        $this->assertSame( WEEK_IN_SECONDS, $result['weekly']['interval'] );
        $this->assertSame( 'Once Weekly', $result['weekly']['display'] );
    }

    public function test_register_weekly_recurrence_preserves_existing_entry(): void {
        // If another plugin or wp-config already defined weekly, don't stomp it.
        $scheduler = new Mighty_Backup_Scheduler();
        $existing  = [
            'weekly' => [ 'interval' => 9999, 'display' => 'Custom Weekly' ],
        ];
        $result = $scheduler->register_weekly_recurrence( $existing );
        $this->assertSame( 9999, $result['weekly']['interval'] );
        $this->assertSame( 'Custom Weekly', $result['weekly']['display'] );
    }

    public function test_calculate_next_run_daily_returns_future_today_or_tomorrow(): void {
        // Stub wp_timezone to UTC for deterministic testing.
        Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );

        $scheduler = new Mighty_Backup_Scheduler();
        $now       = time();
        $next      = $this->invoke( $scheduler, 'calculate_next_run', [ '03:00', 'daily', 'monday' ] );

        $this->assertIsInt( $next );
        $this->assertGreaterThan( $now, $next, 'Next run must be in the future' );
        // Daily run is within 24 hours.
        $this->assertLessThanOrEqual( $now + 86400 + 60, $next );
    }

    public function test_calculate_next_run_weekly_honors_schedule_day(): void {
        // Pin wp_timezone to UTC so weekday math is deterministic.
        Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );

        $scheduler = new Mighty_Backup_Scheduler();

        // For each weekday, the computed next-run's day-of-week must match.
        foreach ( [
            'sunday'    => 0,
            'monday'    => 1,
            'tuesday'   => 2,
            'wednesday' => 3,
            'thursday'  => 4,
            'friday'    => 5,
            'saturday'  => 6,
        ] as $day => $expected_dow ) {
            $next = $this->invoke( $scheduler, 'calculate_next_run', [ '03:00', 'weekly', $day ] );
            $dow  = (int) gmdate( 'w', $next );
            $this->assertSame(
                $expected_dow,
                $dow,
                "calculate_next_run for weekly/{$day} should land on day-of-week {$expected_dow}, got {$dow}"
            );
            $this->assertGreaterThan( time(), $next, 'Next weekly run must be in the future' );
            // Within 7 days.
            $this->assertLessThanOrEqual( time() + WEEK_IN_SECONDS + 60, $next );
        }
    }

    public function test_calculate_next_run_weekly_defaults_to_monday_on_unknown_day(): void {
        Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );

        $scheduler = new Mighty_Backup_Scheduler();
        $next      = $this->invoke( $scheduler, 'calculate_next_run', [ '03:00', 'weekly', 'garbage' ] );

        $this->assertSame( 1, (int) gmdate( 'w', $next ), 'Unknown day defaults to Monday (dow=1)' );
    }
}
