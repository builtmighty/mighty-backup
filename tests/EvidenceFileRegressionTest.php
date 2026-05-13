<?php
/**
 * End-to-end regression test against the original 2026-05-13 incident artifact.
 *
 * `backup-2026-05-13-030009.tar.gz` is the broken archive that prompted this
 * fix. Once the file is removed from the repo root (it's not committed —
 * it's a local investigation artifact), this test is skipped. While it's
 * present, the test must pass: it asserts the new verifier rejects the
 * historical bug.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class EvidenceFileRegressionTest extends TestCase {

    private const EVIDENCE_FILE = 'backup-2026-05-13-030009.tar.gz';

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'untrailingslashit' )->alias( fn( $s ) => rtrim( $s, '/' ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_verifier_rejects_the_original_incident_archive(): void {
        $path = dirname( __DIR__ ) . '/' . self::EVIDENCE_FILE;
        if ( ! file_exists( $path ) ) {
            $this->markTestSkipped(
                sprintf( 'Evidence file %s not present (expected — it is local-only, not committed).', self::EVIDENCE_FILE )
            );
        }

        $settings = $this->createMock( Mighty_Backup_Settings::class );
        $settings->method( 'get' )->willReturnCallback( fn( $k, $d = '' ) => $d );

        $archiver = new Mighty_Backup_File_Archiver( $settings );

        $rc = new ReflectionClass( $archiver );
        $m  = $rc->getMethod( 'verify_archive_structure_php' );
        $m->setAccessible( true );

        try {
            $m->invoke( $archiver, $path );
            $this->fail(
                'Verifier accepted the known-bad incident archive. The v2.10 silent-corruption regression is back.'
            );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Post-archive verification failed', $e->getMessage() );
        }
    }
}
