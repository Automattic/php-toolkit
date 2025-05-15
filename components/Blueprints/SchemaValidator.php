<?php

namespace WordPress\Blueprints;

class ValidationResult {
	public function __construct(
		public bool $valid,
		public array $errors = []
	) {}
}

class ValidationError extends ValidationResult {
	public function __construct(
		public array $path,
		public string $message		
	) {
		parent::__construct(false, [$this]);
	}
}

class SchemaValidator {
	private $array_is_valid_object = false;
	public function __construct(
		private array $schema,
		array $options = []
	) {
		$this->array_is_valid_object = $options['array_is_valid_object'] ?? true;
	}

	public function validate($data): ValidationResult {
		return $this->validate_node(['root'], $data, $this->schema);
	}

	private function validate_node(array $data_path, $data_node, array $schema_node): ValidationResult {
		if(isset($schema_node['$ref'])) {
			$schema_node = $this->resolve_reference($schema_node['$ref']);
		}
		if(isset($schema_node['anyOf'])) {
			return $this->validate_anyof($data_path, $data_node, $schema_node);
		}
		else if(isset($schema_node['oneOf'])) {
			return $this->validate_oneof($data_path, $data_node, $schema_node);
		}
		else if(isset($schema_node['type'])) {
			return $this->validate_type($data_path, $data_node, $schema_node);
		}
		else {
			$this->add_error($data_path, 'Unsupported schema node type: ' . $schema_node['type']);
			return false;
		}
	}

	private function validate_anyof(array $data_path, $data_node, array $schema_node): ValidationResult|bool {
		if(!isset($schema_node['anyOf'])) {
			return true;
		}
		foreach($schema_node['anyOf'] as $anyof_schema) {
			if( $this->validate_node($data_path, $data_node, $anyof_schema) ) {
				return true;
			}
		}
		return new ValidationError($data_path, 'anyOf did not match any clause');
	}

	private function validate_oneof(array $data_path, $data_node, array $schema_node) {
		if(!isset($schema_node['oneOf'])) {
			return true;
		}
		$matched_oneof = false;
		foreach($schema_node['oneOf'] as $oneof_schema) {
			if( ! $this->validate_node($data_path, $data_node, $oneof_schema) ) {
				continue;
			}
			if( $matched_oneof ) {
				$this->add_error($data_path, 'oneOf matched multiple clauses');
				return false;
			}
			$matched_oneof = true;
		}
		if( ! $matched_oneof ) {
			$this->add_error($data_path, 'oneOf did not match any clause');
			return false;
		}
		return true;
	}

	private function validate_type(array $data_path, $data_node, array $schema_node): bool {
		// Validate the type of the data node
		if(!isset($schema_node['type'])) {
			return true;
		}
		switch($schema_node['type']) {
			case 'object':
				$is_valid_object = is_object($data_node) || ($this->array_is_valid_object && is_array($data_node));
				if(!$is_valid_object) {
					$this->add_error($data_path, 'Must be an object but got ' . gettype($data_node));
					return false;
				}
				
				if(!$this->validate_object($data_path, $data_node, $schema_node)) {
					return false;
				}				
				break;
			case 'array':
				$is_valid_array = is_array($data_node);
				if(!$is_valid_array) {
					$this->add_error($data_path, 'Must be an array but got ' . gettype($data_node));
					return false;
				}
				if(!$this->validate_array($data_path, $data_node, $schema_node)) {
					return false;
				}
				break;
			case 'string':
				$is_valid_string = is_string($data_node);
				if(!$is_valid_string) {
					$this->add_error($data_path, 'Must be a string but got ' . gettype($data_node));
					return false;
				}
				break;
			case 'integer':
				$is_valid_integer = is_int($data_node);
				if(!$is_valid_integer) {
					$this->add_error($data_path, 'Must be an integer but got ' . gettype($data_node));
					return false;
				}
				break;
			case 'number':
				$is_valid_number = is_numeric($data_node);
				if(!$is_valid_number) {
					$this->add_error($data_path, 'Must be a number but got ' . gettype($data_node));
					return false;
				}
				break;
			case 'boolean':
				$is_valid_boolean = is_bool($data_node);
				if(!$is_valid_boolean) {
					$this->add_error($data_path, 'Must be a boolean but got ' . gettype($data_node));
					return false;
				}
				break;
			default:
				throw new \Exception(sprintf(
					'Schema specified unsupported type %s at %s', $schema_node['type'], implode('.', $data_path)
				));
		}

		// Validate enum
		if(isset($schema_node['enum'])) {
			if(!in_array($data_node, $schema_node['enum'], true)) {
				$this->add_error($data_path, 'Must be one of ' . implode(', ', $schema_node['enum']));
				return false;
			}
		}
		return true;
	}

	private function validate_object(array $data_path, $data_node, array $schema_node): bool {
		// Confirm the required fields are present
		if(isset($schema_node['required']) && is_array($schema_node['required'])) {
			foreach($schema_node['required'] as $required_field) {
				if(!isset($data_node[$required_field])) {
					$this->add_error([...$data_path, $required_field], 'Required field ' . $required_field . ' is missing');
				}
			}
		}

		// Validate the properties of the data node
		if(isset($schema_node['properties']) && is_array($schema_node['properties'])) {
			foreach($schema_node['properties'] as $property_name => $property_schema) {
				if(isset($data_node[$property_name])) {
					$this->validate_node([...$data_path, $property_name], $data_node[$property_name], $property_schema);
				}
			}
		}

		// Validate additional properties
		if(isset($schema_node['additionalProperties'])) {
			foreach($data_node as $property_name => $property_value) {
				if(isset($schema_node['properties'][$property_name])) {
					// Not an additional property, skip
					continue;
				}

				if($schema_node['additionalProperties'] === false) {
					// Additional property not allowed – error
					$this->add_error([...$data_path, $property_name], 'Unexpected property ' . $property_name);
				} else {
					// Additional property allowed, validate it
					$this->validate_node([...$data_path, $property_name], $property_value, $schema_node['additionalProperties']);
				}
			}
		}
	}

	private function validate_array(array $data_path, $data_node, array $schema_node) {
		if(!is_array($data_node)) {
			$this->add_error($data_path, 'Must be an array but got ' . gettype($data_node));
		}
		if(isset($schema_node['items'])) {
			foreach($data_node as $item_index => $item_node) {
				$this->validate_node([...$data_path, "[$item_index]"], $item_node, $schema_node['items']);
			}
		}
		if(isset($schema_node['minItems'], $schema_node['maxItems'], $schema_node['uniqueItems'])) {
			throw new \Exception('minItems and maxItems are not yet supported for arrays');
		}
	}

	private function add_error($data_path, $message) {
		$this->errors[] = [
			'message' => $message,
			'path' => $data_path
		];
	}

	public function get_errors(): array {
		return $this->errors;
	}

	private function resolve_reference($ref_string) {
		if(strlen($ref_string) === 0) {
			throw new \Exception('Reference string cannot be empty');
		}
		if($ref_string[0] !== '#' || $ref_string[1] !== '/') {
			throw new \Exception('Object reference must start with "#/". External references are not supported.');
		}
		$ref_string = substr($ref_string, 2);
		$ref_parts = explode('/', $ref_string);
		$schema_node = $this->schema;
		foreach($ref_parts as $ref_part) {
			if(!isset($schema_node[$ref_part])) {
				throw new \Exception('Reference path not found: ' . $ref_string);
			}
			$schema_node = $schema_node[$ref_part];
		}
		return $schema_node;
	}

}

$schema = json_decode(file_get_contents(__DIR__ . '/json-schema/blueprint-v2-schema.json'), true);
$validator = new SchemaValidator($schema);
$is_valid = $validator->validate([
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

if($is_valid) {
	echo "The data is valid according to the schema.\n";
} else {
	echo "The data is invalid according to the schema.\n";
	foreach($validator->get_errors() as $error) {
		print_r($error);
	}
}
