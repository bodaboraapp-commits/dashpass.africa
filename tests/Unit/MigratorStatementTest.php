<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use Tests\TestCase;

/**
 * The migration runner must split plain SQL files deterministically: migrations
 * deliberately avoid stored procedures, so a quote/comment-aware semicolon split
 * is the contract.
 */
final class MigratorStatementTest extends TestCase
{
    public function testSplitsMultipleStatements(): void
    {
        $statements = Migrator::splitStatements(
            "CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);\nCREATE TABLE c (id INT);"
        );

        $this->assertCount(3, $statements);
        $this->assertSame('CREATE TABLE a (id INT)', $statements[0]);
        $this->assertSame('CREATE TABLE b (id INT)', $statements[1]);
        $this->assertSame('CREATE TABLE c (id INT)', $statements[2]);
    }

    public function testIgnoresSemicolonsInsideSingleQuotedStrings(): void
    {
        $sql = "INSERT INTO templates (body) VALUES ('First; second; third');";

        $statements = Migrator::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertSame("INSERT INTO templates (body) VALUES ('First; second; third')", $statements[0]);
    }

    public function testIgnoresSemicolonsInsideDoubleQuotedStrings(): void
    {
        $sql = 'INSERT INTO templates (body) VALUES ("a;b");';

        $statements = Migrator::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertSame('INSERT INTO templates (body) VALUES ("a;b")', $statements[0]);
    }

    public function testStripsLineComments(): void
    {
        $sql = "-- create the table; really\nCREATE TABLE t (id INT);\n# another comment;\nCREATE TABLE u (id INT);";

        $statements = Migrator::splitStatements($sql);

        $this->assertCount(2, $statements);
        $this->assertSame('CREATE TABLE t (id INT)', $statements[0]);
        $this->assertSame('CREATE TABLE u (id INT)', $statements[1]);
    }

    public function testStripsBlockComments(): void
    {
        $sql = "/* header; comment */\nCREATE TABLE t (id INT); /* trailing; */";

        $statements = Migrator::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE t (id INT)', $statements[0]);
    }

    public function testCommentMarkersInsideStringsAreNotComments(): void
    {
        $sql = "INSERT INTO notes (body) VALUES ('-- not a comment; # nor this');";

        $statements = Migrator::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertContains('-- not a comment', $statements[0]);
        $this->assertContains('# nor this', $statements[0]);
    }

    public function testBackslashEscapedQuoteDoesNotCloseTheString(): void
    {
        $sql = "INSERT INTO notes (body) VALUES ('it\\'s; still one statement');";

        $statements = Migrator::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertContains("it\\'s; still one statement", $statements[0]);
    }

    public function testTrailingStatementWithoutSemicolonIsIncluded(): void
    {
        $statements = Migrator::splitStatements("CREATE TABLE a (id INT);\nCREATE TABLE b (id INT)");

        $this->assertCount(2, $statements);
        $this->assertSame('CREATE TABLE b (id INT)', $statements[1]);
    }

    public function testEmptyAndWhitespaceSqlReturnNoStatements(): void
    {
        $this->assertSame([], Migrator::splitStatements(''));
        $this->assertSame([], Migrator::splitStatements("  \n\t  \n"));
        $this->assertSame([], Migrator::splitStatements("-- only a comment\n"));
    }

    public function testWindowsLineEndingsAreHandled(): void
    {
        $statements = Migrator::splitStatements("CREATE TABLE a (id INT);\r\nCREATE TABLE b (id INT);\r\n");

        $this->assertCount(2, $statements);
        $this->assertSame('CREATE TABLE b (id INT)', $statements[1]);
    }
}
