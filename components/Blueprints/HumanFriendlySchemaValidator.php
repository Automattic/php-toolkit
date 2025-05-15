<?php

namespace WordPress\Blueprints;

use WordPress\Blueprints\Validator\UnsupportedSchemaException;

/**
 * Represents a single validation error.
 */
class ValidationError {
    /**
     * @param string $pointer JSON Pointer like /steps/0/data/url
     * @param string $code short, stable key: required, type-mismatch …
     * @param string $message human sentence
     * @param array $context expected/actual/allowed, always associative
     * @param ValidationError[] $children nested causes
     */
    public function __construct(
        public string  $pointer,
        public string  $code,
        public string  $message,
        public array   $context = [],
        public array   $children = []
    ) {}

    /**
     * Gets the most probable cause of this validation error.
     * If this error has no children, it is the most probable cause.
     * Otherwise, it recursively calls getMostProbableCause on its children
     * and returns the one with the fewest descendants (naïve: first child if counts are equal).
     */
    public function getMostProbableCause(): ValidationError {
        if (empty($this->children)) {
            return $this;
        }

        $minChild = null;
        $minDescendantsCount = PHP_INT_MAX;

        // Find the child with the minimum number of its own *direct* children.
        // To implement "fewest descendants" fully, we'd need a recursive count here.
        // The current prompt says "choose the one with fewest descendants" but then suggests "count($a->children)".
        // Sticking to the simpler direct children count based on the example.
        foreach ($this->children as $child) {
            $currentChildDescendantsCount = count($child->children);
            if ($currentChildDescendantsCount < $minDescendantsCount) {
                $minDescendantsCount = $currentChildDescendantsCount;
                $minChild = $child;
            }
        }
        
        // If $minChild is still null (e.g. if children array was empty, though caught by initial check),
        // or if for some reason no child was selected, we might return $this or throw an error.
        // However, given the initial empty check, $minChild should be set if $this->children is not empty.
        // If all children have the same number of descendants, the first one encountered will be chosen.
        return $minChild->getMostProbableCause(); 
    }
}

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
 * A lite JSON schema validator with human-centric error messages.
 * 
 * ## Why a custom validator?
 * 
 * Existing JSON validation libraries don't produce user-friendly error messages.
 * 
 * Here's a few examples of what most libraries would report for
 * popular invalid Blueprint scenarios.
 * 
 * In this Blueprint, the "resource" property should have a "url" key:
 * 
 *     {"steps":[{"step":"writeFile","path":"/tmp/media/WordPress-logotype-wmark.png","data":{"resource":"url","path":"https://s.w.org/style/images/about/WordPress-logotype-wmark.png"}}]}'
 * 
 * However, a typical error is more like:
 * 
 *     must be equal to constant at /steps/0/data/resource
 * 
 * An invalid "step" value:
 * 
 *     {"steps":[ {"step":"noSuchStep"} ]}
 * 
 * Is typically rejected with:
 * 
 *     value of tag "step" must be in oneOf at /steps/0
 * 
 * It's not terrible, but it isn't great either. It doesn't tell us what the allowed values are.
 * 
 * It gets worse for schemas without a clear discriminator (such as Blueprint v2).
 * Imagine the following schema:
 * 
 *     {
 *       "type": "object",
 *       "properties": {
 *         "media": {
 *           "anyOf": [
 *             {"type": "string"},
 *             {
 *               "type":"object",
 *               "required":["filename", "content"]
 *             }
 *           ]
 *         }
 *       }
 *     }
 * 
 * The following Blueprint is invalid – it's missing the "content" property:
 * 
 *     {"media": { "filename": "post.html" } }
 * 
 * However, a typical error message is:
 * 
 *     #/properties/media/anyOf: JSON does not match any schemas from 'anyOf'.
 *     #/properties/media/anyOf/1/required Required properties are missing from object: dirname, files.
 *     #/properties/media/anyOf/0/required Required properties are missing from object: content.
 * 
 * It's awful! Technically, everything is true in it. But it's related to
 * JSON schema concepts. You need to open the schema to understand the error.
 * 
 * How much better would it be to have a message similar to this instead?
 * 
 *     The required "media.content" property is missing.
 * 
 * It points you to the exact location and tell you what the problem is. Most
 * libraries just return all the failures on the way and don't bother with
 * making the output useful.
 * 
 * Here's a few other reasons for having a custom validator:
 * 
 * * Compatibility – it supports PHP 7.2 and no dependencies.
 * * Small footprint – it only implements what we need to validate Blueprints.
 * * Leniency – it can accept PHP arrays as objects. Steps accept data as arrays,
 *   and this little feature saves us from recursively converting
 *   between objects and arrays.
 */
final class HumanFriendlySchemaValidator
{
    private bool $arrayIsValidObject;

    public function __construct(
        private array $schema,
        array $options = [],
    ) {
        $this->arrayIsValidObject = $options['array_is_valid_object'] ?? true;
    }

    private function convertPathToString(array $path): string
    {
        if (empty($path) || $path[0] !== 'root') {
            array_unshift($path, '#'); // JSON pointers start with # or are relative
        } else {
            $path[0] = '#'; // Replace 'root' with '#'
        }
        return implode('/', $path);
    }

    // ─────────────────────────────────────────────────────── helpers ─┐

    private function valueSnippet(mixed $v): string
    {
        return substr(json_encode($v, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES), 0, 80);
    }

    // private function tagBranch(string $branch, ValidationResult $r): void
    // {
    //     foreach ($r->errors as $e) { $e->branch ??= $branch; }
    // }

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

    // ───────────────────────────────────────────────────────── validation ─┐

    public function validate(mixed $data): bool|ValidationError
    {
        $violations = $this->validateNode(['root'], $data, $this->schema);
		if($violations === null){
			return true;
		}
		return $violations;
    }

    private function validateNode(array $path, mixed $data, array $schema): ?ValidationError
    {
        if (isset($schema['$ref'])) {
            $schema = $this->resolveReference($schema['$ref']);
        }

        // Check for unsupported keywords
        $unsupportedKeywords = [
            'allOf', 'not', 'patternProperties', 'dependencies',
            'if', 'then', 'else', 'contentMediaType', 'contentEncoding',
            'contentSchema',
        ];
        foreach ($unsupportedKeywords as $keyword) {
            if (isset($schema[$keyword])) {
                // This should remain an exception as it's a schema configuration issue, not a data validation issue.
                throw new UnsupportedSchemaException("The schema keyword \"{$keyword}\" is not supported.");
            }
        }

        // Check if 'type' is an array, which is not supported
        if (isset($schema['type']) && is_array($schema['type'])) {
            // This should remain an exception.
            throw new UnsupportedSchemaException("Defining 'type' as an array of types is not supported. Use anyOf or oneOf instead.");
        }

        $error = match (true) {
            isset($schema['anyOf']) => $this->validateAnyOf($path, $data, $schema),
            isset($schema['oneOf']) => $this->validateOneOf($path, $data, $schema),
            isset($schema['type'])  => $this->validateType($path, $data, $schema),
            default                 => null, // Will be caught by the check below
        };

		if ($error === null && !isset($schema['anyOf']) && !isset($schema['oneOf']) && !isset($schema['type']) && !isset($schema['$ref'])) {
            // If $error is null BUT it's because no validation rule was matched (e.g. schema missing type/anyOf/oneOf/ref)
            // This indicates a malformed schema that this validator cannot process beyond this point.
            // For $ref, it's resolved at the beginning, so if it was just a $ref, it's now the resolved schema.
			throw new UnsupportedSchemaException(
				'Every schema rule must have one of "anyOf", "oneOf", "type" or be a "$ref". Rule for path ' . json_encode($path) . ' did not. Schema snippet: ' . substr(json_encode($schema), 0, 100)
			);
		}

		return $error;
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
        if ($disc && (is_array($data) || is_object($data))) { // Discriminator implies object/array data
            $dataArr = (array) $data; // Cast to array for consistent access
            [$prop, $allowed] = $disc;
            if (array_key_exists($prop, $dataArr)) {
                $wanted = $dataArr[$prop];
                $candidates = array_values(array_filter($candidates, function($b) use($prop,$wanted){
                    $r = isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b;
                    // Ensure properties exist before accessing
                    return isset($r['properties'][$prop]['enum'][0]) && ($r['properties'][$prop]['enum'][0] === $wanted);
                }));
            }
        }
        return $candidates ?: $branches; // never empty
    }

    private function validateAnyOf(array $path, mixed $data, array $schema): ?ValidationError
    {
        $branches = $schema['anyOf'];
        $cands    = $this->narrowBranches($data, $branches, $schema);
        // $narrowed = count($cands) < count($branches); // This logic changes
        $childErrors = [];

        foreach ($cands as $b) {
            // $label = $this->branchLabel($b); // branchLabel might be used in error message context
            $error = $this->validateNode($path, $data, isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b);
            if ($error === null) { return null; } // Success, one branch matched
            // $this->tagBranch($label, $r); // tagBranch is removed
            $childErrors[] = $error;
        }

        // If we are here, no candidate branch validated successfully.
        // The old logic for $narrowed seems less relevant. We always create a parent error with children.
        // The explanation for aggregate mismatch needs to be adapted.
        return $this->explainAggregateMismatch($path, $data, $branches, $schema, 'anyOf', $childErrors);
    }

    private function validateOneOf(array $path, mixed $data, array $schema): ?ValidationError
    {
        $branches = $schema['oneOf'];
        $cands    = $this->narrowBranches($data, $branches, $schema);
        // $narrowed = count($cands) < count($branches);

        $validResults = [];
        $childErrors  = [];
        foreach ($cands as $b) {
            // $label=$this->branchLabel($b);
            $error = $this->validateNode($path, $data, isset($b['$ref'])?$this->resolveReference($b['$ref']):$b);
            if($error === null){
                $validResults[] = $b; // Store the schema of the valid branch
            }
            else{
                // $this->tagBranch($label,$r); // tagBranch removed
                $childErrors[] = $error;
            }
        }

        if (count($validResults) === 1) { return null; } // Exactly one schema matched
        
        if (count($validResults) > 1) {
            $matchedShapes = array_map(function($b) {
                if (isset($b['$ref'])) {
                    $resolved = $this->resolveReference($b['$ref']);
                    return $resolved['title'] ?? $b['$ref'];
                }
                return $this->branchLabel($b);
            }, $validResults);
            return new ValidationError(
                $this->convertPathToString($path),
                'oneOf-multiple-matches',
                'Data matches more than one allowed shape - you need to make it unambiguous. Matched shapes: ' . implode(', ', $matchedShapes) . '.',
                ['matchedShapes' => $matchedShapes]
            );
        }

        // No schema matched, or narrowing didn't help / wasn't conclusive
        // The old logic for $narrowed seems less relevant. We always create a parent error with children.
        return $this->explainAggregateMismatch($path, $data, $branches, $schema, 'oneOf', $childErrors);
    }

    /**
     * Create a parent error for anyOf/oneOf mismatches.
     */
    private function explainAggregateMismatch(
        array  $path,
        mixed  $data,
        array  $branches, // Original branches before narrowing
        array  $parentSchema, // The schema containing anyOf/oneOf
        string $keyword, // 'anyOf' or 'oneOf'
        array  $childErrors // Errors from validating against candidate branches
    ): ValidationError {
        $pointer = $this->convertPathToString($path);
        
        // 1. Type mismatch (if data type doesn't match any of the branch types)
        $allowedTypes = array_unique(array_values(array_filter(array_map(function($b){
            $s = isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b;
            return $s['type'] ?? null;
        }, $branches), fn($type) => $type !== null)));
        
        if (!empty($allowedTypes) && !$this->typeMatchesAny($data, $allowedTypes)) {
            return new ValidationError(
                $pointer,
                'type-mismatch',
                sprintf(
                    "Value must be one of the following types: [%s], but it was of type '%s'.",
                    implode(', ', $allowedTypes),
                    gettype($data)
                ),
                [
                    'expected' => ['types' => $allowedTypes],
                    'actual'   => ['type' => gettype($data), 'snippet' => $this->valueSnippet($data)],
                ]
            );
        }

        // 2. Discriminator check (if applicable and discriminator value is invalid)
        $disc = $this->inferDiscriminator($parentSchema['discriminator'] ?? null, $branches);
        if ($disc) {
            [$prop, $allowedDiscriminatorValues] = $disc;
            $actualValue = MISSING; // Default to missing
            if (is_array($data) && array_key_exists($prop, $data)) {
                $actualValue = $data[$prop];
            } else if (is_object($data) && property_exists($data, $prop)) {
                $actualValue = $data->$prop;
            }

            if (!in_array($actualValue, $allowedDiscriminatorValues, true)) {
				$actual_humanized = ($actualValue === MISSING) ? 'missing' : $this->valueSnippet($actualValue);
                return new ValidationError(
                    $pointer,
                    'discriminator-mismatch',
                    sprintf(
                        "Property '%s' must be one of [%s], but it was %s.",
                        $prop,
                        implode(', ', $allowedDiscriminatorValues),
                        $actual_humanized
                    ),
                    [
                        'expected' => ['property' => $prop, 'allowedValues' => $allowedDiscriminatorValues],
                        'actual'   => ['value' => ($actualValue === MISSING) ? null : $actualValue, 'snippet' => $this->valueSnippet($actualValue)],
                    ]
                );
            }
        }

        // 3. Fallback: Generic message with children errors
        $labels = array_unique(array_map([$this,'branchLabel'], $branches));
		$message = 'Value did not match any of the allowed shapes: '.implode(', ', $labels).'.';
        if ($keyword === 'oneOf') {
            $message = 'Value did not match exactly one of the allowed shapes: '.implode(', ', $labels).'.';
        }

        return new ValidationError(
            $pointer,
            $keyword . '-mismatch', // e.g., 'anyOf-mismatch'
            $message,
            ['allowedShapes' => $labels],
            $childErrors // Attach all child errors here
        );
    }

    // ─────────────────────────────────────────── primitives / objects / arrays ─┐

    private function validateType(array $path,mixed $data,array $schema):?ValidationError
    {
        $type = $schema['type'];
        if (!$this->typeMatches($data, $type)) {
            return new ValidationError(
                $this->convertPathToString($path),
                'type-mismatch',
                sprintf('Expected type "%s" here, but got %s instead.', $type, gettype($data)),
                [
                    'expected'=>['type'=>$type],
                    'actual'  =>['type'=>gettype($data),'snippet'=>$this->valueSnippet($data)],
                ]
            );
        }

        // Schema integrity checks (throw exceptions as these are schema definition issues)
        if ($type === 'string') {
            $unsupportedStringKeywords = ['pattern', 'minLength', 'maxLength', 'format'];
            foreach ($unsupportedStringKeywords as $keyword) {
                if (isset($schema[$keyword])) {
                    throw new UnsupportedSchemaException("The string constraint \"{$keyword}\" is not supported.");
                }
            }
        }
        if ($type === 'number' || $type === 'integer') {
            $unsupportedNumericKeywords = ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'];
            foreach ($unsupportedNumericKeywords as $keyword) {
                if (isset($schema[$keyword])) {
                    throw new UnsupportedSchemaException("The numeric constraint \"{$keyword}\" is not supported.");
                }
            }
        }
        if (isset($schema['enum'])) {
            foreach ($schema['enum'] as $enumValue) {
                if (!$this->typeMatches($enumValue, $type)) {
                    throw new UnsupportedSchemaException(
                        "Enum value " . json_encode($enumValue) . " does not match the declared type \"{$type}\"."
                    );
                }
            }
        }

        if(isset($schema['enum'])){
            if(!in_array($data,$schema['enum'],true)){
                return new ValidationError(
                    $this->convertPathToString($path),
                    'enum-mismatch',
                    sprintf(
                        'The provided value (%s) is not allowed here. Please use one of the following: %s.',
                        $this->valueSnippet($data),
                        implode(', ', $schema['enum'])
                    ),
                    [
                        'expected'=>['enum'=>$schema['enum']],
                        'actual'  =>['value'=>$data,'snippet'=>$this->valueSnippet($data)],
                    ]
                );
            }
        }

        return match($type){
            'object' => $this->validateObject($path,$data,$schema),
            'array'  => $this->validateArray($path,$data,$schema),
            default  => null
        };
    }

    // ───────────────────────────────────────────────────────────── object ─┐

    private function validateObject(array $path,array|object $data,array $schema):?ValidationError
    {
        $arr = is_object($data) ? (array)$data : $data;
        $childrenErrors = [];

        if(!empty($schema['required'])){
            $missing=array_diff($schema['required'],array_keys($arr));
            if($missing){
				foreach($missing as $m){
                    // For missing fields, the error pointer should be to the parent object, 
                    // as the field itself doesn't exist yet to point to.
					$childrenErrors[] = new ValidationError(
                        $this->convertPathToString($path), // Error is about the object at $path
                        'required-field-missing',
                        'Missing required field: '.$m.'.',
                        ['missingField' => $m, 'requiredFields' => $schema['required']]
					);
				}
            }
        }

        if(!empty($schema['properties'])){
            foreach($schema['properties'] as $name=>$propSpec){
                if(array_key_exists($name,$arr)){
                    $error = $this->validateNode([...$path,$name],$arr[$name],$propSpec);
                    if ($error) $childrenErrors[] = $error;
                }
            }
        }

        if(array_key_exists('additionalProperties',$schema) && $schema['additionalProperties'] !== true){
            foreach($arr as $name=>$v){
                if(isset($schema['properties'][$name])){continue;} // Handled by 'properties' validation
                
                $currentPropPath = [...$path, $name];
                if($schema['additionalProperties']===false){
                    $childrenErrors[]= new ValidationError(
                        $this->convertPathToString($currentPropPath),
                        'additional-property-not-allowed',
                        "Property '{$name}' isn't allowed here.",
                        ['propertyName' => $name]
                    );
				} else if(is_array($schema['additionalProperties'])) {
                    $error = $this->validateNode($currentPropPath,$v,$schema['additionalProperties']);
                    if ($error) $childrenErrors[] = $error;
                } else {
					// This is a schema definition issue, not a data validation issue for this specific property.
					throw new UnsupportedSchemaException('Invalid additionalProperties schema. Expected boolean or object for schema at path: ' . $this->convertPathToString($path));
				}
            }
        }

        
        if (!empty($childrenErrors)) {
			if(count($childrenErrors) === 1){
				return $childrenErrors[0];
			}
            return new ValidationError(
                $this->convertPathToString($path),
                'object-validation-failed',
                'Object validation failed.',
                [],
                $childrenErrors
            );
        }
        return null;
    }

    // ───────────────────────────────────────────────────────────── array ─┐

    private function validateArray(array $path,array $data,array $schema):?ValidationError
    {
        $childrenErrors=[];
        if(isset($schema['items'])){
            foreach($data as $idx=>$item){
                $error = $this->validateNode([...$path,$idx],$item,$schema['items']);
                if ($error) $childrenErrors[] = $error;
            }
        }

        $currentPathStr = $this->convertPathToString($path);
        if(isset($schema['minItems']) && count($data)<$schema['minItems']){
            $childrenErrors[]= new ValidationError(
                $currentPathStr,
                'minItems-not-met',
                'Need at least '.$schema['minItems'].' items, found '.count($data).'.',
                ['expectedMin' => $schema['minItems'], 'actualCount' => count($data)]
            );
        }
        if(isset($schema['maxItems']) && count($data)>$schema['maxItems']){
            $childrenErrors[]= new ValidationError(
                $currentPathStr,
                'maxItems-exceeded',
                'May contain at most '.$schema['maxItems'].' items, found '.count($data).'.',
                ['expectedMax' => $schema['maxItems'], 'actualCount' => count($data)]
            );
        }
        if(isset($schema['uniqueItems'])){
            // This is a schema configuration issue, not a data validation issue.
            throw new UnsupportedSchemaException("The array constraint \"uniqueItems\" is not supported.");
        }
        
        if (!empty($childrenErrors)) {
			if(count($childrenErrors) === 1){
				return $childrenErrors[0];
			}
            return new ValidationError(
                $currentPathStr,
                'array-validation-failed',
                'Array validation failed.',
                [],
                $childrenErrors
            );
        }
        return null;
    }

    // ────────────────────────────────────────────────────────── references ─┐

    private function resolveReference(string $ref): array
    {
        if(!str_starts_with($ref,'#/')){
            throw new UnsupportedSchemaException('Only local #/ refs are supported');
        }
        $node=$this->schema;
        $pathParts = explode('/',substr($ref,2));
        foreach($pathParts as $p){
            // Need to handle cases where $p could be an encoded character like ~0 for ~ or ~1 for /
            $p = str_replace(['~1', '~0'], ['/', '~'], $p);
            if(is_array($node) && array_key_exists($p,$node)){
                $node=$node[$p];
            } else {
                 throw new UnsupportedSchemaException("Reference {$ref} not found at segment '{$p}'.");
            }
        }
        return $node;
    }

    // ───────────────────────────────────────────── discriminator inference ─┐

    private function inferDiscriminator(?array $explicit,array $branches):?array
    {
        // Filter branches to only include those that are objects and have properties defined
        $objs = array_filter($branches, function($b) {
            $schema = isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b;
            return ($schema['type'] ?? null) === 'object' && isset($schema['properties']);
        });

        if(count($objs) < 2){return null;} // Discriminator is useful for 2+ object shapes
        // The original code had count($objs) !== count($branches). This might be too restrictive if some branches are non-objects.
        // For now, let's proceed if we have at least two object candidates for discrimination.

        if($explicit && isset($explicit['propertyName'])){
            $prop=$explicit['propertyName'];
            $vals=[];
            foreach($objs as $s_wrapper){
                $s = isset($s_wrapper['$ref']) ? $this->resolveReference($s_wrapper['$ref']) : $s_wrapper;
                $d=$s['properties'][$prop]??null;
                if($d&&isset($d['enum'])&&count($d['enum'])===1){
                    $vals[]=$d['enum'][0];
                } else {
                    // If an explicit discriminator property is not a single-value enum in a branch, it can't be used.
                    return null;
                }
            }
            // Ensure all discriminator values are unique among the object branches considered
            if(count($vals) === count($objs) && count(array_unique($vals)) === count($vals)){
                return [$prop,$vals];
            }
            return null; // Explicit discriminator not viable (e.g. not present in all, or not single enum)
        }
        
        // Auto‑guess single‑value enums
        $candidates=[];
        $firstObjSchema = isset($objs[0]['$ref']) ? $this->resolveReference($objs[0]['$ref']) : $objs[0];
        if (!isset($firstObjSchema['properties'])) return null; // Should not happen due to filter above

        foreach(array_keys($firstObjSchema['properties']) as $prop){
            $possibleValues = [];
            $allObjsHaveThisEnumProp = true;
            foreach($objs as $s_wrapper){
                $s = isset($s_wrapper['$ref']) ? $this->resolveReference($s_wrapper['$ref']) : $s_wrapper;
                if (!isset($s['properties'][$prop]['enum']) || count($s['properties'][$prop]['enum']) !== 1) {
                    $allObjsHaveThisEnumProp = false;
                    break;
                }
                $possibleValues[] = $s['properties'][$prop]['enum'][0];
            }
            if ($allObjsHaveThisEnumProp && count(array_unique($possibleValues)) === count($objs)) {
                $candidates[$prop] = $possibleValues;
            }
        }

        if(count($candidates)===1){ // Only one property serves as a unique discriminator
            return [key($candidates), current($candidates)];
        }
        return null; // No single property found to act as an implicit discriminator, or multiple found (ambiguous)
    }
}



