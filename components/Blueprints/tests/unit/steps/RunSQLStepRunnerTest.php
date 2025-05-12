<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\Steps\DataClass\RunSQLStep;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Resources\ResourceManager;
use WordPress\Blueprints\Runner\Step\RunSQLStepRunner;
use WordPress\Blueprints\Runner\WordPressBoot\BootOptions;
use WordPress\Blueprints\Runner\WordPressBoot\WordPressBootManager;
use WordPress\Blueprints\Runtime\Runtime;

use function WordPress\Filesystem\wp_join_paths;

class RunSQLStepRunnerTest extends PHPUnitTestCase {
    /**
     * @var string
     */
    private $document_root;

    /**
     * @var Runtime
     */
    private $runtime;

    /**
     * @var ResourceManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $resource_manager;

    /**
     * @before
     */
    public function setUp(): void {
        $this->document_root = wp_join_paths(sys_get_temp_dir(), 'test_runsql_' . uniqid());
        if (!is_dir($this->document_root)) {
            mkdir($this->document_root, 0777, true);
        }

        // Boot WordPress using WordPressBootManager
        $options = BootOptions::parse([
            'siteUrl'     => 'https://example.com',
            'documentRoot' => $this->document_root,
        ]);

        $this->runtime = WordPressBootManager::boot($options);
    }

    /**
     * @after
     */
    public function tearDown(): void {
        // Clean up temp directory
        if (is_dir($this->document_root)) {
            $this->removeDirectory($this->document_root);
        }
    }

    private function removeDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object == "." || $object == "..") continue;

            $path = $dir . DIRECTORY_SEPARATOR . $object;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Test running a simple SQL query
     */
    public function testRunSimpleSQLQuery() {
        // Create a test table SQL
        $sql = "CREATE TABLE IF NOT EXISTS test_table (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100));";
		file_put_contents($this->document_root . '/test.sql', $sql);

        $step = new RunSQLStep();
        $step->sql = DataReference::create('test.sql');

        $tracker = new Tracker();
        $step_runner = new RunSQLStepRunner();
        $step_runner->setRuntime($this->runtime);
        $step_runner->run($step, $tracker);

        // Verify the table was created
        $table_exists = $this->runtime->evalPhpInSubProcess(
            <<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            global $wpdb;
            $table_name = 'test_table';
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            echo ($result === $table_name) ? 'true' : 'false';
            PHP
        );

        $this->assertEquals('true', $table_exists);
    }

    /**
     * Test running SQL queries that insert data
     */
    public function testRunSQLQueryWithInserts() {
        // Create tables and insert data
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS test_table (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100));
INSERT INTO test_table (name) VALUES ('Test 1');
INSERT INTO test_table (name) VALUES ('Test 2');
INSERT INTO test_table (name) VALUES ('Test 3');
SQL;
		file_put_contents($this->document_root . '/test.sql', $sql);

        $step_runner = new RunSQLStepRunner();
        $step_runner->setRuntime($this->runtime);

        $step = new RunSQLStep();
        $step->sql = DataReference::create('test.sql');

        $tracker = new Tracker();
        $step_runner->run($step, $tracker);

        // Verify the data was inserted
        $result = $this->runtime->evalPhpInSubProcess(
            <<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            global $wpdb;
            $count = $wpdb->get_var("SELECT COUNT(*) FROM test_table");
            $rows = $wpdb->get_results("SELECT * FROM test_table ORDER BY id", ARRAY_A);
            echo json_encode([
                'count' => (int)$count,
                'rows' => $rows
            ]);
            PHP
        );

        $data = json_decode($result, true);
        $this->assertEquals(3, $data['count']);
        $this->assertEquals('Test 1', $data['rows'][0]['name']);
        $this->assertEquals('Test 2', $data['rows'][1]['name']);
        $this->assertEquals('Test 3', $data['rows'][2]['name']);
    }

    /**
     * Test running SQL queries that modify WordPress options
     */
    public function testRunSQLQueryModifyingWordPressOptions() {
        // Create SQL that inserts into wp_options
        $sql = <<<SQL
INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('sql_test_option', 'sql_test_value', 'yes');
UPDATE wp_options SET option_value = 'updated_via_sql' WHERE option_name = 'sql_test_option';
SQL;
		file_put_contents($this->document_root . '/test.sql', $sql);

        $step_runner = new RunSQLStepRunner();
        $step_runner->setRuntime($this->runtime);

        $step = new RunSQLStep();
        $step->sql = DataReference::create('test.sql');

        $tracker = new Tracker();
        $step_runner->run($step, $tracker);

        // Verify the option was set through WordPress API
        $option_value = $this->runtime->evalPhpInSubProcess(
            <<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            echo get_option('sql_test_option');
            PHP
        );

        $this->assertEquals('updated_via_sql', $option_value);
    }

    /**
     * Test running multiple SQL statements
     */
    public function testRunMultipleSQLStatements() {
        // Create multiple SQL statements
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS test_table_1 (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(100));
CREATE TABLE IF NOT EXISTS test_table_2 (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(100));
INSERT INTO test_table_1 (value) VALUES ('table_1_data');
INSERT INTO test_table_2 (value) VALUES ('table_2_data');
SQL;
		file_put_contents($this->document_root . '/test.sql', $sql);

        $step_runner = new RunSQLStepRunner();
        $step_runner->setRuntime($this->runtime);

        $step = new RunSQLStep();
        $step->sql = DataReference::create('test.sql');

        $tracker = new Tracker();
        $step_runner->run($step, $tracker);

        // Verify both tables were created with data
        $result = $this->runtime->evalPhpInSubProcess(
            <<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            global $wpdb;
            
            $table1_data = $wpdb->get_var("SELECT value FROM test_table_1 LIMIT 1");
            $table2_data = $wpdb->get_var("SELECT value FROM test_table_2 LIMIT 1");
            
            echo json_encode([
                'table1' => $table1_data,
                'table2' => $table2_data
            ]);
            PHP
        );

        $data = json_decode($result, true);
        $this->assertEquals('table_1_data', $data['table1']);
        $this->assertEquals('table_2_data', $data['table2']);
    }

    /**
     * Test handling SQL errors
     */
    public function testHandleSQLErrors() {
        // Create SQL with an error (invalid syntax)
        $sql = "CREATE TABLE test_table (id INT PRIMARY KEY); INSERT INTO nonexistent_table VALUES (1);";
		file_put_contents($this->document_root . '/test.sql', $sql);

        $step_runner = new RunSQLStepRunner();
        $step_runner->setRuntime($this->runtime);

        $step = new RunSQLStep();
        $step->sql = DataReference::create('test.sql');

        $tracker = new Tracker();

        // We expect the second statement to fail, but the runner shouldn't throw
        // But should continue processing the valid statements
        $step_runner->run($step, $tracker);

        // Verify the first table was created despite the error
        $table_exists = $this->runtime->evalPhpInSubProcess(
            <<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            global $wpdb;
            $table_name = 'test_table';
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            echo ($result === $table_name) ? 'true' : 'false';
            PHP
        );

        $this->assertEquals('true', $table_exists);
    }
}
