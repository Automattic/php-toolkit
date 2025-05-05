<?php

namespace WordPress\Blueprints\Tests;

use WordPress\Blueprints\BlueprintV2Validator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the BlueprintV2Validator class.
 */
class BlueprintV2ValidatorTest extends TestCase {
    /**
     * Blueprint validator instance.
     *
     * @var BlueprintV2Validator
     */
    private $validator;

    /**
     * Set up the test.
     */
    public function setUp(): void {
        parent::setUp();
        $this->validator = new BlueprintV2Validator();
    }

    /**
     * Data provider for valid blueprints.
     *
     * @return array[]
     */
    public static function validBlueprintsProvider(): array {
        return [
            'minimal valid blueprint' => ['valid/minimal-valid.json'],
            'full featured blueprint' => ['valid/full-features.json'],
            'blueprint with plugins' => ['valid/with-plugins.json'],
            'blueprint with content' => ['valid/with-content.json'],
            'blueprint with steps' => ['valid/with-steps.json'],
            'blueprint with post types' => ['valid/with-post-types.json'],
            'blueprint with additional steps' => ['valid/with-additional-steps.json'],
        ];
    }

    /**
     * Test valid blueprints.
     */
    #[DataProvider('validBlueprintsProvider')]
    public function testValidBlueprints(string $fixturePath) {
        $blueprint = $this->loadFixture($fixturePath);
        $result = $this->validator->validate($blueprint);
        
        $this->assertTrue($result, 'Validation should pass for valid blueprint');
        $this->assertEmpty($this->validator->get_errors(), 'No errors should be present');
    }

    /**
     * Data provider for invalid blueprints.
     *
     * @return array[]
     */
    public static function invalidBlueprintsProvider(): array {
        return [
            'missing required fields' => [
                'invalid/missing-required.json',
                'version',
                "Required field 'version' is missing",
                'Validation should fail for blueprint with missing required fields'
            ],
            'invalid version' => [
                'invalid/invalid-version.json',
                'version',
                'Version must be 2',
                'Validation should fail for blueprint with invalid version'
            ],
            'invalid plugin format' => [
                'invalid/invalid-plugin-format.json',
                'plugins',
                'Plugin definition must be a string or an object',
                'Validation should fail for blueprint with invalid plugin format'
            ],
            'invalid content type' => [
                'invalid/invalid-content-type.json',
                'content',
                'Invalid content type',
                'Validation should fail for blueprint with invalid content type'
            ],
            'invalid URL format' => [
                'invalid/invalid-url-format.json',
                'blueprintMeta.authorUrl',
                'URL must start with http:// or https://',
                'Validation should fail for blueprint with invalid URL format'
            ],
            'invalid post types' => [
                'invalid/invalid-post-types.json',
                'postTypes.book.show_in_menu',
                'show_in_menu must be a boolean or string',
                'Validation should fail for blueprint with invalid post types'
            ],
            'invalid additional steps' => [
                'invalid/invalid-additional-steps.json',
                'additionalStepsAfterExecution',
                'Unknown step type: unknownStep',
                'Validation should fail for blueprint with invalid additional steps'
            ],
        ];
    }

    /**
     * Test invalid blueprints.
     */
    #[DataProvider('invalidBlueprintsProvider')]
    public function testInvalidBlueprints(string $fixturePath, ?string $expectedErrorPath, ?string $expectedErrorMessage, string $assertMessage) {
        $blueprint = $this->loadFixture($fixturePath);
        $result = $this->validator->validate($blueprint);
        
        $this->assertFalse($result, $assertMessage);
        $errors = $this->validator->get_errors();
        $this->assertNotEmpty($errors, 'Errors should be present');
        
        if ($expectedErrorPath !== null) {
            $hasExpectedError = false;
            foreach ($errors as $error) {
                if (strpos($error['path'], $expectedErrorPath) !== false) {
                    $hasExpectedError = true;
                    break;
                }
            }
            $this->assertTrue($hasExpectedError, "Should contain error related to $expectedErrorPath");
        }
        
        if ($expectedErrorMessage !== null) {
            $hasExpectedMessage = false;
            foreach ($errors as $error) {
                if (strpos($error['message'], $expectedErrorMessage) !== false) {
                    $hasExpectedMessage = true;
                    break;
                }
            }
            $this->assertTrue($hasExpectedMessage, "Should contain error message with '$expectedErrorMessage'");
        }
    }

    /**
     * Test specifically for error messages.
     */
    public function testSpecificErrorMessages() {
        // Test missing required blueprint
        $missingRequiredBlueprint = $this->loadFixture('invalid/missing-required.json');
        $result = $this->validator->validate($missingRequiredBlueprint);
        $this->assertFalse($result);
        $errors = $this->validator->get_errors();
        $this->assertNotEmpty($errors);
        
        $versionError = false;
        foreach ($errors as $error) {
            if (strpos($error['path'], 'version') !== false) {
                $versionError = true;
                $this->assertStringContainsString("Required field 'version' is missing", $error['message']);
                break;
            }
        }
        $this->assertTrue($versionError, "Should have error for missing version");
        
        // Test invalid content type
        $invalidContentBlueprint = $this->loadFixture('invalid/invalid-content-type.json');
        $result = $this->validator->validate($invalidContentBlueprint);
        $this->assertFalse($result);
        $errors = $this->validator->get_errors();
        $this->assertNotEmpty($errors);
        
        $contentError = false;
        foreach ($errors as $error) {
            if (strpos($error['path'], 'content') !== false) {
                $contentError = true;
                $this->assertStringContainsString('Invalid content type', $error['message']);
                break;
            }
        }
        $this->assertTrue($contentError, "Should have error for invalid content type");
        
        // Test post types validation
        $invalidPostTypeBlueprint = $this->loadFixture('invalid/invalid-post-types.json');
        $result = $this->validator->validate($invalidPostTypeBlueprint);
        $this->assertFalse($result);
        $errors = $this->validator->get_errors();
        $this->assertNotEmpty($errors);
        
        $postTypeError = false;
        foreach ($errors as $error) {
            if (strpos($error['path'], 'show_in_menu') !== false) {
                $postTypeError = true;
                $this->assertStringContainsString('show_in_menu must be a boolean or string', $error['message']);
                break;
            }
        }
        $this->assertTrue($postTypeError, "Should have error for invalid post types");
        
        // Test additional steps validation
        $invalidStepsBlueprint = $this->loadFixture('invalid/invalid-additional-steps.json');
        $result = $this->validator->validate($invalidStepsBlueprint);
        $this->assertFalse($result);
        $errors = $this->validator->get_errors();
        $this->assertNotEmpty($errors);
        
        $stepsError = false;
        foreach ($errors as $error) {
            if (strpos($error['path'], 'additionalStepsAfterExecution') !== false && 
                strpos($error['message'], 'Unknown step type') !== false) {
                $stepsError = true;
                break;
            }
        }
        $this->assertTrue($stepsError, "Should have error for invalid additional steps");
    }

    /**
     * Data provider for schema validation tests.
     *
     * @return array[]
     */
    public static function schemaValidationProvider(): array {
        return [
            'valid blueprint' => ['valid/minimal-valid.json', true],
            'invalid blueprint' => ['invalid/invalid-content-type.json', false],
        ];
    }

    /**
     * Test validate_against_schema method.
     */
    #[DataProvider('schemaValidationProvider')]
    public function testValidateAgainstSchema(string $fixturePath, bool $shouldBeValid) {
        $blueprint = $this->loadFixture($fixturePath);
        $errors = $this->validator->validate_against_schema($blueprint);
        
        if ($shouldBeValid) {
            $this->assertEmpty($errors, 'Valid blueprint should not have errors');
        } else {
            $this->assertNotEmpty($errors, 'Invalid blueprint should have errors');
        }
    }

    /**
     * Load a fixture file.
     *
     * @param string $fixture_path The fixture path relative to fixtures directory.
     * @return array The decoded fixture.
     */
    private function loadFixture($fixture_path) {
        $fixture_file = __DIR__ . '/fixtures/' . $fixture_path;
        $json = file_get_contents($fixture_file);
        return json_decode($json, true);
    }
}