<?php
/**
 * Custom SQLite parser implemented in PHP.
 * Ports the TypeScript parser from https://github.com/invisal/sqlite-internal.
 *
 * ───────────────────────────────────────────────────────────────────
 *   ✓  No more static calls · just instantiate with a DB file path
 * ───────────────────────────────────────────────────────────────────
 *   • iterateTables()         → list of table meta‑info
 *   • getTableColumns($name) → column names for that table
 *   • iterateRecords($name)  → decoded rows
 * ───────────────────────────────────────────────────────────────────
 * 
 * Next steps:
 * * Adjust the API to be next_table(): bool, get_table(): string, get_columns(), next_record(): bool, get_record(): array
 *   essentially, to truly support streaming.
 * * Add comments to each method and complex fragment that correlates it with the
 *   relevant setion of the SQLite binary format spec.
 * * Examine error hangling
 * * Add a solud suite of tests that checks for all kinds of corner cases,
 *   e.g. records with nulls, records with overflow pages, indexes, etc.
 */

class SQLiteParser
{
	/* ─────────── ctor & state ─────────── */

	private array  $db        = [];
	private array  $pageCache = [];
	private $fileHandle;
	private int    $pageSize  = 0;
	private int    $pageCount = 0;
	private int    $reservedSpace = 0;

	public function __construct(private string $filePath)
	{
		$this->fileHandle = fopen($filePath, 'rb');
		if (!$this->fileHandle) {
			throw new Exception("Could not open SQLite database file: $filePath");
		}
		
		$header = $this->readHeader();
		$this->pageSize = $header['pageSize'];
		$this->pageCount = $header['pageCount'];
		$this->reservedSpace = $header['reservedSpace'];
		
		$this->db = [
			'header' => $header,
			'pages' => [] // Will be populated on demand
		];
		
		// Mark free list pages
		$this->markFreeListPages($header);
	}
	
	public function __destruct()
	{
		if ($this->fileHandle) {
			fclose($this->fileHandle);
		}
	}

	/* ─────────── public high‑level API ─────────── */

	public function iterateTables(): array
	{
		$rows   = $this->traverseTable(1);       // sqlite_master
		$tables = [];
		foreach ($rows as $pl) {
			$c = $this->decodeRecord($pl);                           // type, name, tbl_name, rootpage, sql
			if ($c[0] === 'table') {
				$tables[] = ['name' => $c[1], 'root' => (int)$c[3], 'sql' => $c[4]];
			}
		}
		return $tables;
	}

	public function getTableColumns(string $name): array
	{
		foreach ($this->iterateTables() as $t) {
			if (strcasecmp($t['name'], $name) === 0 && $t['sql']) {
				if (preg_match('/CREATE\\s+TABLE\\s+.*?\\((.*)\\)/is', $t['sql'], $m)) {
					$defs = preg_split('/,(?![^\\(]*\\))/', $m[1]);
					return array_values(array_filter(array_map(
						fn($d) => preg_match('/^[`"\\[]?([A-Za-z_][A-Za-z0-9_]*)[`"\\]]?\\s+/u', trim($d), $m2) ? $m2[1] : null,
						$defs
					)));
				}
			}
		}
		return [];
	}

	public function iterateRecords(string $name): array
	{
		foreach ($this->iterateTables() as $t) {
			if (strcasecmp($t['name'], $name) === 0) {
				$r = $this->traverseTable($t['root']);
				return array_map(fn($p) => $this->decodeRecord($p), $r);
			}
		}
		return [];
	}

	/* ─────────── file reading helpers ─────────── */
	
	private function readBytes(int $offset, int $length): string
	{
		fseek($this->fileHandle, $offset);
		return fread($this->fileHandle, $length);
	}
	
	private function readPage(int $pageNumber): array
	{
		// Page numbers are 1-indexed
		if (isset($this->pageCache[$pageNumber])) {
			return $this->pageCache[$pageNumber];
		}
		
		$offset = ($pageNumber - 1) * $this->pageSize;
		$data = $this->readBytes($offset, $this->pageSize);
		
		$page = [
			'number' => $pageNumber,
			'data' => $data,
			'type' => 'Unknown'
		];
		
		// Store raw page in cache
		$this->pageCache[$pageNumber] = $page;
		
		// Parse the page
		$parsedPage = $this->parsePage($page);
		$this->pageCache[$pageNumber] = $parsedPage;
		
		return $parsedPage;
	}
	
	private function readHeader(): array
	{
		$headerData = $this->readBytes(0, 100);
		return [
			'pageSize' => $this->u16($headerData, 16),
			'reservedSpace' => $this->u8($headerData, 20),
			'pageCount' => $this->u32($headerData, 28),
			'firstFreelistPage' => $this->u32($headerData, 32),
			'totalFreelistPages' => $this->u32($headerData, 36),
		];
	}
	
	private function markFreeListPages(array $header): void
	{
		$cur = $header['firstFreelistPage'];
		while ($cur) {
			$page = $this->readPage($cur);
			$this->pageCache[$cur]['type'] = 'Free Trunk';
			$cur = $this->u32($page['data'], 0);
		}
	}

	/* ─────────── tiny binary helpers ─────────── */

	private function u8(string $d, int $o): int         { return ord($d[$o]); }
	private function u16(string $d, int $o): int        { return unpack('n', substr($d, $o, 2))[1]; }
	private function u32(string $d, int $o): int        { return unpack('N', substr($d, $o, 4))[1]; }

	private function varint(string $d, int $o): array
	{
		$v = 0; $l = 0;
		do { $b = ord($d[$o+$l]); $v = ($v<<7)+($b&0x7f); $l++; } while ($b & 0x80);
		return [$v,$l];
	}

	/* ─────────── page parsing (B‑tree, freelist, overflow) — unchanged logic, $this‑ified ─────────── */

	private function parsePage(array $p): array
	{
		if ($bt = $this->btree($p)) {
			return match($bt['type']) {
				'Table Interior' => $this->tabInt($bt),
				'Table Leaf'     => $this->tabLeaf($bt),
				'Index Interior' => $this->idxInt($bt),
				'Index Leaf'     => $this->idxLeaf($bt),
				default          => $bt,
			};
		}
		return $p;                                   // unknown stays raw
	}

	private function btree(array $p):?array
	{
		$d=$p['data']; $n=$p['number']; $c=($n===1?100:0); $t=$this->u8($d,$c);
		$m=[0x0d=>'Table Leaf',0x05=>'Table Interior',0x0a=>'Index Leaf',0x02=>'Index Interior'];
		if(!isset($m[$t])) return null;
		$tp=$m[$t]; $cnt=$this->u16($d,$c+3); $ptrOfs=$this->u16($d,$c+5); $hdr=8;
		$right=null; if(str_contains($tp,'Interior')){ $right=$this->u32($d,$c+8); $hdr=12; }
		$c+=$hdr; $ptr=[]; for($i=0;$i<$cnt;$i++){ $ptr[]=['offset'=>$c,'value'=>$this->u16($d,$c)]; $c+=2; }
		return['number'=>$n,'data'=>$d,'type'=>$tp,'cellPointerArray'=>$ptr,
			'header'=>['rightChildPageNumber'=>$right,'cellCount'=>$cnt,'cellPointerArrayOffset'=>$ptrOfs]];
	}

	private function tabInt(array $p):array
	{
		$d=$p['data']; $cells=[];
		foreach($p['cellPointerArray'] as $c){ $o=$c['value']; $pg=$this->u32($d,$o); [$id,$l]=$this->varint($d,$o+4);
			$cells[]=['pageNumber'=>$pg,'rowid'=>$id,'offset'=>$o,'rowidLength'=>$l]; }
		usort($cells,fn($a,$b)=>$a['offset']<=>$b['offset']); $p['cells']=$cells; return $p;
	}

	private function tabLeaf(array $p):array
	{
		$d=$p['data']; 
		$us=$this->pageSize-$this->reservedSpace; 
		$max=$us-35; 
		$min=(int)floor((($us-12)*32)/255-23); 
		$cells=[];
		
		foreach($p['cellPointerArray'] as $c){
			$o=$c['value']; [$sz,$sb]=$this->varint($d,$o); $o+=$sb; [$rid,$rb]=$this->varint($d,$o); $o+=$rb;
			$ls=$sz; $of=false; if($sz>$max){$ls=$min+(($sz-$min)%($us-4)); if($ls>=$max)$ls=$min; $of=true;}
			$local=substr($d,$o,$ls); $o+=$ls; $ovpg=null; if($of){$ovpg=$this->u32($d,$o);$o+=4;$payload=$local.$this->overflow($ovpg);} else {$payload=$local;}
			$cells[]=['rowid'=>$rid,'payload'=>$payload,'offset'=>$c['value']];
		}
		usort($cells,fn($a,$b)=>$a['offset']<=>$b['offset']); $p['cells']=$cells; return $p;
	}

	private function idxInt(array $p):array { return $p; }
	private function idxLeaf(array $p):array { return $p; }

	private function ovPage(array $p):array
	{
		$d=$p['data']; return array_merge($p,['type'=>'Overflow','nextPage'=>$this->u32($d,0),'payload'=>substr($d,4)]);
	}

	private function overflow(int $pg):string
	{
		$pay=''; $cur=$pg; 
		while($cur) { 
			$page = $this->readPage($cur);
			$d = $page['data'];
			$pay .= substr($d,4); 
			$cur = $this->u32($d,0);
		} 
		return $pay;
	}

	/* ─────────── row helpers ─────────── */

	private function traverseTable(int $pg):array
	{
		$p = $this->readPage($pg);
		
		if ($p['type'] === 'Table Interior') { 
			$rows = []; 
			foreach ($p['cells'] as $c) {
				$rows = array_merge($rows, $this->traverseTable($c['pageNumber']));
			}
			$right = $p['header']['rightChildPageNumber']; 
			return $right ? array_merge($rows, $this->traverseTable($right)) : $rows; 
		}
		
		if ($p['type'] === 'Table Leaf') { 
			return array_map(fn($c) => $c['payload'], $p['cells']); 
		}
		
		return [];
	}

	private function decodeRecord(string $pl):array
	{
		[$hdr,$l]=$this->varint($pl,0); $pos=$l; $serial=[]; while($pos<$hdr){[$st,$n]=$this->varint($pl,$pos);$serial[]=$st;$pos+=$n;}
		$off=$hdr; $v=[]; foreach($serial as $s){ switch($s){
			case 0:$v[]=null;break; case 1:$v[]=unpack('c',$pl[$off])[1];$off+=1;break;
			case 2:$v[]=unpack('s>',substr($pl,$off,2))[1];$off+=2;break;
			case 3:$b=substr($pl,$off,3);$i=(ord($b[0])<<16)|(ord($b[1])<<8)|ord($b[2]);if($i&0x800000)$i|=~0xffffff;$v[]=$i;$off+=3;break;
			case 4:$v[]=unpack('l>',substr($pl,$off,4))[1];$off+=4;break;
			case 5:$b=substr($pl,$off,6);$hi=(ord($b[0])<<8)|ord($b[1]);$lo=(ord($b[2])<<24)|(ord($b[3])<<16)|(ord($b[4])<<8)|ord($b[5]);$i=($hi<<32)|$lo;if($i&(1<<47))$i|=~((1<<48)-1);$v[]=$i;$off+=6;break;
			case 6:$v[]=unpack('q>',substr($pl,$off,8))[1];$off+=8;break;
			case 7:$v[]=unpack('E',substr($pl,$off,8))[1];$off+=8;break;
			case 8:$v[]=0;break; case 9:$v[]=1;break;
			default:$len=($s-($s%2?13:12))/2; $v[]=substr($pl,$off,$len); $off+=$len; }
		} return $v;
	}
}

/* ─────────── quick self‑test ─────────── */

$dbFile = __DIR__.'/test.db'; @unlink($dbFile);
$db=new SQLite3($dbFile);
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)");
$db->exec("CREATE TABLE products (sku TEXT PRIMARY KEY, title TEXT, price REAL)");
$db->exec("INSERT INTO users (name,age) VALUES ('Alice',30),('Bob',25),('Carol',28)");
$db->exec("INSERT INTO products (sku,title,price) VALUES ('X123','Widget',19.99),('Y456','Gadget',29.95)");
$long=str_repeat('A',5120);$db->exec("INSERT INTO products (sku,title,price) VALUES ('LONG1','$long',99.99)");
$db->close();

$parser = new SQLiteParser($dbFile);

echo "Tables and rows\n\n";
foreach($parser->iterateTables() as $t){
	echo "-- {$t['name']} --\n";
	echo "Columns: ".implode(', ',$parser->getTableColumns($t['name']))."\n";
	foreach($parser->iterateRecords($t['name']) as $r){
		echo implode(', ',array_map(fn($v)=>var_export($v,true),$r))."\n";}
	echo "\n";
}