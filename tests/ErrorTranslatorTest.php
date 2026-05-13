<?php
/**
 * Tests for Mighty_Backup_Error_Translator — pure mapping, no WP/AWS deps.
 */

use PHPUnit\Framework\TestCase;

/**
 * Fake AwsException-shaped class — exposes getAwsErrorCode() so the translator
 * exercises its AWS-code branch without pulling in the real AWS SDK.
 */
class FakeAwsException extends \RuntimeException {
    private string $aws_code;

    public function __construct( string $code, string $message ) {
        parent::__construct( $message );
        $this->aws_code = $code;
    }

    public function getAwsErrorCode(): string {
        return $this->aws_code;
    }
}

class ErrorTranslatorTest extends TestCase {

    public function test_string_passthrough_when_no_pattern_matches(): void {
        $r = Mighty_Backup_Error_Translator::translate( 'Some entirely unexpected error.' );

        $this->assertSame( 'Some entirely unexpected error.', $r['raw'] );
        $this->assertSame( 'Some entirely unexpected error.', $r['human'] );
        $this->assertNull( $r['suggestion'] );
        $this->assertNull( $r['settings_anchor'] );
    }

    public function test_throwable_message_extracted(): void {
        $exc = new \RuntimeException( 'Some entirely unexpected error.' );
        $r   = Mighty_Backup_Error_Translator::translate( $exc );

        $this->assertSame( 'Some entirely unexpected error.', $r['raw'] );
        $this->assertSame( 'Some entirely unexpected error.', $r['human'] );
    }

    public function test_aws_invalid_access_key(): void {
        $exc = new FakeAwsException( 'InvalidAccessKeyId', 'The AWS Access Key Id you provided does not exist in our records.' );
        $r   = Mighty_Backup_Error_Translator::translate( $exc );

        $this->assertSame( 'The AWS Access Key Id you provided does not exist in our records.', $r['raw'] );
        $this->assertStringContainsString( 'rejected the access key', $r['human'] );
        $this->assertStringContainsString( 'Storage tab', $r['suggestion'] );
        $this->assertSame( 'storage', $r['settings_anchor'] );
    }

    public function test_aws_signature_does_not_match(): void {
        $exc = new FakeAwsException( 'SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.' );
        $r   = Mighty_Backup_Error_Translator::translate( $exc );

        $this->assertStringContainsString( 'secret key', $r['human'] );
        $this->assertSame( 'storage', $r['settings_anchor'] );
    }

    public function test_aws_no_such_bucket(): void {
        $exc = new FakeAwsException( 'NoSuchBucket', 'The specified bucket does not exist' );
        $r   = Mighty_Backup_Error_Translator::translate( $exc );

        $this->assertStringContainsString( 'bucket does not exist', $r['human'] );
        $this->assertSame( 'storage', $r['settings_anchor'] );
    }

    public function test_aws_access_denied(): void {
        $exc = new FakeAwsException( 'AccessDenied', 'Access Denied' );
        $r   = Mighty_Backup_Error_Translator::translate( $exc );

        $this->assertStringContainsString( 'access denied', strtolower( $r['human'] ) );
    }

    public function test_aws_request_time_skewed(): void {
        $exc = new FakeAwsException( 'RequestTimeTooSkewed', 'The difference between the request time and the current time is too large.' );
        $r   = Mighty_Backup_Error_Translator::translate( $exc );

        $this->assertStringContainsString( 'clock', strtolower( $r['human'] ) );
        $this->assertNull( $r['settings_anchor'] );
    }

    public function test_multipart_upload_exception_pattern(): void {
        // No AWS code on this one — translator falls back to string pattern.
        $r = Mighty_Backup_Error_Translator::translate(
            'Upload failed after 3 attempts: An exception occurred while uploading parts to a multipart upload. ' .
            'The following parts had errors: Aws\\S3\\MultipartUploadException'
        );

        $this->assertStringContainsString( 'multipart upload', strtolower( $r['human'] ) );
    }

    public function test_mysqldump_access_denied(): void {
        $r = Mighty_Backup_Error_Translator::translate(
            'Database export failed: mysqldump: Got error: 1045: Access denied for user \'wp\'@\'localhost\''
        );

        $this->assertStringContainsString( 'authenticate', strtolower( $r['human'] ) );
    }

    public function test_mysqldump_max_packet(): void {
        $r = Mighty_Backup_Error_Translator::translate(
            'Database export failed: Got packet bigger than \'max_allowed_packet\' bytes when dumping table'
        );

        $this->assertStringContainsString( 'max_allowed_packet', $r['human'] );
        $this->assertStringContainsString( 'max_allowed_packet', $r['suggestion'] );
    }

    public function test_disk_full(): void {
        $r = Mighty_Backup_Error_Translator::translate(
            'File archive failed: tar: write error: No space left on device'
        );

        $this->assertStringContainsString( 'disk space', strtolower( $r['human'] ) );
    }

    public function test_permission_denied(): void {
        $r = Mighty_Backup_Error_Translator::translate(
            'File archive failed: Permission denied (path: /tmp/bm-backup-xxx.tar.gz)'
        );

        $this->assertStringContainsString( 'permission denied', strtolower( $r['human'] ) );
    }

    public function test_github_bad_credentials(): void {
        $r = Mighty_Backup_Error_Translator::translate(
            'GitHub API 401: Bad credentials'
        );

        $this->assertStringContainsString( 'rejected the Personal Access Token', $r['human'] );
        $this->assertSame( 'devcontainer', $r['settings_anchor'] );
    }

    public function test_github_rate_limit(): void {
        $r = Mighty_Backup_Error_Translator::translate(
            'GitHub API 403: API rate limit exceeded for installation ID 12345 (X-RateLimit-Remaining: 0)'
        );

        $this->assertStringContainsString( 'rate limit', strtolower( $r['human'] ) );
    }

    public function test_github_404(): void {
        $r = Mighty_Backup_Error_Translator::translate(
            'GitHub API 404: Not Found at https://api.github.com/repos/x/y'
        );

        $this->assertStringContainsString( 'not find', strtolower( $r['human'] ) );
        $this->assertSame( 'devcontainer', $r['settings_anchor'] );
    }

    public function test_plugin_not_configured(): void {
        $r = Mighty_Backup_Error_Translator::translate( 'Plugin not configured. Please save your DO Spaces credentials.' );

        $this->assertStringContainsString( 'credentials are missing', $r['human'] );
        $this->assertSame( 'storage', $r['settings_anchor'] );
    }

    public function test_backup_already_running(): void {
        $r = Mighty_Backup_Error_Translator::translate( 'A backup is already in progress.' );

        $this->assertStringContainsString( 'already running', strtolower( $r['human'] ) );
        $this->assertSame( 'backup', $r['settings_anchor'] );
    }

    public function test_humanize_convenience(): void {
        $exc = new FakeAwsException( 'NoSuchBucket', 'Original raw text' );
        $this->assertStringContainsString( 'bucket does not exist', Mighty_Backup_Error_Translator::humanize( $exc ) );
        $this->assertSame(
            'Some plain string with no match',
            Mighty_Backup_Error_Translator::humanize( 'Some plain string with no match' )
        );
    }

    public function test_aws_code_takes_precedence_over_string_match(): void {
        // The message text *also* matches a plain-string pattern, but the AWS
        // code mapping should win because it's more precise.
        $exc = new FakeAwsException(
            'InvalidAccessKeyId',
            'Got packet bigger than max_allowed_packet bytes'
        );
        $r = Mighty_Backup_Error_Translator::translate( $exc );

        $this->assertStringContainsString( 'access key', strtolower( $r['human'] ) );
        // Settings anchor confirms the AWS branch fired, not the mysqldump branch.
        $this->assertSame( 'storage', $r['settings_anchor'] );
    }

    public function test_unknown_aws_code_falls_through_to_string_match(): void {
        $exc = new FakeAwsException( 'TotallyNewAwsErrorCode', 'No space left on device' );
        $r   = Mighty_Backup_Error_Translator::translate( $exc );

        // Should hit the disk-full pattern via string fallback.
        $this->assertStringContainsString( 'disk space', strtolower( $r['human'] ) );
    }
}
