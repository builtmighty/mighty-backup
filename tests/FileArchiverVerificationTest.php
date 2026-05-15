<?php
/**
 * Tests for Mighty_Backup_File_Archiver::verify_archive_structure() and the
 * fopen-failure / size-mismatch guards in stream_directory().
 *
 * The verifier is the load-bearing defense against the v2.10.0 silent-corruption
 * bug where a header announcing N bytes followed by < N bytes of data desynced
 * the entire archive (see backup-plan.md / plans/scan-over-this-plugin-merry-hoare.md).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class FileArchiverVerificationTest extends TestCase {

    private string $tmp_dir;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'untrailingslashit' )->alias( fn( $s ) => rtrim( $s, '/' ) );

        // Per-test scratch dir under the system temp.
        $this->tmp_dir = sys_get_temp_dir() . '/mb-test-' . bin2hex( random_bytes( 4 ) );
        mkdir( $this->tmp_dir );
    }

    protected function tearDown(): void {
        // Recursive cleanup of test scratch.
        if ( is_dir( $this->tmp_dir ) ) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $this->tmp_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $it as $f ) {
                $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
            }
            rmdir( $this->tmp_dir );
        }
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Build a Mighty_Backup_Settings mock that only answers get().
     */
    private function settings( array $values = [] ): Mighty_Backup_Settings {
        $mock = $this->createMock( Mighty_Backup_Settings::class );
        $mock->method( 'get' )->willReturnCallback(
            fn( $k, $d = '' ) => $values[ $k ] ?? $d
        );
        return $mock;
    }

    /**
     * Call a private method via reflection. Keeps the test focused on
     * verification logic without exposing internals as part of the public API.
     */
    private function call_private( object $obj, string $method, array $args = [] ) {
        $rc = new ReflectionClass( $obj );
        $m  = $rc->getMethod( $method );
        $m->setAccessible( true );
        return $m->invokeArgs( $obj, $args );
    }

    /**
     * Build a minimal valid 512-byte tar header for a regular file.
     */
    private function make_tar_header( string $name, int $size ): string {
        $header = pack( 'a100', $name )
                . pack( 'a8',  "0000664\0" )
                . pack( 'a8',  "0000000\0" )
                . pack( 'a8',  "0000000\0" )
                . pack( 'a12', sprintf( '%011o', $size ) . "\0" )
                . pack( 'a12', sprintf( '%011o', 0 ) . "\0" )
                . '        '
                . '0'
                . str_repeat( "\0", 100 )
                . "ustar\000"
                . "00"
                . str_repeat( "\0", 32 )
                . str_repeat( "\0", 32 )
                . str_repeat( "\0", 8 )
                . str_repeat( "\0", 8 )
                . pack( 'a155', '' )
                . str_repeat( "\0", 12 );
        $checksum = array_sum( array_map( 'ord', str_split( $header ) ) );
        return substr_replace( $header, sprintf( '%06o', $checksum ) . "\0 ", 148, 8 );
    }

    /**
     * Write a tar.gz containing one entry. If $actual_size differs from
     * $declared_size, the archive is intentionally desynced (the v2.10 bug
     * shape).
     */
    private function build_archive( string $path, string $entry_name, int $declared_size, int $actual_size, bool $write_eof ): void {
        $gz = gzopen( $path, 'wb1' );
        gzwrite( $gz, $this->make_tar_header( $entry_name, $declared_size ) );
        gzwrite( $gz, str_repeat( 'X', $actual_size ) );
        $padding = ( 512 - ( $actual_size % 512 ) ) % 512;
        if ( $padding > 0 ) {
            gzwrite( $gz, str_repeat( "\0", $padding ) );
        }
        if ( $write_eof ) {
            gzwrite( $gz, str_repeat( "\0", 1024 ) );
        }
        gzclose( $gz );
    }

    public function test_verifier_accepts_a_well_formed_archive(): void {
        $path = $this->tmp_dir . '/good.tar.gz';
        $this->build_archive( $path, 'hello.txt', 1024, 1024, true );

        $archiver = new Mighty_Backup_File_Archiver( $this->settings() );
        // Force the PHP path so we test our own walker even on hosts with shell tar.
        // We do this by calling the inner method directly.
        $this->call_private( $archiver, 'verify_archive_structure_php', [ $path ] );

        $this->assertTrue( true ); // No exception thrown = pass.
    }

    public function test_verifier_rejects_desynced_archive_header_announces_more_than_present(): void {
        // This is the v2.10 bug shape: header says 5 MB, but only 1 KB of data
        // follows + EOF blocks. tar would read past the EOF blocks treating
        // them as still-mysql.sql-data, then run off the end.
        $path = $this->tmp_dir . '/desynced.tar.gz';
        $this->build_archive( $path, 'wp-content/mysql.sql', 5 * 1024 * 1024, 1024, true );

        $archiver = new Mighty_Backup_File_Archiver( $this->settings() );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Post-archive verification failed/i' );
        $this->call_private( $archiver, 'verify_archive_structure_php', [ $path ] );
    }

    public function test_verifier_rejects_missing_eof_blocks(): void {
        // Stream ends cleanly at a 512-boundary (data + padding) but the two
        // zero-block EOF terminator was never written. A real tar will accept
        // this in some implementations and reject it in others — Mighty Backup
        // refuses to accept it.
        $path = $this->tmp_dir . '/no-eof.tar.gz';
        $this->build_archive( $path, 'hello.txt', 1024, 1024, false );

        $archiver = new Mighty_Backup_File_Archiver( $this->settings() );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Post-archive verification failed/i' );
        $this->call_private( $archiver, 'verify_archive_structure_php', [ $path ] );
    }

    public function test_mysql_sql_is_excluded_by_default(): void {
        // Hosting-managed mysql.sql (WP Engine, Pressable) must stay in the
        // default exclusion list — otherwise the fopen-failure guard fires
        // on every backup against those hosts. See v2.11.2 plan.
        $rc   = new ReflectionClass( Mighty_Backup_File_Archiver::class );
        $excl = $rc->getReflectionConstant( 'DEFAULT_EXCLUSIONS' )->getValue();
        $this->assertContains( 'wp-content/mysql.sql', $excl );
    }

    public function test_verifier_rejects_single_zero_block(): void {
        // Pathological: exactly ONE zero block at the end. Some tar
        // implementations accept this, but it's a sign of corruption.
        $path = $this->tmp_dir . '/single-zero.tar.gz';
        $gz = gzopen( $path, 'wb1' );
        gzwrite( $gz, $this->make_tar_header( 'hello.txt', 1024 ) );
        gzwrite( $gz, str_repeat( 'X', 1024 ) );
        gzwrite( $gz, str_repeat( "\0", 512 ) ); // ONE zero block, then EOF
        gzclose( $gz );

        $archiver = new Mighty_Backup_File_Archiver( $this->settings() );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Post-archive verification failed/i' );
        $this->call_private( $archiver, 'verify_archive_structure_php', [ $path ] );
    }

}
