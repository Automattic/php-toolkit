<?php

namespace WordPress\Blueprints;

/**
 * Single validation issue enriched with context.
 */
final class Issue
{
    public const TYPE_ISSUE       = 'issue';
    public const TYPE_EXPLANATION = 'explanation';

    public function __construct(
        public array   $path,
        public string  $message,
        public string  $type   = self::TYPE_ISSUE,
        public ?string $branch = null,                 // e.g. VideoObject, DataReference …
        public array   $meta   = [],                   // expected/actual, snippet …
    ){}
}

/**
 * (In-)valid result plus aggregated issues.
 */
final class ValidationResult
{
    /** @param Issue[] $errors */
    public function __construct(
        public bool  $valid,
        public array $errors = [],
    ){}

    public static function ok(): self
    {
        return new self(true);
    }

    public static function err(
        array   $path,
        string  $message,
        ?string $branch = null,
        array   $meta   = [],
        string  $type   = Issue::TYPE_ISSUE,
    ): self {
        return new self(false, [new Issue($path, $message, $type, $branch, $meta)]);
    }

    public function merge(self $other): self
    {
        if ($this->valid && $other->valid) {
            return self::ok();
        }
        return new self(false, [...$this->errors, ...$other->errors]);
    }

    public static function combine(self ...$results): self
    {
        return array_reduce($results, fn ($c, $r) => $c->merge($r), self::ok());
    }

    /**
     * Append the first error of another result as an explanatory note.
     */
    public function addExplanation(ValidationResult $why): void
    {
        if ($why->valid) { return; }
        $first = $why->errors[0];
        $this->errors[] = new Issue(
            $first->path,
            $first->message,
            Issue::TYPE_EXPLANATION,
            $first->branch,
            $first->meta,
        );
    }
}

class Symbol {
	public function __construct(
		public string $value,
	) {}
	public function __toString(): string
	{
		return $this->value;
	}
}

const MISSING = new Symbol('missing');

/**
 * JSON-schema-lite validator with human‑centred errors and original narrowing logic.
 */
final class SchemaValidator
{
    private bool $arrayIsValidObject;

    public function __construct(
        private array $schema,
        array $options = [],
    ) {
        $this->arrayIsValidObject = $options['array_is_valid_object'] ?? true;
    }

    // ─────────────────────────────────────────────────────── helpers ─┐

    private function valueSnippet(mixed $v): string
    {
        return substr(json_encode($v, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES), 0, 80);
    }

    private function tagBranch(string $branch, ValidationResult $r): void
    {
        foreach ($r->errors as $e) { $e->branch ??= $branch; }
    }

    private function branchLabel(array $s): string
    {
        if (isset($s['$ref'])) {
            return substr($s['$ref'], strrpos($s['$ref'], '/') + 1);
        }
        return $s['title'] ?? ($s['type'] ?? '<schema>');
    }

    private function typeMatches(mixed $data, ?string $type): bool
    {
        return match ($type) {
            'object'  => is_object($data) || ($this->arrayIsValidObject && is_array($data)),
            'array'   => is_array($data),
            'string'  => is_string($data),
            'integer' => is_int($data),
            'number'  => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            null      => true,
            default   => false,
        };
    }

    private function typeMatchesAny(mixed $data, array $types): bool
    {
        foreach ($types as $t) {
            if (is_array($t)) { $t = $t['type'] ?? null; }
            if ($this->typeMatches($data, $t)) { return true; }
        }
        return false;
    }

    private function bestFailure(array $results): ValidationResult
    {
        usort($results, fn($a,$b) => count($a->errors) <=> count($b->errors));
        return $results[0];
    }

    // ───────────────────────────────────────────────────────── validation ─┐

    public function validate(mixed $data): ValidationResult
    {
        return $this->validateNode(['root'], $data, $this->schema);
    }

    private function validateNode(array $path, mixed $data, array $schema): ValidationResult
    {
        if (isset($schema['$ref'])) {
            $schema = $this->resolveReference($schema['$ref']);
        }

        return match (true) {
            isset($schema['anyOf']) => $this->validateAnyOf($path, $data, $schema),
            isset($schema['oneOf']) => $this->validateOneOf($path, $data, $schema),
            isset($schema['type'])  => $this->validateType($path, $data, $schema),
            default                 => ValidationResult::err($path, 'Validator doesn\'t understand this schema node.'),
        };
    }

    // ───────────────────────────────────────────── anyOf / oneOf ─┐

    private function narrowBranches(mixed $data, array $branches, array $schema): array
    {
        // 1. filter by declared top‑level type
        $candidates = array_filter($branches, function($b) use($data){
            $spec = isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b;
            return $this->typeMatches($data, $spec['type'] ?? null);
        });

        // 2. filter by discriminator (explicit or inferred)
        $disc = $this->inferDiscriminator($schema['discriminator'] ?? null, $branches);
        if ($disc && is_array($data)) {
            [$prop, $allowed] = $disc;
            if (array_key_exists($prop, $data)) {
                $wanted = $data[$prop];
                $candidates = array_values(array_filter($candidates, function($b) use($prop,$wanted){
                    $r = isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b;
                    return ($r['properties'][$prop]['enum'][0] ?? null) === $wanted;
                }));
            }
        }
        return $candidates ?: $branches; // never empty
    }

    private function validateAnyOf(array $path, mixed $data, array $schema): ValidationResult
    {
        $branches = $schema['anyOf'];
        $cands    = $this->narrowBranches($data, $branches, $schema);
        $narrowed = count($cands) < count($branches);
        $fails    = [];

        foreach ($cands as $b) {
            $label = $this->branchLabel($b);
            $r = $this->validateNode($path, $data, isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b);
            if ($r->valid) { return ValidationResult::ok(); }
            $this->tagBranch($label, $r);
            $fails[] = $r;
        }

        if ($narrowed && $fails) { // only one plausible branch → propagate its specific errors
            return ValidationResult::combine(...$fails);
        }

        return $this->explainAggregateMismatch($path, $data, $branches, $schema, 'anyOf', $fails);
    }

    private function validateOneOf(array $path, mixed $data, array $schema): ValidationResult
    {
        $branches = $schema['oneOf'];
        $cands    = $this->narrowBranches($data, $branches, $schema);
        $narrowed = count($cands) < count($branches);

        $valid = 0; $fails = [];
        foreach ($cands as $b) {
            $label=$this->branchLabel($b);
            $r=$this->validateNode($path,$data,isset($b['$ref'])?$this->resolveReference($b['$ref']):$b);
            if($r->valid){$valid++;}
            else{ $this->tagBranch($label,$r); $fails[]=$r; }
        }

        if ($valid === 1) { return ValidationResult::ok(); }
        if ($valid > 1)  { return ValidationResult::err($path, 'Data matches more than one allowed shape—make it unambiguous.'); }
        if ($narrowed && $fails) { return ValidationResult::combine(...$fails); }

        return $this->explainAggregateMismatch($path, $data, $branches, $schema, 'oneOf', $fails);
    }

    /**
     * Restore nuanced mismatch explanation:
     *   1. Type mismatch → list allowed types.
     *   2. Discriminator mismatch → point out expected vs actual.
     *   3. Otherwise generic list of branch labels with best‑failure note.
     */
    private function explainAggregateMismatch(
        array  $path,
        mixed  $data,
        array  $branches,
        array  $parentSchema,
        string $ctx,
        array  $fails
    ): ValidationResult {
        // 1. type check
        $allowedTypes = array_unique(array_map(function($b){
            $s = isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b;
            return $s['type'] ?? null;
        }, $branches));
        if (!$this->typeMatchesAny($data, $allowedTypes)) {
            return ValidationResult::err(
                $path,
                'Expected one of ['.implode(', ', $allowedTypes).'] here, but got '.gettype($data).'.',
                meta:[
                    'expected'=>['types'=>$allowedTypes],
                    'actual'  =>['type'=>gettype($data),'snippet'=>$this->valueSnippet($data)],
                ]
            );
        }

        // 2. discriminator check
        $disc = $this->inferDiscriminator($parentSchema['discriminator'] ?? null, $branches);
        if ($disc) {
            [$prop, $allowed] = $disc;
            $actual = is_array($data) && array_key_exists($prop, $data) ? $data[$prop] : MISSING;
            if (!in_array($actual, $allowed, true)) {
				$actual_humanized = $actual === MISSING ? 'missing' : json_encode($actual);
                return ValidationResult::err(
                    $path,
                    "The '$prop' property must be one of [".implode(', ', $allowed)."], but it was " . $actual_humanized . '.',
                    meta:[
                        'expected'=>['discriminator'=>$allowed,'property'=>$prop],
                        'actual'  =>['value'=>$actual,'snippet'=>$this->valueSnippet($actual)],
                    ]
                );
            }
        }

        // 3. fallback generic + attach best‑failure details
        $labels = array_unique(array_map([$this,'branchLabel'],$branches));
        $summary = ValidationResult::err(
            $path,
            ($ctx==='oneOf'?'Exactly one of ':'One of ').implode(', ', $labels).' is required, but none matched.',
        );
        if ($fails) { $summary->addExplanation($this->bestFailure($fails)); }
        return $summary;
    }

    // ─────────────────────────────────────────── primitives / objects / arrays ─┐

    private function validateType(array $path,mixed $data,array $schema):ValidationResult
    {
        $type = $schema['type'];
        if (!$this->typeMatches($data, $type)) {
            return ValidationResult::err(
                $path,
                'Expected '.$type.', got '.gettype($data).'.',
                meta:[
                    'expected'=>['type'=>$type],
                    'actual'  =>['type'=>gettype($data),'snippet'=>$this->valueSnippet($data)],
                ]
            );
        }

        if(isset($schema['enum']) && !in_array($data,$schema['enum'],true)){
            return ValidationResult::err(
                $path,
                'Allowed values: '.implode(', ',$schema['enum']).'. You supplied '.json_encode($data).'.',
                meta:[
                    'expected'=>['enum'=>$schema['enum']],
                    'actual'  =>['value'=>$data,'snippet'=>$this->valueSnippet($data)],
                ]
            );
        }

        return match($type){
            'object' => $this->validateObject($path,$data,$schema),
            'array'  => $this->validateArray($path,$data,$schema),
            default  => ValidationResult::ok(),
        };
    }

    // ───────────────────────────────────────────────────────────── object ─┐

    private function validateObject(array $path,array|object $data,array $schema):ValidationResult
    {
        $arr=is_object($data)?(array)$data:$data;
        $results=[];

        if(!empty($schema['required'])){
            $missing=array_diff($schema['required'],array_keys($arr));
            if($missing){
                $results[]=ValidationResult::err(
                    $path,
                    'Missing required field(s): '.implode(', ',$missing).'.',
                    meta:[
                        'expected'=>['required'=>$schema['required']],
                        'actual'=>['present'=>array_keys($arr)],
                    ]
                );
            }
        }

        if(!empty($schema['properties'])){
            foreach($schema['properties'] as $name=>$propSpec){
                if(array_key_exists($name,$arr)){
                    $results[]=$this->validateNode([...$path,$name],$arr[$name],$propSpec);
                }
            }
        }

        if(array_key_exists('additionalProperties',$schema)){
            foreach($arr as $name=>$v){
                if(isset($schema['properties'][$name])){continue;}
                if($schema['additionalProperties']===false){
                    $results[]=ValidationResult::err([...$path,$name],"Property '$name' isn't allowed here.");
                }else{
                    $results[]=$this->validateNode([...$path,$name],$v,$schema['additionalProperties']);
                }
            }
        }
        return ValidationResult::combine(...$results);
    }

    // ───────────────────────────────────────────────────────────── array ─┐

    private function validateArray(array $path,array $data,array $schema):ValidationResult
    {
        $results=[];
        if(isset($schema['items'])){
            foreach($data as $idx=>$item){
                $results[]=$this->validateNode([...$path,$idx],$item,$schema['items']);
            }
        }
        if(isset($schema['minItems']) && count($data)<$schema['minItems']){
            $results[]=ValidationResult::err($path,'Need at least '.$schema['minItems'].' items, found '.count($data).'.');
        }
        if(isset($schema['maxItems']) && count($data)>$schema['maxItems']){
            $results[]=ValidationResult::err($path,'May contain at most '.$schema['maxItems'].' items, found '.count($data).'.');
        }
        return ValidationResult::combine(...$results);
    }

    // ────────────────────────────────────────────────────────── references ─┐

    private function resolveReference(string $ref): array
    {
        if(!str_starts_with($ref,'#/')){
            throw new \RuntimeException('Only local #/ refs are supported');
        }
        $node=$this->schema;
        foreach(explode('/',substr($ref,2)) as $p){
            if(!array_key_exists($p,$node)){
                throw new \RuntimeException("Reference $ref not found");
            }
            $node=$node[$p];
        }
        return $node;
    }

    // ───────────────────────────────────────────── discriminator inference ─┐

    private function inferDiscriminator(?array $explicit,array $branches):?array
    {
        $objs=array_filter($branches,fn($b)=>($b['type']??null)==='object'&&isset($b['properties']));
        if(count($objs)!==count($branches)||count($objs)<2){return null;}

        if($explicit&&isset($explicit['propertyName'])){
            $prop=$explicit['propertyName'];
            $vals=[];
            foreach($objs as $s){
                $d=$s['properties'][$prop]??null;
                if($d&&isset($d['enum'])&&count($d['enum'])===1){$vals[]=$d['enum'][0];}
            }
            if(count($vals)===count($branches)&&count(array_unique($vals))===count($vals)){
                return [$prop,$vals];
            }
        }
        // auto‑guess single‑value enums
        $candidates=[];
        foreach($objs as $s){
            foreach($s['properties'] as $prop=>$def){
                if(isset($def['enum'])&&count($def['enum'])===1){$candidates[$prop][]=$def['enum'][0];}
            }
        }
        $candidates=array_filter($candidates,fn($v)=>count($v)===count($branches));
        if(count($candidates)===1){
            [$prop,$vals]=[key($candidates),current($candidates)];
            if(count(array_unique($vals))===count($vals)){return [$prop,$vals];}
        }
        return null;
    }
}

echo 'before validation' . PHP_EOL;

$schema = json_decode(file_get_contents(__DIR__ . '/json-schema/blueprint-v2-schema.json'), true);
$validator = new SchemaValidator($schema);
$result = $validator->validate([
	"version" => 2,
	'$schema' => "https://raw.githubusercontent.com/WordPress/blueprints/trunk/blueprints/schema.json",
	"plugins" => [
		"friends"
	],
	"themes" => [
		"adventurer"
	],
	"wordpressVersion" => "6.5",
	"phpVersion" => [
		"min" => "8.0",
		"max" => "8.4",
		"recommended" => "8.2"
	],
	"activeTheme" => "twentytwentyfour",
	"blueprintMeta" => [
		"name" => "Full Featured Blueprint",
		"description" => "A blueprint demonstrating most of the available features",
		"version" => "1.0.0",
		"authors" => ["Test Author", "Another Author"],
		"authorUrl" => "https://example.com",
		"donateLink" => "https://example.com/donate",
		"tags" => ["test", "full-features", "demo"],
		"license" => "GPL-2.0"
	],
	"postTypes" => [
		"book" => [
			"label" => "Books",
			"description" => "Books post type",
			"public" => true,
			"has_archive" => true,
			"show_in_rest" => true,
			"supports" => ["title", "editor", "author", "thumbnail", "excerpt", "comments"]
		]
	],
	// "muPlugins" => [
	// 	"0-test" => [
	// 		"filename" => "0-test.php",
	// 		"content" => "<?php
	// 			echo 'test';
	// 		? >"
	// 	]
	// ],
	"users" => [
		[
			"username" => "admin",
			"password" => "password",
			"email" => "adam@example.com",
			"role" => "adamadamin"
		]
	],
	"roles" => [
		[
			"name" => "adamadamin",
			// @TODO: What's the correct way to set capabilities?
			"capabilities" => ["manage_options"=>"manage_options"]
		]
	],
	"siteOptions" => [
		"blogname" => "Blueprint Demo Site",
		"timezone_string" => "America/New_York",
		"permalink_structure" => "/%year%/%monthnum%/%postname%/"
	],
	"siteLanguage" => "en_US",
	"constants" => [
		"WP_DEBUG" => true,
		"SCRIPT_DEBUG" => true
	],
	"media" => [
		"https://wordpress.org/files/2024/10/design-visual-6-7.png",
		[
			"source" => "2",
			"title" => "Introduction Video",
			"description" => "A brief introduction to our company",
			"alt" => "Company introduction video"
		],
	],
	"additionalStepsAfterExecution" => [
		[
			"step3" => "writeFiles",
			"files" => [
				"wp-content/uploads/custom-file.txt" => [
					"filename" => "custom-file.txt",
					"content" => "This is a custom file created by the Blueprint."
				],
				"0_readme.md" => "https://gist.githubusercontent.com/adamziel/a93297e21f37612751a2904c193d44fa/raw/5f25cdc900c0a44aefa0e1c06352c09c67312f1e/0_README.md",
				"playground" => [
					"gitRepository" => "https://github.com/adamziel/mysql-sqlite-network-proxy.git",
					"path" => "php-implementation",
					// @TODO: Accept branch names without the refs/heads/ prefix
					// @TODO: Accept commit hashes and tag names
					"ref" => "refs/heads/trunk"
				]
			]
		]
	]
]);

if($result->valid) {
	echo "The data is valid according to the schema.\n";
} else {
	echo "The data is invalid according to the schema.\n";
	foreach($result->errors as $error) {
		print_r($error);
	}
}
