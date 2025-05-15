<?php

namespace WordPress\Blueprints;

class ValidationResult
{
    public function __construct(
        public bool   $valid,
        public array  $errors = []        // array<ValidationError>
    ) {}

    /** Fast constructor for success */
    public static function ok(): self
    {
        return new self(true, []);
    }

    /** Merge two results, preserving all errors */
    public function merge(self $other): self
    {
        if ($this->valid && $other->valid) {
            return self::ok();
        }
        return new self(false, array_merge($this->errors, $other->errors));
    }

    /** Convenience wrapper around merge for variadic use */
    public static function combine(self ...$results): self
    {
        return array_reduce(
            $results,
            fn (self $carry, self $item) => $carry->merge($item),
            self::ok()
        );
    }
}

final class ValidationError extends ValidationResult
{
    public function __construct(
        public array  $path,
        public string $message,
    ) {
        parent::__construct(false, [$this]);
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
            isset($schema['anyOf']) => $this->validateAnyOf($path, $data, $schema['anyOf']),
            isset($schema['oneOf']) => $this->validateOneOf($path, $data, $schema['oneOf']),
            isset($schema['type'])  => $this->validateType($path, $data, $schema),
            default                 => new ValidationError($path, 'Unsupported schema node'),
        };
    }

    /** anyOf: succeed if at least one child succeeds */
    private function validateAnyOf(array $path, mixed $data, array $branches): ValidationResult
    {
        $errors = [];
        foreach ($branches as $branch) {
            $res = $this->validateNode($path, $data, $branch);
            if ($res->valid) {
                return ValidationResult::ok();
            }
            $errors = array_merge($errors, $res->errors);
        }
        return new ValidationError($path, 'anyOf did not match any clause');
    }

    /** oneOf: succeed if exactly one child succeeds */
    private function validateOneOf(array $path, mixed $data, array $branches): ValidationResult
    {
        $matches = 0;
        $errors  = [];

        foreach ($branches as $branch) {
            $res = $this->validateNode($path, $data, $branch);
            if ($res->valid) {
                $matches++;
            } else {
                $errors = array_merge($errors, $res->errors);
            }
        }

        return match ($matches) {
            1       => ValidationResult::ok(),
            0       => new ValidationError($path, 'oneOf did not match any clause'),
            default => new ValidationError($path, 'oneOf matched multiple clauses'),
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
            return new ValidationError($path, "Expected $type, got " . gettype($data));
        }

        // enum constraint
        if (isset($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            return new ValidationError($path, 'Value not in enum: ' . implode(', ', $schema['enum']));
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
                    $results[] = new ValidationError([...$path, $field], 'Missing required field');
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
                    $results[] = new ValidationError([...$path, $name], 'Unexpected property');
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
	'$schema' => 2, //"https://raw.githubusercontent.com/WordPress/blueprints/trunk/blueprints/schema.json",
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
