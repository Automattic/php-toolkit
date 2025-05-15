<?php

namespace WordPress\Blueprints\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\HumanFriendlySchemaValidator;
use WordPress\Blueprints\ValidationResult;
use WordPress\Blueprints\Issue;
use WordPress\Blueprints\Validator\UnsupportedSchemaException;

/**
 * Tests for the HumanFriendlySchemaValidator class.
 * This class focuses on general JSON schema validation capabilities.
 */
class HumanFriendlySchemaValidatorTest extends TestCase {

	// Test Primitive Types
	public static function primitiveTypeProvider(): array {
		return [
			'valid string' => [ ['type' => 'string'], 'hello', true ],
			'invalid string (integer given)' => [ ['type' => 'string'], 123, false, 'Expected string, got integer.' ],
			'valid integer' => [ ['type' => 'integer'], 42, true ],
			'invalid integer (string given)' => [ ['type' => 'integer'], 'foo', false, 'Expected integer, got string.' ],
			'valid boolean true' => [ ['type' => 'boolean'], true, true ],
			'valid boolean false' => [ ['type' => 'boolean'], false, true ],
			'invalid boolean (integer given)' => [ ['type' => 'boolean'], 0, false, 'Expected boolean, got integer.' ],
			'valid number (float)' => [ ['type' => 'number'], 3.14, true ],
			'valid number (integer)' => [ ['type' => 'number'], 7, true ],
			'invalid number (string given)' => [ ['type' => 'number'], '7.0', false, 'Expected number, got string.' ],
			// 'valid null' => [ ['type' => 'null'], null, true ], // Assuming 'null' type is supported
			// 'invalid null (string given)' => [ ['type' => 'null'], 'not null', false, 'Expected null, got string.'],
		];
	}

	/**
	 * @dataProvider primitiveTypeProvider
	 */
	public function testPrimitiveTypeValidation(array $schema, $value, bool $shouldBeValid, string $expectedErrorMessage = null) {
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate($value);
		$this->assertSame($shouldBeValid, $result->valid);
		if (!$shouldBeValid && $expectedErrorMessage) {
			$this->assertNotEmpty($result->errors);
			$this->assertStringContainsString($expectedErrorMessage, $result->errors[0]->message);
		}
	}

	// Test Enums
	public static function enumProvider(): array {
		return [
			'valid enum string' => [ ['type' => 'string', 'enum' => ['a', 'b']], 'a', true ],
			'invalid enum string' => [ ['type' => 'string', 'enum' => ['a', 'b']], 'c', false, 'Allowed values: a, b. You supplied "c".' ],
			'valid enum integer' => [ ['type' => 'integer', 'enum' => [1, 2]], 2, true ],
			'invalid enum integer' => [ ['type' => 'integer', 'enum' => [1, 2]], 3, false, 'Allowed values: 1, 2. You supplied 3.' ],
			'enum with empty string allowed' => [ ['type' => 'string', 'enum' => ['', 'foo']], '', true ],
		];
	}

	/**
	 * @dataProvider enumProvider
	 */
	public function testEnumValidation(array $schema, $value, bool $shouldBeValid, string $expectedErrorMessage = null) {
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate($value);
		$this->assertSame($shouldBeValid, $result->valid);
		if (!$shouldBeValid && $expectedErrorMessage) {
			$this->assertNotEmpty($result->errors);
			$this->assertStringContainsString($expectedErrorMessage, $result->errors[0]->message);
		}
	}

	// Test Objects
	public static function objectProvider(): array {
		$baseSchema = [
			'type' => 'object',
			'properties' => [ 
				'foo' => ['type' => 'string'],
				'bar' => ['type' => 'integer']
			],
		];
		return [
			'valid object' => [
				array_merge($baseSchema, ['required' => ['foo']]),
				['foo' => 'text', 'bar' => 123],
				true
			],
			'valid object with optional property missing' => [
				array_merge($baseSchema, ['required' => ['foo']]),
				['foo' => 'text'],
				true
			],
			'invalid object missing required property' => [
				array_merge($baseSchema, ['required' => ['foo', 'bar']]),
				['foo' => 'text'],
				false,
				'Missing required field(s): bar'
			],
			'invalid object property type' => [
				$baseSchema,
				['foo' => 123], // foo should be string
				false,
				'Expected string, got integer.'
			],
			'object with additionalProperties: false, extra prop' => [
				array_merge($baseSchema, ['additionalProperties' => false]),
				['foo' => 'text', 'extra' => 'disallowed'],
				false,
				"Property 'extra' isn't allowed here."
			],
			'object with additionalProperties: true, extra prop' => [ // True is default, but explicit for test
				array_merge($baseSchema, ['additionalProperties' => true]),
				['foo' => 'text', 'extra' => 'allowed'],
				true
			],
			'object with additionalProperties: schema, valid extra prop' => [
				array_merge($baseSchema, ['additionalProperties' => ['type' => 'boolean']]),
				['foo' => 'text', 'extra' => true],
				true
			],
			'object with additionalProperties: schema, invalid extra prop' => [
				array_merge($baseSchema, ['additionalProperties' => ['type' => 'boolean']]),
				['foo' => 'text', 'extra' => 'not a bool'],
				false,
				'Expected boolean, got string.'
			],
			'object with no properties defined, only additionalProperties: schema' => [
				['type' => 'object', 'additionalProperties' => ['type' => 'string']],
				['key1' => 'val1', 'key2' => 'val2'],
				true
			],
			'object with only required, no properties' => [
			    ['type' => 'object', 'required' => ['mustExist']],
				['mustExist' => 'here'],
				true
			],
			'object with only required, no properties, missing required' => [
			    ['type' => 'object', 'required' => ['mustExist']],
				['otherKey' => 'not it'],
				false,
				'Missing required field(s): mustExist'
			]
		];
	}

	/**
	 * @dataProvider objectProvider
	 */
	public function testObjectValidation(array $schema, $value, bool $shouldBeValid, string $expectedErrorMessage = null) {
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate($value);
		$this->assertSame($shouldBeValid, $result->valid, $expectedErrorMessage ?: 'Validation status mismatch');
		if (!$shouldBeValid && $expectedErrorMessage) {
			$this->assertNotEmpty($result->errors, "Errors array should not be empty when validation fails.");
			$foundError = false;
			foreach($result->errors as $error) {
				if (strpos($error->message, $expectedErrorMessage) !== false) {
					$foundError = true;
					break;
				}
			}
			$this->assertTrue($foundError, "Expected error message fragment '{$expectedErrorMessage}' not found. Actual errors: " . print_r($result->errors, true));
		}
	}

	// Test Arrays
	public static function arrayProvider(): array {
		return [
			'valid array of strings' => [
				['type' => 'array', 'items' => ['type' => 'string']],
				['a', 'b', 'c'],
				true
			],
			'invalid array item type' => [
				['type' => 'array', 'items' => ['type' => 'string']],
				['a', 123, 'c'],
				false,
				'Expected string, got integer.'
			],
			'array with minItems: valid' => [
				['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2],
				[1, 2],
				true
			],
			'array with minItems: invalid' => [
				['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2],
				[1],
				false,
				'Need at least 2 items, found 1.'
			],
			'array with maxItems: valid' => [
				['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 2],
				[1, 2],
				true
			],
			'array with maxItems: invalid' => [
				['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 1],
				[1, 2],
				false,
				'May contain at most 1 items, found 2.'
			],
			'empty array, items schema defined: valid' => [
				['type' => 'array', 'items' => ['type' => 'string']],
				[],
				true
			],
			'array with complex items (objects): valid' => [
				[
					'type' => 'array', 
					'items' => [
						'type' => 'object',
						'properties' => ['id' => ['type' => 'integer']],
						'required' => ['id']
					]
				],
				[ ['id' => 1], ['id' => 2] ],
				true
			],
			'array with complex items (objects): invalid item' => [
				[
					'type' => 'array', 
					'items' => [
						'type' => 'object',
						'properties' => ['id' => ['type' => 'integer']],
						'required' => ['id']
					]
				],
				[ ['id' => 1], ['name' => 'oops'] ], // second item missing 'id'
				false,
				'Missing required field(s): id'
			]
		];
	}

	/**
	 * @dataProvider arrayProvider
	 */
	public function testArrayValidation(array $schema, $value, bool $shouldBeValid, string $expectedErrorMessage = null) {
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate($value);
		$this->assertSame($shouldBeValid, $result->valid, $expectedErrorMessage ?: 'Validation status mismatch');
		if (!$shouldBeValid && $expectedErrorMessage) {
			$this->assertNotEmpty($result->errors);
            $foundError = false;
			foreach($result->errors as $error) {
				if (strpos($error->message, $expectedErrorMessage) !== false) {
					$foundError = true;
					break;
				}
			}
			$this->assertTrue($foundError, "Expected error message fragment '{$expectedErrorMessage}' not found. Actual errors: " . print_r($result->errors, true));
		}
	}

	// Test anyOf
	public static function anyOfProvider(): array {
		return [
			'anyOf: matches first schema (string)' => [
				['anyOf' => [ ['type' => 'string'], ['type' => 'integer'] ]],
				'i am a string',
				true
			],
			'anyOf: matches second schema (integer)' => [
				['anyOf' => [ ['type' => 'string'], ['type' => 'integer'] ]],
				123,
				true
			],
			'anyOf: matches no schema (boolean given)' => [
				['anyOf' => [ ['type' => 'string'], ['type' => 'integer'] ]],
				true,
				false,
				'Expected one of [string, integer] here, but got boolean.'
			],
			'anyOf: overlapping schemas, matches both (number and integer for an int)' => [
				['anyOf' => [ ['type' => 'number'], ['type' => 'integer'] ]],
				5, // Matches both 'number' and 'integer'
				true // anyOf should pass if at least one matches
			],
			// Partial matches with object schemas
			'anyOf: partial match with object schemas' => [
				['anyOf' => [
					['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']], 'required' => ['a', 'b']],
					['type' => 'object', 'properties' => ['c' => ['type' => 'string'], 'd' => ['type' => 'integer']], 'required' => ['c', 'd']]
				]],
				['a' => 'value', 'b' => 123], // Matches first schema
				true
			],
			'anyOf: another partial match with object schemas' => [
				['anyOf' => [
					['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']], 'required' => ['a', 'b']],
					['type' => 'object', 'properties' => ['c' => ['type' => 'string'], 'd' => ['type' => 'integer']], 'required' => ['c', 'd']]
				]],
				['c' => 'value', 'd' => 456], // Matches second schema
				true
			],
			'anyOf: no match with useful error message' => [
				['anyOf' => [
					['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']], 'required' => ['a', 'b']],
					['type' => 'object', 'properties' => ['c' => ['type' => 'string'], 'd' => ['type' => 'integer']], 'required' => ['c', 'd']]
				]],
				['a' => 'value', 'c' => 'value'], // Missing required properties
				false,
				'Value did not match any of the allowed shapes: object.'
			],
			// Near misses with useful error messages
			'anyOf: near miss with wrong type' => [
				['anyOf' => [
					['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
					['type' => 'object', 'properties' => ['b' => ['type' => 'string']], 'required' => ['b']]
				]],
				['a' => 123], // a should be string but is integer
				false,
				'Value did not match any of the allowed shapes: object.'
			],
			// Nested anyOf (one level deeper)
			'anyOf: one level nested' => [
				['type' => 'object', 'properties' => [
					'nested' => ['anyOf' => [
						['type' => 'string'],
						['type' => 'integer']
					]]
				]],
				['nested' => 'string value'], // Valid nested string
				true
			],
			'anyOf: one level nested failure' => [
				['type' => 'object', 'properties' => [
					'nested' => ['anyOf' => [
						['type' => 'string'],
						['type' => 'integer']
					]]
				]],
				['nested' => true], // Invalid nested value (boolean)
				false,
				'Expected one of [string, integer] here, but got boolean.'
			],
			// Two levels deeper nesting
			'anyOf: two levels nested' => [
				['type' => 'object', 'properties' => [
					'level1' => ['type' => 'object', 'properties' => [
						'level2' => ['anyOf' => [
							['type' => 'string'],
							['type' => 'integer']
						]]
					]]
				]],
				['level1' => ['level2' => 42]], // Valid nested integer
				true
			],
			'anyOf: two levels nested failure' => [
				['type' => 'object', 'properties' => [
					'level1' => ['type' => 'object', 'properties' => [
						'level2' => ['anyOf' => [
							['type' => 'string'],
							['type' => 'integer']
						]]
					]]
				]],
				['level1' => ['level2' => false]], // Invalid nested value (boolean)
				false,
				'Expected one of [string, integer] here, but got boolean.'
			],
			// Mixed types in anyOf
			'anyOf: mixed types - string input' => [
				['anyOf' => [
					['type' => 'string'],
					['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
					['type' => 'array', 'items' => ['type' => 'integer']]
				]],
				'valid string', // Valid string input
				true
			],
			'anyOf: mixed types - object input' => [
				['anyOf' => [
					['type' => 'string'],
					['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
					['type' => 'array', 'items' => ['type' => 'integer']]
				]],
				['a' => 'valid object'], // Valid object input
				true
			],
			'anyOf: mixed types - array input' => [
				['anyOf' => [
					['type' => 'string'],
					['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
					['type' => 'array', 'items' => ['type' => 'integer']]
				]],
				[1, 2, 3], // Valid array input
				true
			],
			'anyOf: mixed types - invalid input' => [
				['anyOf' => [
					['type' => 'string'],
					['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
					['type' => 'array', 'items' => ['type' => 'integer']]
				]],
				false, // Boolean doesn't match any schema
				false,
				'Expected one of [string, object, array] here, but got boolean.'
			],
			// Deep ambiguity resolution test
			'anyOf: ambiguity resolved at second level' => [
				['anyOf' => [
					[
						'type' => 'object',
						'properties' => [
							'data' => [
								'type' => 'object',
								'properties' => [
									'type' => ['type' => 'string', 'enum' => ['typeA']],
									'value' => ['type' => 'string']
								],
								'required' => ['type', 'value']
							]
						],
						'required' => ['data']
					],
					[
						'type' => 'object',
						'properties' => [
							'data' => [
								'type' => 'object',
								'properties' => [
									'type' => ['type' => 'string', 'enum' => ['typeB']],
									'count' => ['type' => 'integer']
								],
								'required' => ['type', 'count']
							]
						],
						'required' => ['data']
					]
				]],
				['data' => ['type' => 'typeA', 'value' => 'test string']], // Should match first schema
				true
			],
			'anyOf: ambiguity resolved at second level without enums' => [
				['anyOf' => [
					[
						'type' => 'object',
						'properties' => [
							'data' => [
								'type' => 'object',
								'properties' => [
									'type' => ['type' => 'string'],
									'value' => ['type' => 'string']
								],
								'required' => ['type', 'value']
							]
						],
						'required' => ['data']
					],
					[
						'type' => 'object',
						'properties' => [
							'data' => [
								'type' => 'object',
								'properties' => [
									'name' => ['type' => 'string'],
									'count' => ['type' => 'integer']
								],
								'required' => ['name', 'count']
							]
						],
						'required' => ['data']
					]
				]],
				['data' => ['name' => 'test name', 'count' => 123]], // Should match first schema
				true
			],
			'anyOf: invalid at second level' => [
				['anyOf' => [
					[
						'type' => 'object',
						'properties' => [
							'data' => [
								'type' => 'object',
								'properties' => [
									'type' => ['type' => 'string', 'enum' => ['typeA']],
									'value' => ['type' => 'string']
								],
								'required' => ['type', 'value']
							]
						],
						'required' => ['data']
					],
					[
						'type' => 'object',
						'properties' => [
							'data' => [
								'type' => 'object',
								'properties' => [
									'type' => ['type' => 'string', 'enum' => ['typeB']],
									'count' => ['type' => 'integer']
								],
								'required' => ['type', 'count']
							]
						],
						'required' => ['data']
					]
				]],
				['data' => ['type' => 'typeA', 'count' => 123]], // "typeA" but missing required "value"
				false,
				'Value did not match any of the allowed shapes: object.'
			],
			'anyOf: ambiguity unresolved at second level without enums' => [
				['anyOf' => [
					[
						'type' => 'object',
						'properties' => [
							'data' => [
								'type' => 'object',
								'properties' => [
									'type' => ['type' => 'string'],
									'value' => ['type' => 'string']
								],
								'required' => ['type', 'value']
							]
						],
						'required' => ['data']
					],
					[
						'type' => 'object',
						'properties' => [
							'data' => [
								'type' => 'object',
								'properties' => [
									'name' => ['type' => 'string'],
									'count' => ['type' => 'integer']
								],
								'required' => ['name', 'count']
							]
						],
						'required' => ['data']
					]
				]],
				['data' => ['lastName' => 'test name', 'count' => 123]], // Should match first schema
				false,
				'Value did not match any of the allowed shapes: object.'
			],
		];
	}

	/**
	 * @dataProvider anyOfProvider
	 */
	public function testAnyOfValidation(array $schema, $value, bool $shouldBeValid, string $expectedErrorMessage = null) {
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate($value);
		$this->assertSame($shouldBeValid, $result->valid);
		if (!$shouldBeValid && $expectedErrorMessage) {
			$this->assertNotEmpty($result->errors);
			$this->assertStringContainsString($expectedErrorMessage, $result->errors[0]->message);
			// Check for explanation in aggregate errors
			if (count($result->errors) > 1 && $result->errors[0]->type === Issue::TYPE_ISSUE) {
				$hasExplanation = false;
				for ($i = 1; $i < count($result->errors); $i++) {
					if ($result->errors[$i]->type === Issue::TYPE_EXPLANATION) {
						$hasExplanation = true;
						break;
					}
				}
				// This assertion might be too strict depending on how explanations are added for all anyOf failures.
				$this->assertTrue($hasExplanation, "Expected an explanation for anyOf failure.");
			}
		}
	}

	// Test oneOf
	public static function oneOfProvider(): array {
		return [
			'oneOf: matches first schema (string)' => [
				['oneOf' => [ ['type' => 'string'], ['type' => 'integer'] ]],
				'i am a string',
				true
			],
			'oneOf: matches second schema (integer)' => [
				['oneOf' => [ ['type' => 'string'], ['type' => 'integer'] ]],
				123,
				true
			],
			'oneOf: matches no schema (boolean given)' => [
				['oneOf' => [ ['type' => 'string'], ['type' => 'integer'] ]],
				true,
				false,
				'Expected one of [string, integer] here, but got boolean.'
			],
			'oneOf: matches multiple schemas (number and integer for an int)' => [
				['oneOf' => [ ['type' => 'number'], ['type' => 'integer'] ]],
				5, // Matches both 'number' and 'integer'
				false, // oneOf should fail if more than one matches
				'Data matches more than one allowed shape—make it unambiguous.'
			],
			'oneOf: ambiguous object schemas without discriminator' => [
				['oneOf' => [
					['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
					['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]
				]],
				['a' => 'value', 'b' => 'value'],
				false, // Should fail because it matches both schemas
				'Data matches more than one allowed shape—make it unambiguous.'
			],
			'oneOf: ambiguous object schemas with overlapping properties' => [
				['oneOf' => [
					['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'c' => ['type' => 'integer']]],
					['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'd' => ['type' => 'integer']]]
				]],
				['a' => 'value', 'c' => 1, 'd' => 2],
				false, // Should fail because it matches both schemas
				'Data matches more than one allowed shape—make it unambiguous.'
			],
			'oneOf: ambiguous object schemas with missing discriminator' => [
				['oneOf' => [
					['type' => 'object', 'properties' => ['type' => ['enum' => ['A']], 'value' => ['type' => 'string']]],
					['type' => 'object', 'properties' => ['type' => ['enum' => ['B']], 'value' => ['type' => 'string']]]
				]],
				['value' => 'test'],
				false, // Should fail because discriminator is missing
				'Data matches more than one allowed shape—make it unambiguous.'
			],
		];
	}

	/**
	 * @dataProvider oneOfProvider
	 */
	public function testOneOfValidation(array $schema, $value, bool $shouldBeValid, string $expectedErrorMessage = null) {
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate($value);
		$this->assertSame($shouldBeValid, $result->valid);
		if (!$shouldBeValid && $expectedErrorMessage) {
			$this->assertNotEmpty($result->errors);
			$this->assertStringContainsString($expectedErrorMessage, $result->errors[0]->message);
		}
	}

	// Test $ref (local references only)
	public function testLocalRefValidation() {
		$schema = [
			'definitions' => [
				'name' => ['type' => 'string'],
				'user' => [
					'type' => 'object',
					'properties' => [
						'username' => [ '$ref' => '#/definitions/name' ],
						'id' => ['type' => 'integer']
					],
					'required' => ['username', 'id']
				]
			],
			'type' => 'object',
			'properties' => [
				'admin' => [ '$ref' => '#/definitions/user' ]
			]
		];
		$validator = new HumanFriendlySchemaValidator($schema);

		// Valid
		$this->assertTrue($validator->validate(['admin' => ['username' => 'test', 'id' => 1]])->valid);
		
		// Invalid: property type within referenced schema
		$resultInvalidType = $validator->validate(['admin' => ['username' => 'test', 'id' => 'not-an-int']]);
		$this->assertFalse($resultInvalidType->valid);
		$this->assertStringContainsString('Expected integer, got string.', $resultInvalidType->errors[0]->message);
		$this->assertEquals(['root', 'admin', 'id'], $resultInvalidType->errors[0]->path);
	}

	public function testUnsupportedExternalRefThrows() {
		$schema = [ 'type' => 'object', 'properties' => [ 'foo' => [ '$ref' => 'external.json#/foo' ] ] ];
		$validator = new HumanFriendlySchemaValidator($schema);
		$this->expectException(UnsupportedSchemaException::class);
		$this->expectExceptionMessage('Only local #/ refs are supported');
		$validator->validate(['foo' => 'bar']);
	}
	
	public function testInvalidLocalRefPathThrows() {
		$schema = [ 'type' => 'object', 'properties' => [ 'foo' => [ '$ref' => '#/definitions/nonExistent' ] ] ];
		$validator = new HumanFriendlySchemaValidator($schema);
		$this->expectException(UnsupportedSchemaException::class);
		$this->expectExceptionMessage('Reference #/definitions/nonExistent not found');
		$validator->validate(['foo' => 'bar']);
	}

	// Test Error Messages and Paths
	public function testErrorMessageContainsPathAndDetails() {
		$schema = [
			'type' => 'object',
			'properties' => [
				'user' => [
					'type' => 'object',
					'properties' => ['name' => ['type' => 'string']],
					'required' => ['name']
				]
			],
			'required' => ['user']
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate(['user' => []]); // Missing user.name
		$this->assertFalse($result->valid);
		$this->assertCount(1, $result->errors);
		$error = $result->errors[0];
		$this->assertEquals(['root', 'user'], $error->path);
		$this->assertStringContainsString("Missing required field(s): name", $error->message);
		$this->assertArrayHasKey('expected', $error->meta);
		$this->assertArrayHasKey('actual', $error->meta);
	}

	// Test Schema Issues
	public function testUnknownSchemaNode() {
		$schema = [ 'type' => 'object', 'properties' => [ 'foo' => [ 'weirdKeyword' => true ] ] ];
		$this->expectException(UnsupportedSchemaException::class);
		$validator = new HumanFriendlySchemaValidator($schema);
		$validator->validate(['foo' => 'bar']);
	}

	public function testEmptySchema() {
		$schema = []; // No type, no anyOf/oneOf
		$this->expectException(UnsupportedSchemaException::class);
		$validator = new HumanFriendlySchemaValidator($schema);
		$validator->validate('anything');
	}

	public function testUnknownTypeInSchema() {
		$schema = ['type' => 'futureType'];
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate('data');
		$this->assertFalse($result->valid);
		$this->assertStringContainsString('Expected futureType', $result->errors[0]->message);
	}

	// Test Input Variations
	public function testNullInput() {
		$schema = ['type' => 'string'];
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate(null);
		$this->assertFalse($result->valid);
		$this->assertNotEmpty($result->errors);
		$this->assertStringContainsString('Expected string, got NULL.', $result->errors[0]->message); // PHP gettype(null) is "NULL"
	}

	public function testUnexpectedInputTypeResource() {
		$schema = ['type' => 'string'];
		$validator = new HumanFriendlySchemaValidator($schema);
		$resource = fopen('php://memory', 'r');
		$result = $validator->validate($resource);
		fclose($resource);
		$this->assertFalse($result->valid);
		$this->assertStringContainsString('Expected string, got resource.', $result->errors[0]->message);
	}
	
	public function testValidatorDoesNotMutateInput() {
		$schema = ['type' => 'object', 'properties' => ['foo' => ['type' => 'string']]];
		$validator = new HumanFriendlySchemaValidator($schema);
		$input = ['foo' => 'bar'];
		$inputCopy = $input;
		$validator->validate($input); // Call validation
		$this->assertSame($inputCopy, $input, "Input data should not be mutated.");
	}

	// Test Edge Cases
	public function testDeeplyNestedObjects() {
		$schema = [
			'type' => 'object', 'properties' => ['a' => [
				'type' => 'object', 'properties' => ['b' => [
					'type' => 'object', 'properties' => ['c' => [
						'type' => 'string'
					]], 'required' => ['c']
				]], 'required' => ['b']
			]], 'required' => ['a']
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		// Valid
		$this->assertTrue($validator->validate(['a' => ['b' => ['c' => 'ok']]])->valid);
		// Invalid
		$result = $validator->validate(['a' => ['b' => ['d' => 'wrong']]]); // c is missing
		$this->assertFalse($result->valid);
		$this->assertStringContainsString('Missing required field(s): c', $result->errors[0]->message);
		$this->assertEquals(['root', 'a', 'b'], $this->findErrorByPath($result->errors, ['root', 'a', 'b'])->path);
	}
	
	public function testLargeArrayPerformanceStub() {
	    // This is not a true performance test but checks for crashes with large arrays.
		$schema = ['type' => 'array', 'items' => ['type' => 'integer']];
		$validator = new HumanFriendlySchemaValidator($schema);
		$largeArray = range(1, 500); // Reduced from 10000 to avoid excessive test time / memory
		$result = $validator->validate($largeArray);
		$this->assertTrue($result->valid, "Validation of large array failed.");
		// Test invalid large array
		$largeArray[] = "not_an_integer";
		$resultInvalid = $validator->validate($largeArray);
		$this->assertFalse($resultInvalid->valid);
		$this->assertStringContainsString('Expected integer, got string.', $resultInvalid->errors[0]->message);
	}

	public function testDiscriminatorLikeAnyOf() {
		$schema = [
			'anyOf' => [
				[
					'type' => 'object',
					'properties' => [
						'type' => [ 'type' => "string", 'enum' => ['A'] ],
						'propA' => ['type' => 'string']
					],
					'required' => ['type', 'propA']
				],
				[
					'type' => 'object',
					'properties' => [
						'type' => [ 'type' => "string", 'enum' => ['B'] ],
						'propB' => ['type' => 'integer']
					],
					'required' => ['type', 'propB']
				]
			]
		];
		$validator = new HumanFriendlySchemaValidator($schema);

		// Valid type A
		$this->assertTrue($validator->validate(['type' => 'A', 'propA' => 'hello'])->valid);
		// Valid type B
		$this->assertTrue($validator->validate(['type' => 'B', 'propB' => 123])->valid);

		// Invalid: type A data with type B value (missing propA for matched 'A' schema)
		$result1 = $validator->validate(['type' => 'A', 'propB' => 123]);
		$this->assertFalse($result1->valid);
		$this->assertStringContainsString("Missing required field(s): propA", $this->findErrorMessageContaining($result1->errors, "propA"));


		// Invalid: type B data with type A value (missing propB for matched 'B' schema)
		$result2 = $validator->validate(['type' => 'B', 'propA' => 'hello']);
		$this->assertFalse($result2->valid);
		$this->assertStringContainsString("Missing required field(s): propB", $this->findErrorMessageContaining($result2->errors, "propB"));

		// Invalid: unknown type value for discriminator
		$result3 = $validator->validate(['type' => 'C', 'propA' => 'hello']);
		$this->assertFalse($result3->valid);
		$this->assertStringContainsString("The 'type' property must be one of [A, B], but it was \"C\"", $result3->errors[0]->message);
		
		// Invalid: missing type (discriminator property)
		$result4 = $validator->validate(['propA' => 'hello']);
		$this->assertFalse($result4->valid);
        $this->assertStringContainsString("The 'type' property must be one of [A, B], but it was missing", $result4->errors[0]->message);
	}
	
	/**
	 * Helper to find an error message containing a specific substring.
	 */
	private function findErrorMessageContaining(array $errors, string $substring): ?string
	{
		foreach ($errors as $error) {
			if (strpos($error->message, $substring) !== false) {
				return $error->message;
			}
		}
		return null;
	}

    private function findErrorByPath(array $errors, array $path): ?Issue
    {
        foreach ($errors as $error) {
            if ($error->path === $path) {
                return $error;
            }
        }
        return null;
    }
	
	public function testArrayIsValidObjectOption() {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];

        // Default: array is valid object
        $validatorDefault = new HumanFriendlySchemaValidator($schema); // array_is_valid_object defaults to true
        $resultDefault = $validatorDefault->validate(['a' => 'test']); // Using PHP array for object
        $this->assertTrue($resultDefault->valid);

        // Option false: array is NOT valid object
        $validatorStrict = new HumanFriendlySchemaValidator($schema, ['array_is_valid_object' => false]);
        $resultStrict = $validatorStrict->validate(['a' => 'test']); // Using PHP array for object
        $this->assertFalse($resultStrict->valid);
        $this->assertStringContainsString('Expected object, got array.', $resultStrict->errors[0]->message);

        // Still validates actual objects correctly
        $stdClass = new \stdClass();
        $stdClass->a = 'test';
        $resultObject = $validatorStrict->validate($stdClass);
        $this->assertTrue($resultObject->valid);
    }

	// Test for unsupported schema keywords
	public function testAllOfThrows() {
		$schema = ['allOf' => [['type' => 'string'], ['type' => 'string']]];
		$validator = new HumanFriendlySchemaValidator($schema);
		$this->expectException(UnsupportedSchemaException::class);
		$this->expectExceptionMessage('The schema keyword "allOf" is not supported');
		$validator->validate('test');
	}

	public function testNotThrows() {
		$schema = ['not' => ['type' => 'string']];
		$validator = new HumanFriendlySchemaValidator($schema);
		$this->expectException(UnsupportedSchemaException::class);
		$this->expectExceptionMessage('The schema keyword "not" is not supported');
		$validator->validate('test');
	}

	public function testPatternThrows() {
		$schema = ['type' => 'string', 'pattern' => '^[a-z]+$'];
		$validator = new HumanFriendlySchemaValidator($schema);
		$this->expectException(UnsupportedSchemaException::class);
		$this->expectExceptionMessage('The string constraint "pattern" is not supported');
		$validator->validate('test');
	}

	public function testMinimumThrows() {
		$schema = ['type' => 'number', 'minimum' => 5];
		$validator = new HumanFriendlySchemaValidator($schema);
		$this->expectException(UnsupportedSchemaException::class);
		$this->expectExceptionMessage('The numeric constraint "minimum" is not supported');
		$validator->validate(10);
	}

	public function testMaximumThrows() {
		$schema = ['type' => 'integer', 'maximum' => 100];
		$validator = new HumanFriendlySchemaValidator($schema);
		$this->expectException(UnsupportedSchemaException::class);
		$this->expectExceptionMessage('The numeric constraint "maximum" is not supported');
		$validator->validate(50);
	}

	public function testUniqueItemsThrows() {
		$schema = ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true];
		$validator = new HumanFriendlySchemaValidator($schema);
		$this->expectException(UnsupportedSchemaException::class);
		$this->expectExceptionMessage('The array constraint "uniqueItems" is not supported');
		$validator->validate(['a', 'b', 'c']);
	}

	public function testTypeAsArrayThrows() {
		$schema = ['type' => ['string', 'integer']];
		$validator = new HumanFriendlySchemaValidator($schema);
		$this->expectException(UnsupportedSchemaException::class);
		$this->expectExceptionMessage("Defining 'type' as an array of types is not supported");
		$validator->validate('test');
	}

	public function testEnumMismatchedTypeThrows() {
		$schema = ['type' => 'string', 'enum' => ['valid', 123]]; // 123 is not a string
		$validator = new HumanFriendlySchemaValidator($schema);
		$this->expectException(UnsupportedSchemaException::class);
		$this->expectExceptionMessage('Enum value 123 does not match the declared type "string"');
		$validator->validate('valid');
	}

}
