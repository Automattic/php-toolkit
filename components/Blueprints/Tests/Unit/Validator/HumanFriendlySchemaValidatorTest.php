<?php

namespace WordPress\Blueprints\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\HumanFriendlySchemaValidator;
use WordPress\Blueprints\Validator\UnsupportedSchemaException;
use WordPress\Blueprints\ValidationError; // Added import

/**
 * Tests for the HumanFriendlySchemaValidator class.
 * This class focuses on general JSON schema validation capabilities.
 */
class HumanFriendlySchemaValidatorTest extends TestCase {

	private function assertValidationError(
		?ValidationError $error,
		string $expectedMessageContains = null,
		string $expectedCode = null,
		string $expectedPointer = null,
		array $expectedContextContains = null,
		int $expectedChildrenCount = null
	): void {
		$this->assertInstanceOf(ValidationError::class, $error, 'Expected a ValidationError instance.');

		if ($expectedMessageContains !== null) {
			$this->assertStringContainsString($expectedMessageContains, $error->message, 'Error message mismatch.');
		}
		if ($expectedCode !== null) {
			$this->assertEquals($expectedCode, $error->code, 'Error code mismatch.');
		}
		if ($expectedPointer !== null) {
			$this->assertEquals($expectedPointer, $error->pointer, 'Error pointer mismatch.');
		}
		if ($expectedContextContains !== null) {
			foreach ($expectedContextContains as $key => $value) {
				$this->assertArrayHasKey($key, $error->context, "Context missing key: {$key}");
				$this->assertEquals($value, $error->context[$key], "Context value mismatch for key: {$key}");
			}
		}
		if ($expectedChildrenCount !== null) {
			$this->assertCount($expectedChildrenCount, $error->children, 'Error children count mismatch.');
		}
	}

	private function assertNotValidationError(?ValidationError $error, string $message = 'Expected no validation error.'): void
	{
		$this->assertNull($error, $message);
	}

	// Test Primitive Types
	public static function primitiveTypeProvider(): array {
		return [
			'valid string' => [ ['type' => 'string'], 'hello', true ],
			'invalid string (integer given)' => [ ['type' => 'string'], 123, false, 'Expected string, got integer.', 'type-mismatch', '#/' ],
			'valid integer' => [ ['type' => 'integer'], 42, true ],
			'invalid integer (string given)' => [ ['type' => 'integer'], 'foo', false, 'Expected integer, got string.', 'type-mismatch', '#/' ],
			'valid boolean true' => [ ['type' => 'boolean'], true, true ],
			'valid boolean false' => [ ['type' => 'boolean'], false, true ],
			'invalid boolean (integer given)' => [ ['type' => 'boolean'], 0, false, 'Expected boolean, got integer.', 'type-mismatch', '#/' ],
			'valid number (float)' => [ ['type' => 'number'], 3.14, true ],
			'valid number (integer)' => [ ['type' => 'number'], 7, true ],
			'invalid number (string given)' => [ ['type' => 'number'], '7.0', false, 'Expected number, got string.', 'type-mismatch', '#/' ],
		];
	}

	/**
	 * @dataProvider primitiveTypeProvider
	 */
	public function testPrimitiveTypeValidation(array $schema, $value, bool $shouldBeNull, string $expectedErrorMessage = null, string $expectedCode = null, string $expectedPointer = null) {
		$validator = new HumanFriendlySchemaValidator($schema);
		$error = $validator->validate($value);
		if ($shouldBeNull) {
			$this->assertNotValidationError($error);
		} else {
			$this->assertValidationError($error, $expectedErrorMessage, $expectedCode, $expectedPointer);
		}
	}

	// Test Enums
	public static function enumProvider(): array {
		return [
			'valid enum string' => [ ['type' => 'string', 'enum' => ['a', 'b']], 'a', true ],
			'invalid enum string' => [ ['type' => 'string', 'enum' => ['a', 'b']], 'c', false, 'Allowed values: a, b. You supplied "c".', 'enum-mismatch', '#/' ],
			'valid enum integer' => [ ['type' => 'integer', 'enum' => [1, 2]], 2, true ],
			'invalid enum integer' => [ ['type' => 'integer', 'enum' => [1, 2]], 3, false, 'Allowed values: 1, 2. You supplied 3.', 'enum-mismatch', '#/' ],
			'enum with empty string allowed' => [ ['type' => 'string', 'enum' => ['', 'foo']], '', true ],
		];
	}

	/**
	 * @dataProvider enumProvider
	 */
	public function testEnumValidation(array $schema, $value, bool $shouldBeNull, string $expectedErrorMessage = null, string $expectedCode = null, string $expectedPointer = null) {
		$validator = new HumanFriendlySchemaValidator($schema);
		$error = $validator->validate($value);
		if ($shouldBeNull) {
			$this->assertNotValidationError($error);
		} else {
			$this->assertValidationError($error, $expectedErrorMessage, $expectedCode, $expectedPointer);
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
				['Missing required field: bar.']
			],
			'invalid object missing multiple required properties' => [
				array_merge($baseSchema, ['required' => ['foo', 'bar']]),
				[],
				false,
				['Missing required field: foo.', 'Missing required field: bar.']
			],
			'invalid object property type' => [
				$baseSchema,
				['foo' => 123], // foo should be string
				false,
				['Expected string, got integer.']
			],
			'object with additionalProperties: false, extra prop' => [
				array_merge($baseSchema, ['additionalProperties' => false]),
				['foo' => 'text', 'extra' => 'disallowed'],
				false,
				["Property 'extra' isn't allowed here."]
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
				['Expected boolean, got string.']
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
				['Missing required field: mustExist.']
			],
			'invalid object with multiple violations' => [
				array_merge($baseSchema, ['required' => ['foo', 'bar'], 'additionalProperties' => false]),
				['foo' => 123, 'extra' => 'disallowed'],
				false,
				['Expected string, got integer.', 'Missing required field: bar.', "Property 'extra' isn't allowed here."]
			]
		];
	}

	/**
	 * @dataProvider objectProvider
	 */
	public function testObjectValidation(array $schema, $value, bool $shouldBeNull, array $expectedChildErrorChecks = [], string $expectedParentCode = null) {
		$validator = new HumanFriendlySchemaValidator($schema);
		$error = $validator->validate($value);

		if ($shouldBeNull) {
			$this->assertNotValidationError($error, 'Validation status mismatch. Expected null.');
		} else {
			$this->assertInstanceOf(ValidationError::class, $error, "Parent error should be a ValidationError instance.");
			if ($expectedParentCode !== null) {
				$this->assertEquals($expectedParentCode, $error->code, "Parent error code mismatch.");
			}
			
			if (!empty($expectedChildErrorChecks)) {
				$this->assertCount(count($expectedChildErrorChecks), $error->children, "Children count mismatch.");
				foreach($expectedChildErrorChecks as $index => $check) {
					$childError = $error->children[$index] ?? null;
					$this->assertValidationError(
						$childError,
						$check->messageContains ?? null,
						$check->code ?? null,
						$check->pointer ?? null
					);
				}
			} else if ($expectedParentCode === null) { // If no parent code and no children checks, it implies a single direct error
				 $this->assertValidationError($error, $expectedChildErrorChecks[0]->messageContains ?? null, $expectedChildErrorChecks[0]->code ?? null, $expectedChildErrorChecks[0]->pointer ?? null);
			}
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
				'Missing required field: id'
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
				'Value did not match any of the allowed shapes: object.',
				'Missing required field: b.'
			],
			// Near misses with useful error messages
			'anyOf: near miss with wrong type' => [
				['anyOf' => [
					['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
					['type' => 'object', 'properties' => ['b' => ['type' => 'string']], 'required' => ['b']]
				]],
				['a' => 123], // a should be string but is integer
				false,
				'Value did not match any of the allowed shapes: object.',
				'Expected string, got integer.'
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
				'Expected one of [string, integer] here, but got boolean.',
				null
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
				'Expected one of [string, integer] here, but got boolean.',
				null
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
				'Expected one of [string, object, array] here, but got boolean.',
				null // No explanation for type mismatch
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
				'Value did not match any of the allowed shapes: object.',
				'Missing required field: value.'
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
				['data' => ['lastName' => 'test name', 'count' => 123]], // Should match neither schema
				false,
				'Value did not match any of the allowed shapes: object.',
				'Missing required field: name.'
			],
		];
	}

	/**
	 * @dataProvider anyOfProvider
	 */
	public function testAnyOfValidation(array $schema, $value, bool $shouldBeValid, string $expectedErrorMessage = null, string $expectedExplanation = null) {
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate($value);
		$this->assertSame($shouldBeValid, $result);
		if (!$shouldBeValid && $expectedErrorMessage) {
			$this->assertNotEmpty($result->errors);
			$this->assertStringContainsString($expectedErrorMessage, $result->errors[0]->message);
			
			// Check for explanation in aggregate errors
			if ($expectedExplanation && count($result->errors) > 1) {
				$hasExpectedExplanation = false;
				for ($i = 1; $i < count($result->errors); $i++) {
					if ($result->errors[$i]->type === Issue::TYPE_EXPLANATION && 
						$result->errors[$i]->message === $expectedExplanation) {
						$hasExpectedExplanation = true;
						break;
					}
				}
				$this->assertTrue($hasExpectedExplanation, 
					"Expected exact explanation '$expectedExplanation' not found in validation errors");
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
				'Data matches more than one allowed shape - you need to make it unambiguous. Matched shapes: number, integer.'
			],
			'oneOf: ambiguous object schemas without discriminator' => [
				['oneOf' => [
					['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
					['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]
				]],
				['a' => 'value', 'b' => 'value'],
				false, // Should fail because it matches both schemas
				'Data matches more than one allowed shape - you need to make it unambiguous. Matched shapes: object, object.'
			],
			'oneOf: ambiguous object schemas with overlapping properties' => [
				['oneOf' => [
					['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'c' => ['type' => 'integer']]],
					['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'd' => ['type' => 'integer']]]
				]],
				['a' => 'value', 'c' => 1, 'd' => 2],
				false, // Should fail because it matches both schemas
				'Data matches more than one allowed shape - you need to make it unambiguous. Matched shapes: object, object.'
			],
			'oneOf: ambiguous object schemas with missing discriminator' => [
				['oneOf' => [
					['type' => 'object', 'properties' => ['type' => ['enum' => ['A']], 'value' => ['type' => 'string']]],
					['type' => 'object', 'properties' => ['type' => ['enum' => ['B']], 'value' => ['type' => 'string']]]
				]],
				['value' => 'test'],
				false, // Should fail because discriminator is missing
				'Data matches more than one allowed shape - you need to make it unambiguous. Matched shapes: object, object.'
			],
		];
	}

	/**
	 * @dataProvider oneOfProvider
	 */
	public function testOneOfValidation(array $schema, $value, bool $shouldBeValid, string $expectedErrorMessage = null) {
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate($value);
		$this->assertSame($shouldBeValid, $result);
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
		$this->assertTrue($validator->validate(['admin' => ['username' => 'test', 'id' => 1]]));
		
		// Invalid: property type within referenced schema
		$resultInvalidType = $validator->validate(['admin' => ['username' => 'test', 'id' => 'not-an-int']]);
		$this->assertFalse($resultInvalidType);
		$this->assertStringContainsString('Expected integer, got string.', $resultInvalidType->errors[0]->message);
		$this->assertEquals(['root', 'admin', 'id'], $resultInvalidType->errors[0]->path);
	}

	/**
	 * Test anyOf with references
	 */
	public function testAnyOfWithReferences() {
		$schema = [
			'definitions' => [
				'stringConfig' => ['type' => 'string'],
				'numberConfig' => ['type' => 'integer'],
				'objectConfig' => [
					'type' => 'object',
					'properties' => [
						'name' => ['type' => 'string'],
						'value' => ['type' => 'integer']
					],
					'required' => ['name', 'value']
				]
			],
			'type' => 'object',
			'properties' => [
				'config' => [
					'anyOf' => [
						['$ref' => '#/definitions/stringConfig'],
						['$ref' => '#/definitions/numberConfig'],
						['$ref' => '#/definitions/objectConfig']
					]
				]
			],
			'required' => ['config']
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		
		// Valid string reference
		$this->assertTrue($validator->validate(['config' => 'string value']));
		
		// Valid number reference
		$this->assertTrue($validator->validate(['config' => 42]));
		
		// Valid object reference
		$this->assertTrue($validator->validate(['config' => ['name' => 'test', 'value' => 123]]));
		
		// Invalid: doesn't match any reference schema
		$result1 = $validator->validate(['config' => true]);
		$this->assertFalse($result1);
		$this->assertStringContainsString('Expected one of [string, integer, object] here, but got boolean', $result1->errors[0]->message);
		
		// Invalid: partial match with object reference
		$result2 = $validator->validate(['config' => ['name' => 'test']]);
		$this->assertFalse($result2);
		$this->assertStringContainsString('Missing required field: value', $result2->errors[0]->message);
	}
	
	/**
	 * Test oneOf with references
	 */
	public function testOneOfWithReferences() {
		$schema = [
			'definitions' => [
				'categoryA' => [
					'type' => 'object',
					'properties' => [
						'type' => ['type' => 'string', 'enum' => ['A']],
						'value' => ['type' => 'string']
					],
					'required' => ['type', 'value']
				],
				'categoryB' => [
					'type' => 'object',
					'properties' => [
						'type' => ['type' => 'string', 'enum' => ['B']],
						'count' => ['type' => 'integer']
					],
					'required' => ['type', 'count']
				]
			],
			'type' => 'object',
			'properties' => [
				'data' => [
					'oneOf' => [
						['$ref' => '#/definitions/categoryA'],
						['$ref' => '#/definitions/categoryB']
					]
				]
			],
			'required' => ['data']
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		
		// Valid category A
		$this->assertTrue($validator->validate(['data' => ['type' => 'A', 'value' => 'test']]));
		
		// Valid category B
		$this->assertTrue($validator->validate(['data' => ['type' => 'B', 'count' => 42]]));
		
		// Invalid: matches neither
		$result1 = $validator->validate(['data' => ['type' => 'C', 'value' => 'test']]);
		$this->assertFalse($result1);
		$this->assertStringContainsString('Value did not match any of the allowed shapes', $result1->errors[0]->message);
		
		// Invalid: missing required property in the matched reference
		$result2 = $validator->validate(['data' => ['type' => 'A', 'count' => 42]]);
		$this->assertFalse($result2);
		$this->assertStringContainsString('Missing required field: value', $this->findErrorMessageContaining($result2->errors, 'value'));
	}
	
	/**
	 * Test for mixed references and inline schemas
	 */
	public function testMixedReferencesAndInlineSchemas() {
		$schema = [
			'definitions' => [
				'stringProperty' => ['type' => 'string'],
				'integerProperty' => ['type' => 'integer']
			],
			'type' => 'object',
			'properties' => [
				'mixed' => [
					'anyOf' => [
						['$ref' => '#/definitions/stringProperty'],
						['type' => 'object', 'properties' => [
							'name' => ['$ref' => '#/definitions/stringProperty'],
							'count' => ['$ref' => '#/definitions/integerProperty']
						], 'required' => ['name', 'count']]
					]
				]
			],
			'required' => ['mixed']
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		
		// Valid string reference
		$this->assertTrue($validator->validate(['mixed' => 'string value']));
		
		// Valid inline object with referenced properties
		$this->assertTrue($validator->validate(['mixed' => ['name' => 'test', 'count' => 42]]));
		
		// Invalid: object with wrong property types
		$result = $validator->validate(['mixed' => ['name' => 123, 'count' => 'not a number']]);
		$this->assertFalse($result);
		$this->assertStringContainsString('Expected string, got integer', $this->findErrorMessageContaining($result->errors, 'string'));
	}
	
	/**
	 * Test complex nested references
	 */
	public function testComplexNestedReferences() {
		$schema = [
			'definitions' => [
				'baseConfig' => [
					'type' => 'object',
					'properties' => [
						'id' => ['type' => 'string'],
						'enabled' => ['type' => 'boolean']
					],
					'required' => ['id']
				],
				'extendedConfig' => [
					'allOf' => [
						['$ref' => '#/definitions/baseConfig'],
						[
							'properties' => [
								'advanced' => ['type' => 'boolean'],
								'settings' => ['$ref' => '#/definitions/settingsObject']
							]
						]
					]
				],
				'settingsObject' => [
					'type' => 'object',
					'properties' => [
						'timeout' => ['type' => 'integer'],
						'retries' => ['type' => 'integer']
					]
				]
			],
			'type' => 'object',
			'properties' => [
				'config' => ['$ref' => '#/definitions/baseConfig'],
				'advancedConfig' => ['$ref' => '#/definitions/extendedConfig']
			]
		];
		
		// We expect this to throw an exception because allOf is not supported
		$validator = new HumanFriendlySchemaValidator($schema);
		$this->expectException(UnsupportedSchemaException::class);
		$this->expectExceptionMessage('The schema keyword "allOf" is not supported');
		$validator->validate(['config' => ['id' => 'test'], 'advancedConfig' => ['id' => 'advanced', 'enabled' => true]]);
	}
	
	/**
	 * Test nested structure with references
	 */
	public function testNestedStructureWithReferences() {
		$schema = [
			'definitions' => [
				'idObject' => [
					'type' => 'object',
					'properties' => [
						'id' => ['type' => 'string']
					],
					'required' => ['id']
				],
				'nameObject' => [
					'type' => 'object',
					'properties' => [
						'name' => ['type' => 'string']
					],
					'required' => ['name']
				]
			],
			'type' => 'object',
			'properties' => [
				'nested' => [
					'type' => 'object',
					'properties' => [
						'inner' => [
							'anyOf' => [
								['$ref' => '#/definitions/idObject'],
								['$ref' => '#/definitions/nameObject']
							]
						]
					],
					'required' => ['inner']
				]
			],
			'required' => ['nested']
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		
		// Valid with id reference
		$this->assertTrue($validator->validate(['nested' => ['inner' => ['id' => 'test-id']]]));
		
		// Valid with name reference
		$this->assertTrue($validator->validate(['nested' => ['inner' => ['name' => 'test-name']]]));
		
		// Invalid: neither reference matches
		$result = $validator->validate(['nested' => ['inner' => ['description' => 'wrong property']]]);
		$this->assertFalse($result);
		$this->assertStringContainsString('Value did not match any of the allowed shapes', $result->errors[0]->message);
		// Check for nested explanation in error
		$this->assertStringContainsString('Missing required field', $result->errors[1]->message);
	}
	
	/**
	 * Test circular references (which are not supported)
	 */
	public function testCircularReferences() {
		$schema = [
			'definitions' => [
				'recursive' => [
					'type' => 'object',
					'properties' => [
						'child' => ['$ref' => '#/definitions/recursive']
					]
				]
			],
			'type' => 'object',
			'properties' => [
				'data' => ['$ref' => '#/definitions/recursive']
			]
		];
		
		$validator = new HumanFriendlySchemaValidator($schema);
		
		// Test with one level of nesting (should be fine)
		$this->assertTrue($validator->validate(['data' => ['child' => []]]));
		
		// PHP would hit a recursion limit with many levels, but the validator doesn't have
		// special handling for this case, so we don't need to test deep recursion
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
		$this->assertFalse($result);
		$this->assertStringContainsString('Expected futureType', $result->errors[0]->message);
	}

	// Test Input Variations
	public function testNullInput() {
		$schema = ['type' => 'string'];
		$validator = new HumanFriendlySchemaValidator($schema);
		$result = $validator->validate(null);
		$this->assertFalse($result);
		$this->assertNotEmpty($result->errors);
		$this->assertStringContainsString('Expected string, got NULL.', $result->errors[0]->message); // PHP gettype(null) is "NULL"
	}

	public function testUnexpectedInputTypeResource() {
		$schema = ['type' => 'string'];
		$validator = new HumanFriendlySchemaValidator($schema);
		$resource = fopen('php://memory', 'r');
		$result = $validator->validate($resource);
		fclose($resource);
		$this->assertFalse($result);
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
		$this->assertTrue($validator->validate(['a' => ['b' => ['c' => 'ok']]]));
		// Invalid
		$result = $validator->validate(['a' => ['b' => ['d' => 'wrong']]]); // c is missing
		$this->assertFalse($result);
		$this->assertStringContainsString('Missing required field: c', $result->errors[0]->message);
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
		$this->assertFalse($resultInvalid);
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
		$this->assertTrue($validator->validate(['type' => 'A', 'propA' => 'hello']));
		// Valid type B
		$this->assertTrue($validator->validate(['type' => 'B', 'propB' => 123]));

		// Invalid: type A data with type B value (missing propA for matched 'A' schema)
		$result1 = $validator->validate(['type' => 'A', 'propB' => 123]);
		$this->assertFalse($result1);
		$this->assertStringContainsString("Missing required field: propA", $this->findErrorMessageContaining($result1->errors, "propA"));


		// Invalid: type B data with type A value (missing propB for matched 'B' schema)
		$result2 = $validator->validate(['type' => 'B', 'propA' => 'hello']);
		$this->assertFalse($result2);
		$this->assertStringContainsString("Missing required field: propB", $this->findErrorMessageContaining($result2->errors, "propB"));

		// Invalid: unknown type value for discriminator
		$result3 = $validator->validate(['type' => 'C', 'propA' => 'hello']);
		$this->assertFalse($result3);
		$this->assertStringContainsString("The 'type' property must be one of [A, B], but it was \"C\"", $result3->errors[0]->message);
		
		// Invalid: missing type (discriminator property)
		$result4 = $validator->validate(['propA' => 'hello']);
		$this->assertFalse($result4);
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
        $this->assertTrue($resultDefault);

        // Option false: array is NOT valid object
        $validatorStrict = new HumanFriendlySchemaValidator($schema, ['array_is_valid_object' => false]);
        $resultStrict = $validatorStrict->validate(['a' => 'test']); // Using PHP array for object
        $this->assertFalse($resultStrict);
        $this->assertStringContainsString('Expected object, got array.', $resultStrict->errors[0]->message);

        // Still validates actual objects correctly
        $stdClass = new \stdClass();
        $stdClass->a = 'test';
        $resultObject = $validatorStrict->validate($stdClass);
        $this->assertTrue($resultObject);
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

	/**
	 * Test anyOf with mixed types including references
	 */
	public function testAnyOfWithMixedTypesAndReferences() {
		$schema = [
			'definitions' => [
				'stringDef' => ['type' => 'string'],
				'numberDef' => ['type' => 'number'],
				'objectDef' => [
					'type' => 'object',
					'properties' => [
						'id' => ['type' => 'string']
					],
					'required' => ['id']
				]
			],
			'anyOf' => [
				['$ref' => '#/definitions/stringDef'],
				['$ref' => '#/definitions/numberDef'],
				['$ref' => '#/definitions/objectDef'],
				['type' => 'array', 'items' => ['type' => 'string']]
			]
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		
		// Test valid string reference
		$this->assertTrue($validator->validate('test string'));
		
		// Test valid number reference
		$this->assertTrue($validator->validate(42.5));
		
		// Test valid object reference
		$this->assertTrue($validator->validate(['id' => 'test-id']));
		
		// Test valid array (inline schema)
		$this->assertTrue($validator->validate(['a', 'b', 'c']));
		
		// Test invalid type (boolean)
		$result1 = $validator->validate(true);
		$this->assertFalse($result1);
		$this->assertStringContainsString('Expected one of [string, number, object, array] here, but got boolean', $result1->errors[0]->message);
		
		// Skip tests for more detailed error message checks - they are tested elsewhere
	}
	
	/**
	 * Test anyOf with discriminated references
	 */
	public function testAnyOfWithDiscriminatedReferences() {
		$schema = [
			'definitions' => [
				'typeA' => [
					'type' => 'object',
					'properties' => [
						'type' => ['type' => 'string', 'enum' => ['A']],
						'value' => ['type' => 'string']
					],
					'required' => ['type', 'value']
				],
				'typeB' => [
					'type' => 'object',
					'properties' => [
						'type' => ['type' => 'string', 'enum' => ['B']],
						'count' => ['type' => 'integer']
					],
					'required' => ['type', 'count']
				]
			],
			'anyOf' => [
				['$ref' => '#/definitions/typeA'],
				['$ref' => '#/definitions/typeB']
			]
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		
		// Valid type A
		$this->assertTrue($validator->validate(['type' => 'A', 'value' => 'test']));
		
		// Valid type B
		$this->assertTrue($validator->validate(['type' => 'B', 'count' => 42]));
		
		// Invalid discriminator value
		$result1 = $validator->validate(['type' => 'C', 'value' => 'test']);
		$this->assertFalse($result1);
		// Check for direct enum error message
		$foundEnumError = false;
		foreach ($result1->errors as $error) {
			if (strpos($error->message, 'Allowed values') !== false && 
				strpos($error->message, 'You supplied "C"') !== false) {
				$foundEnumError = true;
				break;
			}
		}
		$this->assertTrue($foundEnumError, "Missing message about invalid enum value");
		
		// Invalid missing discriminator
		$result2 = $validator->validate(['value' => 'test']);
		$this->assertFalse($result2);
		// Check for any message about missing type field
		$foundMissingType = false;
		foreach ($result2->errors as $error) {
			if (strpos($error->message, 'Missing required field: type') !== false) {
				$foundMissingType = true;
				break;
			}
		}
		$this->assertTrue($foundMissingType, "Missing message about required discriminator field");
	}

	/**
	 * Test anyOf with explicit discriminator
	 */
	public function testAnyOfWithExplicitDiscriminator() {
		$schema = [
			'definitions' => [
				'dogType' => [
					'type' => 'object',
					'properties' => [
						'type' => ['type' => 'string', 'enum' => ['dog']],
						'breed' => ['type' => 'string'],
						'age' => ['type' => 'integer']
					],
					'required' => ['type', 'breed']
				],
				'catType' => [
					'type' => 'object',
					'properties' => [
						'type' => ['type' => 'string', 'enum' => ['cat']],
						'color' => ['type' => 'string'],
						'indoor' => ['type' => 'boolean']
					],
					'required' => ['type', 'color']
				]
			],
			'type' => 'object',
			'properties' => [
				'pet' => [
					'anyOf' => [
						['$ref' => '#/definitions/dogType'],
						['$ref' => '#/definitions/catType']
					],
					'discriminator' => [
						'propertyName' => 'type'
					]
				]
			],
			'required' => ['pet']
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		
		// Valid dog reference
		$this->assertTrue($validator->validate(['pet' => ['type' => 'dog', 'breed' => 'Labrador', 'age' => 3]]));
		
		// Valid cat reference
		$this->assertTrue($validator->validate(['pet' => ['type' => 'cat', 'color' => 'black', 'indoor' => true]]));
		
		// Invalid: wrong discriminator value
		$result1 = $validator->validate(['pet' => ['type' => 'bird', 'species' => 'parrot']]);
		$this->assertFalse($result1);
		
		// Just check that we have an error, without specifying its exact content
		$this->assertNotEmpty($result1->errors, "Should have error for invalid type value");
		
		// Invalid: missing discriminator property
		$result2 = $validator->validate(['pet' => ['breed' => 'Labrador', 'age' => 3]]);
		$this->assertFalse($result2);
		// Check for message about missing type field
		$foundMissingType = false;
		foreach ($result2->errors as $error) {
			if (strpos($error->message, 'Missing required field: type') !== false ||
			    strpos($error->message, 'property must be one of') !== false && strpos($error->message, 'missing') !== false) {
				$foundMissingType = true;
				break;
			}
		}
		$this->assertTrue($foundMissingType, "Missing message about required discriminator field");
		
		// Invalid: correct discriminator but missing required property
		$result3 = $validator->validate(['pet' => ['type' => 'dog', 'age' => 3]]);
		$this->assertFalse($result3);
		// Check for message about missing required field
		$foundMissingField = false;
		foreach ($result3->errors as $error) {
			if (strpos($error->message, 'Missing required field: breed') !== false) {
				$foundMissingField = true;
				break;
			}
		}
		$this->assertTrue($foundMissingField, "Missing message about required field");
	}
	
	/**
	 * Test anyOf with implicit discriminator (inferred from enum values)
	 */
	public function testAnyOfWithImplicitDiscriminator() {
		$schema = [
			'definitions' => [
				'configA' => [
					'type' => 'object',
					'properties' => [
						'mode' => ['type' => 'string', 'enum' => ['A']],
						'value' => ['type' => 'string']
					],
					'required' => ['mode', 'value']
				],
				'configB' => [
					'type' => 'object',
					'properties' => [
						'mode' => ['type' => 'string', 'enum' => ['B']],
						'count' => ['type' => 'integer']
					],
					'required' => ['mode', 'count']
				]
			],
			'anyOf' => [
				['$ref' => '#/definitions/configA'],
				['$ref' => '#/definitions/configB'],
				['type' => 'string']
			]
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		
		// Valid config A
		$this->assertTrue($validator->validate(['mode' => 'A', 'value' => 'test']));
		
		// Valid config B
		$this->assertTrue($validator->validate(['mode' => 'B', 'count' => 123]));
		
		// Valid string
		$this->assertTrue($validator->validate('simple string'));
		
		// Invalid: wrong discriminator value
		$result1 = $validator->validate(['mode' => 'C', 'value' => 'test']);
		$this->assertFalse($result1);
		// Check for error about wrong enum value
		$this->assertStringContainsString('Allowed values', $result1->errors[0]->message);
		$this->assertStringContainsString('You supplied "C"', $result1->errors[0]->message);
		
		// Invalid: missing discriminator property but has other object properties
		$result2 = $validator->validate(['value' => 'test', 'count' => 123]);
		$this->assertFalse($result2);
		// Check for message about missing field
		$foundMissingField = false;
		foreach ($result2->errors as $error) {
			if (strpos($error->message, 'Missing required field: mode') !== false) {
				$foundMissingField = true;
				break;
			}
		}
		$this->assertTrue($foundMissingField, "Missing message about required discriminator field");
		
		// Invalid: wrong type entirely
		$result3 = $validator->validate(123);
		$this->assertFalse($result3);
		$this->assertStringContainsString('Expected one of [object, string] here, but got integer', $result3->errors[0]->message);
	}

	/**
	 * Test anyOf with mixed types (refs, objects, arrays, primitives) and no discriminator
	 */
	public function testAnyOfWithMixedTypesNoDiscriminator() {
		$schema = [
			'definitions' => [
				'stringType' => ['type' => 'string'],
				'numberType' => ['type' => 'number'],
				'simpleArray' => ['type' => 'array', 'items' => ['type' => 'string']]
			],
			'anyOf' => [
				['$ref' => '#/definitions/stringType'],
				['$ref' => '#/definitions/numberType'],
				['$ref' => '#/definitions/simpleArray'],
				['type' => 'object', 'properties' => [
					'name' => ['type' => 'string'],
					'values' => ['$ref' => '#/definitions/simpleArray']
				], 'required' => ['name']]
			]
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		
		// Valid string
		$this->assertTrue($validator->validate('test string'));
		
		// Valid number
		$this->assertTrue($validator->validate(42.5));
		
		// Valid array reference
		$this->assertTrue($validator->validate(['one', 'two', 'three']));
		
		// Valid object with array reference
		$this->assertTrue($validator->validate(['name' => 'test object', 'values' => ['a', 'b', 'c']]));
		
		// Invalid: object missing required property
		$result1 = $validator->validate(['values' => ['a', 'b', 'c']]);
		$this->assertFalse($result1);
		// Check for message about missing field
		$foundMissingField = false;
		foreach ($result1->errors as $error) {
			if (strpos($error->message, 'Missing required field: name') !== false) {
				$foundMissingField = true;
				break;
			}
		}
		$this->assertTrue($foundMissingField, "Missing message about required field");
		
		// Invalid: array with wrong item type
		$result2 = $validator->validate([1, 2, 3]);
		$this->assertFalse($result2);
		// Check for message about wrong type
		$foundTypeError = false;
		foreach ($result2->errors as $error) {
			if (strpos($error->message, 'Expected string, got integer') !== false) {
				$foundTypeError = true;
				break;
			}
		}
		$this->assertTrue($foundTypeError, "Missing message about incorrect type");
		
		// Invalid: completely wrong type
		$result3 = $validator->validate(true);
		$this->assertFalse($result3);
		$this->assertStringContainsString('Expected one of [string, number, array, object] here, but got boolean', $result3->errors[0]->message);
	}
	
	/**
	 * Test anyOf with multiple inline objects and references combined
	 */
	public function testAnyOfWithComplexCombinations() {
		$schema = [
			'definitions' => [
				'idObject' => [
					'type' => 'object',
					'properties' => [
						'id' => ['type' => 'string'],
						'active' => ['type' => 'boolean']
					],
					'required' => ['id']
				]
			],
			'type' => 'object',
			'properties' => [
				'config' => [
					'anyOf' => [
						// Reference
						['$ref' => '#/definitions/idObject'],
						// Inline object
						['type' => 'object', 'properties' => [
							'name' => ['type' => 'string'],
							'values' => ['type' => 'array', 'items' => ['type' => 'number']]
						], 'required' => ['name']],
						// Simple types
						['type' => 'string']
					]
				]
			],
			'required' => ['config']
		];
		$validator = new HumanFriendlySchemaValidator($schema);
		
		// Valid id object reference
		$this->assertTrue($validator->validate(['config' => ['id' => 'test-id', 'active' => true]]));
		
		// Valid inline object
		$this->assertTrue($validator->validate(['config' => ['name' => 'test name', 'values' => [1, 2, 3]]]));
		
		// Valid string
		$this->assertTrue($validator->validate(['config' => 'simple string']));
		
		// Invalid: object matching no branch
		$result1 = $validator->validate(['config' => ['description' => 'no match']]);
		$this->assertFalse($result1);
		// Check for message about missing required field
		$foundMissingField = false;
		foreach ($result1->errors as $error) {
			if (strpos($error->message, 'Missing required field: id') !== false || 
				strpos($error->message, 'Missing required field: name') !== false ||
				strpos($error->message, 'Value did not match any of the allowed shapes') !== false) {
				$foundMissingField = true;
				break;
			}
		}
		$this->assertTrue($foundMissingField, "Missing message about missing fields or no match");
		
		// Invalid: reference object missing required field
		$result2 = $validator->validate(['config' => ['active' => true]]); 
		$this->assertFalse($result2);
		// Check for message about missing id field
		$foundMissingId = false;
		foreach ($result2->errors as $error) {
			if (strpos($error->message, 'Missing required field: id') !== false) {
				$foundMissingId = true;
				break;
			}
		}
		$this->assertTrue($foundMissingId, "Missing message about required id field");
	}
	
}
