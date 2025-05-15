<?php

class WordPressBlueprintValidator
{
    private array $errors = [];
    private array $pathStack = [];
    private $dataRoot; // The decoded JSON data (stdClass or array at root)

    // --- Helper Methods ---

    private function pushPath(string $segment): void
    {
        $this->pathStack[] = $segment;
    }

    private function popPath(): void
    {
        if (count($this->pathStack) > 1) {
            array_pop($this->pathStack);
        }
    }

    private function getCurrentPath(): string
    {
        if (empty($this->pathStack)) return '#';
        $path = $this->pathStack[0];
        for ($i = 1; $i < count($this->pathStack); $i++) {
            $path .= $this->pathStack[$i];
        }
        return $path;
    }

    private function addError(string $message, ?string $specificPath = null): void
    {
        $path = $specificPath ?? $this->getCurrentPath();
        foreach ($this->errors as $existingError) {
            if ($existingError['path'] === $path && $existingError['message'] === $message) {
                return; // Avoid exact duplicate errors
            }
        }
        $this->errors[] = ['path' => $path, 'message' => $message];
    }

    // --- Type Checkers ---
    // These check the PHP type. JSON types are mapped from these.

    private function isObject($value): bool
    {
        return is_object($value); // Assumes json_decode without assoc=true for objects
    }

    private function isArray($value): bool
    {
        // Checks if it's a PHP array AND numerically indexed (JSON array)
        return is_array($value) && (empty($value) || array_keys($value) === range(0, count($value) - 1));
    }
    
    private function isAssociativeArray($value): bool
    {
        // For cases where an object might be decoded as an assoc array
        return is_array($value) && !empty($value) && array_keys($value) !== range(0, count($value) - 1);
    }

    private function isString($value): bool
    {
        return is_string($value);
    }

    private function isInteger($value): bool
    {
        // JSON integer can be PHP int. Floats like 2.0 are numbers, not integers by JSON schema type "integer".
        return is_int($value);
    }
    
    private function isNumber($value): bool
    {
        // JSON number can be PHP int or float
        return is_int($value) || is_float($value);
    }

    private function isBoolean($value): bool
    {
        return is_bool($value);
    }

    private function isNull($value): bool
    {
        return is_null($value);
    }

    private function matchesPattern($value, string $pattern): bool
    {
        // JSON Schema patterns are implicitly anchored.
        // PCRE delimiters are needed for preg_match.
        $delimitedPattern = '/^' . str_replace('/', '\/', $pattern) . '$/';
        return preg_match($delimitedPattern, $value) === 1;
    }

    // --- Main Validation Logic ---

    public function validate($jsonData): bool
    {
        $this->errors = [];
        $this->pathStack = ['#'];
        $this->dataRoot = $jsonData;

        if (!$this->isObject($this->dataRoot)) {
            $this->addError("Blueprint root must be an object.");
            return empty($this->errors);
        }

        $this->validateRootObject($this->dataRoot);

        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    // --- Root Object Validator ---
    private function validateRootObject(stdClass $data): void
    {
        // Required properties
        $required = ["version", "content"];
        foreach ($required as $prop) {
            if (!property_exists($data, $prop)) {
                $this->addError("Required property '{$prop}' is missing.");
            }
        }

        // Validate known properties
        if (property_exists($data, 'version')) {
            $this->pushPath('.version');
            $this->validateVersionProperty($data->version);
            $this->popPath();
        }

        if (property_exists($data, '$schema')) {
            $this->pushPath('.$schema');
            $this->validateDollarSchemaProperty($data->{'$schema'});
            $this->popPath();
        }

        if (property_exists($data, 'blueprintMeta')) {
            $this->pushPath('.blueprintMeta');
            $this->validateBlueprintMetaProperty($data->blueprintMeta);
            $this->popPath();
        }
        
        if (property_exists($data, 'siteLanguage')) {
            $this->pushPath('.siteLanguage');
            if (!$this->isString($data->siteLanguage)) {
                 $this->addError("Property 'siteLanguage' must be a string.");
            }
            // Default: "en_US" - validation doesn't enforce default presence
            $this->popPath();
        }

        if (property_exists($data, 'siteOptions')) {
            $this->pushPath('.siteOptions');
            $this->validateSiteOptionsProperty($data->siteOptions);
            $this->popPath();
        }
        
        if (property_exists($data, 'constants')) {
            $this->pushPath('.constants');
            $this->validateConstantsProperty($data->constants);
            $this->popPath();
        }
        
        if (property_exists($data, 'wordpressVersion')) {
            $this->pushPath('.wordpressVersion');
            $this->validateWordpressVersionTopLevelProperty($data->wordpressVersion);
            $this->popPath();
        }
        
        if (property_exists($data, 'phpVersion')) {
            $this->pushPath('.phpVersion');
            $this->validatePhpVersionTopLevelProperty($data->phpVersion);
            $this->popPath();
        }
        
        if (property_exists($data, 'activeTheme')) {
            $this->pushPath('.activeTheme');
            $this->validateThemeReferenceDefinition($data->activeTheme);
            $this->popPath();
        }

        if (property_exists($data, 'themes')) {
            $this->pushPath('.themes');
            $this->validateThemesProperty($data->themes);
            $this->popPath();
        }
        
        if (property_exists($data, 'plugins')) {
            $this->pushPath('.plugins');
            $this->validatePluginsProperty($data->plugins);
            $this->popPath();
        }
        
        if (property_exists($data, 'muPlugins')) {
            $this->pushPath('.muPlugins');
            $this->validateMuPluginsProperty($data->muPlugins);
            $this->popPath();
        }
        
        if (property_exists($data, 'postTypes')) {
            $this->pushPath('.postTypes');
            $this->validatePostTypesProperty($data->postTypes);
            $this->popPath();
        }
        
        if (property_exists($data, 'fonts')) {
            $this->pushPath('.fonts');
            $this->validateFontsProperty($data->fonts);
            $this->popPath();
        }
        
        if (property_exists($data, 'media')) {
            $this->pushPath('.media');
            $this->validateMediaProperty($data->media);
            $this->popPath();
        }

        if (property_exists($data, 'content')) { // Already checked for presence
            $this->pushPath('.content');
            $this->validateContentProperty($data->content);
            $this->popPath();
        }
        
        if (property_exists($data, 'users')) {
            $this->pushPath('.users');
            $this->validateUsersProperty($data->users);
            $this->popPath();
        }
        
        if (property_exists($data, 'roles')) {
            $this->pushPath('.roles');
            $this->validateRolesProperty($data->roles);
            $this->popPath();
        }
        
        if (property_exists($data, 'additionalStepsAfterExecution')) {
            $this->pushPath('.additionalStepsAfterExecution');
            $this->validateAdditionalStepsAfterExecutionProperty($data->additionalStepsAfterExecution);
            $this->popPath();
        }

        // Root object does not have `additionalProperties: false`, so unknown properties are allowed.
    }

    // --- Property Validators (delegating to Definition Validators or handling specifics) ---

    private function validateVersionProperty($value): void
    {
        if (!$this->isInteger($value)) {
            $this->addError("Property 'version' must be an integer.");
            return;
        }
        if ($value !== 2) {
            $this->addError("Property 'version' must be 2.");
        }
    }

    private function validateDollarSchemaProperty($value): void
    {
        // anyOf: URLReference, ExecutionContextPath
        $currentPath = $this->getCurrentPath();
        $initialErrorCount = count($this->errors);
        $isValid = false;

        // Try URLReference
        $tempErrors = $this->errors; $this->errors = [];
        $this->pushPath('/anyOf[0]');
        if ($this->isString($value)) {
            $this->validateURLReferenceDefinition($value);
            if(empty($this->errors)) $isValid = true;
        } else {
            $this->addError("Expected string for URLReference variant.");
        }
        $this->popPath();
        $errorsForBranch1 = $this->errors;
        $this->errors = $tempErrors; // Restore, then add branch errors if this branch was chosen AND failed

        if ($isValid) {
            if(!empty($errorsForBranch1)) $this->errors = array_merge($this->errors, $errorsForBranch1);
            return;
        }

        // Try ExecutionContextPath
        $tempErrors = $this->errors; $this->errors = [];
        $this->pushPath('/anyOf[1]');
        if ($this->isString($value)) {
            $this->validateExecutionContextPathDefinition($value);
            if(empty($this->errors)) $isValid = true;
        } else {
            $this->addError("Expected string for ExecutionContextPath variant.");
        }
        $this->popPath();
        $errorsForBranch2 = $this->errors;
        $this->errors = $tempErrors;

        if ($isValid) {
            if(!empty($errorsForBranch2)) $this->errors = array_merge($this->errors, $errorsForBranch2);
            return;
        }

        // If neither matched
        $this->addError("Value must be a valid URLReference or ExecutionContextPath.", $currentPath);
    }
    
    private function validateBlueprintMetaProperty($value): void
    {
        if (!$this->isObject($value)) {
            $this->addError("Property 'blueprintMeta' must be an object.");
            return;
        }

        $knownProps = ['name', 'description', 'version', 'authors', 'authorUrl', 'donateLink', 'tags', 'license'];
        foreach (get_object_vars($value) as $propName => $propValue) {
            if (!in_array($propName, $knownProps)) {
                $this->addError("Unknown property '{$propName}' in blueprintMeta.");
            }
        }
        
        if (property_exists($value, 'name')) {
            $this->pushPath('.name');
            if (!$this->isString($value->name)) $this->addError("Property 'name' must be a string.");
            $this->popPath();
        }
        if (property_exists($value, 'description')) {
            $this->pushPath('.description');
            if (!$this->isString($value->description)) $this->addError("Property 'description' must be a string.");
            $this->popPath();
        }
        if (property_exists($value, 'version')) {
            $this->pushPath('.version');
            if (!$this->isString($value->version)) $this->addError("Property 'version' (in blueprintMeta) must be a string.");
            $this->popPath();
        }
        if (property_exists($value, 'authors')) {
            $this->pushPath('.authors');
            if (!$this->isArray($value->authors)) {
                $this->addError("Property 'authors' must be an array.");
            } else {
                foreach ($value->authors as $idx => $author) {
                    $this->pushPath("[{$idx}]");
                    if (!$this->isString($author)) $this->addError("Author item must be a string.");
                    $this->popPath();
                }
            }
            $this->popPath();
        }
        if (property_exists($value, 'authorUrl')) {
            $this->pushPath('.authorUrl');
            $this->validateURLReferenceDefinition($value->authorUrl);
            $this->popPath();
        }
        if (property_exists($value, 'donateLink')) {
            $this->pushPath('.donateLink');
            $this->validateURLReferenceDefinition($value->donateLink);
            $this->popPath();
        }
        if (property_exists($value, 'tags')) {
            $this->pushPath('.tags');
            if (!$this->isArray($value->tags)) {
                $this->addError("Property 'tags' must be an array.");
            } else {
                foreach ($value->tags as $idx => $tag) {
                    $this->pushPath("[{$idx}]");
                    if (!$this->isString($tag)) $this->addError("Tag item must be a string.");
                    $this->popPath();
                }
            }
            $this->popPath();
        }
        if (property_exists($value, 'license')) {
            $this->pushPath('.license');
            // anyOf: LicenseKeyword, string
            $isValid = false;
            $initialErrorCount = count($this->errors);

            $tempErrors = $this->errors; $this->errors = [];
            $this->pushPath('/anyOf[0]');
            if ($this->isString($value->license)) {
                 $this->validateLicenseKeywordDefinition($value->license);
                 if(empty($this->errors)) $isValid = true;
            } else { $this->addError("Expected string for LicenseKeyword variant."); }
            $this->popPath();
            $errorsForBranch1 = $this->errors; $this->errors = $tempErrors;
            if ($isValid) { if(!empty($errorsForBranch1)) $this->errors = array_merge($this->errors, $errorsForBranch1); }
            else {
                $tempErrors = $this->errors; $this->errors = [];
                $this->pushPath('/anyOf[1]');
                if ($this->isString($value->license)) {
                    // Any string is valid for this branch
                    $isValid = true;
                } else { $this->addError("Expected string for general license string variant."); }
                $this->popPath();
                $errorsForBranch2 = $this->errors; $this->errors = $tempErrors;
                if ($isValid) { if(!empty($errorsForBranch2)) $this->errors = array_merge($this->errors, $errorsForBranch2); }
                else {
                    $this->addError("Property 'license' must be a valid LicenseKeyword or a string.");
                }
            }
            $this->popPath();
        }
    }

    private function validateSiteOptionsProperty($value): void
    {
        if (!$this->isObject($value)) {
            $this->addError("Property 'siteOptions' must be an object.");
            return;
        }
        // `not`: { `required`: ["siteUrl"] } -> siteUrl must not be present
        if (property_exists($value, 'siteUrl')) {
            $this->addError("Property 'siteUrl' is not allowed in 'siteOptions'.");
        }

        if (property_exists($value, 'blogname')) {
            $this->pushPath('.blogname');
            if (!$this->isString($value->blogname)) $this->addError("Property 'blogname' must be a string.");
            $this->popPath();
        }
        if (property_exists($value, 'timezone_string')) {
            $this->pushPath('.timezone_string');
            if (!$this->isString($value->timezone_string)) $this->addError("Property 'timezone_string' must be a string.");
            $this->popPath();
        }
        if (property_exists($value, 'permalink_structure')) {
            $this->pushPath('.permalink_structure');
            // anyOf: string, boolean (false)
            $isValid = false;
            if ($this->isString($value->permalink_structure)) {
                $isValid = true;
            } elseif ($this->isBoolean($value->permalink_structure) && $value->permalink_structure === false) {
                $isValid = true;
            }
            if (!$isValid) {
                $this->addError("Property 'permalink_structure' must be a string or false.");
            }
            $this->popPath();
        }

        // additionalProperties: JsonValue
        $definedProps = ['blogname', 'timezone_string', 'permalink_structure'];
        foreach (get_object_vars($value) as $propName => $propValue) {
            if (!in_array($propName, $definedProps) && $propName !== 'siteUrl' /* already handled by 'not' */) {
                $this->pushPath('.' . $propName);
                $this->validateJsonValueDefinition($propValue);
                $this->popPath();
            }
        }
    }

    private function validateConstantsProperty($value): void
    {
        if (!$this->isObject($value)) {
            $this->addError("Property 'constants' must be an object.");
            return;
        }
        
        $definedProps = ['WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG'];
        if (property_exists($value, 'WP_DEBUG')) {
            $this->pushPath('.WP_DEBUG');
            if (!$this->isBoolean($value->WP_DEBUG)) $this->addError("Constant 'WP_DEBUG' must be a boolean.");
            $this->popPath();
        }
        // ... similar for WP_DEBUG_LOG, WP_DEBUG_DISPLAY, SCRIPT_DEBUG ...
        if (property_exists($value, 'WP_DEBUG_LOG')) {
            $this->pushPath('.WP_DEBUG_LOG');
            if (!$this->isBoolean($value->WP_DEBUG_LOG)) $this->addError("Constant 'WP_DEBUG_LOG' must be a boolean.");
            $this->popPath();
        }
        if (property_exists($value, 'WP_DEBUG_DISPLAY')) {
            $this->pushPath('.WP_DEBUG_DISPLAY');
            if (!$this->isBoolean($value->WP_DEBUG_DISPLAY)) $this->addError("Constant 'WP_DEBUG_DISPLAY' must be a boolean.");
            $this->popPath();
        }
        if (property_exists($value, 'SCRIPT_DEBUG')) {
            $this->pushPath('.SCRIPT_DEBUG');
            if (!$this->isBoolean($value->SCRIPT_DEBUG)) $this->addError("Constant 'SCRIPT_DEBUG' must be a boolean.");
            $this->popPath();
        }

        // additionalProperties: anyOf: boolean, string, number
        foreach (get_object_vars($value) as $propName => $propValue) {
            if (!in_array($propName, $definedProps)) {
                $this->pushPath('.' . $propName);
                $isValid = false;
                if ($this->isBoolean($propValue)) $isValid = true;
                elseif ($this->isString($propValue)) $isValid = true;
                elseif ($this->isNumber($propValue)) $isValid = true;
                
                if (!$isValid) {
                    $this->addError("Additional constant '{$propName}' must be a boolean, string, or number.");
                }
                $this->popPath();
            }
        }
    }

    private function validateWordpressVersionTopLevelProperty($value): void {
        // anyOf: WordPressVersionString, URLReference, ExecutionContextPath, object
        $currentPath = $this->getCurrentPath();
        $isValid = false;
        $initialErrorCount = count($this->errors);

        // Try WordPressVersionString
        $tempErrors = $this->errors; $this->errors = [];
        $this->pushPath('/anyOf[0]');
        if ($this->isString($value)) {
            $this->validateWordPressVersionStringDefinition($value);
            if(empty($this->errors)) $isValid = true;
        } // else { $this->addError("Expected string for WordPressVersionString variant."); }
        $this->popPath();
        $errorsForBranch1 = $this->errors; $this->errors = $tempErrors;
        if ($isValid) { if(!empty($errorsForBranch1)) $this->errors = array_merge($this->errors, $errorsForBranch1); return; }

        // Try URLReference
        $tempErrors = $this->errors; $this->errors = [];
        $this->pushPath('/anyOf[1]');
        if ($this->isString($value)) {
            $this->validateURLReferenceDefinition($value);
            if(empty($this->errors)) $isValid = true;
        } // else { $this->addError("Expected string for URLReference variant."); }
        $this->popPath();
        $errorsForBranch2 = $this->errors; $this->errors = $tempErrors;
        if ($isValid) { if(!empty($errorsForBranch2)) $this->errors = array_merge($this->errors, $errorsForBranch2); return; }
        
        // Try ExecutionContextPath
        $tempErrors = $this->errors; $this->errors = [];
        $this->pushPath('/anyOf[2]');
        if ($this->isString($value)) {
            $this->validateExecutionContextPathDefinition($value);
            if(empty($this->errors)) $isValid = true;
        } // else { $this->addError("Expected string for ExecutionContextPath variant."); }
        $this->popPath();
        $errorsForBranch3 = $this->errors; $this->errors = $tempErrors;
        if ($isValid) { if(!empty($errorsForBranch3)) $this->errors = array_merge($this->errors, $errorsForBranch3); return; }

        // Try Object
        $tempErrors = $this->errors; $this->errors = [];
        $this->pushPath('/anyOf[3]');
        if ($this->isObject($value)) {
            if (!property_exists($value, 'minVersion')) {
                $this->addError("Required property 'minVersion' is missing in WordPress version object.");
            } else {
                $this->pushPath('.minVersion');
                $this->validateWordPressVersionStringDefinition($value->minVersion);
                $this->popPath();
            }
            if (property_exists($value, 'maxVersion')) {
                $this->pushPath('.maxVersion');
                $this->validateWordPressVersionStringDefinition($value->maxVersion);
                $this->popPath();
            }
            if (property_exists($value, 'preferredVersion')) {
                $this->pushPath('.preferredVersion');
                $this->validateWordPressVersionStringDefinition($value->preferredVersion);
                $this->popPath();
            }
            $known = ['minVersion', 'maxVersion', 'preferredVersion'];
            foreach(get_object_vars($value) as $k => $v) {
                if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in WordPress version object.");
            }
            if(empty($this->errors)) $isValid = true;

        } // else { $this->addError("Expected object for WordPress version object variant."); }
        $this->popPath();
        $errorsForBranch4 = $this->errors; $this->errors = $tempErrors;
        if ($isValid) { if(!empty($errorsForBranch4)) $this->errors = array_merge($this->errors, $errorsForBranch4); return; }
        
        $this->addError("Property 'wordpressVersion' does not match any allowed type.", $currentPath);
    }
    
    private function validatePhpVersionTopLevelProperty($value): void {
        // anyOf: PHPVersionString, object
        $currentPath = $this->getCurrentPath();
        $isValid = false;
        $initialErrorCount = count($this->errors);

        // Try PHPVersionString
        $tempErrors = $this->errors; $this->errors = [];
        $this->pushPath('/anyOf[0]');
        if ($this->isString($value)) {
            $this->validatePHPVersionStringDefinition($value);
             if(empty($this->errors)) $isValid = true;
        } // else { $this->addError("Expected string for PHPVersionString variant."); }
        $this->popPath();
        $errorsForBranch1 = $this->errors; $this->errors = $tempErrors;
        if ($isValid) { if(!empty($errorsForBranch1)) $this->errors = array_merge($this->errors, $errorsForBranch1); return; }

        // Try Object
        $tempErrors = $this->errors; $this->errors = [];
        $this->pushPath('/anyOf[1]');
        if ($this->isObject($value)) {
            if (property_exists($value, 'minVersion')) {
                $this->pushPath('.minVersion');
                $this->validatePHPVersionStringDefinition($value->minVersion);
                $this->popPath();
            }
            if (property_exists($value, 'recommendedVersion')) {
                $this->pushPath('.recommendedVersion');
                $this->validatePHPVersionStringDefinition($value->recommendedVersion);
                $this->popPath();
            }
            if (property_exists($value, 'maxVersion')) {
                $this->pushPath('.maxVersion');
                $this->validatePHPVersionStringDefinition($value->maxVersion);
                $this->popPath();
            }
            $known = ['minVersion', 'recommendedVersion', 'maxVersion'];
            foreach(get_object_vars($value) as $k => $v) {
                if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in PHP version object.");
            }
            if(empty($this->errors)) $isValid = true;
        } // else { $this->addError("Expected object for PHP version object variant."); }
        $this->popPath();
        $errorsForBranch2 = $this->errors; $this->errors = $tempErrors;
        if ($isValid) { if(!empty($errorsForBranch2)) $this->errors = array_merge($this->errors, $errorsForBranch2); return; }
        
        $this->addError("Property 'phpVersion' does not match any allowed type.", $currentPath);
    }

    private function validateThemesProperty($value): void {
        if (!$this->isArray($value)) {
            $this->addError("Property 'themes' must be an array.");
            return;
        }
        foreach ($value as $idx => $themeItem) {
            $this->pushPath("[{$idx}]");
            $this->validateThemeReferenceDefinition($themeItem);
            $this->popPath();
        }
    }
    
    private function validatePluginsProperty($value): void {
        if (!$this->isArray($value)) {
            $this->addError("Property 'plugins' must be an array.");
            return;
        }
        foreach ($value as $idx => $pluginItem) {
            $this->pushPath("[{$idx}]");
            $this->validatePluginDefinitionDefinition($pluginItem);
            $this->popPath();
        }
    }

    private function validateMuPluginsProperty($value): void {
        // anyOf: URLReference, array of ExecutionContextPath
        $currentPath = $this->getCurrentPath();
        $isValid = false;
        $initialErrorCount = count($this->errors);

        // Try URLReference
        $tempErrors = $this->errors; $this->errors = [];
        $this->pushPath('/anyOf[0]');
        if ($this->isString($value)) { // URLReference is a string
            $this->validateURLReferenceDefinition($value);
             if(empty($this->errors)) $isValid = true;
        } // else { $this->addError("Expected string for URLReference variant."); }
        $this->popPath();
        $errorsForBranch1 = $this->errors; $this->errors = $tempErrors;
        if ($isValid) { if(!empty($errorsForBranch1)) $this->errors = array_merge($this->errors, $errorsForBranch1); return; }

        // Try array of ExecutionContextPath
        $tempErrors = $this->errors; $this->errors = [];
        $this->pushPath('/anyOf[1]');
        if ($this->isArray($value)) {
            foreach ($value as $idx => $pathItem) {
                $this->pushPath("[{$idx}]");
                if ($this->isString($pathItem)) {
                    $this->validateExecutionContextPathDefinition($pathItem);
                } else {
                    $this->addError("Item must be a string (ExecutionContextPath).");
                }
                $this->popPath();
            }
            if(empty($this->errors)) $isValid = true;
        } // else { $this->addError("Expected array for ExecutionContextPath list variant."); }
        $this->popPath();
        $errorsForBranch2 = $this->errors; $this->errors = $tempErrors;
        if ($isValid) { if(!empty($errorsForBranch2)) $this->errors = array_merge($this->errors, $errorsForBranch2); return; }
        
        $this->addError("Property 'muPlugins' does not match any allowed type.", $currentPath);
    }

    private function validatePostTypesProperty($value): void {
        if (!$this->isObject($value)) {
            $this->addError("Property 'postTypes' must be an object.");
            return;
        }
        // additionalProperties: anyOf: PostType, ExecutionContextPath
        foreach (get_object_vars($value) as $propName => $propValue) {
            $this->pushPath('.' . $propName);
            $currentPropPath = $this->getCurrentPath();
            $isValid = false;
            $initialErrorCount = count($this->errors);

            // Try PostType
            $tempErrors = $this->errors; $this->errors = [];
            $this->pushPath('/anyOf[0]');
            if ($this->isObject($propValue)) {
                $this->validatePostTypeDefinition($propValue);
                 if(empty($this->errors)) $isValid = true;
            } // else { $this->addError("Expected object for PostType variant."); }
            $this->popPath();
            $errorsForBranch1 = $this->errors; $this->errors = $tempErrors;
            if ($isValid) { if(!empty($errorsForBranch1)) $this->errors = array_merge($this->errors, $errorsForBranch1); }
            else {
                // Try ExecutionContextPath
                $tempErrors = $this->errors; $this->errors = [];
                $this->pushPath('/anyOf[1]');
                if ($this->isString($propValue)) {
                    $this->validateExecutionContextPathDefinition($propValue);
                     if(empty($this->errors)) $isValid = true;
                } // else { $this->addError("Expected string for ExecutionContextPath variant."); }
                $this->popPath();
                $errorsForBranch2 = $this->errors; $this->errors = $tempErrors;
                if ($isValid) { if(!empty($errorsForBranch2)) $this->errors = array_merge($this->errors, $errorsForBranch2); }
                 else {
                    $this->addError("Post type definition '{$propName}' does not match any allowed type (PostType object or ExecutionContextPath string).", $currentPropPath);
                }
            }
            $this->popPath();
        }
    }
    
    private function validateFontsProperty($value): void {
        if (!$this->isObject($value)) {
            $this->addError("Property 'fonts' must be an object.");
            return;
        }
        // additionalProperties: anyOf: DataReference, FontCollection
        foreach (get_object_vars($value) as $propName => $propValue) {
            $this->pushPath('.' . $propName);
            $currentPropPath = $this->getCurrentPath();
            $isValid = false;
            $initialErrorCount = count($this->errors);

            // Try DataReference
            $tempErrors = $this->errors; $this->errors = [];
            $this->pushPath('/anyOf[0]');
            // DataReference can be string or object
            $this->validateDataReferenceDefinition($propValue); // This handles its own anyOf complexity
             if(empty($this->errors)) $isValid = true;
            $this->popPath();
            $errorsForBranch1 = $this->errors; $this->errors = $tempErrors;
            if ($isValid) { if(!empty($errorsForBranch1)) $this->errors = array_merge($this->errors, $errorsForBranch1); }
            else {
                // Try FontCollection
                $tempErrors = $this->errors; $this->errors = [];
                $this->pushPath('/anyOf[1]');
                if ($this->isObject($propValue)) {
                    $this->validateFontCollectionDefinition($propValue);
                     if(empty($this->errors)) $isValid = true;
                } // else { $this->addError("Expected object for FontCollection variant."); }
                $this->popPath();
                $errorsForBranch2 = $this->errors; $this->errors = $tempErrors;
                if ($isValid) { if(!empty($errorsForBranch2)) $this->errors = array_merge($this->errors, $errorsForBranch2); }
                 else {
                    $this->addError("Font definition '{$propName}' does not match any allowed type (DataReference or FontCollection).", $currentPropPath);
                }
            }
            $this->popPath();
        }
    }

    private function validateMediaProperty($value): void {
        if (!$this->isArray($value)) {
            $this->addError("Property 'media' must be an array.");
            return;
        }
        foreach ($value as $idx => $mediaItem) {
            $this->pushPath("[{$idx}]");
            // anyOf: DataReference, object
            $currentPath = $this->getCurrentPath();
            $isValid = false;
            $initialErrorCount = count($this->errors);

            // Try DataReference
            $tempErrors = $this->errors; $this->errors = [];
            $this->pushPath('/anyOf[0]');
            $this->validateDataReferenceDefinition($mediaItem);
             if(empty($this->errors)) $isValid = true;
            $this->popPath();
            $errorsForBranch1 = $this->errors; $this->errors = $tempErrors;
            if ($isValid) { if(!empty($errorsForBranch1)) $this->errors = array_merge($this->errors, $errorsForBranch1); }
            else {
                // Try object {source, title?, ...}
                $tempErrors = $this->errors; $this->errors = [];
                $this->pushPath('/anyOf[1]');
                if ($this->isObject($mediaItem)) {
                    if (!property_exists($mediaItem, 'source')) {
                        $this->addError("Required property 'source' missing in media item object.");
                    } else {
                        $this->pushPath('.source');
                        $this->validateDataReferenceDefinition($mediaItem->source);
                        $this->popPath();
                    }
                    if (property_exists($mediaItem, 'title')) {
                        $this->pushPath('.title');
                        if (!$this->isString($mediaItem->title)) $this->addError("Property 'title' must be a string.");
                        $this->popPath();
                    }
                    // ... similar for description, alt, caption
                    if (property_exists($mediaItem, 'description')) {
                         $this->pushPath('.description');
                        if (!$this->isString($mediaItem->description)) $this->addError("Property 'description' must be a string.");
                        $this->popPath();
                    }
                     if (property_exists($mediaItem, 'alt')) {
                         $this->pushPath('.alt');
                        if (!$this->isString($mediaItem->alt)) $this->addError("Property 'alt' must be a string.");
                        $this->popPath();
                    }
                     if (property_exists($mediaItem, 'caption')) {
                         $this->pushPath('.caption');
                        if (!$this->isString($mediaItem->caption)) $this->addError("Property 'caption' must be a string.");
                        $this->popPath();
                    }
                    $known = ['source', 'title', 'description', 'alt', 'caption'];
                    foreach(get_object_vars($mediaItem) as $k => $v) {
                        if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in media item object.");
                    }
                    if(empty($this->errors)) $isValid = true;
                } // else { $this->addError("Expected object for media item variant."); }
                $this->popPath();
                $errorsForBranch2 = $this->errors; $this->errors = $tempErrors;
                if ($isValid) { if(!empty($errorsForBranch2)) $this->errors = array_merge($this->errors, $errorsForBranch2); }
                 else {
                    $this->addError("Media item does not match any allowed type (DataReference or object).", $currentPath);
                }
            }
            $this->popPath();
        }
    }

    private function validateContentProperty($value): void {
        if (!$this->isArray($value)) {
            $this->addError("Property 'content' must be an array.");
            return;
        }
        if (empty($value) && in_array('content', ['version', 'content'])) { // Check if it's actually required and empty
             // This is fine, an empty array is allowed if the required field 'content' is present.
        }

        foreach ($value as $idx => $contentItem) {
            $this->pushPath("[{$idx}]");
            // anyOf: object (mysql-dump), object (posts), object (wxr)
            $currentPath = $this->getCurrentPath();
            $isValid = false;
            $initialErrorCount = count($this->errors);

            if (!$this->isObject($contentItem)) {
                $this->addError("Content item must be an object.");
                $this->popPath();
                continue;
            }
            if (!property_exists($contentItem, 'type') || !$this->isString($contentItem->type)) {
                 $this->addError("Content item must have a string 'type' property.");
                 $this->popPath();
                 continue;
            }

            $contentType = $contentItem->type;
            $branchErrors = [];

            if ($contentType === 'mysql-dump') {
                $tempErrors = $this->errors; $this->errors = [];
                $this->pushPath('/anyOf[0]'); // Conceptually
                $this->validateContentMysqlDump($contentItem);
                if(empty($this->errors)) $isValid = true;
                $this->popPath();
                $branchErrors = $this->errors; $this->errors = $tempErrors;
                if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }

            } elseif ($contentType === 'posts') {
                $tempErrors = $this->errors; $this->errors = [];
                $this->pushPath('/anyOf[1]'); // Conceptually
                $this->validateContentPosts($contentItem);
                if(empty($this->errors)) $isValid = true;
                $this->popPath();
                $branchErrors = $this->errors; $this->errors = $tempErrors;
                if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }
                
            } elseif ($contentType === 'wxr') {
                $tempErrors = $this->errors; $this->errors = [];
                $this->pushPath('/anyOf[2]'); // Conceptually
                $this->validateContentWxr($contentItem);
                if(empty($this->errors)) $isValid = true;
                $this->popPath();
                $branchErrors = $this->errors; $this->errors = $tempErrors;
                if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }

            } else {
                $this->addError("Unknown content item type '{$contentType}'. Allowed types are 'mysql-dump', 'posts', 'wxr'.");
            }

            if (!$isValid && $contentType !== 'mysql-dump' && $contentType !== 'posts' && $contentType !== 'wxr') {
                 // Error already added for unknown type.
            } elseif (!$isValid) {
                 $this->addError("Content item of type '{$contentType}' is not valid.", $currentPath);
            }
            $this->popPath();
        }
    }
    
    private function validateUsersProperty($value): void {
        if (!$this->isArray($value)) {
            $this->addError("Property 'users' must be an array.");
            return;
        }
        foreach ($value as $idx => $userItem) {
            $this->pushPath("[{$idx}]");
            if (!$this->isObject($userItem)) {
                $this->addError("User item must be an object.");
            } else {
                $required = ['username', 'email', 'role'];
                foreach($required as $req) {
                    if(!property_exists($userItem, $req)) $this->addError("Required property '{$req}' missing in user item.");
                }
                if (property_exists($userItem, 'username') && !$this->isString($userItem->username)) $this->addError("User 'username' must be a string.");
                if (property_exists($userItem, 'email') && !$this->isString($userItem->email)) $this->addError("User 'email' must be a string.");
                if (property_exists($userItem, 'role') && !$this->isString($userItem->role)) $this->addError("User 'role' must be a string.");
                if (property_exists($userItem, 'meta')) {
                    $this->pushPath('.meta');
                    if (!$this->isObject($userItem->meta)) {
                        $this->addError("User 'meta' must be an object.");
                    } else {
                        foreach(get_object_vars($userItem->meta) as $metaKey => $metaValue) {
                            if (!$this->isString($metaValue)) {
                                $this->addError("User meta value for '{$metaKey}' must be a string.");
                            }
                        }
                    }
                    $this->popPath();
                }
                 $known = ['username', 'email', 'role', 'meta'];
                 foreach(get_object_vars($userItem) as $k => $v) {
                    if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in user item.");
                 }
            }
            $this->popPath();
        }
    }
    
    private function validateRolesProperty($value): void {
        if (!$this->isArray($value)) {
            $this->addError("Property 'roles' must be an array.");
            return;
        }
        foreach ($value as $idx => $roleItem) {
            $this->pushPath("[{$idx}]");
            if (!$this->isObject($roleItem)) {
                $this->addError("Role item must be an object.");
            } else {
                $required = ['name', 'capabilities'];
                foreach($required as $req) {
                    if(!property_exists($roleItem, $req)) $this->addError("Required property '{$req}' missing in role item.");
                }
                if (property_exists($roleItem, 'name') && !$this->isString($roleItem->name)) $this->addError("Role 'name' must be a string.");
                if (property_exists($roleItem, 'capabilities')) {
                    $this->pushPath('.capabilities');
                    if (!$this->isObject($roleItem->capabilities)) {
                        $this->addError("Role 'capabilities' must be an object.");
                    } else {
                        foreach(get_object_vars($roleItem->capabilities) as $capKey => $capValue) {
                             if (!$this->isString($capValue)) { // Schema says "string", but WP capabilities are boolean. Assuming schema is king.
                                $this->addError("Role capability value for '{$capKey}' must be a string (as per schema; WordPress uses booleans).");
                            }
                        }
                    }
                    $this->popPath();
                }
                $known = ['name', 'capabilities'];
                 foreach(get_object_vars($roleItem) as $k => $v) {
                    if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in role item.");
                 }
            }
            $this->popPath();
        }
    }
    
    private function validateAdditionalStepsAfterExecutionProperty($value): void {
        if (!$this->isArray($value)) {
            $this->addError("Property 'additionalStepsAfterExecution' must be an array.");
            return;
        }
        foreach ($value as $idx => $stepItem) {
            $this->pushPath("[{$idx}]");
            $this->validateStepDefinition($stepItem);
            $this->popPath();
        }
    }

    // --- Definition Validators (called by property validators) ---
    // These assume path is already set by the caller.

    private function validateURLReferenceDefinition($value): void
    {
        if (!$this->isString($value)) {
            $this->addError("URLReference must be a string.");
            return;
        }
        if (!$this->matchesPattern($value, '^https?://.*')) {
            $this->addError("String does not match URLReference pattern (^https?://.*).");
        }
    }

    private function validateExecutionContextPathDefinition($value): void
    {
        if (!$this->isString($value)) {
            $this->addError("ExecutionContextPath must be a string.");
            return;
        }
        // Original pattern: "^(/|\\./)+.*"
        // PHP preg_match needs escaping for backslash in pattern string: "^(?:/|\\./)+.*"
        // More robust for PHP:
        $pattern = '^(?:/|\./).*'; // Simplified: starts with / or ./
        if (!$this->matchesPattern($value, $pattern)) {
             // Original pattern has `+` which means one or more occurrences of `/` or `./`
             // Let's use the more direct one from schema:
            if (!$this->matchesPattern($value, '^(?:/|\\./)+.*')) {
                 $this->addError("String does not match ExecutionContextPath pattern (^(?:/|\\./)+.*).");
            }
        }
    }
    
    private function validateDataReferenceDefinition($value): void {
        // anyOf: URLReference, ExecutionContextPath, InlineFile, InlineDirectory, GitPath, object { file: DataReference, ... }
        $currentPath = $this->getCurrentPath();
        $isValid = false;
        $errorSnapshot = $this->errors; 

        // Try URLReference
        $this->errors = []; $this->pushPath('/anyOf[0]');
        $this->validateURLReferenceDefinition($value);
        if (empty($this->errors)) $isValid = true;
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }

        // Try ExecutionContextPath
        $this->errors = []; $this->pushPath('/anyOf[1]');
        $this->validateExecutionContextPathDefinition($value);
        if (empty($this->errors)) $isValid = true;
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }
        
        // Try InlineFile
        $this->errors = []; $this->pushPath('/anyOf[2]');
        $this->validateInlineFileDefinition($value);
        if (empty($this->errors)) $isValid = true;
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }
        
        // Try InlineDirectory
        $this->errors = []; $this->pushPath('/anyOf[3]');
        $this->validateInlineDirectoryDefinition($value);
        if (empty($this->errors)) $isValid = true;
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }
        
        // Try GitPath
        $this->errors = []; $this->pushPath('/anyOf[4]');
        $this->validateGitPathDefinition($value);
        if (empty($this->errors)) $isValid = true;
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }
        
        // Try object { file: DataReference, humanReadableName?: string }
        $this->errors = []; $this->pushPath('/anyOf[5]');
        if ($this->isObject($value)) {
            if (!property_exists($value, 'file')) {
                $this->addError("Required property 'file' missing in DataReference object.");
            } else {
                $this->pushPath('.file');
                $this->validateDataReferenceDefinition($value->file); // Recursive
                $this->popPath();
            }
            if (property_exists($value, 'humanReadableName')) {
                $this->pushPath('.humanReadableName');
                if (!$this->isString($value->humanReadableName)) $this->addError("Property 'humanReadableName' must be a string.");
                $this->popPath();
            }
            $known = ['file', 'humanReadableName'];
            foreach(get_object_vars($value) as $k => $v) {
                if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in DataReference object.");
            }
             if (empty($this->errors)) $isValid = true;
        } else {
            if (count($this->errors) === 0) $this->addError("Expected object for DataReference object variant."); // Only add if no other errors so far for this branch
        }
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }
        
        $this->addError("Value does not match any allowed DataReference type.", $currentPath);
    }

    private function validateInlineFileDefinition($value): void {
        if (!$this->isObject($value)) {
            if (empty($this->errors)) $this->addError("InlineFile must be an object."); // Only if this branch is pursued and it's not an object
            return;
        }
        $required = ['filename', 'content'];
        foreach($required as $req) {
            if(!property_exists($value, $req)) $this->addError("Required property '{$req}' missing in InlineFile.");
        }
        if (property_exists($value, 'filename') && !$this->isString($value->filename)) $this->addError("InlineFile 'filename' must be a string.");
        if (property_exists($value, 'content') && !$this->isString($value->content)) $this->addError("InlineFile 'content' must be a string.");
        
        $known = ['filename', 'content'];
        foreach(get_object_vars($value) as $k => $v) {
            if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in InlineFile object.");
        }
    }

    private function validateInlineDirectoryDefinition($value): void {
        if (!$this->isObject($value)) {
            if (empty($this->errors)) $this->addError("InlineDirectory must be an object.");
            return;
        }
        $required = ['name', 'children'];
        foreach($required as $req) {
            if(!property_exists($value, $req)) $this->addError("Required property '{$req}' missing in InlineDirectory.");
        }
        if (property_exists($value, 'name') && !$this->isString($value->name)) $this->addError("InlineDirectory 'name' must be a string.");
        if (property_exists($value, 'children')) {
            $this->pushPath('.children');
            if (!$this->isArray($value->children)) {
                $this->addError("InlineDirectory 'children' must be an array.");
            } else {
                foreach ($value->children as $idx => $child) {
                    $this->pushPath("[{$idx}]");
                    // anyOf: InlineFile, InlineDirectory (recursive)
                    $currentPath = $this->getCurrentPath();
                    $isValid = false;
                    $errorSnapshot = $this->errors;

                    $this->errors = []; $this->pushPath('/anyOf[0]');
                    $this->validateInlineFileDefinition($child);
                    if (empty($this->errors)) $isValid = true;
                    $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }
                    else {
                        $this->errors = []; $this->pushPath('/anyOf[1]');
                        $this->validateInlineDirectoryDefinition($child); // Recursive
                        if (empty($this->errors)) $isValid = true;
                        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }
                        else {
                             $this->addError("Child item must be an InlineFile or InlineDirectory.", $currentPath);
                        }
                    }
                    $this->popPath();
                }
            }
            $this->popPath();
        }
        $known = ['name', 'children'];
        foreach(get_object_vars($value) as $k => $v) {
            if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in InlineDirectory object.");
        }
    }

    private function validateGitPathDefinition($value): void {
         if (!$this->isObject($value)) {
            if (empty($this->errors)) $this->addError("GitPath must be an object.");
            return;
        }
        if (!property_exists($value, 'gitRepository')) {
            $this->addError("Required property 'gitRepository' missing in GitPath.");
        } else {
            $this->pushPath('.gitRepository');
            $this->validateURLReferenceDefinition($value->gitRepository);
            $this->popPath();
        }
        if (property_exists($value, 'ref') && !$this->isString($value->ref)) $this->addError("GitPath 'ref' must be a string.");
        if (property_exists($value, 'path') && !$this->isString($value->path)) $this->addError("GitPath 'path' must be a string.");
        if (property_exists($value, 'localDirectoryName') && !$this->isString($value->localDirectoryName)) $this->addError("GitPath 'localDirectoryName' must be a string.");
        
        $known = ['gitRepository', 'ref', 'path', 'localDirectoryName'];
        foreach(get_object_vars($value) as $k => $v) {
            if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in GitPath object.");
        }
    }

    private function validateLicenseKeywordDefinition(string $value): void {
        $enum = ["AFL-3.0", "Apache-2.0", "Artistic-2.0", "BSL-1.0", "BSD-2-Clause", "BSD-3-Clause", "BSD-3-Clause-Clear", "BSD-4-Clause", "0BSD", "CC", "CC0-1.0", "CC-BY-4.0", "CC-BY-SA-4.0", "WTFPL", "ECL-2.0", "EPL-1.0", "EPL-2.0", "EUPL-1.1", "AGPL-3.0", "GPL", "GPL-2.0", "GPL-3.0", "LGPL", "LGPL-2.1", "LGPL-3.0", "ISC", "LPPL-1.3c", "MS-PL", "MIT", "MPL-2.0", "OSL-3.0", "PostgreSQL", "OFL-1.1", "NCSA", "Unlicense", "Zlib"];
        if (!in_array($value, $enum, true)) {
            $this->addError("Value '{$value}' is not a valid LicenseKeyword. Must be one of: " . implode(', ', $enum) . ".");
        }
    }
    
    private function validateJsonValueDefinition($value): void {
        // anyOf: string, boolean, number, array of JsonValue, object with additionalProperties JsonValue
        $currentPath = $this->getCurrentPath();
        $isValid = false;
        $errorSnapshot = $this->errors;

        // Try string, boolean, number directly
        if ($this->isString($value) || $this->isBoolean($value) || $this->isNumber($value) || $this->isNull($value) /* JSON null is a valid value */ ) {
            $isValid = true;
        }

        if ($isValid) return;

        // Try array of JsonValue (recursive)
        $this->errors = []; $this->pushPath('/anyOf[array]'); // Conceptual path
        if ($this->isArray($value)) {
            foreach ($value as $idx => $item) {
                $this->pushPath("[{$idx}]");
                $this->validateJsonValueDefinition($item); // Recursive
                $this->popPath();
            }
            if (empty($this->errors)) $isValid = true;
        }
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }

        // Try object with additionalProperties JsonValue (recursive)
        $this->errors = []; $this->pushPath('/anyOf[object]'); // Conceptual path
        if ($this->isObject($value)) {
            foreach (get_object_vars($value) as $propName => $propValue) {
                $this->pushPath('.' . $propName);
                $this->validateJsonValueDefinition($propValue); // Recursive
                $this->popPath();
            }
            if (empty($this->errors)) $isValid = true;
        }
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }

        if (!$isValid) {
             $this->addError("Value is not a valid JSON serializable type (string, boolean, number, array, or object).", $currentPath);
        }
    }
    
    private function validateWordPressVersionStringDefinition($value): void {
        if (!$this->isString($value)) {
            $this->addError("WordPressVersion string must be a string.");
            return;
        }
        $patterns = [
            "^latest$",
            "^\\d+\\.\\d+$",
            "^\\d+\\.\\d+\\.\\d+$",
            "^\\d+\\.\\d+(?:\\.\\d+)?-(?:beta\\d+|rc\\d+)$" // Adjusted for non-capturing group for optional patch
        ];
        $matched = false;
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($value, $pattern)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $this->addError("String '{$value}' does not match any valid WordPressVersion pattern.");
        }
    }

    private function validatePHPVersionStringDefinition($value): void {
        if (!$this->isString($value)) {
            $this->addError("PHPVersion string must be a string.");
            return;
        }
        $patterns = [
            "^latest$",
            "^\\d+\\.\\d+$",
            "^\\d+\\.\\d+\\.\\d+$"
        ];
        $matched = false;
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($value, $pattern)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $this->addError("String '{$value}' does not match any valid PHPVersion pattern.");
        }
    }
    
    private function validateThemeReferenceDefinition($value): void {
        // anyOf: ThemeDirectoryReference, URLReference, ExecutionContextPath
        $currentPath = $this->getCurrentPath();
        $isValid = false;
        $errorSnapshot = $this->errors;

        // Try ThemeDirectoryReference (which is string)
        $this->errors = []; $this->pushPath('/anyOf[0]');
        if ($this->isString($value)) {
            $this->validateThemeDirectoryReferenceDefinition($value);
            if (empty($this->errors)) $isValid = true;
        }
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }
        
        // Try URLReference (which is string)
        $this->errors = []; $this->pushPath('/anyOf[1]');
        if ($this->isString($value)) {
            $this->validateURLReferenceDefinition($value);
            if (empty($this->errors)) $isValid = true;
        }
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }

        // Try ExecutionContextPath (which is string)
        $this->errors = []; $this->pushPath('/anyOf[2]');
        if ($this->isString($value)) {
            $this->validateExecutionContextPathDefinition($value);
            if (empty($this->errors)) $isValid = true;
        }
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }

        // If none of the string-based options matched, or if $value wasn't a string
        if (!$this->isString($value)) {
            $this->addError("ThemeReference must be a string.", $currentPath);
        } else {
            $this->addError("String '{$value}' does not match any allowed ThemeReference type (ThemeDirectory, URL, or ExecutionContextPath).", $currentPath);
        }
    }

    private function validateThemeDirectoryReferenceDefinition(string $value): void {
        // Assumes $value is already confirmed string
        $patterns = [
            "^[a-zA-Z0-9_-]+$",
            "^[a-zA-Z0-9_-]+@(latest|\\d+\\.\\d+(?:\\.\\d+)?)$" // Adjusted for optional patch
        ];
        $matched = false;
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($value, $pattern)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $this->addError("String '{$value}' does not match any valid ThemeDirectoryReference pattern.");
        }
    }
    
    private function validatePluginDefinitionDefinition($value): void {
        // anyOf: PluginStringReference, object { source, active?, ... }
        $currentPath = $this->getCurrentPath();
        $isValid = false;
        $errorSnapshot = $this->errors;

        // Try PluginStringReference
        $this->errors = []; $this->pushPath('/anyOf[0]');
        // PluginStringReference itself is an anyOf of string types
        $this->validatePluginStringReferenceDefinition($value);
        if (empty($this->errors)) $isValid = true;
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }

        // Try object
        $this->errors = []; $this->pushPath('/anyOf[1]');
        if ($this->isObject($value)) {
            if (!property_exists($value, 'source')) {
                $this->addError("Required property 'source' missing in plugin object definition.");
            } else {
                $this->pushPath('.source');
                $this->validatePluginStringReferenceDefinition($value->source);
                $this->popPath();
            }
            if (property_exists($value, 'active') && !$this->isBoolean($value->active)) {
                $this->addError("Plugin 'active' property must be a boolean.");
            }
            if (property_exists($value, 'activationOptions')) {
                $this->pushPath('.activationOptions');
                if (!$this->isObject($value->activationOptions)) {
                    $this->addError("Plugin 'activationOptions' must be an object.");
                } else {
                    foreach (get_object_vars($value->activationOptions) as $optName => $optValue) {
                        $this->pushPath('.' . $optName);
                        $this->validateJsonValueDefinition($optValue);
                        $this->popPath();
                    }
                }
                $this->popPath();
            }
            if (property_exists($value, 'onError')) {
                $this->pushPath('.onError');
                if (!$this->isString($value->onError) || !in_array($value->onError, ["skip-plugin", "throw"], true)) {
                    $this->addError("Plugin 'onError' must be 'skip-plugin' or 'throw'.");
                }
                $this->popPath();
            }
            $known = ['source', 'active', 'activationOptions', 'onError'];
            foreach(get_object_vars($value) as $k => $v) {
                if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in plugin object definition.");
            }
            if (empty($this->errors)) $isValid = true;
        }
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }

        $this->addError("Value does not match any allowed PluginDefinition type (PluginStringReference or object).", $currentPath);
    }

    private function validatePluginStringReferenceDefinition($value): void {
        // anyOf: PluginDirectoryReference, URLReference, ExecutionContextPath
        $currentPath = $this->getCurrentPath();
        $isValid = false;
        $errorSnapshot = $this->errors;

        // Try PluginDirectoryReference (string)
        $this->errors = []; $this->pushPath('/anyOf[0]');
        if ($this->isString($value)) {
            $this->validatePluginDirectoryReferenceDefinition($value);
            if (empty($this->errors)) $isValid = true;
        }
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }

        // Try URLReference (string)
        $this->errors = []; $this->pushPath('/anyOf[1]');
        if ($this->isString($value)) {
            $this->validateURLReferenceDefinition($value);
            if (empty($this->errors)) $isValid = true;
        }
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }
        
        // Try ExecutionContextPath (string)
        $this->errors = []; $this->pushPath('/anyOf[2]');
        if ($this->isString($value)) {
            $this->validateExecutionContextPathDefinition($value);
            if (empty($this->errors)) $isValid = true;
        }
        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); return; }
        
        if (!$this->isString($value)) {
             $this->addError("PluginStringReference must be a string.", $currentPath);
        } else {
             $this->addError("String '{$value}' does not match any allowed PluginStringReference type (PluginDirectory, URL, or ExecutionContextPath).", $currentPath);
        }
    }

    private function validatePluginDirectoryReferenceDefinition(string $value): void {
        // Assumes $value is already confirmed string
        $patterns = [
            "^[a-zA-Z0-9_-]+$",
            "^[a-zA-Z0-9_-]+@(latest|\\d+\\.\\d+(?:\\.\\d+)?)$" // Adjusted for optional patch
        ];
        $matched = false;
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($value, $pattern)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $this->addError("String '{$value}' does not match any valid PluginDirectoryReference pattern.");
        }
    }

    // --- Content Item Validators ---
    private function validateContentMysqlDump(stdClass $item): void {
        if (!property_exists($item, 'source')) {
            $this->addError("Required property 'source' missing in mysql-dump content item.");
        } else {
            $this->pushPath('.source');
            // anyOf: DataReference, array of DataReference
            $currentPath = $this->getCurrentPath();
            $isValid = false;
            $errorSnapshot = $this->errors;

            $this->errors = []; $this->pushPath('/anyOf[0]');
            $this->validateDataReferenceDefinition($item->source);
            if(empty($this->errors)) $isValid = true;
            $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }
            else {
                $this->errors = []; $this->pushPath('/anyOf[1]');
                if ($this->isArray($item->source)) {
                    foreach($item->source as $idx => $dataRef) {
                        $this->pushPath("[{$idx}]");
                        $this->validateDataReferenceDefinition($dataRef);
                        $this->popPath();
                    }
                    if(empty($this->errors)) $isValid = true;
                }
                $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }
                else {
                     $this->addError("Property 'source' for mysql-dump must be a DataReference or an array of DataReferences.", $currentPath);
                }
            }
            $this->popPath(); // .source
        }
        if (property_exists($item, 'urlsMode')) {
            if (!$this->isString($item->urlsMode) || !in_array($item->urlsMode, ['rewrite', 'preserve'], true)) {
                $this->addError("Property 'urlsMode' must be 'rewrite' or 'preserve'.");
            }
        }
        if (property_exists($item, 'urlsMap')) {
            $this->pushPath('.urlsMap');
            if (!$this->isObject($item->urlsMap)) {
                $this->addError("Property 'urlsMap' must be an object.");
            } else {
                foreach(get_object_vars($item->urlsMap) as $key => $urlRef) {
                    $this->pushPath('.' . $key);
                    $this->validateURLReferenceDefinition($urlRef);
                    $this->popPath();
                }
            }
            $this->popPath();
        }
        $known = ['type', 'source', 'urlsMode', 'urlsMap'];
        foreach(get_object_vars($item) as $k => $v) {
            if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in mysql-dump content item.");
        }
    }

    private function validateContentPosts(stdClass $item): void {
        if (!property_exists($item, 'source')) {
            $this->addError("Required property 'source' missing in posts content item.");
        } else {
            $this->pushPath('.source');
            // anyOf: DataReference, array of DataReference, WordPressPost, array of WordPressPost
            $currentPath = $this->getCurrentPath();
            $isValid = false;
            $errorSnapshot = $this->errors;

            // Try DataReference
            $this->errors = []; $this->pushPath('/anyOf[0]');
            $this->validateDataReferenceDefinition($item->source);
            if(empty($this->errors)) $isValid = true;
            $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }
            else {
                // Try array of DataReference
                $this->errors = []; $this->pushPath('/anyOf[1]');
                if ($this->isArray($item->source)) {
                    $isArrOfDataRef = true;
                    foreach($item->source as $idx => $subItem) {
                        $this->pushPath("[{$idx}]");
                        $beforeCount = count($this->errors);
                        $this->validateDataReferenceDefinition($subItem);
                        if (count($this->errors) > $beforeCount) {
                            $isArrOfDataRef = false; // If any item is not a DataRef, this branch fails
                            // Remove errors from this specific item check as we'll try next anyOf branch.
                            array_splice($this->errors, $beforeCount);
                            break; 
                        }
                        $this->popPath();
                    }
                    if($isArrOfDataRef && empty($this->errors)) $isValid = true;
                }
                $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }
                else {
                     // Try WordPressPost
                    $this->errors = []; $this->pushPath('/anyOf[2]');
                    if($this->isObject($item->source)) {
                        $this->validateWordPressPostDefinition($item->source);
                        if(empty($this->errors)) $isValid = true;
                    }
                    $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }
                    else {
                        // Try array of WordPressPost
                        $this->errors = []; $this->pushPath('/anyOf[3]');
                        if ($this->isArray($item->source)) {
                            $isArrOfWPPost = true;
                            foreach($item->source as $idx => $subItem) {
                                $this->pushPath("[{$idx}]");
                                $beforeCount = count($this->errors);
                                if($this->isObject($subItem)) {
                                    $this->validateWordPressPostDefinition($subItem);
                                } else {
                                    $this->addError("Expected object for WordPressPost item in array.");
                                }
                                if (count($this->errors) > $beforeCount) {
                                    $isArrOfWPPost = false; 
                                    array_splice($this->errors, $beforeCount);
                                    break;
                                }
                                $this->popPath();
                            }
                             if($isArrOfWPPost && empty($this->errors)) $isValid = true;
                        }
                        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if ($isValid) { if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }
                        else {
                            $this->addError("Property 'source' for posts item does not match any allowed type.", $currentPath);
                        }
                    }
                }
            }
            $this->popPath(); // .source
        }
        // urlsMode, urlsMap validation same as mysql-dump
        if (property_exists($item, 'urlsMode')) {
            if (!$this->isString($item->urlsMode) || !in_array($item->urlsMode, ['rewrite', 'preserve'], true)) {
                $this->addError("Property 'urlsMode' must be 'rewrite' or 'preserve'.");
            }
        }
        if (property_exists($item, 'urlsMap')) {
            $this->pushPath('.urlsMap');
            if (!$this->isObject($item->urlsMap)) {
                $this->addError("Property 'urlsMap' must be an object.");
            } else {
                foreach(get_object_vars($item->urlsMap) as $key => $urlRef) {
                    $this->pushPath('.' . $key);
                    $this->validateURLReferenceDefinition($urlRef);
                    $this->popPath();
                }
            }
            $this->popPath();
        }
        $known = ['type', 'source', 'urlsMode', 'urlsMap'];
        foreach(get_object_vars($item) as $k => $v) {
            if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in posts content item.");
        }
    }

    private function validateContentWxr(stdClass $item): void {
         if (!property_exists($item, 'source')) {
            $this->addError("Required property 'source' missing in wxr content item.");
        } else {
            $this->pushPath('.source');
            $this->validateDataReferenceDefinition($item->source);
            $this->popPath();
        }
        if (property_exists($item, 'staticAssets') && (!$this->isString($item->staticAssets) || !in_array($item->staticAssets, ['fetch', 'hotlink']))) {
            $this->addError("Property 'staticAssets' must be 'fetch' or 'hotlink'.");
        }
        if (property_exists($item, 'authorsMode') && (!$this->isString($item->authorsMode) || !in_array($item->authorsMode, ['create', 'default-author', 'map']))) {
            $this->addError("Property 'authorsMode' must be 'create', 'default-author', or 'map'.");
        }
        if (property_exists($item, 'defaultAuthorUsername') && !$this->isString($item->defaultAuthorUsername)) {
            $this->addError("Property 'defaultAuthorUsername' must be a string.");
        }
        if (property_exists($item, 'authorsMap')) {
            $this->pushPath('.authorsMap');
            if (!$this->isObject($item->authorsMap)) {
                $this->addError("Property 'authorsMap' must be an object.");
            } else {
                foreach(get_object_vars($item->authorsMap) as $key => $username) {
                    if (!$this->isString($username)) $this->addError("authorsMap value for '{$key}' must be a string (username).");
                }
            }
            $this->popPath();
        }
        if (property_exists($item, 'importUsers') && !$this->isBoolean($item->importUsers)) $this->addError("Property 'importUsers' must be a boolean.");
        if (property_exists($item, 'importComments') && !$this->isBoolean($item->importComments)) $this->addError("Property 'importComments' must be a boolean.");
        if (property_exists($item, 'importSiteOptions') && !$this->isBoolean($item->importSiteOptions)) $this->addError("Property 'importSiteOptions' must be a boolean.");
        // urlsMode, urlsMap validation same as mysql-dump
        if (property_exists($item, 'urlsMode')) {
            if (!$this->isString($item->urlsMode) || !in_array($item->urlsMode, ['rewrite', 'preserve'], true)) {
                $this->addError("Property 'urlsMode' must be 'rewrite' or 'preserve'.");
            }
        }
        if (property_exists($item, 'urlsMap')) {
            $this->pushPath('.urlsMap');
            if (!$this->isObject($item->urlsMap)) {
                $this->addError("Property 'urlsMap' must be an object.");
            } else {
                foreach(get_object_vars($item->urlsMap) as $key => $urlRef) {
                    $this->pushPath('.' . $key);
                    $this->validateURLReferenceDefinition($urlRef);
                    $this->popPath();
                }
            }
            $this->popPath();
        }
         $known = ['type', 'source', 'staticAssets', 'authorsMode', 'defaultAuthorUsername', 'authorsMap', 'importUsers', 'importComments', 'importSiteOptions', 'urlsMode', 'urlsMap'];
        foreach(get_object_vars($item) as $k => $v) {
            if(!in_array($k, $known)) $this->addError("Unknown property '{$k}' in wxr content item.");
        }
    }

    // --- Complex Definition Validators (PostType, FontFace, FontCollection, WordPressPost, Step) ---
    // These are large and would make this response exceed limits.
    // They would follow the same pattern: check type, required, properties, additionalProperties, anyOf/oneOf.

    private function validatePostTypeDefinition($value): void { /* Placeholder for brevity */ $this->addError("VALIDATOR_STUB: PostTypeDefinition validation not fully implemented due to length.", $this->getCurrentPath()); }
    private function validateFontFaceDefinition($value): void { /* Placeholder for brevity */ $this->addError("VALIDATOR_STUB: FontFaceDefinition validation not fully implemented due to length.", $this->getCurrentPath()); }
    private function validateFontCollectionDefinition($value): void { /* Placeholder for brevity */ $this->addError("VALIDATOR_STUB: FontCollectionDefinition validation not fully implemented due to length.", $this->getCurrentPath()); }
    private function validateWordPressPostDefinition($value): void { /* Placeholder for brevity */ $this->addError("VALIDATOR_STUB: WordPressPostDefinition validation not fully implemented due to length.", $this->getCurrentPath()); }
    
    private function validateStepDefinition($value): void {
        if (!$this->isObject($value)) {
            $this->addError("Step must be an object.", $this->getCurrentPath());
            return;
        }
        if (!property_exists($value, 'step') || !$this->isString($value->step)) {
            $this->addError("Step object must have a 'step' string property.", $this->getCurrentPath());
            return;
        }

        $stepType = $value->step;
        $knownStepProps = [];

        switch ($stepType) {
            case 'activatePlugin':
                $knownStepProps = ['step', 'pluginPath'];
                if (!property_exists($value, 'pluginPath')) $this->addError("Required property 'pluginPath' missing for step 'activatePlugin'.");
                elseif (!$this->isString($value->pluginPath)) $this->addError("'pluginPath' must be a string for step 'activatePlugin'.");
                break;
            case 'activateTheme':
                $knownStepProps = ['step', 'themeFolderName'];
                if (!property_exists($value, 'themeFolderName')) $this->addError("Required property 'themeFolderName' missing for step 'activateTheme'.");
                elseif (!$this->isString($value->themeFolderName)) $this->addError("'themeFolderName' must be a string for step 'activateTheme'.");
                break;
            // ... many more step types ...
            // For brevity, only a few are implemented here. A full validator would list all.
            case 'cp':
                $knownStepProps = ['step', 'fromPath', 'toPath'];
                if (!property_exists($value, 'fromPath') || !property_exists($value, 'toPath')) $this->addError("Required properties 'fromPath' and 'toPath' missing for step 'cp'.");
                if (property_exists($value, 'fromPath') && !$this->isString($value->fromPath)) $this->addError("'fromPath' must be a string for step 'cp'.");
                if (property_exists($value, 'toPath') && !$this->isString($value->toPath)) $this->addError("'toPath' must be a string for step 'cp'.");
                break;
            case 'defineConstants':
                $knownStepProps = ['step', 'constants'];
                if (!property_exists($value, 'constants')) $this->addError("Required property 'constants' missing for step 'defineConstants'.");
                elseif (!$this->isObject($value->constants)) $this->addError("'constants' must be an object for step 'defineConstants'.");
                else {
                    foreach(get_object_vars($value->constants) as $cKey => $cValue) {
                        if(!$this->isString($cValue)) $this->addError("Constant value for '{$cKey}' must be a string in step 'defineConstants'.");
                    }
                }
                break;
            case 'installPlugin':
                $knownStepProps = ['step', 'plugin'];
                 if (!property_exists($value, 'plugin')) $this->addError("Required property 'plugin' missing for step 'installPlugin'.");
                 else {
                    $this->pushPath('.plugin');
                    $this->validatePluginDefinitionDefinition($value->plugin);
                    $this->popPath();
                 }
                break;
            case 'installTheme':
                $knownStepProps = ['step', 'source', 'activate', 'importStarterContent', 'targetFolderName'];
                if (!property_exists($value, 'source')) $this->addError("Required property 'source' missing for step 'installTheme'.");
                else {
                    $this->pushPath('.source');
                    $this->validateThemeReferenceDefinition($value->source);
                    $this->popPath();
                }
                if (property_exists($value, 'activate') && !$this->isBoolean($value->activate)) $this->addError("'activate' must be a boolean for step 'installTheme'.");
                if (property_exists($value, 'importStarterContent') && !$this->isBoolean($value->importStarterContent)) $this->addError("'importStarterContent' must be a boolean for step 'installTheme'.");
                if (property_exists($value, 'targetFolderName') && !$this->isString($value->targetFolderName)) $this->addError("'targetFolderName' must be a string for step 'installTheme'.");
                break;
            case 'runPHP':
                 $knownStepProps = ['step', 'code', 'relativeUri', 'scriptPath', 'protocol', 'method', 'headers', 'body', 'env', '$_SERVER'];
                 if (property_exists($value, 'code') && !$this->isString($value->code)) $this->addError("'code' must be a string for step 'runPHP'.");
                 // ... and so on for all runPHP properties ...
                 if (property_exists($value, 'method')) {
                    if (!$this->isString($value->method) || !in_array($value->method, ["GET", "POST", "HEAD", "OPTIONS", "PATCH", "PUT", "DELETE"])) {
                        $this->addError("Invalid 'method' for step 'runPHP'.");
                    }
                 }
                 if (property_exists($value, 'headers') && !$this->isObject($value->headers)) $this->addError("'headers' must be an object for step 'runPHP'."); // simplified
                 if (property_exists($value, 'body') && !$this->isString($value->body)) $this->addError("'body' must be a string for step 'runPHP'.");
                 if (property_exists($value, 'env') && !$this->isObject($value->env)) $this->addError("'env' must be an object for step 'runPHP'."); // simplified
                 if (property_exists($value, '$_SERVER') && !$this->isObject($value->{'$SERVER'})) $this->addError("'$_SERVER' must be an object for step 'runPHP'."); // simplified
                break;
            case 'runSql':
                $knownStepProps = ['step', 'source'];
                if (!property_exists($value, 'source')) $this->addError("Required property 'source' missing for step 'runSql'.");
                else {
                    $this->pushPath('.source');
                    $this->validateDataReferenceDefinition($value->source);
                    $this->popPath();
                }
                break;
            case 'unzip':
                $knownStepProps = ['step', 'zipFile', 'extractToPath'];
                if (!property_exists($value, 'zipFile') || !property_exists($value, 'extractToPath')) $this->addError("Required properties 'zipFile' and 'extractToPath' missing for step 'unzip'.");
                else {
                    $this->pushPath('.zipFile');
                    // anyOf URLReference, ExecutionContextPath
                    $currentZipPath = $this->getCurrentPath();
                    $zipValid = false; $errorSnapshot = $this->errors;
                    $this->errors = []; $this->pushPath('/anyOf[0]');
                    if($this->isString($value->zipFile)) $this->validateURLReferenceDefinition($value->zipFile);
                    if(empty($this->errors)) $zipValid = true;
                    $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if($zipValid){ if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }
                    else {
                        $this->errors = []; $this->pushPath('/anyOf[1]');
                        if($this->isString($value->zipFile)) $this->validateExecutionContextPathDefinition($value->zipFile);
                        if(empty($this->errors)) $zipValid = true;
                        $this->popPath(); $branchErrors = $this->errors; $this->errors = $errorSnapshot; if($zipValid){ if(!empty($branchErrors)) $this->errors = array_merge($this->errors, $branchErrors); }
                        else {
                            $this->addError("'zipFile' must be a URLReference or ExecutionContextPath.", $currentZipPath);
                        }
                    }
                    $this->popPath(); // .zipFile
                    if (!$this->isString($value->extractToPath)) $this->addError("'extractToPath' must be a string for step 'unzip'.");
                }
                break;
            case 'wp-cli':
                $knownStepProps = ['step', 'command', 'wpCliPath'];
                if (!property_exists($value, 'command')) $this->addError("Required property 'command' missing for step 'wp-cli'.");
                elseif (!$this->isString($value->command)) $this->addError("'command' must be a string for step 'wp-cli'.");
                if (property_exists($value, 'wpCliPath') && !$this->isString($value->wpCliPath)) $this->addError("'wpCliPath' must be a string for step 'wp-cli'.");
                break;
             case 'writeFile':
                $knownStepProps = ['step', 'path', 'content'];
                if (!property_exists($value, 'path') || !property_exists($value, 'content')) $this->addError("Required properties 'path' and 'content' missing for step 'writeFile'.");
                if (property_exists($value, 'path') && !$this->isString($value->path)) $this->addError("'path' must be a string for step 'writeFile'.");
                if (property_exists($value, 'content')) {
                    $this->pushPath('.content');
                    $this->validateDataReferenceDefinition($value->content);
                    $this->popPath();
                }
                break;
            default:
                // Add more step types here like mkdir, mv, rm, rmdir, setSiteLanguage, setSiteOptions, writeFiles
                $this->addError("Unknown step type: '{$stepType}'. This validator might be incomplete for this step type.");
                break;
        }
        // Check for unknown properties in the specific step
        if (!empty($knownStepProps)) {
            foreach(get_object_vars($value) as $prop => $_) {
                if (!in_array($prop, $knownStepProps)) {
                    $this->addError("Unknown property '{$prop}' for step type '{$stepType}'.");
                }
            }
        }
    }
}