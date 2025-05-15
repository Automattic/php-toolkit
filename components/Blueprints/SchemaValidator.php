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

	/* -------------------------------------------------
	*  Helper: does this branch’s broad `type` fit $data?
	* ------------------------------------------------- */
	private function typeMatchesAny(mixed $data, array $types): bool
	{
		foreach ($types as $type) {
			if(is_array($type)) {
				$type = isset($type['$ref']) ? $this->resolveReference($type['$ref']) : $type;
			}
			if(is_array($type)) {
				$type = $type['type'] ?? null;
			}
			if ($this->typeMatches($data, $type)) {
				return true;
			}
		}
		return false;
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

	/* -------------------------------------------------
	*  Helper: human-readable label for a branch
	*          – `$ref` ➜ last path segment
	*          – otherwise `title`, else `type`
	* ------------------------------------------------- */
	private function branchLabel(array $branch): string
	{
		if (isset($branch['$ref'])) {
			$ref = $branch['$ref'];
			return substr($ref, strrpos($ref, '/') + 1);
		}
		return $branch['title'] ?? ($branch['type'] ?? '<schema>');
	}

	/* -------------------------------------------------
	*  Helper: trim obvious non-candidates, maybe by discriminator
	* ------------------------------------------------- */
	private function narrowBranches(mixed $data, array $branches, array $schema): array
	{
		// prune by top-level type
		$candidates = array_filter(
			$branches,
			function ($branch) use ($data) {
				$schema_subset = isset($branch['$ref']) ? $this->resolveReference($branch['$ref']) : $branch;
				return $this->typeMatches(
					$data,
					$schema_subset['type'] ?? null
				);
			}
		);

		// apply discriminator if present + value available
		$disc = $this->inferDiscriminator($schema['discriminator'] ?? null, $branches);
		if ($disc && is_array($data) && array_key_exists($disc[0], $data)) {
			$wanted = $data[$disc[0]];
			$candidates = array_values(array_filter($candidates, function ($b) use ($disc, $wanted) {
				$r = isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b;
				return ($r['properties'][$disc[0]]['enum'][0] ?? null) === $wanted;
			}));
		}
		return $candidates ?: $branches;      // never return empty
	}

	/* -------------------------------------------------
	*  anyOf – succeed if one passes.
	*          If a single narrowed branch fails ⇒ bubble its error.
	*          Else ⇒ generic message listing all possibilities.
	* ------------------------------------------------- */
	private function validateAnyOf(array $path, mixed $data, array $schema): ValidationResult
	{
		$branches    = $schema['anyOf'];
		$candidates  = $this->narrowBranches($data, $branches, $schema);
		$narrowed    = count($candidates) < count($branches);
		$failures    = [];

		foreach ($candidates as $b) {
			$r = $this->validateNode(
				$path,
				$data,
				isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b
			);
			if ($r->valid) {
				return ValidationResult::ok();
			}
			$failures[] = $r;
		}

		if ($narrowed && $failures) {
			return ValidationResult::combine(...$failures);        // precise variant-specific error(s)
		}

		$disc = $this->inferDiscriminator($schema['discriminator'] ?? null, $branches);
		return $this->explainAggregateMismatch($path, $data, $branches, $disc, 'anyOf');
	}

	/* -------------------------------------------------
	*  oneOf – must have *exactly* one passing branch.
	* ------------------------------------------------- */
	private function validateOneOf(array $path, mixed $data, array $schema): ValidationResult
	{
		$branches   = $schema['oneOf'];
		$candidates = $this->narrowBranches($data, $branches, $schema);
		$narrowed   = count($candidates) < count($branches);

		$valid   = 0;
		$fails   = [];

		foreach ($candidates as $b) {
			$r = $this->validateNode(
				$path,
				$data,
				isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b
			);
			$valid += $r->valid ? 1 : 0;
			if (!$r->valid) { $fails[] = $r; }
		}

		return match (true) {
			$valid === 1 => ValidationResult::ok(),
			$valid > 1   => ValidationResult::err($path, 'oneOf matched multiple clauses'),
			$narrowed && $fails
				=> ValidationResult::combine(...$fails),             // specific branch errors
			default => $this->explainAggregateMismatch(
				$path,
				$data,
				$branches,
				$this->inferDiscriminator($schema['discriminator'] ?? null, $branches),
				'oneOf'
			),
		};
	}

	/** Produce richer error message for anyOf/oneOf aggregate failure */
	/* ------------------------------------------------------------------
	*  Helper: craft the aggregate error message in priority order
	*          1 type mismatch          → list allowed PHP types
	*          2 discriminator mismatch → list allowed discriminator values
	*          3 fallback               → list branch labels once
	* ------------------------------------------------------------------ */
	private function explainAggregateMismatch(
		array  $path,
		mixed  $data,
		array  $branches,
		?array $discriminator,            // [$prop, $allowed]|null
		string $ctx                       // 'anyOf' | 'oneOf'
	): ValidationResult {
		// a) type information
		$allowedTypes = [];
		foreach ($branches as $b) {
			$r = isset($b['$ref']) ? $this->resolveReference($b['$ref']) : $b;
			if (isset($r['type'])) { $allowedTypes[] = $r['type']; }
		}
		$allowedTypes = array_unique($allowedTypes);
		if (!$this->typeMatchesAny($data, $allowedTypes)) {
			return ValidationResult::err(
				$path,
				"$ctx: expected one of [" . implode(', ', $allowedTypes) . "], got " . gettype($data)
			);
		}
		$actualType   = gettype($data);
		if($this->arrayIsValidObject && $actualType === 'array' && in_array('object', $allowedTypes, true)) {
			$actualType = 'object';
		}
		$allowedTypes = array_unique($allowedTypes);
		if ($allowedTypes && !in_array($actualType, $allowedTypes, true)) {
			return ValidationResult::err(
				$path,
				"$ctx: expected one of [" . implode(', ', $allowedTypes) . "], got $actualType"
			);
		}

		// b) discriminator information
		if ($discriminator) {
			[$prop, $allowed] = $discriminator;
			$val = (is_array($data) && array_key_exists($prop, $data))
				? var_export($data[$prop], true)
				: 'missing';
			return ValidationResult::err(
				$path,
				"$ctx: discriminator '$prop' must be " .
				"[" . implode(', ', $allowed) . "], got $val"
			);
		}

		// c) generic – list unique labels once
		$labels = array_unique(array_map([$this, 'branchLabel'], $branches));
		return ValidationResult::err(
			$path,
			"$ctx: value must match " .
			($ctx === 'oneOf' ? 'exactly one of ' : 'one of ') .
			implode(', ', $labels)
		);
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
			'string' => $this->validateString($path, $data, $schema),
            default  => ValidationResult::ok(),
        };
    }

	private function validateString(array $path, string $data, array $schema): ValidationResult
	{
		if(isset($schema['minLength'])) {
			throw new \RuntimeException('minLength validation is not supported at ' . implode('.', $path));
		}

		if(isset($schema['maxLength'])) {
			throw new \RuntimeException('maxLength validation is not supported at ' . implode('.', $path));
		}

		if(isset($schema['pattern'])) {
			throw new \RuntimeException('pattern validation is not supported at ' . implode('.', $path));
		}

		return ValidationResult::ok();
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

		if(isset($schema['minItems'])) {
			if(count($data) < $schema['minItems']) {
				$results[] = ValidationResult::err([...$path], 'Expected at least ' . $schema['minItems'] . ' items, got ' . count($data));
			}
		}

		if(isset($schema['maxItems'])) {
			if(count($data) > $schema['maxItems']) {
				$results[] = ValidationResult::err([...$path], 'Expected at most ' . $schema['maxItems'] . ' items, got ' . count($data));
			}
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
			"source" => 2,//"2",
			"title" => "Introduction Video",
			"description" => "A brief introduction to our company",
			"alt" => "Company introduction video"
		],
	],
	"additionalStepsAfterExecution" => [
		[
			"step" => "writeFiles",
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
