<?php

namespace Illuminate\Tests\Database;

use BadMethodCallException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Grammars\SqlServerGrammar;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class DatabaseSchemaBlueprintIntegrationTest extends TestCase
{
    protected $db;

    /**
     * Bootstrap Eloquent.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db = $db = new DB;

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->setAsGlobal();

        $container = new Container;
        $container->instance('db', $db->getDatabaseManager());
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        $this->db->connection()->getSchemaBuilder()->useNativeSchemaOperationsIfPossible(false);
    }

    public function testRenamingAndChangingColumnsWork()
    {
        $this->db->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name');
            $table->string('age');
        });

        $blueprint = new Blueprint('users', function ($table) {
            $table->renameColumn('name', 'first_name');
            $table->integer('age')->change();
        });

        $queries = $blueprint->toSql($this->db->connection(), new SQLiteGrammar);

        // Expect one of the following two query sequences to be present...
        $expected = [
            [
                'CREATE TEMPORARY TABLE __temp__users AS SELECT name, age FROM users',
                'DROP TABLE users',
                'CREATE TABLE users (name VARCHAR(255) NOT NULL, age INTEGER NOT NULL)',
                'INSERT INTO users (name, age) SELECT name, age FROM __temp__users',
                'DROP TABLE __temp__users',
                'CREATE TEMPORARY TABLE __temp__users AS SELECT name, age FROM users',
                'DROP TABLE users',
                'CREATE TABLE users (first_name VARCHAR(255) NOT NULL, age VARCHAR(255) NOT NULL COLLATE "BINARY")',
                'INSERT INTO users (first_name, age) SELECT name, age FROM __temp__users',
                'DROP TABLE __temp__users',
            ],
            [
                'CREATE TEMPORARY TABLE __temp__users AS SELECT name, age FROM users',
                'DROP TABLE users',
                'CREATE TABLE users (name VARCHAR(255) NOT NULL COLLATE "BINARY", age INTEGER NOT NULL)',
                'INSERT INTO users (name, age) SELECT name, age FROM __temp__users',
                'DROP TABLE __temp__users',
                'CREATE TEMPORARY TABLE __temp__users AS SELECT name, age FROM users',
                'DROP TABLE users',
                'CREATE TABLE users (first_name VARCHAR(255) NOT NULL, age VARCHAR(255) NOT NULL COLLATE "BINARY")',
                'INSERT INTO users (first_name, age) SELECT name, age FROM __temp__users',
                'DROP TABLE __temp__users',
            ],
        ];

        $this->assertContains($queries, $expected);
    }

    public function testRenamingColumnsWithoutDoctrineWorks()
    {
        $connection = $this->db->connection();
        $schema = $connection->getSchemaBuilder();

        $schema->useNativeSchemaOperationsIfPossible();

        $base = new Blueprint('users', function ($table) {
            $table->renameColumn('name', 'new_name');
        });

        $blueprint = clone $base;
        $this->assertEquals(['alter table `users` rename column `name` to `new_name`'], $blueprint->toSql($connection, new MySqlGrammar));

        $blueprint = clone $base;
        $this->assertEquals(['alter table "users" rename column "name" to "new_name"'], $blueprint->toSql($connection, new PostgresGrammar));

        $blueprint = clone $base;
        $this->assertEquals(['alter table "users" rename column "name" to "new_name"'], $blueprint->toSql($connection, new SQLiteGrammar));

        $blueprint = clone $base;
        $this->assertEquals(['sp_rename \'"users"."name"\', "new_name", \'COLUMN\''], $blueprint->toSql($connection, new SqlServerGrammar));

        $schema->create('test', function (Blueprint $table) {
            $table->string('foo');
            $table->string('baz');
        });

        $schema->table('test', function (Blueprint $table) {
            $table->renameColumn('foo', 'bar');
            $table->renameColumn('baz', 'qux');
        });

        $this->assertFalse($schema->hasColumn('test', 'foo'));
        $this->assertFalse($schema->hasColumn('test', 'baz'));
        $this->assertTrue($schema->hasColumns('test', ['bar', 'qux']));
    }

    public function testDroppingColumnsWithoutDoctrineWorks()
    {
        $connection = $this->db->connection();
        $schema = $connection->getSchemaBuilder();

        $schema->useNativeSchemaOperationsIfPossible();

        $blueprint = new Blueprint('users', function ($table) {
            $table->dropColumn('name');
        });

        $this->assertEquals(['alter table "users" drop column "name"'], $blueprint->toSql($connection, new SQLiteGrammar));
    }

    public function testNativeColumnModifyingOnMySql()
    {
        $connection = $this->db->connection();
        $schema = $connection->getSchemaBuilder();

        $schema->useNativeSchemaOperationsIfPossible();

        $blueprint = new Blueprint('users', function ($table) {
            $table->double('amount', 6, 2)->nullable()->invisible()->after('name')->change();
            $table->timestamp('added_at', 4)->nullable(false)->useCurrent()->useCurrentOnUpdate()->change();
            $table->enum('difficulty', ['easy', 'hard'])->default('easy')->charset('utf8mb4')->collation('unicode')->change();
            $table->multiPolygon('positions')->srid(1234)->storedAs('expression')->change();
            $table->string('old_name', 50)->renameTo('new_name')->change();
            $table->bigIncrements('id')->first()->from(10)->comment('my comment')->change();
        });

        $this->assertEquals([
            'alter table `users` '
            .'modify `amount` double(6, 2) null invisible after `name`, '
            .'modify `added_at` timestamp(4) not null default CURRENT_TIMESTAMP(4) on update CURRENT_TIMESTAMP(4), '
            ."modify `difficulty` enum('easy', 'hard') character set utf8mb4 collate 'unicode' not null default 'easy', "
            .'modify `positions` multipolygon as (expression) stored srid 1234, '
            .'change `old_name` `new_name` varchar(50) not null, '
            ."modify `id` bigint unsigned not null auto_increment primary key comment 'my comment' first",
            'alter table `users` auto_increment = 10',
        ], $blueprint->toSql($connection, new MySqlGrammar));
    }

    public function testNativeColumnModifyingOnPostgreSql()
    {
        $connection = $this->db->connection();
        $schema = $connection->getSchemaBuilder();

        $schema->useNativeSchemaOperationsIfPossible();

        $blueprint = new Blueprint('users', function ($table) {
            $table->integer('code')->autoIncrement()->from(10)->comment('my comment')->change();
        });

        $this->assertEquals([
            'alter table "users" '
            .'alter column "code" type serial, '
            .'alter column "code" set not null, '
            .'alter column "code" drop default, '
            .'alter column "code" drop identity if exists',
            'alter sequence users_code_seq restart with 10',
            'comment on column "users"."code" is \'my comment\'',
        ], $blueprint->toSql($connection, new PostgresGrammar));

        $blueprint = new Blueprint('users', function ($table) {
            $table->char('name', 40)->nullable()->default('easy')->collation('unicode')->change();
        });

        $this->assertEquals([
            'alter table "users" '
            .'alter column "name" type char(40) collate "unicode", '
            .'alter column "name" drop not null, '
            .'alter column "name" set default \'easy\', '
            .'alter column "name" drop identity if exists',
            'comment on column "users"."name" is NULL',
        ], $blueprint->toSql($connection, new PostgresGrammar));

        $blueprint = new Blueprint('users', function ($table) {
            $table->integer('foo')->generatedAs('expression')->always()->change();
        });

        $this->assertEquals([
            'alter table "users" '
            .'alter column "foo" type integer, '
            .'alter column "foo" set not null, '
            .'alter column "foo" drop default, '
            .'alter column "foo" drop identity if exists, '
            .'alter column "foo" add  generated always as identity (expression)',
            'comment on column "users"."foo" is NULL',
        ], $blueprint->toSql($connection, new PostgresGrammar));

        $blueprint = new Blueprint('users', function ($table) {
            $table->point('foo')->isGeometry()->projection(1234)->change();
        });

        $this->assertEquals([
            'alter table "users" '
            .'alter column "foo" type geometry(point, 1234), '
            .'alter column "foo" set not null, '
            .'alter column "foo" drop default, '
            .'alter column "foo" drop identity if exists',
            'comment on column "users"."foo" is NULL',
        ], $blueprint->toSql($connection, new PostgresGrammar));

        $blueprint = new Blueprint('users', function ($table) {
            $table->timestamp('added_at', 2)->useCurrent()->storedAs(null)->change();
        });

        $this->assertEquals([
            'alter table "users" '
            .'alter column "added_at" type timestamp(2) without time zone, '
            .'alter column "added_at" set not null, '
            .'alter column "added_at" set default CURRENT_TIMESTAMP, '
            .'alter column "added_at" drop expression if exists, '
            .'alter column "added_at" drop identity if exists',
            'comment on column "users"."added_at" is NULL',
        ], $blueprint->toSql($connection, new PostgresGrammar));
    }

    public function testNativeColumnModifyingOnSqlServer()
    {
        $connection = $this->db->connection();
        $schema = $connection->getSchemaBuilder();

        $schema->useNativeSchemaOperationsIfPossible();

        $blueprint = new Blueprint('users', function ($table) {
            $table->timestamp('added_at', 4)->nullable(false)->useCurrent()->change();
        });

        $this->assertEquals([
            "DECLARE @sql NVARCHAR(MAX) = '';SELECT @sql += 'ALTER TABLE [dbo].[users] DROP CONSTRAINT ' + OBJECT_NAME([default_object_id]) + ';' FROM sys.columns WHERE [object_id] = OBJECT_ID('[dbo].[users]') AND [name] in ('added_at') AND [default_object_id] <> 0;EXEC(@sql)",
            'alter table "users" alter column "added_at" datetime2(4) not null',
            'alter table "users" add default CURRENT_TIMESTAMP for "added_at"',
        ], $blueprint->toSql($connection, new SqlServerGrammar));

        $blueprint = new Blueprint('users', function ($table) {
            $table->char('name', 40)->nullable()->default('easy')->collation('unicode')->change();
        });

        $this->assertEquals([
            "DECLARE @sql NVARCHAR(MAX) = '';SELECT @sql += 'ALTER TABLE [dbo].[users] DROP CONSTRAINT ' + OBJECT_NAME([default_object_id]) + ';' FROM sys.columns WHERE [object_id] = OBJECT_ID('[dbo].[users]') AND [name] in ('name') AND [default_object_id] <> 0;EXEC(@sql)",
            'alter table "users" alter column "name" nchar(40) collate unicode null',
            'alter table "users" add default \'easy\' for "name"',
        ], $blueprint->toSql($connection, new SqlServerGrammar));

        $blueprint = new Blueprint('users', function ($table) {
            $table->integer('foo')->change();
        });

        $this->assertEquals([
            "DECLARE @sql NVARCHAR(MAX) = '';SELECT @sql += 'ALTER TABLE [dbo].[users] DROP CONSTRAINT ' + OBJECT_NAME([default_object_id]) + ';' FROM sys.columns WHERE [object_id] = OBJECT_ID('[dbo].[users]') AND [name] in ('foo') AND [default_object_id] <> 0;EXEC(@sql)",
            'alter table "users" alter column "foo" int not null',
        ], $blueprint->toSql($connection, new SqlServerGrammar));
    }

    public function testChangingColumnWithCollationWorks()
    {
        $this->db->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('age');
        });

        $blueprint = new Blueprint('users', function ($table) {
            $table->integer('age')->collation('RTRIM')->change();
        });

        $blueprint2 = new Blueprint('users', function ($table) {
            $table->integer('age')->collation('NOCASE')->change();
        });

        $queries = $blueprint->toSql($this->db->connection(), new SQLiteGrammar);

        $expected = [
            [
                'CREATE TEMPORARY TABLE __temp__users AS SELECT age FROM users',
                'DROP TABLE users',
                'CREATE TABLE users (age INTEGER NOT NULL)',
                'INSERT INTO users (age) SELECT age FROM __temp__users',
                'DROP TABLE __temp__users',
            ],
        ];

        $this->assertContains($queries, $expected);

        $queries = $blueprint2->toSql($this->db->connection(), new SQLiteGrammar);

        $expected = [
            [
                'CREATE TEMPORARY TABLE __temp__users AS SELECT age FROM users',
                'DROP TABLE users',
                'CREATE TABLE users (age INTEGER NOT NULL)',
                'INSERT INTO users (age) SELECT age FROM __temp__users',
                'DROP TABLE __temp__users',
            ],
        ];

        $this->assertContains($queries, $expected);
    }

    public function testChangingCharColumnsWork()
    {
        $this->db->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name');
        });

        $blueprint = new Blueprint('users', function ($table) {
            $table->char('name', 50)->change();
        });

        $queries = $blueprint->toSql($this->db->connection(), new SQLiteGrammar);

        $expected = [
            [
                'CREATE TEMPORARY TABLE __temp__users AS SELECT name FROM users',
                'DROP TABLE users',
                'CREATE TABLE users (name CHAR(50) NOT NULL)',
                'INSERT INTO users (name) SELECT name FROM __temp__users',
                'DROP TABLE __temp__users',
            ],
            [
                'CREATE TEMPORARY TABLE __temp__users AS SELECT name FROM users',
                'DROP TABLE users',
                'CREATE TABLE users (name CHAR(50) NOT NULL COLLATE "BINARY")',
                'INSERT INTO users (name) SELECT name FROM __temp__users',
                'DROP TABLE __temp__users',
            ],
        ];

        $this->assertContains($queries, $expected);
    }

    public function testChangingPrimaryAutoincrementColumnsToNonAutoincrementColumnsWork()
    {
        $this->db->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->increments('id');
        });

        $blueprint = new Blueprint('users', function ($table) {
            $table->binary('id')->change();
        });

        $queries = $blueprint->toSql($this->db->connection(), new SQLiteGrammar);

        $expected = [
            [
                'CREATE TEMPORARY TABLE __temp__users AS SELECT id FROM users',
                'DROP TABLE users',
                'CREATE TABLE users (id BLOB NOT NULL, PRIMARY KEY(id))',
                'INSERT INTO users (id) SELECT id FROM __temp__users',
                'DROP TABLE __temp__users',
            ],
        ];

        $this->assertContains($queries, $expected);
    }

    public function testChangingDoubleColumnsWork()
    {
        $this->db->connection()->getSchemaBuilder()->create('products', function ($table) {
            $table->integer('price');
        });

        $blueprint = new Blueprint('products', function ($table) {
            $table->double('price')->change();
        });

        $queries = $blueprint->toSql($this->db->connection(), new SQLiteGrammar);

        $expected = [
            'CREATE TEMPORARY TABLE __temp__products AS SELECT price FROM products',
            'DROP TABLE products',
            'CREATE TABLE products (price DOUBLE PRECISION NOT NULL)',
            'INSERT INTO products (price) SELECT price FROM __temp__products',
            'DROP TABLE __temp__products',
        ];

        $this->assertEquals($expected, $queries);
    }

    public function testRenameIndexWorks()
    {
        $this->db->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name');
            $table->string('age');
        });

        $this->db->connection()->getSchemaBuilder()->table('users', function ($table) {
            $table->index(['name'], 'index1');
        });

        $blueprint = new Blueprint('users', function ($table) {
            $table->renameIndex('index1', 'index2');
        });

        $queries = $blueprint->toSql($this->db->connection(), new SQLiteGrammar);

        $expected = [
            'DROP INDEX index1',
            'CREATE INDEX index2 ON users (name)',
        ];

        $this->assertEquals($expected, $queries);

        $queries = $blueprint->toSql($this->db->connection(), new SqlServerGrammar);

        $expected = [
            'sp_rename N\'"users"."index1"\', "index2", N\'INDEX\'',
        ];

        $this->assertEquals($expected, $queries);

        $queries = $blueprint->toSql($this->db->connection(), new MySqlGrammar);

        $expected = [
            'alter table `users` rename index `index1` to `index2`',
        ];

        $this->assertEquals($expected, $queries);

        $queries = $blueprint->toSql($this->db->connection(), new PostgresGrammar);

        $expected = [
            'alter index "index1" rename to "index2"',
        ];

        $this->assertEquals($expected, $queries);
    }

    public function testAddUniqueIndexWithoutNameWorks()
    {
        $this->db->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name')->nullable();
        });

        $blueprintMySql = new Blueprint('users', function ($table) {
            $table->string('name')->nullable()->unique()->change();
        });

        $queries = $blueprintMySql->toSql($this->db->connection(), new MySqlGrammar);

        $expected = [
            [
                'alter table `users` add unique `users_name_unique`(`name`)',
            ],
        ];

        $this->assertContains($queries, $expected);

        $blueprintPostgres = new Blueprint('users', function ($table) {
            $table->string('name')->nullable()->unique()->change();
        });

        $queries = $blueprintPostgres->toSql($this->db->connection(), new PostgresGrammar);

        $expected = [
            [
                'alter table "users" add constraint "users_name_unique" unique ("name")',
            ],
        ];

        $this->assertContains($queries, $expected);

        $blueprintSQLite = new Blueprint('users', function ($table) {
            $table->string('name')->nullable()->unique()->change();
        });

        $queries = $blueprintSQLite->toSql($this->db->connection(), new SQLiteGrammar);

        $expected = [
            [
                'create unique index "users_name_unique" on "users" ("name")',
            ],
        ];

        $this->assertContains($queries, $expected);

        $blueprintSqlServer = new Blueprint('users', function ($table) {
            $table->string('name')->nullable()->unique()->change();
        });

        $queries = $blueprintSqlServer->toSql($this->db->connection(), new SqlServerGrammar);

        $expected = [
            [
                'create unique index "users_name_unique" on "users" ("name")',
            ],
        ];

        $this->assertContains($queries, $expected);
    }

    public function testAddUniqueIndexWithNameWorks()
    {
        $this->db->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name')->nullable();
        });

        $blueprintMySql = new Blueprint('users', function ($table) {
            $table->string('name')->nullable()->unique('index1')->change();
        });

        $queries = $blueprintMySql->toSql($this->db->connection(), new MySqlGrammar);

        $expected = [
            [
                'alter table `users` add unique `index1`(`name`)',
            ],
        ];

        $this->assertContains($queries, $expected);

        $blueprintPostgres = new Blueprint('users', function ($table) {
            $table->unsignedInteger('name')->nullable()->unique('index1')->change();
        });

        $queries = $blueprintPostgres->toSql($this->db->connection(), new PostgresGrammar);

        $expected = [
            'CREATE TEMPORARY TABLE __temp__users AS SELECT name FROM users',
            'DROP TABLE users',
            'CREATE TABLE users (name INTEGER UNSIGNED DEFAULT NULL)',
            'INSERT INTO users (name) SELECT name FROM __temp__users',
            'DROP TABLE __temp__users',
            'alter table "users" add constraint "index1" unique ("name")',
        ];

        $this->assertEquals($expected, $queries);

        $blueprintSQLite = new Blueprint('users', function ($table) {
            $table->unsignedInteger('name')->nullable()->unique('index1')->change();
        });

        $queries = $blueprintSQLite->toSql($this->db->connection(), new SQLiteGrammar);

        $expected = [
            'CREATE TEMPORARY TABLE __temp__users AS SELECT name FROM users',
            'DROP TABLE users',
            'CREATE TABLE users (name INTEGER UNSIGNED DEFAULT NULL)',
            'INSERT INTO users (name) SELECT name FROM __temp__users',
            'DROP TABLE __temp__users',
            'create unique index "index1" on "users" ("name")',
        ];

        $this->assertEquals($expected, $queries);

        $blueprintSqlServer = new Blueprint('users', function ($table) {
            $table->unsignedInteger('name')->nullable()->unique('index1')->change();
        });

        $queries = $blueprintSqlServer->toSql($this->db->connection(), new SqlServerGrammar);

        $expected = [
            'CREATE TEMPORARY TABLE __temp__users AS SELECT name FROM users',
            'DROP TABLE users',
            'CREATE TABLE users (name INTEGER UNSIGNED DEFAULT NULL)',
            'INSERT INTO users (name) SELECT name FROM __temp__users',
            'DROP TABLE __temp__users',
            'create unique index "index1" on "users" ("name")',
        ];

        $this->assertEquals($expected, $queries);
    }

    public function testDropIndexOnColumnChangeWorks()
    {
        $connection = $this->db->connection();

        $connection->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name')->nullable();
        });

        $blueprint = new Blueprint('users', function ($table) {
            $table->string('name')->nullable()->unique(false)->change();
        });

        $this->assertContains(
            'alter table `users` drop index `users_name_unique`',
            $blueprint->toSql($connection, new MySqlGrammar)
        );

        $blueprint = new Blueprint('users', function ($table) {
            $table->string('name')->nullable()->unique(false)->change();
        });

        $this->assertContains(
            'alter table "users" drop constraint "users_name_unique"',
            $blueprint->toSql($connection, new PostgresGrammar)
        );

        $blueprint = new Blueprint('users', function ($table) {
            $table->string('name')->nullable()->unique(false)->change();
        });

        $this->assertContains(
            'drop index "users_name_unique"',
            $blueprint->toSql($connection, new SQLiteGrammar)
        );

        $blueprint = new Blueprint('users', function ($table) {
            $table->string('name')->nullable()->unique(false)->change();
        });

        $this->assertContains(
            'drop index "users_name_unique" on "users"',
            $blueprint->toSql($connection, new SqlServerGrammar)
        );
    }

    public function testItEnsuresDroppingMultipleColumnsIsAvailable()
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage("SQLite doesn't support multiple calls to dropColumn / renameColumn in a single modification.");

        $this->db->connection()->getSchemaBuilder()->table('users', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->dropColumn('email');
        });
    }

    public function testItEnsuresRenamingMultipleColumnsIsAvailable()
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage("SQLite doesn't support multiple calls to dropColumn / renameColumn in a single modification.");

        $this->db->connection()->getSchemaBuilder()->table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'first_name');
            $table->renameColumn('name2', 'last_name');
        });
    }

    public function testItEnsuresRenamingAndDroppingMultipleColumnsIsAvailable()
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage("SQLite doesn't support multiple calls to dropColumn / renameColumn in a single modification.");

        $this->db->connection()->getSchemaBuilder()->table('users', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->renameColumn('name2', 'last_name');
        });
    }

    public function testItDoesNotSetPrecisionHigherThanSupportedWhenRenamingTimestamps()
    {
        $this->db->connection()->getSchemaBuilder()->create('users', function (Blueprint $table) {
            $table->timestamp('created_at');
        });

        try {
            // this would only fail in mysql, postgres and sql server
            $this->db->connection()->getSchemaBuilder()->table('users', function (Blueprint $table) {
                $table->renameColumn('created_at', 'new_created_at');
            });
            $this->addToAssertionCount(1); // it did not throw
        } catch (\Exception $e) {
            // Expecting something similar to:
            // Illuminate\Database\QueryException
            //   SQLSTATE[42000]: Syntax error or access violation: 1426 Too big precision 10 specified for 'my_timestamp'. Maximum is 6....
            $this->fail('test_it_does_not_set_precision_higher_than_supported_when_renaming_timestamps has failed. Error: '.$e->getMessage());
        }
    }

    public function testItEnsuresDroppingForeignKeyIsAvailable()
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage("SQLite doesn't support dropping foreign keys (you would need to re-create the table).");

        $this->db->connection()->getSchemaBuilder()->table('users', function (Blueprint $table) {
            $table->dropForeign('something');
        });
    }
}
