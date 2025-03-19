<?php

use WordPress\Sync\RecursiveDirectorySeeker;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once 'ConsoleTable.php';

/**
 * Example schema for sync_entries table (single table approach):
 *
 * CREATE TABLE IF NOT EXISTS sync_entries (
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   entity_type VARCHAR(16) NOT NULL, -- 'file' or 'db'
 *   table_name VARCHAR(64) DEFAULT NULL,
 *   record_id VARCHAR(255) NOT NULL,
 *   row_hash VARCHAR(64) NOT NULL,
 *   last_updated DATETIME NOT NULL,
 *   is_deleted TINYINT(1) NOT NULL DEFAULT 0
 * ) ENGINE=InnoDB;
 *
 * Example schema for config_store table:
 *
 * CREATE TABLE IF NOT EXISTS config_store (
 *   config_key VARCHAR(255) PRIMARY KEY,
 *   config_value TEXT
 * ) ENGINE=InnoDB;
 */

/**
 * Minimal key/value config store. Uses a table named config_store with columns:
 *   config_key (PK), config_value (TEXT)
 */
class ConfigStore {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function get(string $key) {
        $stmt = $this->db->prepare("SELECT config_value FROM config_store WHERE config_key = :k");
        $stmt->bindValue(':k', $key);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return @unserialize($row['config_value']);
    }

    public function set(string $key, $value) {
        $serialized = serialize($value);
        $stmt = $this->db->prepare("
            INSERT INTO config_store (config_key, config_value)
            VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE config_value = :v
        ");
        $stmt->bindValue(':k', $key);
        $stmt->bindValue(':v', $serialized);
        $stmt->execute();
    }
}

/**
 * Simple Bloom filter in PHP. Not memory-friendly for huge sets, but illustrative.
 */
class BloomFilter {
    private $size;
    private $bitArray;
    private $hashFunctions;

    public function __construct(int $size, int $hashFunctions = 3) {
        $this->size = $size;
        $this->bitArray = array_fill(0, $size, false);
        $this->hashFunctions = $hashFunctions;
    }

    public function add(string $value) {
        foreach ($this->getHashes($value) as $hash) {
            $this->bitArray[$hash % $this->size] = true;
        }
    }

    public function mightContain(string $value): bool {
        foreach ($this->getHashes($value) as $hash) {
            if (!$this->bitArray[$hash % $this->size]) {
                return false;
            }
        }
        return true;
    }

    private function getHashes(string $value): array {
        $results = [];
        for ($i = 0; $i < $this->hashFunctions; $i++) {
            $results[] = crc32($value . $i);
        }
        return $results;
    }
}

/**
 * Data access object for the sync_entries table.
 */

/**
 * SyncStateDAO refactored to use (entity_type, table_name, record_id) as the unique key for upserting,
 * ignoring the entire record content for the ON DUPLICATE KEY condition.
 * 
 * Example schema change:
 *   ALTER TABLE sync_entries
 *   ADD UNIQUE KEY uniq_entity_table_record (entity_type, table_name, record_id);
 */
class SyncStateDAO {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function upsertEntry(
        string $entityType,
        ?string $tableName,
        string $recordId,
        string $rowHash,
        bool $isDeleted
    ) {
        $stmt = $this->db->prepare("
            INSERT INTO sync_entries (entity_type, table_name, record_id, row_hash, last_updated, is_deleted)
            VALUES (:et, :tn, :rid, :rh, NOW(), :del)
            ON DUPLICATE KEY UPDATE
				last_updated = IF(row_hash != VALUES(row_hash), VALUES(last_updated), last_updated),
				row_hash = IF(row_hash != VALUES(row_hash), VALUES(row_hash), row_hash),
                is_deleted = VALUES(is_deleted)
        ");
        $stmt->bindValue(':et', $entityType);
        $stmt->bindValue(':tn', $tableName);
        $stmt->bindValue(':rid', $recordId);
        $stmt->bindValue(':rh', $rowHash);
        $stmt->bindValue(':del', $isDeleted ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function markDeletedByRecordId(string $entityType, ?string $tableName, string $recordId) {
        $stmt = $this->db->prepare("
            UPDATE sync_entries
            SET is_deleted = 1, last_updated = NOW()
            WHERE entity_type = :et
              AND (table_name <=> :tn)
              AND record_id = :rid
        ");
        $stmt->bindValue(':et', $entityType);
        $stmt->bindValue(':tn', $tableName);
        $stmt->bindValue(':rid', $recordId);
        $stmt->execute();
    }

    public function markDeletedBulk(array $ids) {
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            UPDATE sync_entries
            SET is_deleted = 1, last_updated = NOW()
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($ids);
    }

    public function fetchRangeById(int $startId, int $endId): array {
        $stmt = $this->db->prepare("
            SELECT id, entity_type, table_name, record_id
            FROM sync_entries
            WHERE id BETWEEN :start AND :end
        ");
        $stmt->bindValue(':start', $startId, PDO::PARAM_INT);
        $stmt->bindValue(':end', $endId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}


/**
 * Scans database tables. Streams $n rows at a time.
 * Tracks cursor in config store (key example: "scan_cursor_tablename").
 */

/**
 * DbScanner refactored to detect single-column primary key and resume scanning with string or numeric keys.
 */
class DbScanner {
    private $db;
    private $configStore;

    public function __construct(PDO $db, ConfigStore $configStore) {
        $this->db = $db;
        $this->configStore = $configStore;
    }

    /**
     * Get a list of all tables in the current database
     * 
     * @return array List of table names
     */
    public function findTablesToSync(): array {
        $stmt = $this->db->query("SHOW TABLES");
        $tables = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            if ($row[0] !== 'sync_entries') {
                $tables[] = $row[0];
            }
        }
        return $tables;
    }

    public function scanTable(string $tableName, int $n, int $timeLimitSec, BloomFilter $bloomFilter) {
        $startTime = time();

        // Detect single-column primary key
        $pkColumn = $this->getPrimaryKeyColumn($tableName);
        if (!$pkColumn) {
            return; // ignoring multi-column or no-PK tables
        }

        $cursorKey = "scan_cursor_{$tableName}_{$pkColumn}";
        $cursor = $this->configStore->get($cursorKey);
        if (!is_string($cursor)) {
            $cursor = ''; // start from empty string
        }
        $rowsScanned = 0;

        while ($rowsScanned < $n) {
            if (time() - $startTime >= $timeLimitSec) {
                break;
            }

            // We do a lexical comparison for the PK, even if numeric
            $stmt = $this->db->prepare("
                SELECT *
                FROM `{$tableName}`
                WHERE `{$pkColumn}` > :cursor
                ORDER BY `{$pkColumn}` ASC
                LIMIT :lim
            ");
            $stmt->bindValue(':cursor', $cursor, PDO::PARAM_STR);
            $stmt->bindValue(':lim', $n - $rowsScanned, PDO::PARAM_INT);
            $stmt->execute();

            $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$fetched) {
                // we've reached the end; reset so next time we start from beginning
                $this->configStore->set($cursorKey, '');
                break;
            }

            // Build a bulk upsert statement
            $placeholders = [];
            $values = [];
            foreach ($fetched as $row) {
                $recordId = (string) $row[$pkColumn];
                $rowHash = sha1(json_encode($row));

                // Prepare data for bulk insert
                $placeholders[] = "('db', ?, ?, ?, NOW(), 0)";
                $values = array_merge($values, [$tableName, $recordId, $rowHash]);
            }

            // Execute bulk upsert with ON DUPLICATE KEY UPDATE
            $sql = "
                INSERT INTO sync_entries (entity_type, table_name, record_id, row_hash, last_updated, is_deleted)
                VALUES " . implode(',', $placeholders) . "
                ON DUPLICATE KEY UPDATE
                  last_updated = IF(row_hash != VALUES(row_hash), VALUES(last_updated), last_updated),
                  row_hash = IF(row_hash != VALUES(row_hash), VALUES(row_hash), row_hash),
                  is_deleted = 0
            ";
            $insertStmt = $this->db->prepare($sql);
            $insertStmt->execute($values);

            // Update bloom filter and cursor
            foreach ($fetched as $row) {
                $recordId = (string) $row[$pkColumn];
                $bloomFilter->add($tableName . '#' . $recordId);
                $cursor = $recordId;
                $rowsScanned++;
                if ((time() - $startTime) >= $timeLimitSec || $rowsScanned >= $n) {
                    break 2;
                }
            }

            $this->configStore->set($cursorKey, $cursor);
        }
    }

    private function getPrimaryKeyColumn(string $tableName): ?string {
        $stmt = $this->db->prepare("SHOW KEYS FROM `{$tableName}` WHERE Key_name = 'PRIMARY'");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // naive: only handle single-column primary keys
        if (!$rows) {
            return null;
        }
        $uniqueColumns = array_unique(array_column($rows, 'Column_name'));
        if (count($uniqueColumns) === 1) {
            return $uniqueColumns[0];
        }
        return null;
    }
}

/**
 * Recursive filesystem scanner in chunks. Tracks cursor in config store (key: "scan_cursor_files").
 * We store a list of paths for partial scanning. Minimally illustrative.
 */

/**
 * FileScanner refactored to resume without relying on numeric skip offsets.
 * We keep a "lastPath" cursor for each directory to know which file or folder we last processed.
 * On the next scan, we open the directory, skip entries until we find that "lastPath".
 * If it's not found, we assume it's no longer valid and just read from the beginning.
 */
class FileScanner {
    private $configStore;
    private $rootDir;
    private $syncDao;
    private $db;

    public function __construct(SyncStateDAO $syncDao, PDO $db, ConfigStore $configStore, string $rootDir) {
        $this->syncDao = $syncDao;
        $this->configStore = $configStore;
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->db = $db;
    }

    public function scanFiles(int $n, int $timeLimitSec, BloomFilter $bloomFilter) {
        $startTime = time();
        $cursorKey = "scan_cursor_files";

        /**
         * State format stored in config:
         * [
         *   "queue" => [...],               // directories left to scan
         *   "currentDirectory" => <string>, // directory path currently being scanned
         *   "lastPath" => <string|null>    // the last file/directory name we processed in currentDirectory
         * ]
         */
        $state = $this->configStore->get($cursorKey);
        if (!is_array($state)) {
            $state = [
                'lastPath' => $this->rootDir,
            ];
        }

        $scanned = 0;
		$iterator = new RecursiveDirectorySeeker($this->rootDir);
		if(isset($state['lastPath']) && $state['lastPath'] !== $this->rootDir) {
			$iterator->seek_to_closest_matching_prefix( $state['lastPath'] );
			// In case the last entry was deleted, mark it as such in the database
			// and rewind to the beginning.
			if(!file_exists($state['lastPath'])) {
				$this->syncDao->markDeletedByRecordId('file', NULL, $state['lastPath']);
			}
		}

        while ($scanned < $n && (time() - $startTime) < $timeLimitSec) {
			if(false === $iterator->next_path()) {
				// Directory tree scan complete. Loop back to the root.
				$iterator->reset();
				$iterator->next_path();
			}
			
            $entry = $iterator->get_current_path();
            if ($entry === '.' || $entry === '..' || $entry === $this->rootDir) {
                continue;
            }

			$this->configStore->set($cursorKey, [
				'lastPath' => $iterator->get_current_path(),
			]);

            if(!is_file($entry)) {
				continue;
			}

            $hash = @sha1_file($entry) ?: sha1($entry);
            $this->syncDao->upsertEntry('file', '', $entry, $hash, 0);
            $bloomFilter->add($entry);
            $scanned++;
        }
	}
}


/**
 * Top-level synchronizer that manages concurrency and bloom filter sweeps.
 */
class SyncScanner {
    private $db;
    private $configStore;
    private $syncDao;
    private $dbScanner;
    private $fileScanner;
    private $lockName;

    public function __construct(
        PDO $db,
        ConfigStore $configStore,
        SyncStateDAO $syncDao,
        DbScanner $dbScanner,
        FileScanner $fileScanner,
        string $lockName = 'sync_scanner_lock'
    ) {
        $this->db = $db;
        $this->configStore = $configStore;
        $this->syncDao = $syncDao;
        $this->dbScanner = $dbScanner;
        $this->fileScanner = $fileScanner;
        $this->lockName = $lockName;
    }

    public function runScan(array $tablesToScan, int $maxItems, int $timeLimitSec, BloomFilter $bloomFilter) {
        if (!$this->acquireLock()) {
            return;
        }

        try {
            $budgetPerSource = ceil($maxItems / (count($tablesToScan) + 1));
            foreach ($tablesToScan as $tableName) {
                $this->dbScanner->scanTable($tableName, $budgetPerSource, $timeLimitSec, $bloomFilter);
            }
            $this->fileScanner->scanFiles($budgetPerSource, $timeLimitSec, $bloomFilter);
        } finally {
            $this->releaseLock();
        }
    }

    public function runBloomFilterSweep(int $startId, int $endId, BloomFilter $bloomFilter) {
        $this->db->beginTransaction();
        try {
            $records = $this->syncDao->fetchRangeById($startId, $endId);
            $toDelete = [];
            foreach ($records as $r) {
                $key = ($r['entity_type'] === 'file')
                    ? $r['record_id']
                    : ($r['table_name'] . '#' . $r['record_id']);
                if (!$bloomFilter->mightContain($key)) {
                    $toDelete[] = $r['id'];
                }
            }
            $this->syncDao->markDeletedBulk($toDelete);
            $this->db->commit();
        } catch (\Exception $ex) {
            $this->db->rollBack();
            throw $ex;
        }
    }

    private function acquireLock(): bool {
        $stmt = $this->db->prepare("SELECT GET_LOCK(:lockName, 0) AS got_lock");
        $stmt->bindValue(':lockName', $this->lockName, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return isset($row['got_lock']) && $row['got_lock'] == 1;
    }

    private function releaseLock(): void {
        $stmt = $this->db->prepare("SELECT RELEASE_LOCK(:lockName)");
        $stmt->bindValue(':lockName', $this->lockName, PDO::PARAM_STR);
        $stmt->execute();
    }
}

$pdo = new PDO('mysql:host=127.0.0.1', 'root', 'my-secret-pw');
// Create tables if they don't exist
$pdo->exec("CREATE DATABASE IF NOT EXISTS mydb2");
$pdo->exec("USE mydb2");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS sync_entries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(16) NOT NULL,
        table_name VARCHAR(64) DEFAULT NULL,
        record_id VARCHAR(255) NOT NULL,
        row_hash VARCHAR(64) NOT NULL,
        last_updated DATETIME NOT NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_entity_table_record (entity_type, table_name, record_id)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS config_store (
        config_key VARCHAR(255) PRIMARY KEY,
        config_value TEXT
    )
");

// Create a new table for users
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        email VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL
    )
");

// Insert some fake records into the users table
// $pdo->exec("
//     INSERT INTO users (username, email, created_at) VALUES
//     ('john_doe', 'john@example.com', NOW()),
//     ('jane_smith', 'jane@example.com', NOW()),
//     ('bob_johnson', 'bob@example.com', NOW()),
//     ('alice_williams', 'alice@example.com', NOW()),
//     ('mike_brown', 'mike@example.com', NOW())
// ");
// Update Mike Brown's email
$stmt = $pdo->prepare("
    UPDATE users
    SET email = 'mike.brown@ex3a3mpele.com'
    WHERE username = 'mike_brown'
");
$stmt->execute();


$configStore = new ConfigStore($pdo);
$syncDao = new SyncStateDAO($pdo);
$dbScanner = new DbScanner($pdo, $configStore);
$fileScanner = new FileScanner($syncDao, $pdo, $configStore, './');
$scanner = new SyncScanner($pdo, $configStore, $syncDao, $dbScanner, $fileScanner);

$bloom = new BloomFilter(1000000, 3);
$tables = $dbScanner->findTablesToSync();
$scanner->runScan($tables, 1000, 10, $bloom);

// @TODO: Make this work:
// $scanner->runBloomFilterSweep(1, 50000, $bloom);

// Print entries from the sync_entries table
echo "Sync Entries:\n";
$stmt = $pdo->query("SELECT * FROM sync_entries");
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($entries)) {
    echo "No entries found in the sync_entries table.\n";
} else {
    $table = new ConsoleTable(['ID', 'Type', 'Table', 'Record ID', 'Hash', 'Updated', 'Deleted']);
    foreach ($entries as $entry) {
        $table->addRow([
            $entry['id'],
            $entry['entity_type'],
            $entry['table_name'] ?? 'NULL',
            $entry['record_id'],
            substr($entry['row_hash'], 0, 10) . '...',
            $entry['last_updated'],
            $entry['is_deleted'] ? 'Yes' : 'No'
        ]);
    }
    echo $table->render();
}
