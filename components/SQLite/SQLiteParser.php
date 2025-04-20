<?php
/**
 * Custom SQLite parser implemented in PHP.
 * 
 * Ports the TypeScript parser from https://github.com/invisal/sqlite-internal.
 */

class SQLiteParser {
	/**
	 * @internal All pages buffer for overflow payloads
	 */
	private static array $allPages = [];

    public static function readUint8(string $data, int $offset): int {
        return ord($data[$offset]);
    }

    public static function readUint16(string $data, int $offset): int {
        $arr = unpack('n', substr($data, $offset, 2));
        return $arr[1];
    }

    public static function readUint32(string $data, int $offset): int {
        $arr = unpack('N', substr($data, $offset, 4));
        return $arr[1];
    }

    public static function parseVarint(string $data, int $offset): array {
        $value = 0;
        $length = 0;
        do {
            $byte = ord($data[$offset + $length]);
            $value = ($value << 7) + ($byte & 0x7f);
            $length++;
        } while ($byte & 0x80);
        return [$value, $length]; // 
    }

    public static function parseDatabaseHeader(string $buffer): array {
        return [
            'pageSize' => self::readUint16($buffer, 16),
            'fileFormatWriteVersion' => self::readUint8($buffer, 18),
            'fileFormatReadVersion' => self::readUint8($buffer, 19),
            'reservedSpace' => self::readUint8($buffer, 20),
            'maximumEmbedPayloadFraction' => self::readUint8($buffer, 21),
            'minimumEmbedPayloadFraction' => self::readUint8($buffer, 22),
            'leafPayloadFraction' => self::readUint8($buffer, 23),
            'fileChangeCounter' => self::readUint32($buffer, 24),
            'pageCount' => self::readUint32($buffer, 28),
            'firstFreelistPage' => self::readUint32($buffer, 32),
            'totalFreelistPages' => self::readUint32($buffer, 36),
            'schemaCookie' => self::readUint32($buffer, 40),
            'schemaFormatNumber' => self::readUint32($buffer, 44),
            'defaultPageCacheSize' => self::readUint32($buffer, 48),
            'largesRootBTreePage' => self::readUint32($buffer, 52),
            'textEncoding' => self::readUint8($buffer, 56),
            'userVersion' => self::readUint32($buffer, 60),
            'incrementalVacuumMode' => self::readUint32($buffer, 64),
            'applicationId' => self::readUint32($buffer, 68),
            'versionValidFor' => self::readUint32($buffer, 92),
            'sqliteVersionNumber' => self::readUint32($buffer, 96),
        ]; // 
    }

    public static function splitPages(string $buffer, array $header): array {
        $pageSize = $header['pageSize'];
        $pageCount = $header['pageCount'];
        $pages = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $offset = $i * $pageSize;
            $pages[] = [
                'number' => $i + 1,
                'data' => substr($buffer, $offset, $pageSize),
                'type' => 'Unknown',
            ];
        }
        return $pages; // 
    }

    public static function parseBTreePage(array $page): ?array {
        $data = $page['data'];
        $number = $page['number'];
        $cursor = ($number === 1 ? 100 : 0);
        $btreeType = self::readUint8($data, $cursor);
        if ($btreeType === 0x0d) {
            $pageType = 'Table Leaf';
        } elseif ($btreeType === 0x05) {
            $pageType = 'Table Interior';
        } elseif ($btreeType === 0x0a) {
            $pageType = 'Index Leaf';
        } elseif ($btreeType === 0x02) {
            $pageType = 'Index Interior';
        } else {
            return null;
        }
        $cellCount = self::readUint16($data, $cursor + 3);
        $cellPointerArrayOffset = self::readUint16($data, $cursor + 5);
        $rightChildPageNumber = null;
        $headerSize = 8;
        if ($pageType === 'Index Interior' || $pageType === 'Table Interior') {
            $rightChildPageNumber = self::readUint32($data, $cursor + 8);
            $headerSize = 12;
        }
        $cursor += $headerSize;
        $cellPointerArray = [];
        for ($i = 0; $i < $cellCount; $i++) {
            $offset2 = $cursor;
            $content = substr($data, $cursor, 2);
            $value = self::readUint16($data, $cursor);
            $cellPointerArray[] = [
                'offset' => $offset2,
                'length' => 2,
                'content' => $content,
                'value' => $value,
            ];
            $cursor += 2;
        }
        $firstFreeblockOffset = self::readUint16($data, $cursor + 1);
        $fragmentFreeBytes = self::readUint8($data, $cursor + 7);
        return [
            'number' => $number,
            'data' => $data,
            'type' => $pageType,
            'cellPointerArray' => $cellPointerArray,
            'header' => [
                'pageType' => $btreeType,
                'firstFreeblockOffset' => $firstFreeblockOffset,
                'cellCount' => $cellCount,
                'cellPointerArrayOffset' => $cellPointerArrayOffset,
                'fragmentFreeBytes' => $fragmentFreeBytes,
                'rightChildPageNumber' => $rightChildPageNumber,
            ],
        ]; // 
    }

    public static function parseTableInteriorPage(array $page): array {
        $data = $page['data'];
        $cells = [];
        foreach ($page['cellPointerArray'] as $cell) {
            $cursor = $cell['value'];
            $pageNumber = self::readUint32($data, $cursor);
            list($rowid, $rowidLength) = self::parseVarint($data, $cursor + 4);
            $cells[] = [
                'pageNumber' => $pageNumber,
                'rowid' => $rowid,
                'rowidLength' => $rowidLength,
                'content' => substr($data, $cursor, 4 + $rowidLength),
                'length' => 4 + $rowidLength,
                'offset' => $cell['value'],
            ];
        }
        usort($cells, fn($a, $b) => $a['offset'] <=> $b['offset']);
        return array_merge($page, ['type' => 'Table Interior', 'cells' => $cells]); // 
    }

    public static function parseTableLeafPage(array $page, array $header): array {
        $data = $page['data'];
        $usableSize = $header['pageSize'] - $header['reservedSpace'];
        $maxLocal = $usableSize - 35;
        $minLocal = (int) floor((($usableSize - 12) * 32) / 255 - 23);
        $cells = [];
        foreach ($page['cellPointerArray'] as $cell) {
            $cursor = $cell['value'];
            list($size, $sizeBytes) = self::parseVarint($data, $cursor);
            $cursor += $sizeBytes;
            list($rowid, $rowidBytes) = self::parseVarint($data, $cursor);
            $cursor += $rowidBytes;
            $localSize = $size;
            $overflow = false;
            if ($size > $maxLocal) {
                $localSize = $minLocal + (($size - $minLocal) % ($usableSize - 4));
                if ($localSize >= $maxLocal) {
                    $localSize = $minLocal;
                }
                $overflow = true;
            }
			// read the local fragment
			$payloadLocal = substr($data, $cursor, $localSize);
			$cursor      += $localSize;
			$overflowPageNumber = null;
			if ($overflow) {
			    // next 4 bytes is the first overflow page pointer
			    $overflowPageNumber = self::readUint32($data, $cursor);
			    $cursor += 4;
			    // now fetch and append all overflow fragments
			    $payload = $payloadLocal . self::readOverflowChain($overflowPageNumber);
			} else {
			    $payload = $payloadLocal;
			}
            $cells[] = [
                'rowid' => $rowid,
                'size' => $size,
                'payloadSizeLength' => $sizeBytes,
                'rowidLength' => $rowidBytes,
                'payload' => $payload,
                'overflowPageNumber' => $overflowPageNumber,
                'content' => substr($data, $cell['value'], $cursor - $cell['value']),
                'length' => $cursor - $cell['value'],
                'offset' => $cell['value'],
            ];
        }
        usort($cells, fn($a, $b) => $a['offset'] <=> $b['offset']);
        return array_merge($page, ['type' => 'Table Leaf', 'cells' => $cells]); // 
    }

    public static function parseIndexInteriorPage(array $page, array $header): array {
        $data = $page['data'];
        $usableSize = $header['pageSize'] - $header['reservedSpace'];
        $maxLocal = (int) floor((($usableSize - 12) * 64) / 255 - 23);
        $minLocal = (int) floor((($usableSize - 12) * 32) / 255 - 23);
        $cells = [];
        foreach ($page['cellPointerArray'] as $cell) {
            $cursor = $cell['value'];
            $leftChild = self::readUint32($data, $cursor);
            $cursor += 4;
            list($payloadSize, $payloadSizeBytes) = self::parseVarint($data, $cursor);
            $cursor += $payloadSizeBytes;
            $overflow = false;
            if ($payloadSize > $maxLocal) {
                $overflow = true;
                $localPayload = $minLocal + (($payloadSize - $minLocal) % ($usableSize - 4));
            } else {
                $localPayload = $payloadSize;
            }
            $payload = substr($data, $cursor, $localPayload);
            $cursor += $localPayload;
            $overflowPageNumber = null;
            if ($overflow) {
                $overflowPageNumber = self::readUint32($data, $cursor);
                $cursor += 4;
            }
            $cells[] = [
                'leftChildPagePointer' => $leftChild,
                'payloadSize' => $payloadSize,
                'payloadSizeBytes' => $payloadSizeBytes,
                'payload' => $payload,
                'overflowPageNumber' => $overflowPageNumber,
                'length' => $cursor - $cell['value'],
                'offset' => $cell['value'],
            ];
        }
        usort($cells, fn($a, $b) => $a['offset'] <=> $b['offset']);
        return array_merge($page, ['type' => 'Index Interior', 'cells' => $cells]); // 
    }

    public static function parseIndexLeafPage(array $page, array $header): array {
        $data = $page['data'];
        $usableSize = $header['pageSize'] - $header['reservedSpace'];
        $maxLocal = (int) floor((($usableSize - 12) * 64) / 255 - 23);
        $minLocal = (int) floor((($usableSize - 12) * 32) / 255 - 23);
        $cells = [];
        foreach ($page['cellPointerArray'] as $cell) {
            $cursor = $cell['value'];
            list($payloadSize, $payloadBytes) = self::parseVarint($data, $cursor);
            $cursor += $payloadBytes;
            $overflow = false;
            if ($payloadSize > $maxLocal) {
                $overflow = true;
                $localPayload = $minLocal + (($payloadSize - $minLocal) % ($usableSize - 4));
            } else {
                $localPayload = $payloadSize;
            }
            $payload = substr($data, $cursor, $localPayload);
            $cursor += $localPayload;
            $overflowPageNumber = null;
            if ($overflow) {
                $overflowPageNumber = self::readUint32($data, $cursor);
                $cursor += 4;
            }
            $cells[] = [
                'payloadSizeLength' => $payloadBytes,
                'payloadSize' => $payloadSize,
                'payload' => $payload,
                'overflowPageNumber' => $overflowPageNumber,
                'length' => $cursor - $cell['value'],
                'offset' => $cell['value'],
            ];
        }
        usort($cells, fn($a, $b) => $a['offset'] <=> $b['offset']);
        return array_merge($page, ['type' => 'Index Leaf', 'cells' => $cells]); // 
    }

    public static function walkThroughFreeList(array $pages, array $header): array {
        $result = $pages;
        $trunks = [];
        $current = $header['firstFreelistPage'];
        while ($current) {
            $trunkPage = self::parseFreeListTrunk($result[$current - 1]);
            $result[$current - 1] = $trunkPage;
            $trunks[] = $trunkPage;
            $current = $trunkPage['nextTrunkPage'];
        }
        foreach ($trunks as $trunk) {
            foreach ($trunk['freePageNumbers'] as $free) {
                $result[$free['pageNumber'] - 1]['type'] = 'Free Leaf';
            }
        }
        return $result; // 
    }

    private static function parseFreeListTrunk(array $page): array {
        $data = $page['data'];
        $nextTrunk = self::readUint32($data, 0);
        $count = self::readUint32($data, 4);
        $freePages = [];
        $cursor = 8;
        for ($i = 0; $i < $count; $i++) {
            $freePages[] = [
                'offset' => $cursor,
                'length' => 4,
                'pageNumber' => self::readUint32($data, $cursor),
            ];
            $cursor += 4;
        }
        return array_merge($page, [
            'type' => 'Free Trunk',
            'nextTrunkPage' => $nextTrunk,
            'count' => $count,
            'freePageNumbers' => $freePages,
        ]); // 
    }

    public static function walkThroughOverflowPage(array $pages): array {
        return array_map(fn($p) => $p['type'] === 'Unknown' ? self::parseOverflowPage($p) : $p, $pages); // 
    }

    private static function parseOverflowPage(array $page): array {
        $data = $page['data'];
        $next = self::readUint32($data, 0);
        $payload = [
            'offset' => 4,
            'length' => strlen($data) - 4,
            'content' => substr($data, 4),
        ];
        return array_merge($page, [
            'type' => 'Overflow',
            'nextPage' => $next,
            'payload' => $payload,
        ]); // 
    }

	/**
	 * Follow an overflow page chain and concatenate all payload fragments.
	 *
	 * @param int $startPage Page number of the first overflow page
	 * @return string Full concatenated payload
	 */
	private static function readOverflowChain(int $startPage): string {
	    $payload = '';
	    $current = $startPage;
	    while ($current) {
	        $page = self::$allPages[$current - 1] ?? null;
	        if (!$page) {
	            break;
	        }
	        $data = $page['data'];
	        $next = self::readUint32($data, 0);
	        // bytes 4..end are the payload fragment
	        $payload .= substr($data, 4);
	        $current = $next;
	    }
	    return $payload;
	}

    public static function parsePage(array $page, array $header): array {
        if ($btree = self::parseBTreePage($page)) {
            switch ($btree['type']) {
                case 'Table Interior':  return self::parseTableInteriorPage($btree);
                case 'Table Leaf':      return self::parseTableLeafPage($btree, $header);
                case 'Index Interior':  return self::parseIndexInteriorPage($btree, $header);
                case 'Index Leaf':      return self::parseIndexLeafPage($btree, $header);
            }
        }
        return $page; // Unknown 
    }

    public static function parseDatabase(string $buffer): array {
        $header = self::parseDatabaseHeader($buffer);
        $pages = self::splitPages($buffer, $header);
        $pages = self::walkThroughFreeList($pages, $header);
        // store raw pages so overflow chains can be read later
        self::$allPages = $pages;
        $pages = array_map(fn($p) => self::parsePage($p, $header), $pages);
        $pages = self::walkThroughOverflowPage($pages);
        return ['header' => $header, 'pages' => $pages]; // 
    }
}


// --- 1. build test database ---
$dbFile = __DIR__ . '/test.db';
@unlink($dbFile);

$db = new SQLite3($dbFile);
$db->exec("
  CREATE TABLE users (
    id   INTEGER PRIMARY KEY,
    name TEXT,
    age  INTEGER
  )
");
$db->exec("
  CREATE TABLE products (
    sku   TEXT PRIMARY KEY,
    title TEXT,
    price REAL
  )
");
$db->exec("
  INSERT INTO users (name, age) VALUES
    ('Alice', 30),
    ('Bob',   25),
    ('Carol', 28)
");
$db->exec("
  INSERT INTO products (sku, title, price) VALUES
    ('X123', 'Widget', 19.99),
    ('Y456', 'Gadget', 29.95)
");

// Insert records with long titles (approx 5KB)
$longTitle = str_repeat('A', 5 * 1024);

// Use prepared statements for efficiency and safety with large data
$stmt = $db->prepare("INSERT INTO products (sku, title, price) VALUES (:sku, :title, :price)");

// Record 1
$stmt->bindValue(':sku', 'LONG1', SQLITE3_TEXT);
$stmt->bindValue(':title', $longTitle, SQLITE3_TEXT);
$stmt->bindValue(':price', 99.99, SQLITE3_FLOAT);
$stmt->execute();

// Record 2
$stmt->bindValue(':sku', 'LONG2', SQLITE3_TEXT);
$stmt->bindValue(':title', $longTitle . 'B', SQLITE3_TEXT); // Slightly different title
$stmt->bindValue(':price', 199.99, SQLITE3_FLOAT);
$stmt->execute();

// Record 3
$stmt->bindValue(':sku', 'LONG3', SQLITE3_TEXT);
$stmt->bindValue(':title', 'C' . $longTitle, SQLITE3_TEXT); // Slightly different title
$stmt->bindValue(':price', 299.99, SQLITE3_FLOAT);
$stmt->execute();

$stmt->close(); // Close the prepared statement

$db->close();

// --- 2. read file, parse pages ---
$buf    = file_get_contents($dbFile);
$result = SqliteParser::parseDatabase($buf);
$pages  = $result['pages'];

// helper: walk a table B‑tree from a given root page
function traverseTable(array $pages, int $pageNum): array {
    $page = $pages[$pageNum - 1];
    if ($page['type'] === 'Table Interior') {
        $rows = [];
        foreach ($page['cells'] as $cell) {
            $rows = array_merge($rows, traverseTable($pages, $cell['pageNumber']));
        }
        $right = $page['header']['rightChildPageNumber'];
        return $right
            ? array_merge($rows, traverseTable($pages, $right))
            : $rows;
    }
    if ($page['type'] === 'Table Leaf') {
        return array_map(fn($c)=>$c['payload'], $page['cells']);
    }
    return [];
}

// helper: decode a record payload into PHP values
function decodeRecord(string $payload): array {
    list($hdrLen,) = SqliteParser::parseVarint($payload, 0);
    $pos = strlen(pack('C', 0)); // skip initial varint length byte(s)
    // actually parse header varints
    list($hdrLen2, $hlen) = SqliteParser::parseVarint($payload, 0);
    $pos = $hlen;
    $serials = [];
    while ($pos < $hdrLen2) {
        list($st,$l) = SqliteParser::parseVarint($payload, $pos);
        $serials[] = $st;
        $pos += $l;
    }
    $dataOff = $hdrLen2;
    $vals = [];
    foreach ($serials as $st) {
        if ($st === 0) {
            $vals[] = null;
        } elseif ($st === 1) {
            $vals[] = unpack('c', $payload[$dataOff])[1];
            $dataOff += 1;
        } elseif ($st === 2) {
            $vals[] = unpack('s>', substr($payload,$dataOff,2))[1];
            $dataOff += 2;
        } elseif ($st === 3) {
            $b = substr($payload,$dataOff,3);
            $int = (ord($b[0])<<16)|(ord($b[1])<<8)|ord($b[2]);
            if ($int & 0x800000) $int |= ~0xffffff;
            $vals[] = $int;
            $dataOff += 3;
        } elseif ($st === 4) {
            $vals[] = unpack('l>', substr($payload,$dataOff,4))[1];
            $dataOff += 4;
        } elseif ($st === 5) {
            $b = substr($payload,$dataOff,6);
            $hi = (ord($b[0])<<8)|ord($b[1]);
            $lo = (ord($b[2])<<24)|(ord($b[3])<<16)|(ord($b[4])<<8)|ord($b[5]);
            $int = ($hi<<32)|$lo;
            if ($int & (1<<47)) $int |= ~((1<<48)-1);
            $vals[] = $int;
            $dataOff += 6;
        } elseif ($st === 6) {
            $vals[] = unpack('q>', substr($payload,$dataOff,8))[1];
            $dataOff += 8;
        } elseif ($st === 7) {
            $vals[] = unpack('E', substr($payload,$dataOff,8))[1];
            $dataOff += 8;
        } elseif ($st === 8) {
            $vals[] = 0;
        } elseif ($st === 9) {
            $vals[] = 1;
        } elseif ($st >= 12) {
            $len = ($st - ($st % 2 ? 13 : 12)) / 2;
            $data = substr($payload, $dataOff, $len);
            $vals[] = ($st % 2) ? $data : $data; // text or blob raw
            $dataOff += $len;
        } else {
            $vals[] = null;
        }
    }
    return $vals;
}

// --- 3. find sqlite_master rows ---
$masterRows = traverseTable($pages, 1);
$tables = [];
foreach ($masterRows as $pl) {
    $cols = decodeRecord($pl);
    // sqlite_master columns: type, name, tbl_name, rootpage, sql
    if ($cols[0] === 'table') {
        $tables[] = [
            'name' => $cols[1],
            'root' => (int)$cols[3],
        ];
    }
}

// --- 4. output results ---
echo "Tables and their rows:\n\n";
foreach ($tables as $t) {
    echo "-- {$t['name']} --\n";
    $rows = traverseTable($pages, $t['root']);
    foreach ($rows as $pl) {
        $values = decodeRecord($pl);
        // print comma‑separated
        echo implode(', ', array_map(fn($v)=>var_export($v, true), $values)), "\n";
    }
    echo "\n";
}
