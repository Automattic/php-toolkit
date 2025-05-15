<?php

namespace WordPress\Blueprints;

final class Issue
{
    public function __construct(
        public array  $path,
        public string $message
    ) {}
}

class ValidationResult
{
    public function __construct(
        public bool  $valid,
        /** @var Issue[] */
        public array $errors = []
    ) {}

    public static function ok(): self
    {
        return new self(true);
    }

    public static function err(array $path, string $message): self
    {
        return new self(false, [new Issue($path, $message)]);
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
}


final class SchemaValidator
{
    private bool $arrayIsValidObject;

    public function __construct(
        private array $schema,
        array $options = [],
    ) {
        $this->arrayIsValidObject = $options['array_is_valid_object'] ?? true;
    }

    public function validate(mixed $data): ValidationResult
    {
        return $this->validateNode(['root'], $data, $this->schema);
    }

    /** Core dispatch */
    private function validateNode(array $path, mixed $data, array $schema): ValidationResult
    {
        if (isset($schema['$ref'])) {
            $schema = $this->resolveReference($schema['$ref']);
        }

        return match (true) {
            isset($schema['anyOf']) => $this->validateAnyOf($path, $data, $schema),
            isset($schema['oneOf']) => $this->validateOneOf($path, $data, $schema),
            isset($schema['type'])  => $this->validateType($path, $data, $schema),
            default                 => ValidationResult::err($path, 'Unsupported schema node'),
        };
    }

	/** -------------------------------------------------
	 *  anyOf – succeed if one branch valid, else:
	 *          • if at least one branch “fits” but is invalid → bubble that branch’s errors
	 *          • otherwise → aggregate mismatch hint
	 * ------------------------------------------------- */
	private function validateAnyOf(array $path, mixed $data, array $schema): ValidationResult
	{
		$branches = $schema['anyOf'];
		$discriminator = $this->inferDiscriminator($schema['discriminator'] ?? null, $branches);
		$compatibleFailures = [];

		foreach ($branches as $idx => $branch) {
			$resolved = isset($branch['$ref']) ? $this->resolveReference($branch['$ref']) : $branch;
			$result   = $this->validateNode($path, $data, $resolved);

			if ($result->valid) {
				return ValidationResult::ok();                            // ✅ one branch passed – we’re done
			}

			if ($this->typeMatches($data, $resolved)) {
				$compatibleFailures[] = $result;                          // same shape, but failed deeper
			}
		}

		// at least one branch matched type → surface its errors
		if ($compatibleFailures) {
			return ValidationResult::combine(...$compatibleFailures);
		}

		// nothing matched even the broad type → aggregate mismatch diagnostics
		return $this->explainAggregateMismatch($path, $data, $schema, $discriminator, 'anyOf');
	}

	/** Produce richer error message for anyOf/oneOf aggregate failure */
	private function explainAggregateMismatch(array $path, mixed $data, array $branches, $discriminator, string $baseMsg): ValidationResult
	{
		// 1. type mismatch?
		$branchTypes = array_filter(array_unique(array_map(fn ($b) => $b['type'] ?? null, $branches)));
		$actualType  = gettype($data);
		if (count($branchTypes) > 0 && !in_array($actualType, $branchTypes, true)) {
			$hint = " Expected one of [".implode(', ', $branchTypes)."], got $actualType.";
			return ValidationResult::err($path, $baseMsg.$hint);
		}

		// 2. common discriminator?
		if ($discriminator) {
			[$prop, $allowed] = $discriminator;
			$val = is_array($data) && array_key_exists($prop, $data) ? $data[$prop] : null;
			$hint = " Discriminator '$prop' must be one of [".implode(', ', $allowed)."]. Got ".var_export($val, true).".";
			return ValidationResult::err($path, $baseMsg.$hint);
		}

		// 3. pure $ref list?
		$refs = array_values(array_filter(array_map(fn ($b) => $b['$ref'] ?? null, $branches)));
		if ($refs) {
			$names = array_map(fn ($r) => basename($r), $refs);
			$hint  = " Value must match one of referenced schemas: ".implode(', ', $names).".";
			return ValidationResult::err($path, $baseMsg.$hint);
		}

		return ValidationResult::err($path, $baseMsg);   // fall-back
	}

	/** Try to find a single enum-based discriminator shared by all object branches */
	private function inferDiscriminator(?array $explicit_discriminator, array $branches): ?array
	{
		// keep only object branches with properties
		$objects = array_filter($branches, fn ($b) => ($b['type'] ?? null) === 'object' && isset($b['properties']));
		if (count($objects) !== count($branches) || count($objects) < 2) {
			return null;
		}

		// Use the explicitly defined discriminator if one exists
		if ($explicit_discriminator && isset($explicit_discriminator['propertyName'])) {
			$prop = $explicit_discriminator['propertyName'];
			$values = [];
			foreach ($objects as $schema) {
				$def = $schema['properties'][$prop] ?? null;
				if ($def && isset($def['enum']) && count($def['enum']) === 1) {
					$values[] = $def['enum'][0];
				}
			}

			if (count(array_unique($values)) !== count($values)) {
				return null; // values not unique => not a discriminator
			}

			return [$prop, $values];
		}

		// No explicit discriminator. Let's try to infer one.

		// collect all props that are single-value enums
		$candidates = [];
		foreach ($objects as $schema) {
			foreach ($schema['properties'] as $prop => $def) {
				if (isset($def['enum']) && count($def['enum']) === 1) {
					$candidates[$prop][] = $def['enum'][0];
				}
			}
		}

		// discriminator exists if there's:
		//
		// * exactly one single-value enum
		// * that appears in every branch
		// * that has a unique value in every branch
		$candidates = array_filter($candidates, fn ($vals) => count($vals) === count($branches));
		if (count($candidates) !== 1) {
			return null;
		}

		[$prop, $values] = [key($candidates), current($candidates)];
		if (count(array_unique($values)) !== count($values)) {
			return null; // values not unique => not a discriminator
		}

		return [$prop, $values];
	}

	/** -------------------------------------------------
	 *  oneOf – succeed if exactly one branch valid.
	 *          Failure cases:
	 *          • >1 valid → multiple-match error (as before)
	 *          • 0 valid  → pick nearest match if any, else aggregate mismatch
	 * ------------------------------------------------- */
	private function validateOneOf(array $path, mixed $data, array $schema): ValidationResult
	{
		$branches = $schema['oneOf'];
		// Check if data matches any of the types defined in $schema
		if (isset($schema['type'])) {
			$types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
			$matchesType = false;
			foreach ($types as $type) {
				if ($this->typeMatches($data, ['type' => $type])) {
					$matchesType = true;
					break;
				}
			}
			if (!$matchesType) {
				// @TODO: List the allowed types
				return ValidationResult::err($path, 'Data does not match any allowed type(s) for oneOf');
			}
		}

		// If there is only one branch matching the data type, only validate against that branch
		$typeMatchingBranches = [];
		foreach ($branches as $branch) {
			$resolved = isset($branch['$ref']) ? $this->resolveReference($branch['$ref']) : $branch;
			if ($this->typeMatches($data, $resolved)) {
				$typeMatchingBranches[] = $branch;
			}
		}
		if (count($typeMatchingBranches) === 1) {
			return $this->validateNode($path, $data, $typeMatchingBranches[0]);
		}

		// If there's a discriminator, choose the branch that matches the discriminator
		$discriminator = $this->inferDiscriminator($schema['discriminator'] ?? null, $branches);
		if ($discriminator) {
			if(!isset($data[$discriminator[0]])) {
				return ValidationResult::err($path, 'Data does not match any allowed type(s) for oneOf: ' . $discriminator[0]);
			}
			if(!is_string($data[$discriminator[0]])) {
				return ValidationResult::err($path, 'Data does not match any allowed type(s) for oneOf: ' . $discriminator[0]);
			}

			$discriminator_value = $data[$discriminator[0]];

			$matchingBranch = null;
			foreach ($branches as $branch) {
				$expected_discriminator_value = $branch['properties'][$discriminator[0]]['enum'][0] ?? null;
				if ($expected_discriminator_value === $discriminator_value) {
					$matchingBranch = $branch;
					break;
				}
			}
			if (!$matchingBranch) {
				return ValidationResult::err($path, 'Data does not match any allowed type(s) for oneOf: ' . $data[$discriminator[0]]);
			}
			// Validate just the matching branch
			return $this->validateNode($path, $data, $matchingBranch);
		}

		// We cannot prune the branches any further.
		// We need to validate against all branches.
		// @TODO: Smaller error message – no need to combine errors from all the branches.

		$validCount          = 0;
		$compatibleFailures  = [];
		foreach ($branches as $branch) {
			$resolved = isset($branch['$ref']) ? $this->resolveReference($branch['$ref']) : $branch;
			$result   = $this->validateNode($path, $data, $resolved);

			if ($result->valid) {
				$validCount++;
			} elseif ($this->typeMatches($data, $resolved)) {
				$compatibleFailures[] = $result;
			}
		}

		if ($validCount === 1) {
			return ValidationResult::ok();                                // ✅ exactly one branch ok
		}
		if ($validCount > 1) {
			return ValidationResult::err($path, 'oneOf matched multiple clauses');
		}
		// 0 valid
		if ($compatibleFailures) {
			return ValidationResult::combine(...$compatibleFailures);     // bubble variant-specific errors
		}
		return $this->explainAggregateMismatch($path, $data, $branches, $discriminator, 'oneOf did not match any clause');
	}

	/** quick structural/type check used to spot “compatible but invalid” branches */
	private function typeMatches(mixed $data, array $schema): bool
	{
		$t = $schema['type'] ?? null;
		return match ($t) {
			'object'  => is_object($data) || ($this->arrayIsValidObject && is_array($data)),
			'array'   => is_array($data),
			'string'  => is_string($data),
			'integer' => is_int($data),
			'number'  => is_int($data) || is_float($data),
			'boolean' => is_bool($data),
			null      => true,                                            // no type declared – assume compatible
			default   => false,
		};
	}

    /** Primitive + composite type validation */
    private function validateType(array $path, mixed $data, array $schema): ValidationResult
    {
        $type = $schema['type'];

        $typeCheck = match ($type) {
            'object'  => is_object($data) || ($this->arrayIsValidObject && is_array($data)),
            'array'   => is_array($data),
            'string'  => is_string($data),
            'integer' => is_int($data),
            'number'  => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            default   => throw new \RuntimeException("Unsupported type $type at " . implode('.', $path)),
        };

        if (!$typeCheck) {
            return ValidationResult::err($path, "Expected $type, got " . gettype($data));
        }

        // enum constraint
        if (isset($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            return ValidationResult::err($path, 'Value not in enum: ' . implode(', ', $schema['enum']));
        }

        return match ($type) {
            'object' => $this->validateObject($path, $data, $schema),
            'array'  => $this->validateArray($path, $data, $schema),
            default  => ValidationResult::ok(),
        };
    }

    private function validateObject(array $path, array|object $data, array $schema): ValidationResult
    {
        $dataArr = is_object($data) ? (array) $data : $data;
        $results = [];

        // required
        if (!empty($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (!array_key_exists($field, $dataArr)) {
                    $results[] = ValidationResult::err([...$path], 'Missing required field "' . $field . '"');
                }
            }
        }

        // properties
        if (!empty($schema['properties'])) {
            foreach ($schema['properties'] as $name => $propSchema) {
                if (array_key_exists($name, $dataArr)) {
                    $results[] = $this->validateNode([...$path, $name], $dataArr[$name], $propSchema);
                }
            }
        }

        // additionalProperties
        if (array_key_exists('additionalProperties', $schema)) {
            foreach ($dataArr as $name => $value) {
                if (isset($schema['properties'][$name])) {
                    continue;
                }
                if ($schema['additionalProperties'] === false) {
                    $results[] = ValidationResult::err([...$path, $name], 'Unexpected property');
                } else {
                    $results[] = $this->validateNode([...$path, $name], $value, $schema['additionalProperties']);
                }
            }
        }

        return ValidationResult::combine(...$results);
    }

    private function validateArray(array $path, array $data, array $schema): ValidationResult
    {
        $results = [];

        if (isset($schema['items'])) {
            foreach ($data as $idx => $item) {
                $results[] = $this->validateNode([...$path, $idx], $item, $schema['items']);
            }
        }

        // minItems / maxItems / uniqueItems placeholders
        if (isset($schema['minItems']) || isset($schema['maxItems']) || isset($schema['uniqueItems'])) {
            throw new \RuntimeException('minItems, maxItems, uniqueItems not implemented');
        }

        return ValidationResult::combine(...$results);
    }

    private function resolveReference(string $ref): array
    {
        if ($ref === '' || !str_starts_with($ref, '#/')) {
            throw new \RuntimeException('Only in-document #/ references supported');
        }
        $parts = explode('/', substr($ref, 2));
        $node  = $this->schema;

        foreach ($parts as $part) {
            if (!array_key_exists($part, $node)) {
                throw new \RuntimeException("Reference $ref not found");
            }
            $node = $node[$part];
        }
        return $node;
    }
}


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
			"source" => "https://wordpress.org/files/2024/10/design-visual-6-7.png",
			"title" => "Introduction Video",
			"description" => "A brief introduction to our company",
			"alt" => "Company introduction video"
		],
	],
	"additionalStepsAfterExecution" => [
		[
			"step" => "writeFiles",
			"filesz" => [
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
