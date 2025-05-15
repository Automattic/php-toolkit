<?php
// Script 3: generate-validator-class.php

// Load JSON Schema (assuming it is saved as schema.json in the same directory)
$schemaFile = $argv[1];// ?? __DIR__ . '/schema.json';
$schemaData = json_decode(file_get_contents($schemaFile), true);
if (!$schemaData) {
    exit("Failed to parse schema.json\n");
}

// Determine root definition name (if $ref is used at top level)
$definitions = isset($schemaData['definitions']) ? $schemaData['definitions'] : array();
$rootDefName = null;
if (isset($schemaData['$ref'])) {
    // e.g., "#/definitions/Person" -> "Person"
    $parts = explode('/', $schemaData['$ref']);
    $rootDefName = end($parts);
} else {
    $rootDefName = 'RootType';
    // Use the schema itself as a root definition
    $definitions[$rootDefName] = $schemaData;
    unset($definitions[$rootDefName]['definitions']);  // avoid recursion if any
}

// Begin generating PHP class code
$className = 'SchemaValidator';
$out = '';
$out .= "<?php\n\n";
$out .= "class $className {\n";
$out .= "    protected \$errors = array();\n\n";
$out .= "    /**\n";
$out .= "     * Validate data against the schema. Returns an array of {path, message} errors.\n";
$out .= "     */\n";
$out .= "    public function validate(\$data) {\n";
$out .= "        \$this->errors = array();\n";
$out .= "        \$this->validate{$rootDefName}(\$data, '$');\n";
$out .= "        return \$this->errors;\n";
$out .= "    }\n\n";
$out .= "    protected function addError(\$path, \$message) {\n";
$out .= "        \$this->errors[] = array('path' => \$path, 'message' => \$message);\n";
$out .= "    }\n\n";
$out .= "    // Helper to check if an array is associative (object) vs sequential (list)\n";
$out .= "    protected function isAssoc(\$arr) {\n";
$out .= "        if (!is_array(\$arr)) return false;\n";
$out .= "        \$keys = array_keys(\$arr);\n";
$out .= "        return \$keys !== array_keys(\$keys);\n";
$out .= "    }\n\n";

// Function to generate code for each definition recursively
function generateValidatorFunction($defName, $schema, &$out) {
    $indent = "    ";
    $out .= "{$indent}protected function validate{$defName}(\$value, \$path) {\n";
    // Handle union types (oneOf) at the top level of this definition
    if (isset($schema['oneOf']) && !isset($schema['type'])) {
        // If a discriminator is specified for this union
        if (isset($schema['discriminator'])) {
            $discProp = $schema['discriminator']['propertyName'];
            $out .= "{$indent}    // Discriminator-based union validation for {$defName}\n";
            $out .= "{$indent}    if (!is_array(\$value) || !\$this->isAssoc(\$value)) {\n";
            $out .= "{$indent}        \$this->addError(\$path, 'Expected object with discriminator \"{$discProp}\" for {$defName}');\n";
            $out .= "{$indent}        return;\n";
            $out .= "{$indent}    }\n";
            $out .= "{$indent}    if (!isset(\$value['{$discProp}'])) {\n";
            $out .= "{$indent}        \$this->addError(\$path . '.{$discProp}', 'Missing discriminator property \"{$discProp}\"');\n";
            $out .= "{$indent}        return;\n";
            $out .= "{$indent}    }\n";
            $out .= "{$indent}    \$discValue = \$value['{$discProp}'];\n";
            // Generate mapping checks
            foreach ($schema['discriminator']['mapping'] as $discVal => $ref) {
                if ($ref) {
                    $targetDef = end(explode('/', $ref));
                    $out .= "{$indent}    if (\$discValue === '{$discVal}') {\n";
                    $out .= "{$indent}        \$this->validate{$targetDef}(\$value, \$path);\n";
                    $out .= "{$indent}        return;\n";
                    $out .= "{$indent}    }\n";
                }
            }
            // Unknown discriminator value
            $allowed = implode(', ', array_map(function($v){ return "'$v'"; }, array_keys($schema['discriminator']['mapping'])));
            $out .= "{$indent}    \$this->addError(\$path . '.{$discProp}', 'Invalid discriminator value. Allowed: {$allowed}');\n";
        } else {
            $out .= "{$indent}    // Union type without discriminator for {$defName}\n";
            $out .= "{$indent}    \$match = false;\n";
            // Try each oneOf schema in sequence (shallow validation to find a match)
            foreach ($schema['oneOf'] as $index => $subSchema) {
                if (isset($subSchema['$ref'])) {
                    $subDef = end(explode('/', $subSchema['$ref']));
                    // Attempt validation by calling the sub-definition validator
                    $out .= "{$indent}    if (!\$match) {\n";
                    $out .= "{$indent}        \$prevErrors = \$this->errors; // backup current errors\n";
                    $out .= "{$indent}        \$this->validate{$subDef}(\$value, \$path);\n";
                    $out .= "{$indent}        if (empty(array_diff_key(\$this->errors, \$prevErrors))) {\n";
                    $out .= "{$indent}            // No new errors added, assume valid for this schema\n";
                    $out .= "{$indent}            \$match = true;\n";
                    $out .= "{$indent}        }\n";
                    $out .= "{$indent}        // Restore errors (we will report only if no variant matches)\n";
                    $out .= "{$indent}        \$this->errors = \$prevErrors;\n";
                    $out .= "{$indent}    }\n";
                } else if (isset($subSchema['type'])) {
                    // Basic type check for non-ref schema variants
                    $typeCheck = '';
                    switch ($subSchema['type']) {
                        case 'object': 
                            $typeCheck = "is_array(\$value) && \$this->isAssoc(\$value)";
                            break;
                        case 'array':
                            $typeCheck = "is_array(\$value) && !\$this->isAssoc(\$value)";
                            break;
                        case 'string':
                            $typeCheck = "is_string(\$value)";
                            break;
                        case 'number':
                            $typeCheck = "is_int(\$value) || is_float(\$value)";
                            break;
                        case 'integer':
                            $typeCheck = "is_int(\$value)";
                            break;
                        case 'boolean':
                            $typeCheck = "is_bool(\$value)";
                            break;
                        default:
                            $typeCheck = ""; 
                    }
                    if ($typeCheck) {
                        $out .= "{$indent}    \$match = \$match || ({$typeCheck});\n";
                    }
                }
            }
            $out .= "{$indent}    if (!\$match) {\n";
            $out .= "{$indent}        \$this->addError(\$path, 'Value does not match any type in {$defName} union');\n";
            $out .= "{$indent}    }\n";
        }
    }
    // Handle typed schemas (object, array, primitive)
    if (isset($schema['type'])) {
        switch ($schema['type']) {
            case 'object':
                $out .= "{$indent}    // Validate object structure for {$defName}\n";
                $out .= "{$indent}    if (!is_array(\$value) || !\$this->isAssoc(\$value)) {\n";
                $out .= "{$indent}        \$this->addError(\$path, 'Expected object for {$defName}');\n";
                $out .= "{$indent}        return;\n";
                $out .= "{$indent}    }\n";
                // Required properties
                if (isset($schema['required'])) {
                    foreach ($schema['required'] as $prop) {
                        $out .= "{$indent}    if (!isset(\$value['{$prop}'])) {\n";
                        $out .= "{$indent}        \$this->addError(\$path . '.{$prop}', 'Missing required property \"{$prop}\"');\n";
                        $out .= "{$indent}    }\n";
                    }
                }
                // Additional properties check
                if (isset($schema['additionalProperties']) && $schema['additionalProperties'] === false && isset($schema['properties'])) {
                    $allowedProps = array_keys($schema['properties']);
                    $allowedList = implode("', '", $allowedProps);
                    $out .= "{$indent}    foreach (\$value as \$key => \$unused) {\n";
                    $out .= "{$indent}        if (!in_array(\$key, array('{$allowedList}'))) {\n";
                    $out .= "{$indent}            \$this->addError(\$path . '.'.\$key, 'Unexpected property \"'.\$key.'\"');\n";
                    $out .= "{$indent}        }\n";
                    $out .= "{$indent}    }\n";
                }
                // Validate each defined property
                if (isset($schema['properties'])) {
                    foreach ($schema['properties'] as $prop => $propSchema) {
                        $out .= "{$indent}    if (isset(\$value['{$prop}'])) {\n";
                        $out .= "{$indent}        \$propValue = \$value['{$prop}'];\n";
                        $propPath = "\$path . '.{$prop}'";
                        // If property has a $ref to another definition
                        if (isset($propSchema['$ref'])) {
                            $subDef = end(explode('/', $propSchema['$ref']));
                            $out .= "{$indent}        \$this->validate{$subDef}(\$propValue, {$propPath});\n";
                        } 
                        // If property is a primitive or direct type
                        elseif (isset($propSchema['type'])) {
                            switch ($propSchema['type']) {
                                case 'string':
                                    $out .= "{$indent}        if (!is_string(\$propValue)) {\n";
                                    $out .= "{$indent}            \$this->addError({$propPath}, 'Expected string for \"{$prop}\"');\n";
                                    $out .= "{$indent}        }\n";
                                    break;
                                case 'number':
                                    $out .= "{$indent}        if (!(is_int(\$propValue) || is_float(\$propValue))) {\n";
                                    $out .= "{$indent}            \$this->addError({$propPath}, 'Expected number for \"{$prop}\"');\n";
                                    $out .= "{$indent}        }\n";
                                    break;
                                case 'integer':
                                    $out .= "{$indent}        if (!is_int(\$propValue)) {\n";
                                    $out .= "{$indent}            \$this->addError({$propPath}, 'Expected integer for \"{$prop}\"');\n";
                                    $out .= "{$indent}        }\n";
                                    break;
                                case 'boolean':
                                    $out .= "{$indent}        if (!is_bool(\$propValue)) {\n";
                                    $out .= "{$indent}            \$this->addError({$propPath}, 'Expected boolean for \"{$prop}\"');\n";
                                    $out .= "{$indent}        }\n";
                                    break;
                                case 'object':
                                    // Inline object schema – validate recursively within this context
                                    $out .= "{$indent}        if (!is_array(\$propValue) || !\$this->isAssoc(\$propValue)) {\n";
                                    $out .= "{$indent}            \$this->addError({$propPath}, 'Expected object for \"{$prop}\"');\n";
                                    $out .= "{$indent}        } else {\n";
                                    // Recursively validate inline object properties (if any defined)
                                    if (isset($propSchema['properties'])) {
                                        // Required properties of inline object
                                        if (isset($propSchema['required'])) {
                                            foreach ($propSchema['required'] as $subReq) {
                                                $out .= "{$indent}            if (!isset(\$propValue['{$subReq}'])) {\n";
                                                $out .= "{$indent}                \$this->addError({$propPath} . '.{$subReq}', 'Missing required property \"{$subReq}\"');\n";
                                                $out .= "{$indent}            }\n";
                                            }
                                        }
                                        // Additional properties in inline object
                                        if (isset($propSchema['additionalProperties']) && $propSchema['additionalProperties'] === false) {
                                            $allowedKeys = array_keys($propSchema['properties']);
                                            $allowedList = implode("', '", $allowedKeys);
                                            $out .= "{$indent}            foreach (\$propValue as \$key => \$unused) {\n";
                                            $out .= "{$indent}                if (!in_array(\$key, array('{$allowedList}'))) {\n";
                                            $out .= "{$indent}                    \$this->addError({$propPath} . '.'.\$key, 'Unexpected property \"'.\$key.'\"');\n";
                                            $out .= "{$indent}                }\n";
                                            $out .= "{$indent}            }\n";
                                        }
                                        // Validate each property of inline object
                                        foreach ($propSchema['properties'] as $subKey => $subSchema) {
                                            $subPath = "\$path . '.{$prop}.{$subKey}'";
                                            $out .= "{$indent}            if (isset(\$propValue['{$subKey}'])) {\n";
                                            $out .= "{$indent}                \$subVal = \$propValue['{$subKey}'];\n";
                                            if (isset($subSchema['$ref'])) {
                                                $subDefInline = end(explode('/', $subSchema['$ref']));
                                                $out .= "{$indent}                \$this->validate{$subDefInline}(\$subVal, {$subPath});\n";
                                            } elseif (isset($subSchema['type'])) {
                                                switch ($subSchema['type']) {
                                                    case 'string':
                                                        $out .= "{$indent}                if (!is_string(\$subVal)) {\n";
                                                        $out .= "{$indent}                    \$this->addError({$subPath}, 'Expected string');\n";
                                                        $out .= "{$indent}                }\n";
                                                        break;
                                                    case 'number':
                                                        $out .= "{$indent}                if (!(is_int(\$subVal) || is_float(\$subVal))) {\n";
                                                        $out .= "{$indent}                    \$this->addError({$subPath}, 'Expected number');\n";
                                                        $out .= "{$indent}                }\n";
                                                        break;
                                                    case 'integer':
                                                        $out .= "{$indent}                if (!is_int(\$subVal)) {\n";
                                                        $out .= "{$indent}                    \$this->addError({$subPath}, 'Expected integer');\n";
                                                        $out .= "{$indent}                }\n";
                                                        break;
                                                    case 'boolean':
                                                        $out .= "{$indent}                if (!is_bool(\$subVal)) {\n";
                                                        $out .= "{$indent}                    \$this->addError({$subPath}, 'Expected boolean');\n";
                                                        $out .= "{$indent}                }\n";
                                                        break;
                                                }
                                            }
                                            // (Nested arrays or objects in deeper inline schemas can be handled similarly if needed)
                                            $out .= "{$indent}            }\n";
                                        }
                                    }
                                    $out .= "{$indent}        }\n";
                                    break;
                                case 'array':
                                    $out .= "{$indent}        if (!is_array(\$propValue) || \$this->isAssoc(\$propValue)) {\n";
                                    $out .= "{$indent}            \$this->addError({$propPath}, 'Expected array for \"{$prop}\"');\n";
                                    $out .= "{$indent}        } else {\n";
                                    $out .= "{$indent}            \$idx = 0;\n";
                                    $out .= "{$indent}            foreach (\$propValue as \$elem) {\n";
                                    $elemPath = "\$path . '.{$prop}[' . \$idx . ']'";
                                    if (isset($propSchema['items'])) {
                                        $itemsSchema = $propSchema['items'];
                                        if (isset($itemsSchema['$ref'])) {
                                            $itemDef = end(explode('/', $itemsSchema['$ref']));
                                            $out .= "{$indent}                \$this->validate{$itemDef}(\$elem, {$elemPath});\n";
                                        } elseif (isset($itemsSchema['type'])) {
                                            switch ($itemsSchema['type']) {
                                                case 'string':
                                                    $out .= "{$indent}                if (!is_string(\$elem)) {\n";
                                                    $out .= "{$indent}                    \$this->addError({$elemPath}, 'Expected string');\n";
                                                    $out .= "{$indent}                }\n";
                                                    break;
                                                case 'number':
                                                    $out .= "{$indent}                if (!(is_int(\$elem) || is_float(\$elem))) {\n";
                                                    $out .= "{$indent}                    \$this->addError({$elemPath}, 'Expected number');\n";
                                                    $out .= "{$indent}                }\n";
                                                    break;
                                                case 'integer':
                                                    $out .= "{$indent}                if (!is_int(\$elem)) {\n";
                                                    $out .= "{$indent}                    \$this->addError({$elemPath}, 'Expected integer');\n";
                                                    $out .= "{$indent}                }\n";
                                                    break;
                                                case 'boolean':
                                                    $out .= "{$indent}                if (!is_bool(\$elem)) {\n";
                                                    $out .= "{$indent}                    \$this->addError({$elemPath}, 'Expected boolean');\n";
                                                    $out .= "{$indent}                }\n";
                                                    break;
                                                case 'object':
                                                    // Inline object in array items
                                                    $out .= "{$indent}                if (!is_array(\$elem) || !\$this->isAssoc(\$elem)) {\n";
                                                    $out .= "{$indent}                    \$this->addError({$elemPath}, 'Expected object');\n";
                                                    $out .= "{$indent}                } else {\n";
                                                    if (isset($itemsSchema['properties'])) {
                                                        if (isset($itemsSchema['required'])) {
                                                            foreach ($itemsSchema['required'] as $reqKey) {
                                                                $out .= "{$indent}                    if (!isset(\$elem['{$reqKey}'])) {\n";
                                                                $out .= "{$indent}                        \$this->addError({$elemPath} . '.{$reqKey}', 'Missing required property \"{$reqKey}\"');\n";
                                                                $out .= "{$indent}                    }\n";
                                                            }
                                                        }
                                                        if (isset($itemsSchema['additionalProperties']) && $itemsSchema['additionalProperties'] === false) {
                                                            $allowedKeys = array_keys($itemsSchema['properties']);
                                                            $allowedList = implode("', '", $allowedKeys);
                                                            $out .= "{$indent}                    foreach (\$elem as \$key => \$unused) {\n";
                                                            $out .= "{$indent}                        if (!in_array(\$key, array('{$allowedList}'))) {\n";
                                                            $out .= "{$indent}                            \$this->addError({$elemPath} . '.'.\$key, 'Unexpected property \"'.\$key.'\"');\n";
                                                            $out .= "{$indent}                        }\n";
                                                            $out .= "{$indent}                    }\n";
                                                        }
                                                        // We won't recursively generate deeper nested arrays/objects beyond this for brevity
                                                    }
                                                    $out .= "{$indent}                }\n";
                                                    break;
                                            }
                                        } elseif (isset($itemsSchema['oneOf'])) {
                                            // If array items is a union type
                                            $out .= "{$indent}                // Validate union types in array items\n";
                                            $out .= "{$indent}                \$validItem = false;\n";
                                            foreach ($itemsSchema['oneOf'] as $idx => $optSchema) {
                                                if (isset($optSchema['$ref'])) {
                                                    $optDef = end(explode('/', $optSchema['$ref']));
                                                    $out .= "{$indent}                if (!\$validItem) {\n";
                                                    $out .= "{$indent}                    \$prevErr = \$this->errors;\n";
                                                    $out .= "{$indent}                    \$this->validate{$optDef}(\$elem, {$elemPath});\n";
                                                    $out .= "{$indent}                    if (empty(array_diff_key(\$this->errors, \$prevErr))) {\n";
                                                    $out .= "{$indent}                        \$validItem = true;\n";
                                                    $out .= "{$indent}                    }\n";
                                                    $out .= "{$indent}                    \$this->errors = \$prevErr;\n";
                                                    $out .= "{$indent}                }\n";
                                                } elseif (isset($optSchema['type'])) {
                                                    $typeCheck = '';
                                                    switch ($optSchema['type']) {
                                                        case 'string': $typeCheck = "is_string(\$elem)"; break;
                                                        case 'number': $typeCheck = "is_int(\$elem) || is_float(\$elem)"; break;
                                                        case 'integer': $typeCheck = "is_int(\$elem)"; break;
                                                        case 'boolean': $typeCheck = "is_bool(\$elem)"; break;
                                                        case 'object': $typeCheck = "is_array(\$elem) && \$this->isAssoc(\$elem)"; break;
                                                        case 'array': $typeCheck = "is_array(\$elem) && !\$this->isAssoc(\$elem)"; break;
                                                    }
                                                    if ($typeCheck) {
                                                        $out .= "{$indent}                \$validItem = \$validItem || ({$typeCheck});\n";
                                                    }
                                                }
                                            }
                                            $out .= "{$indent}                if (!\$validItem) {\n";
                                            $out .= "{$indent}                    \$this->addError({$elemPath}, 'Array item does not match any type');\n";
                                            $out .= "{$indent}                }\n";
                                        }
                                    }
                                    $out .= "{$indent}                \$idx++;\n";
                                    $out .= "{$indent}            }\n";
                                    $out .= "{$indent}        }\n";
                                    break;
                            }
                        }
                        // If property has an enum or const constraint
                        if (isset($propSchema['enum'])) {
                            $values = $propSchema['enum'];
                            $valsList = implode("', '", $values);
                            $out .= "{$indent}        if (!in_array(\$propValue, array('{$valsList}'))) {\n";
                            $out .= "{$indent}            \$this->addError({$propPath}, 'Value must be one of [{$valsList}]');\n";
                            $out .= "{$indent}        }\n";
                        }
                        if (isset($propSchema['const'])) {
                            $constVal = $propSchema['const'];
                            $constDisplay = var_export($constVal, true);
                            $out .= "{$indent}        if (\$propValue !== {$constDisplay}) {\n";
                            $out .= "{$indent}            \$this->addError({$propPath}, 'Value must equal {$constDisplay}');\n";
                            $out .= "{$indent}        }\n";
                        }
                        $out .= "{$indent}    }\n";  // end if isset property
                    }
                }
                break;
            case 'array':
                $out .= "{$indent}    // Validate array structure for {$defName}\n";
                $out .= "{$indent}    if (!is_array(\$value) || \$this->isAssoc(\$value)) {\n";
                $out .= "{$indent}        \$this->addError(\$path, 'Expected array for {$defName}');\n";
                $out .= "{$indent}        return;\n";
                $out .= "{$indent}    }\n";
                if (isset($schema['items'])) {
                    $out .= "{$indent}    \$idx = 0;\n";
                    $out .= "{$indent}    foreach (\$value as \$elem) {\n";
                    $elemPath = "\$path . '[' . \$idx . ']'";
                    $itemsSchema = $schema['items'];
                    if (isset($itemsSchema['$ref'])) {
                        $itemDef = end(explode('/', $itemsSchema['$ref']));
                        $out .= "{$indent}        \$this->validate{$itemDef}(\$elem, {$elemPath});\n";
                    } elseif (isset($itemsSchema['type'])) {
                        switch ($itemsSchema['type']) {
                            case 'string':
                                $out .= "{$indent}        if (!is_string(\$elem)) {\n";
                                $out .= "{$indent}            \$this->addError({$elemPath}, 'Expected string');\n";
                                $out .= "{$indent}        }\n";
                                break;
                            case 'number':
                                $out .= "{$indent}        if (!(is_int(\$elem) || is_float(\$elem))) {\n";
                                $out .= "{$indent}            \$this->addError({$elemPath}, 'Expected number');\n";
                                $out .= "{$indent}        }\n";
                                break;
                            case 'integer':
                                $out .= "{$indent}        if (!is_int(\$elem)) {\n";
                                $out .= "{$indent}            \$this->addError({$elemPath}, 'Expected integer');\n";
                                $out .= "{$indent}        }\n";
                                break;
                            case 'boolean':
                                $out .= "{$indent}        if (!is_bool(\$elem)) {\n";
                                $out .= "{$indent}            \$this->addError({$elemPath}, 'Expected boolean');\n";
                                $out .= "{$indent}        }\n";
                                break;
                            case 'object':
                                $out .= "{$indent}        if (!is_array(\$elem) || !\$this->isAssoc(\$elem)) {\n";
                                $out .= "{$indent}            \$this->addError({$elemPath}, 'Expected object');\n";
                                $out .= "{$indent}        } else {\n";
                                // For brevity, not fully expanding nested object-in-array validation here
                                $out .= "{$indent}            // (Inline object validation logic would go here if needed)\n";
                                $out .= "{$indent}        }\n";
                                break;
                            case 'array':
                                $out .= "{$indent}        if (!is_array(\$elem) || \$this->isAssoc(\$elem)) {\n";
                                $out .= "{$indent}            \$this->addError({$elemPath}, 'Expected array');\n";
                                $out .= "{$indent}        } else {\n";
                                $out .= "{$indent}            // (Nested array validation logic if needed)\n";
                                $out .= "{$indent}        }\n";
                                break;
                        }
                    } elseif (isset($itemsSchema['oneOf'])) {
                        $out .= "{$indent}        // Union type array items for {$defName}\n";
                        $out .= "{$indent}        \$validItem = false;\n";
                        foreach ($itemsSchema['oneOf'] as $optIdx => $optSchema) {
                            if (isset($optSchema['$ref'])) {
                                $optDef = end(explode('/', $optSchema['$ref']));
                                $out .= "{$indent}        if (!\$validItem) {\n";
                                $out .= "{$indent}            \$prevErr = \$this->errors;\n";
                                $out .= "{$indent}            \$this->validate{$optDef}(\$elem, {$elemPath});\n";
                                $out .= "{$indent}            if (empty(array_diff_key(\$this->errors, \$prevErr))) {\n";
                                $out .= "{$indent}                \$validItem = true;\n";
                                $out .= "{$indent}            }\n";
                                $out .= "{$indent}            \$this->errors = \$prevErr;\n";
                                $out .= "{$indent}        }\n";
                            } elseif (isset($optSchema['type'])) {
                                $typeCheck = "";
                                switch ($optSchema['type']) {
                                    case 'string': $typeCheck = "is_string(\$elem)"; break;
                                    case 'number': $typeCheck = "is_int(\$elem) || is_float(\$elem)"; break;
                                    case 'integer': $typeCheck = "is_int(\$elem)"; break;
                                    case 'boolean': $typeCheck = "is_bool(\$elem)"; break;
                                    case 'object': $typeCheck = "is_array(\$elem) && \$this->isAssoc(\$elem)"; break;
                                    case 'array': $typeCheck = "is_array(\$elem) && !\$this->isAssoc(\$elem)"; break;
                                }
                                if ($typeCheck) {
                                    $out .= "{$indent}        \$validItem = \$validItem || ({$typeCheck});\n";
                                }
                            }
                        }
                        $out .= "{$indent}        if (!\$validItem) {\n";
                        $out .= "{$indent}            \$this->addError({$elemPath}, 'Array item does not match any allowed schema');\n";
                        $out .= "{$indent}        }\n";
                    }
                    $out .= "{$indent}        \$idx++;\n";
                    $out .= "{$indent}    }\n";
                }
                break;
            case 'string':
                $out .= "{$indent}    if (!is_string(\$value)) {\n";
                $out .= "{$indent}        \$this->addError(\$path, 'Expected string');\n";
                $out .= "{$indent}    }\n";
                break;
            case 'number':
                $out .= "{$indent}    if (!(is_int(\$value) || is_float(\$value))) {\n";
                $out .= "{$indent}        \$this->addError(\$path, 'Expected number');\n";
                $out .= "{$indent}    }\n";
                break;
            case 'integer':
                $out .= "{$indent}    if (!is_int(\$value)) {\n";
                $out .= "{$indent}        \$this->addError(\$path, 'Expected integer');\n";
                $out .= "{$indent}    }\n";
                break;
            case 'boolean':
                $out .= "{$indent}    if (!is_bool(\$value)) {\n";
                $out .= "{$indent}        \$this->addError(\$path, 'Expected boolean');\n";
                $out .= "{$indent}    }\n";
                break;
        }
    }
    $out .= "{$indent}}\n\n";
}

// Generate validator functions for each definition
foreach ($definitions as $defName => $defSchema) {
    generateValidatorFunction($defName, $defSchema, $out);
}

// Close class definition
$out .= "}\n";

// Output the generated class code (you can also write this to a PHP file)
echo $out;
