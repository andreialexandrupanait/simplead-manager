<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\SqlDumpParser;
use PHPUnit\Framework\TestCase;

class SqlDumpParserTest extends TestCase
{
    private SqlDumpParser $parser;

    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SqlDumpParser;
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_valid_dump_passes(): void
    {
        $sql = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n"
            ."CREATE TABLE wp_options (id int);\n"
            ."INSERT INTO wp_options VALUES (1);\n"
            ."INSERT INTO wp_options VALUES (2);\n"
            ."CREATE TABLE wp_posts (id int);\n"
            ."SET FOREIGN_KEY_CHECKS = 1;\n";
        $path = $this->writeFile($sql, false);

        $result = $this->parser->parse($path);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame(2, $result['table_count']);
        $this->assertSame(2, $result['insert_count']);
        $this->assertTrue($result['has_expected_header']);
        $this->assertTrue($result['has_expected_footer']);
    }

    public function test_gzipped_dump_passes(): void
    {
        $sql = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n"
            ."CREATE TABLE foo (id int);\nINSERT INTO foo VALUES (1);\n"
            ."SET FOREIGN_KEY_CHECKS = 1;\n";
        $path = $this->writeFile($sql, true);

        $result = $this->parser->parse($path);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['table_count']);
    }

    public function test_empty_file_fails(): void
    {
        $path = $this->writeFile('', false);
        $result = $this->parser->parse($path);

        $this->assertFalse($result['ok']);
        $this->assertSame('file is empty', $result['error']);
    }

    public function test_missing_header_fails(): void
    {
        $path = $this->writeFile("INSERT INTO foo VALUES (1);\n", false);
        $result = $this->parser->parse($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('missing expected header', $result['error']);
    }

    public function test_no_create_table_fails(): void
    {
        $path = $this->writeFile("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nINSERT INTO foo VALUES (1);\n", false);
        $result = $this->parser->parse($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no CREATE TABLE', $result['error']);
    }

    public function test_missing_file_fails(): void
    {
        $result = $this->parser->parse('/nonexistent/path/to/file.sql');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not exist', $result['error']);
    }

    private function writeFile(string $content, bool $gzip): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sqltest_').($gzip ? '.sql.gz' : '.sql');
        if ($gzip) {
            $gz = gzopen($path, 'wb');
            gzwrite($gz, $content);
            gzclose($gz);
        } else {
            file_put_contents($path, $content);
        }
        $this->cleanup[] = $path;

        return $path;
    }
}
