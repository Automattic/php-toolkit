<?php

namespace WordPress\Blueprints;

/**
 * Blueprint V2 validator class.
 * 
 * Validates Blueprint JSON files against the schema without using a schema validation library.
 */
class BlueprintV2Validator {
    /**
     * Store validation errors.
     *
     * @var array
     */
    private $errors = [];

    /**
     * The blueprint data to validate.
     *
     * @var array
     */
    private $data;

    /**
     * Current path in the blueprint being validated (for error messages).
     *
     * @var string
     */
    private $current_path = '';

    /**
     * Validate a blueprint.
     *
     * @param array $data The blueprint data to validate.
     * @return bool True if the blueprint is valid, false otherwise.
     */
    public function validate($data) {
        $this->data = $data;
        $this->errors = [];
        
        // Check if data is an array/object
        if (!is_array($data)) {
            $this->add_error('', 'Blueprint must be a JSON object');
            return false;
        }
        
        // Check required fields
        $this->validate_required_fields($data, ['version'], '');
        
        // Only proceed with detailed validation if required fields exist
        if (empty($this->errors)) {
            // Validate version is 2
            if (!isset($data['version']) || $data['version'] !== 2) {
                $this->add_error('version', 'Version must be 2');
            }
            
            // Validate schema
            if (isset($data['$schema'])) {
                $this->validate_schema_reference($data['$schema']);
            }
            
            // Validate blueprint metadata
            if (isset($data['blueprintMeta'])) {
                $this->validate_blueprint_meta($data['blueprintMeta']);
            }
            
            // Validate site language
            if (isset($data['siteLanguage'])) {
                $this->validate_type($data['siteLanguage'], 'string', 'siteLanguage');
            }
            
            // Validate site options
            if (isset($data['siteOptions'])) {
                $this->validate_site_options($data['siteOptions']);
            }
            
            // Validate constants
            if (isset($data['constants'])) {
                $this->validate_constants($data['constants']);
            }
            
            // Validate WordPress version
            if (isset($data['wordpressVersion'])) {
                $this->validate_wordpress_version($data['wordpressVersion']);
            }
            
            // Validate PHP version
            if (isset($data['phpVersion'])) {
                $this->validate_php_version($data['phpVersion']);
            }
            
            // Validate active theme
            if (isset($data['activeTheme'])) {
                $this->validate_active_theme($data['activeTheme']);
            }
            
            // Validate themes
            if (isset($data['themes'])) {
                $this->validate_themes($data['themes']);
            }
            
            // Validate plugins
            if (isset($data['plugins'])) {
                $this->validate_plugins($data['plugins']);
            }
            
            // Validate mu plugins
            if (isset($data['muPlugins'])) {
                $this->validate_mu_plugins($data['muPlugins']);
            }
            
            // Validate post types
            if (isset($data['postTypes'])) {
                $this->validate_post_types();
            }
            
            // Validate fonts
            if (isset($data['fonts'])) {
                $this->validate_fonts();
            }
            
            // Validate media
            if (isset($data['media'])) {
                $this->validate_media();
            }
            
            // Validate content
            if (isset($data['content'])) {
                $this->validate_content();
            }
            
            // Validate users
            if (isset($data['users'])) {
                $this->validate_users();
            }
            
            // Validate roles
            if (isset($data['roles'])) {
                $this->validate_roles();
            }
            
            // Validate additional steps
            if (isset($data['additionalStepsAfterExecution'])) {
                $this->validate_additional_steps();
            }
        }
        
        return empty($this->errors);
    }

    /**
     * Get validation errors.
     *
     * @return array The validation errors.
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Add a validation error.
     *
     * @param string $path The path to the field with the error.
     * @param string $message The error message.
     */
    private function add_error($path, $message) {
        $full_path = empty($this->current_path) ? $path : 
            (empty($path) ? $this->current_path : $this->current_path . '.' . $path);
        
        $this->errors[] = [
            'path' => $full_path,
            'message' => $message,
        ];
    }

    /**
     * Set the current path for error reporting.
     *
     * @param string $path The path to set.
     */
    private function set_path($path) {
        $this->current_path = $path;
    }

    /**
     * Validate that required fields exist.
     *
     * @param array $data The data to check.
     * @param array $required_fields The required fields.
     * @param string $path The path to the data.
     * @return bool True if all required fields exist, false otherwise.
     */
    private function validate_required_fields($data, $required_fields, $path) {
        $valid = true;
        $old_path = $this->current_path;
        $this->current_path = $path;
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                $this->add_error($field, "Required field '$field' is missing");
                $valid = false;
            }
        }
        
        $this->current_path = $old_path;
        return $valid;
    }

    /**
     * Validate that a value is of the expected type.
     *
     * @param mixed $value The value to check.
     * @param string|array $expected_type The expected type(s).
     * @param string $path The path to the value.
     * @return bool True if the value is of the expected type, false otherwise.
     */
    private function validate_type($value, $expected_type, $path) {
        $old_path = $this->current_path;
        $this->current_path = $path;
        
        $valid = true;
        $types = is_array($expected_type) ? $expected_type : [$expected_type];
        
        $type_valid = false;
        foreach ($types as $type) {
            switch ($type) {
                case 'string':
                    if (is_string($value)) {
                        $type_valid = true;
                    }
                    break;
                case 'integer':
                case 'number':
                    if (is_int($value) || is_float($value)) {
                        $type_valid = true;
                    }
                    break;
                case 'boolean':
                    if (is_bool($value)) {
                        $type_valid = true;
                    }
                    break;
                case 'array':
                    if (is_array($value) && array_keys($value) === range(0, count($value) - 1)) {
                        $type_valid = true;
                    }
                    break;
                case 'object':
                    if (is_array($value) && array_keys($value) !== range(0, count($value) - 1)) {
                        $type_valid = true;
                    }
                    break;
            }
            
            if ($type_valid) {
                break;
            }
        }
        
        if (!$type_valid) {
            $type_str = is_array($expected_type) ? implode(' or ', $expected_type) : $expected_type;
            $this->add_error('', "Value must be of type $type_str");
            $valid = false;
        }
        
        $this->current_path = $old_path;
        return $valid;
    }

    /**
     * Validate URL format.
     *
     * @param string $url The URL to validate.
     * @param string $path The path to the URL.
     * @return bool True if the URL is valid, false otherwise.
     */
    private function validate_url($url, $path) {
        if (!is_string($url)) {
            $this->add_error($path, 'URL must be a string');
            return false;
        }
        
        if (!preg_match('/^https?:\/\/.*/', $url)) {
            $this->add_error($path, 'URL must start with http:// or https://');
            return false;
        }
        
        return true;
    }

    /**
     * Validate execution context path format.
     *
     * @param string $path The path to validate.
     * @param string $field_path The path to the field.
     * @return bool True if the path is valid, false otherwise.
     */
    private function validate_execution_context_path($path, $field_path) {
        if (!is_string($path)) {
            $this->add_error($field_path, 'Path must be a string');
            return false;
        }
        
        if (!preg_match('/^(\/|\.\/).*/', $path)) {
            $this->add_error($field_path, 'Path must start with / or ./ for execution context paths');
            return false;
        }
        
        return true;
    }

    /**
     * Validate blueprint metadata.
     */
    private function validate_blueprint_meta() {
        if (!isset($this->data['blueprintMeta'])) {
            return; // Optional field
        }
        
        $this->set_path('blueprintMeta');
        
        if (!$this->validate_type($this->data['blueprintMeta'], 'object', '')) {
            return;
        }
        
        $meta = $this->data['blueprintMeta'];
        
        // Validate individual fields
        if (isset($meta['name'])) {
            $this->validate_type($meta['name'], 'string', 'name');
        }
        
        if (isset($meta['description'])) {
            $this->validate_type($meta['description'], 'string', 'description');
        }
        
        if (isset($meta['version'])) {
            $this->validate_type($meta['version'], 'string', 'version');
        }
        
        if (isset($meta['authors'])) {
            if ($this->validate_type($meta['authors'], 'array', 'authors')) {
                foreach ($meta['authors'] as $i => $author) {
                    $this->validate_type($author, 'string', "authors[$i]");
                }
            }
        }
        
        if (isset($meta['authorUrl'])) {
            $this->validate_url($meta['authorUrl'], 'authorUrl');
        }
        
        if (isset($meta['donateLink'])) {
            $this->validate_url($meta['donateLink'], 'donateLink');
        }
        
        if (isset($meta['tags'])) {
            if ($this->validate_type($meta['tags'], 'array', 'tags')) {
                foreach ($meta['tags'] as $i => $tag) {
                    $this->validate_type($tag, 'string', "tags[$i]");
                }
            }
        }
        
        if (isset($meta['license'])) {
            if (!$this->validate_type($meta['license'], 'string', 'license')) {
                return;
            }
            
            // Validate license keyword if it's not a custom license string
            $license_keywords = [
                'AFL-3.0', 'Apache-2.0', 'Artistic-2.0', 'BSL-1.0', 'BSD-2-Clause', 
                'BSD-3-Clause', 'BSD-3-Clause-Clear', 'BSD-4-Clause', '0BSD', 'CC', 
                'CC0-1.0', 'CC-BY-4.0', 'CC-BY-SA-4.0', 'WTFPL', 'ECL-2.0', 'EPL-1.0', 
                'EPL-2.0', 'EUPL-1.1', 'AGPL-3.0', 'GPL', 'GPL-2.0', 'GPL-3.0', 'LGPL', 
                'LGPL-2.1', 'LGPL-3.0', 'ISC', 'LPPL-1.3c', 'MS-PL', 'MIT', 'MPL-2.0', 
                'OSL-3.0', 'PostgreSQL', 'OFL-1.1', 'NCSA', 'Unlicense', 'Zlib'
            ];
            
            // No error if it's a custom license string
            // (we don't need to check license_keywords as this is just a recommendation)
        }
    }

    /**
     * Validate site language.
     */
    private function validate_site_language() {
        if (!isset($this->data['siteLanguage'])) {
            return; // Optional field
        }
        
        $this->set_path('siteLanguage');
        
        if (!$this->validate_type($this->data['siteLanguage'], 'string', '')) {
            return;
        }
        
        // Could potentially validate against list of known language codes
        // but that's beyond the schema requirements
    }

    /**
     * Validate site options.
     */
    private function validate_site_options() {
        if (!isset($this->data['siteOptions'])) {
            return; // Optional field
        }
        
        $this->set_path('siteOptions');
        
        if (!$this->validate_type($this->data['siteOptions'], 'object', '')) {
            return;
        }
        
        $options = $this->data['siteOptions'];
        
        // Check if siteUrl is present (not allowed)
        if (isset($options['siteUrl'])) {
            $this->add_error('siteUrl', 'siteUrl option is not allowed in siteOptions');
        }
        
        // Validate specific properties
        if (isset($options['blogname'])) {
            $this->validate_type($options['blogname'], 'string', 'blogname');
        }
        
        if (isset($options['timezone_string'])) {
            $this->validate_type($options['timezone_string'], 'string', 'timezone_string');
        }
        
        if (isset($options['permalink_structure'])) {
            if (!is_string($options['permalink_structure']) && 
                !($options['permalink_structure'] === false)) {
                $this->add_error('permalink_structure', 'permalink_structure must be a string or false');
            }
        }
        
        // Validate that all other options are JSON-serializable (no custom objects)
        foreach ($options as $key => $value) {
            if ($key !== 'blogname' && $key !== 'timezone_string' && $key !== 'permalink_structure') {
                $this->validate_json_value($value, $key);
            }
        }
    }

    /**
     * Validate constants.
     */
    private function validate_constants() {
        if (!isset($this->data['constants'])) {
            return; // Optional field
        }
        
        $this->set_path('constants');
        
        if (!$this->validate_type($this->data['constants'], 'object', '')) {
            return;
        }
        
        $constants = $this->data['constants'];
        
        // Validate specific constants
        $boolean_constants = ['WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG'];
        
        foreach ($boolean_constants as $constant) {
            if (isset($constants[$constant])) {
                if (!is_bool($constants[$constant])) {
                    $this->add_error($constant, "$constant must be a boolean");
                }
            }
        }
        
        // Check all other constants are of allowed types
        foreach ($constants as $key => $value) {
            if (!in_array($key, $boolean_constants)) {
                if (!is_bool($value) && !is_string($value) && !is_numeric($value)) {
                    $this->add_error($key, "Constants must be of type boolean, string, or number");
                }
            }
        }
    }

    /**
     * Validate JSON value (recursively).
     *
     * @param mixed  $value The value to validate.
     * @param string $path  The path to the value.
     */
    private function validate_json_value($value, $path) {
        if (is_string($value) || is_bool($value) || is_numeric($value) || is_null($value)) {
            return; // Basic JSON types are valid
        } else if (is_array($value)) {
            if (array_keys($value) !== range(0, count($value) - 1)) {
                // Associative array (object)
                foreach ($value as $key => $item) {
                    $this->validate_json_value($item, "$path.$key");
                }
            } else {
                // Sequential array
                foreach ($value as $i => $item) {
                    $this->validate_json_value($item, "$path[$i]");
                }
            }
        } else {
            $this->add_error($path, 'Value is not a valid JSON-serializable value');
        }
    }

    /**
     * Validate WordPress version.
     */
    private function validate_wordpress_version() {
        if (!isset($this->data['wordpressVersion'])) {
            return; // Optional field
        }
        
        $this->set_path('wordpressVersion');
        
        $version = $this->data['wordpressVersion'];
        
        if (is_string($version)) {
            // Check if it's a URL
            if (preg_match('/^https?:\/\/.*/', $version)) {
                return; // Valid URL
            }
            
            // Check if it's an execution context path
            if (preg_match('/^(\/|\.\/).*/', $version)) {
                return; // Valid execution context path
            }
            
            // Check if it's a WordPress version
            $this->validate_wp_version_string($version, '');
        } else if (is_array($version)) {
            // Check if it's a version object
            if (!$this->validate_type($version, 'object', '')) {
                return;
            }
            
            // Must have minVersion
            if (!isset($version['minVersion'])) {
                $this->add_error('minVersion', 'minVersion is required when specifying a version range');
                return;
            }
            
            // Validate minVersion
            $this->validate_wp_version_string($version['minVersion'], 'minVersion');
            
            // Validate maxVersion if present
            if (isset($version['maxVersion'])) {
                $this->validate_wp_version_string($version['maxVersion'], 'maxVersion');
            }
            
            // Validate preferredVersion if present
            if (isset($version['preferredVersion'])) {
                $this->validate_wp_version_string($version['preferredVersion'], 'preferredVersion');
            }
        } else {
            $this->add_error('', 'wordpressVersion must be a string or an object with version constraints');
        }
    }
    
    /**
     * Validate a WordPress version string.
     *
     * @param string $version The version to validate.
     * @param string $path The path to the version.
     * @return bool True if the version is valid, false otherwise.
     */
    private function validate_wp_version_string($version, $path) {
        if (!is_string($version)) {
            $this->add_error($path, 'WordPress version must be a string');
            return false;
        }
        
        if ($version === 'latest') {
            return true; // Valid "latest" keyword
        }
        
        // Validate version format (X.Y or X.Y.Z or X.Y-betaZ or X.Y.Z-rcZ)
        if (!preg_match('/^\d+\.\d+$/', $version) && 
            !preg_match('/^\d+\.\d+\.\d+$/', $version) && 
            !preg_match('/^\d+\.\d+(\.\d+)?-(beta\d+|rc\d+)$/', $version)) {
            $this->add_error($path, 'Invalid WordPress version format. Expected format: X.Y, X.Y.Z, X.Y-betaZ, or X.Y.Z-rcZ');
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate PHP version.
     */
    private function validate_php_version() {
        if (!isset($this->data['phpVersion'])) {
            return; // Optional field
        }
        
        $this->set_path('phpVersion');
        
        $version = $this->data['phpVersion'];
        
        if (is_string($version)) {
            $this->validate_php_version_string($version, '');
        } else if (is_array($version)) {
            // Check if it's a version object
            if (!$this->validate_type($version, 'object', '')) {
                return;
            }
            
            // Validate minVersion if present
            if (isset($version['minVersion'])) {
                $this->validate_php_version_string($version['minVersion'], 'minVersion');
            }
            
            // Validate recommendedVersion if present
            if (isset($version['recommendedVersion'])) {
                $this->validate_php_version_string($version['recommendedVersion'], 'recommendedVersion');
            }
            
            // Validate maxVersion if present
            if (isset($version['maxVersion'])) {
                $this->validate_php_version_string($version['maxVersion'], 'maxVersion');
            }
        } else {
            $this->add_error('', 'phpVersion must be a string or an object with version constraints');
        }
    }
    
    /**
     * Validate a PHP version string.
     *
     * @param string $version The version to validate.
     * @param string $path The path to the version.
     * @return bool True if the version is valid, false otherwise.
     */
    private function validate_php_version_string($version, $path) {
        if (!is_string($version)) {
            $this->add_error($path, 'PHP version must be a string');
            return false;
        }
        
        if ($version === 'latest') {
            return true; // Valid "latest" keyword
        }
        
        // Validate version format (X.Y or X.Y.Z)
        if (!preg_match('/^\d+\.\d+$/', $version) && 
            !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            $this->add_error($path, 'Invalid PHP version format. Expected format: X.Y or X.Y.Z');
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate themes.
     */
    private function validate_themes() {
        // Validate activeTheme
        if (isset($this->data['activeTheme'])) {
            $this->set_path('activeTheme');
            $this->validate_theme_reference($this->data['activeTheme'], '');
        }
        
        // Validate themes array
        if (isset($this->data['themes'])) {
            $this->set_path('themes');
            
            if (!$this->validate_type($this->data['themes'], 'array', '')) {
                return;
            }
            
            foreach ($this->data['themes'] as $i => $theme) {
                $this->validate_theme_reference($theme, $i);
            }
        }
    }
    
    /**
     * Validate a theme reference.
     *
     * @param mixed $theme The theme reference to validate.
     * @param string $path The path to the theme reference.
     * @return bool True if the theme reference is valid, false otherwise.
     */
    private function validate_theme_reference($theme, $path) {
        if (is_string($theme)) {
            // Check if it's a URL
            if (preg_match('/^https?:\/\/.*/', $theme)) {
                return true; // Valid URL
            }
            
            // Check if it's an execution context path
            if (preg_match('/^(\/|\.\/).*/', $theme)) {
                return true; // Valid execution context path
            }
            
            // Check if it's a theme directory reference
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $theme) && 
                !preg_match('/^[a-zA-Z0-9_-]+@(latest|\d+\.\d+(\.\d+)?)$/', $theme)) {
                $this->add_error($path, 'Invalid theme directory reference. Expected format: themeslug or themeslug@version');
                return false;
            }
        } else {
            $this->add_error($path, 'Theme reference must be a string');
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate plugins.
     */
    private function validate_plugins() {
        if (!isset($this->data['plugins'])) {
            return; // Optional field
        }
        
        $this->set_path('plugins');
        
        if (!$this->validate_type($this->data['plugins'], 'array', '')) {
            return;
        }
        
        foreach ($this->data['plugins'] as $i => $plugin) {
            $this->set_path("plugins[$i]");
            
            if (is_string($plugin)) {
                $this->validate_plugin_string_reference($plugin, '');
            } else if (is_array($plugin)) {
                if (!$this->validate_type($plugin, 'object', '')) {
                    continue;
                }
                
                // Validate required source field
                if (!isset($plugin['source'])) {
                    $this->add_error('source', 'source is required for plugin definition');
                    continue;
                }
                
                $this->validate_plugin_string_reference($plugin['source'], 'source');
                
                // Validate optional fields
                if (isset($plugin['active']) && !is_bool($plugin['active'])) {
                    $this->add_error('active', 'active must be a boolean');
                }
                
                if (isset($plugin['activationOptions'])) {
                    if (!$this->validate_type($plugin['activationOptions'], 'object', 'activationOptions')) {
                        continue;
                    }
                    
                    // Validate that all activation options are JSON-serializable
                    foreach ($plugin['activationOptions'] as $key => $value) {
                        $this->validate_json_value($value, "activationOptions.$key");
                    }
                }
                
                if (isset($plugin['onError'])) {
                    if (!is_string($plugin['onError'])) {
                        $this->add_error('onError', 'onError must be a string');
                    } else if (!in_array($plugin['onError'], ['skip-plugin', 'throw'])) {
                        $this->add_error('onError', "onError must be one of: 'skip-plugin', 'throw'");
                    }
                }
            } else {
                $this->add_error('', 'Plugin definition must be a string or an object');
            }
        }
    }
    
    /**
     * Validate a plugin string reference.
     *
     * @param mixed $plugin The plugin reference to validate.
     * @param string $path The path to the plugin reference.
     * @return bool True if the plugin reference is valid, false otherwise.
     */
    private function validate_plugin_string_reference($plugin, $path) {
        if (!is_string($plugin)) {
            $this->add_error($path, 'Plugin reference must be a string');
            return false;
        }
        
        // Check if it's a URL
        if (preg_match('/^https?:\/\/.*/', $plugin)) {
            return true; // Valid URL
        }
        
        // Check if it's an execution context path
        if (preg_match('/^(\/|\.\/).*/', $plugin)) {
            return true; // Valid execution context path
        }
        
        // Check if it's a plugin directory reference
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $plugin) && 
            !preg_match('/^[a-zA-Z0-9_-]+@(latest|\d+\.\d+(\.\d+)?)$/', $plugin)) {
            $this->add_error($path, 'Invalid plugin directory reference. Expected format: pluginslug or pluginslug@version');
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate mu plugins.
     */
    private function validate_mu_plugins() {
        if (!isset($this->data['muPlugins'])) {
            return; // Optional field
        }
        
        $this->set_path('muPlugins');
        
        if (is_string($this->data['muPlugins'])) {
            // Check if it's a URL
            if (!preg_match('/^https?:\/\/.*/', $this->data['muPlugins'])) {
                $this->add_error('', 'muPlugins URL must start with http:// or https://');
            }
        } else if (is_array($this->data['muPlugins'])) {
            if (!$this->validate_type($this->data['muPlugins'], 'array', '')) {
                return;
            }
            
            foreach ($this->data['muPlugins'] as $i => $path) {
                $this->validate_execution_context_path($path, $i);
            }
        } else {
            $this->add_error('', 'muPlugins must be a URL or an array of execution context paths');
        }
    }

    /**
     * Validate post types.
     */
    private function validate_post_types() {
        if (!isset($this->data['postTypes'])) {
            return; // Optional field
        }
        
        $this->set_path('postTypes');
        
        if (!$this->validate_type($this->data['postTypes'], 'object', '')) {
            return;
        }
        
        foreach ($this->data['postTypes'] as $post_type_name => $post_type) {
            $this->set_path("postTypes.$post_type_name");
            
            // Post type can be an execution context path or a post type definition object
            if (is_string($post_type)) {
                $this->validate_execution_context_path($post_type, '');
                continue;
            }
            
            if (!$this->validate_type($post_type, 'object', '')) {
                continue;
            }
            
            // Validate known post type object properties
            if (isset($post_type['label'])) {
                $this->validate_type($post_type['label'], 'string', 'label');
            }
            
            if (isset($post_type['labels'])) {
                if (!$this->validate_type($post_type['labels'], 'object', 'labels')) {
                    continue;
                }
                
                // Validate that all label properties are strings
                foreach ($post_type['labels'] as $label_key => $label_value) {
                    $this->validate_type($label_value, 'string', "labels.$label_key");
                }
            }
            
            if (isset($post_type['description'])) {
                $this->validate_type($post_type['description'], 'string', 'description');
            }
            
            // Boolean properties
            $boolean_props = [
                'public', 'hierarchical', 'exclude_from_search', 
                'publicly_queryable', 'show_ui', 'show_in_admin_bar', 
                'show_in_nav_menus', 'show_in_rest', 'rename_capabilities',
                'map_meta_cap', 'can_export', 'delete_with_user'
            ];
            
            foreach ($boolean_props as $prop) {
                if (isset($post_type[$prop])) {
                    $this->validate_type($post_type[$prop], 'boolean', $prop);
                }
            }
            
            // String properties
            $string_props = [
                'rest_base', 'rest_namespace', 'rest_controller_class',
                'menu_icon', 'singular_capability_name', 'plural_capability_name',
                'query_var_name', 'register_meta_box_cb', 'enter_title_here'
            ];
            
            foreach ($string_props as $prop) {
                if (isset($post_type[$prop])) {
                    $this->validate_type($post_type[$prop], 'string', $prop);
                }
            }
            
            // Special properties with multiple possible types
            if (isset($post_type['show_in_menu'])) {
                if (!is_bool($post_type['show_in_menu']) && !is_string($post_type['show_in_menu'])) {
                    $this->add_error('show_in_menu', 'show_in_menu must be a boolean or string');
                }
            }
            
            if (isset($post_type['menu_position'])) {
                if (!is_string($post_type['menu_position']) && !is_numeric($post_type['menu_position'])) {
                    $this->add_error('menu_position', 'menu_position must be a string or number');
                }
            }
            
            // Array properties
            if (isset($post_type['taxonomies'])) {
                if ($this->validate_type($post_type['taxonomies'], 'array', 'taxonomies')) {
                    foreach ($post_type['taxonomies'] as $i => $taxonomy) {
                        $this->validate_type($taxonomy, 'string', "taxonomies[$i]");
                    }
                }
            }
            
            if (isset($post_type['supports'])) {
                if ($this->validate_type($post_type['supports'], 'array', 'supports')) {
                    foreach ($post_type['supports'] as $i => $support) {
                        $this->validate_type($support, 'string', "supports[$i]");
                    }
                }
            }
            
            // Complex properties
            if (isset($post_type['capability_type'])) {
                if (!is_string($post_type['capability_type']) && 
                    !(is_array($post_type['capability_type']) && 
                      count($post_type['capability_type']) == 2 && 
                      is_string($post_type['capability_type'][0]) && 
                      is_string($post_type['capability_type'][1]))) {
                    $this->add_error('capability_type', 'capability_type must be a string or an array of exactly two strings');
                }
            }
            
            if (isset($post_type['capabilities'])) {
                if ($this->validate_type($post_type['capabilities'], 'object', 'capabilities')) {
                    foreach ($post_type['capabilities'] as $cap_key => $cap_value) {
                        $this->validate_type($cap_value, 'string', "capabilities.$cap_key");
                    }
                }
            }
            
            if (isset($post_type['has_archive'])) {
                if (!is_bool($post_type['has_archive']) && !is_string($post_type['has_archive'])) {
                    $this->add_error('has_archive', 'has_archive must be a boolean or string');
                }
            }
            
            if (isset($post_type['rewrite'])) {
                if (!is_bool($post_type['rewrite']) && !is_array($post_type['rewrite'])) {
                    $this->add_error('rewrite', 'rewrite must be a boolean or an object');
                } else if (is_array($post_type['rewrite'])) {
                    // Validate rewrite object properties
                    if (isset($post_type['rewrite']['slug'])) {
                        $this->validate_type($post_type['rewrite']['slug'], 'string', 'rewrite.slug');
                    }
                    if (isset($post_type['rewrite']['with_front'])) {
                        $this->validate_type($post_type['rewrite']['with_front'], 'boolean', 'rewrite.with_front');
                    }
                    if (isset($post_type['rewrite']['pages'])) {
                        $this->validate_type($post_type['rewrite']['pages'], 'boolean', 'rewrite.pages');
                    }
                    if (isset($post_type['rewrite']['feeds'])) {
                        $this->validate_type($post_type['rewrite']['feeds'], 'boolean', 'rewrite.feeds');
                    }
                    if (isset($post_type['rewrite']['ep_mask'])) {
                        $this->validate_type($post_type['rewrite']['ep_mask'], 'number', 'rewrite.ep_mask');
                    }
                }
            }
            
            if (isset($post_type['query_var'])) {
                if (!is_bool($post_type['query_var']) && !is_string($post_type['query_var'])) {
                    $this->add_error('query_var', 'query_var must be a boolean or string');
                }
            }
            
            if (isset($post_type['template'])) {
                if (!$this->validate_type($post_type['template'], 'array', 'template')) {
                    continue;
                }
                
                foreach ($post_type['template'] as $i => $template_item) {
                    if (!is_array($template_item) || count($template_item) < 1 || count($template_item) > 2) {
                        $this->add_error("template[$i]", 'template item must be an array with 1 or 2 elements');
                        continue;
                    }
                    
                    if (!is_string($template_item[0])) {
                        $this->add_error("template[$i][0]", 'first element of template item must be a string');
                    }
                    
                    if (count($template_item) > 1 && !is_array($template_item[1])) {
                        $this->add_error("template[$i][1]", 'second element of template item must be an object');
                    }
                }
            }
            
            if (isset($post_type['template_lock'])) {
                if (!($post_type['template_lock'] === false || 
                      (is_string($post_type['template_lock']) && 
                       ($post_type['template_lock'] === 'all' || $post_type['template_lock'] === 'insert')))) {
                    $this->add_error('template_lock', "template_lock must be false, 'all', or 'insert'");
                }
            }
        }
    }

    /**
     * Validate fonts.
     */
    private function validate_fonts() {
        if (!isset($this->data['fonts'])) {
            return; // Optional field
        }
        
        $this->set_path('fonts');
        
        if (!$this->validate_type($this->data['fonts'], 'object', '')) {
            return;
        }
        
        foreach ($this->data['fonts'] as $font_name => $font) {
            $this->set_path("fonts.$font_name");
            
            // Font can be a data reference or a font collection
            if (is_string($font)) {
                // Validate as a data reference (URL or execution context path)
                if (preg_match('/^https?:\/\/.*/', $font)) {
                    // It's a URL
                    $this->validate_url($font, '');
                } else if (preg_match('/^(\/|\.\/).*/', $font)) {
                    // It's an execution context path
                    $this->validate_execution_context_path($font, '');
                } else {
                    $this->add_error('', 'Font reference must be a URL or an execution context path');
                }
                continue;
            }
            
            // Check if it's an inline file or directory
            if (is_array($font) && isset($font['filename']) && isset($font['content'])) {
                // Inline file
                $this->validate_type($font['filename'], 'string', 'filename');
                $this->validate_type($font['content'], 'string', 'content');
                continue;
            }
            
            if (is_array($font) && isset($font['name']) && isset($font['children'])) {
                // Inline directory
                $this->validate_type($font['name'], 'string', 'name');
                if (!$this->validate_type($font['children'], 'array', 'children')) {
                    continue;
                }
                
                // We won't recursively validate children for simplicity
                continue;
            }
            
            // Check if it's a Git path
            if (is_array($font) && isset($font['gitRepository'])) {
                $this->validate_url($font['gitRepository'], 'gitRepository');
                
                if (isset($font['ref'])) {
                    $this->validate_type($font['ref'], 'string', 'ref');
                }
                if (isset($font['path'])) {
                    $this->validate_type($font['path'], 'string', 'path');
                }
                if (isset($font['localDirectoryName'])) {
                    $this->validate_type($font['localDirectoryName'], 'string', 'localDirectoryName');
                }
                continue;
            }
            
            // File reference with humanReadableName
            if (is_array($font) && isset($font['file'])) {
                if (is_string($font['file'])) {
                    // Validate as a data reference (URL or execution context path)
                    if (preg_match('/^https?:\/\/.*/', $font['file'])) {
                        // It's a URL
                        $this->validate_url($font['file'], 'file');
                    } else if (preg_match('/^(\/|\.\/).*/', $font['file'])) {
                        // It's an execution context path
                        $this->validate_execution_context_path($font['file'], 'file');
                    } else {
                        $this->add_error('file', 'Font file reference must be a URL or an execution context path');
                    }
                } else {
                    // Could be an inline file/directory or git path, but we won't recurse for simplicity
                }
                
                if (isset($font['humanReadableName'])) {
                    $this->validate_type($font['humanReadableName'], 'string', 'humanReadableName');
                }
                continue;
            }
            
            // If we got here, check if it's a font collection
            if (!is_array($font) || !isset($font['font_families'])) {
                $this->add_error('', 'Font must be a data reference or a font collection with font_families');
                continue;
            }
            
            // Validate as a font collection
            if (!$this->validate_type($font['font_families'], 'array', 'font_families')) {
                continue;
            }
            
            foreach ($font['font_families'] as $i => $font_family) {
                $this->set_path("fonts.$font_name.font_families[$i]");
                
                if (!$this->validate_type($font_family, 'object', '')) {
                    continue;
                }
                
                if (!isset($font_family['font_family_settings'])) {
                    $this->add_error('font_family_settings', 'Required field font_family_settings is missing');
                    continue;
                }
                
                if (!$this->validate_type($font_family['font_family_settings'], 'object', 'font_family_settings')) {
                    continue;
                }
                
                // Validate font_family_settings
                $settings = $font_family['font_family_settings'];
                
                // Required fields
                if (!$this->validate_required_fields($settings, ['name', 'slug', 'fontFamily'], 'font_family_settings')) {
                    continue;
                }
                
                $this->validate_type($settings['name'], 'string', 'font_family_settings.name');
                $this->validate_type($settings['slug'], 'string', 'font_family_settings.slug');
                $this->validate_type($settings['fontFamily'], 'string', 'font_family_settings.fontFamily');
                
                if (isset($settings['preview'])) {
                    $this->validate_type($settings['preview'], 'string', 'font_family_settings.preview');
                }
                
                // Validate fontFace array if present
                if (isset($settings['fontFace'])) {
                    if (!$this->validate_type($settings['fontFace'], 'array', 'font_family_settings.fontFace')) {
                        continue;
                    }
                    
                    foreach ($settings['fontFace'] as $j => $face) {
                        $this->set_path("fonts.$font_name.font_families[$i].font_family_settings.fontFace[$j]");
                        
                        if (!$this->validate_type($face, 'object', '')) {
                            continue;
                        }
                        
                        // Required fields for fontFace
                        if (!$this->validate_required_fields($face, ['fontFamily', 'src'], '')) {
                            continue;
                        }
                        
                        $this->validate_type($face['fontFamily'], 'string', 'fontFamily');
                        
                        // src can be a single data reference or an array of them
                        if (is_array($face['src']) && !isset($face['src']['filename']) && !isset($face['src']['name']) && !isset($face['src']['gitRepository'])) {
                            // Array of data references
                            foreach ($face['src'] as $k => $src) {
                                // We're not validating deeply for simplicity
                                if (!is_string($src) && !is_array($src)) {
                                    $this->add_error("src[$k]", 'Font src must be a string or an object');
                                }
                            }
                        } else {
                            // Single data reference - we're not validating deeply for simplicity
                            if (!is_string($face['src']) && !is_array($face['src'])) {
                                $this->add_error('src', 'Font src must be a string or an object');
                            }
                        }
                        
                        // Optional string properties
                        $string_props = [
                            'preview', 'fontStyle', 'fontDisplay', 'fontStretch',
                            'ascentOverride', 'descentOverride', 'fontVariant',
                            'fontFeatureSettings', 'fontVariationSettings',
                            'lineGapOverride', 'sizeAdjust', 'unicodeRange'
                        ];
                        
                        foreach ($string_props as $prop) {
                            if (isset($face[$prop])) {
                                $this->validate_type($face[$prop], 'string', $prop);
                            }
                        }
                        
                        // fontWeight can be string or number
                        if (isset($face['fontWeight'])) {
                            if (!is_string($face['fontWeight']) && !is_numeric($face['fontWeight'])) {
                                $this->add_error('fontWeight', 'fontWeight must be a string or number');
                            }
                        }
                        
                        // fontDisplay must be one of the allowed values if specified
                        if (isset($face['fontDisplay'])) {
                            $allowed_displays = ['auto', 'block', 'fallback', 'swap', 'optional'];
                            if (!in_array($face['fontDisplay'], $allowed_displays)) {
                                $this->add_error('fontDisplay', "fontDisplay must be one of: " . implode(', ', $allowed_displays));
                            }
                        }
                    }
                }
                
                // Validate categories if present
                if (isset($font_family['categories'])) {
                    if (!$this->validate_type($font_family['categories'], 'array', 'categories')) {
                        continue;
                    }
                    
                    foreach ($font_family['categories'] as $j => $category) {
                        $this->validate_type($category, 'string', "categories[$j]");
                    }
                }
            }
        }
    }

    /**
     * Validate media.
     */
    private function validate_media() {
        if (!isset($this->data['media'])) {
            return; // Optional field
        }
        
        $this->set_path('media');
        
        if (!$this->validate_type($this->data['media'], 'array', '')) {
            return;
        }
        
        foreach ($this->data['media'] as $i => $media_item) {
            $this->set_path("media[$i]");
            
            // Media item can be a data reference or a media object
            if (is_string($media_item)) {
                // Validate as a data reference (URL or execution context path)
                if (preg_match('/^https?:\/\/.*/', $media_item)) {
                    // It's a URL
                    $this->validate_url($media_item, '');
                } else if (preg_match('/^(\/|\.\/).*/', $media_item)) {
                    // It's an execution context path
                    $this->validate_execution_context_path($media_item, '');
                } else {
                    $this->add_error('', 'Media reference must be a URL or an execution context path');
                }
                continue;
            }
            
            // Check if it's an inline file
            if (is_array($media_item) && isset($media_item['filename']) && isset($media_item['content'])) {
                // Inline file
                $this->validate_type($media_item['filename'], 'string', 'filename');
                $this->validate_type($media_item['content'], 'string', 'content');
                continue;
            }
            
            // Check if it's a Git path
            if (is_array($media_item) && isset($media_item['gitRepository'])) {
                $this->validate_url($media_item['gitRepository'], 'gitRepository');
                
                if (isset($media_item['ref'])) {
                    $this->validate_type($media_item['ref'], 'string', 'ref');
                }
                if (isset($media_item['path'])) {
                    $this->validate_type($media_item['path'], 'string', 'path');
                }
                if (isset($media_item['localDirectoryName'])) {
                    $this->validate_type($media_item['localDirectoryName'], 'string', 'localDirectoryName');
                }
                continue;
            }
            
            // If we got here, it should be a media object with source and optional metadata
            if (!is_array($media_item)) {
                $this->add_error('', 'Media item must be a data reference or an object with source and optional metadata');
                continue;
            }
            
            // Required field
            if (!isset($media_item['source'])) {
                $this->add_error('source', 'Required field source is missing');
                continue;
            }
            
            // Validate source (data reference)
            if (is_string($media_item['source'])) {
                // Validate as a data reference (URL or execution context path)
                if (preg_match('/^https?:\/\/.*/', $media_item['source'])) {
                    // It's a URL
                    $this->validate_url($media_item['source'], 'source');
                } else if (preg_match('/^(\/|\.\/).*/', $media_item['source'])) {
                    // It's an execution context path
                    $this->validate_execution_context_path($media_item['source'], 'source');
                } else {
                    $this->add_error('source', 'Media source reference must be a URL or an execution context path');
                }
            } else if (is_array($media_item['source'])) {
                // Could be an inline file, directory, or git path
                // We won't validate deeply for simplicity
            } else {
                $this->add_error('source', 'Media source must be a string or an object');
            }
            
            // Optional string fields
            $string_fields = ['title', 'description', 'alt', 'caption'];
            foreach ($string_fields as $field) {
                if (isset($media_item[$field])) {
                    $this->validate_type($media_item[$field], 'string', $field);
                }
            }
        }
    }

    /**
     * Validate content.
     */
    private function validate_content() {
        if (!isset($this->data['content'])) {
            return; // This should have been caught by required field check
        }
        
        $this->set_path('content');
        
        if (!$this->validate_type($this->data['content'], 'array', '')) {
            return;
        }
        
        // Validate each content item
        foreach ($this->data['content'] as $i => $content_item) {
            $this->set_path("content[$i]");
            
            if (!$this->validate_type($content_item, 'object', '')) {
                continue;
            }
            
            // Check required fields
            if (!$this->validate_required_fields($content_item, ['type'], '')) {
                continue;
            }
            
            // Validate content type
            $valid_types = ['post', 'page', 'attachment', 'term', 'user', 'option', 'menu', 'custom-post', 'posts', 'mysql-dump', 'wxr'];
            if (!in_array($content_item['type'], $valid_types)) {
                $this->add_error('type', "Invalid content type '{$content_item['type']}'. Valid types are: " . implode(', ', $valid_types));
            }
        }
    }

    /**
     * Validate users.
     */
    private function validate_users() {
        if (!isset($this->data['users'])) {
            return; // Optional field
        }
        
        $this->set_path('users');
        
        if (!$this->validate_type($this->data['users'], 'array', '')) {
            return;
        }
        
        foreach ($this->data['users'] as $i => $user) {
            $this->set_path("users[$i]");
            
            if (!$this->validate_type($user, 'object', '')) {
                continue;
            }
            
            // Required fields
            if (!$this->validate_required_fields($user, ['username', 'email', 'role'], '')) {
                continue;
            }
            
            // Validate field types
            $this->validate_type($user['username'], 'string', 'username');
            $this->validate_type($user['email'], 'string', 'email');
            $this->validate_type($user['role'], 'string', 'role');
            
            // Validate meta if present
            if (isset($user['meta'])) {
                if (!$this->validate_type($user['meta'], 'object', 'meta')) {
                    continue;
                }
                
                // Meta values should be strings
                foreach ($user['meta'] as $key => $value) {
                    $this->validate_type($value, 'string', "meta.$key");
                }
            }
        }
    }

    /**
     * Validate roles.
     */
    private function validate_roles() {
        if (!isset($this->data['roles'])) {
            return; // Optional field
        }
        
        $this->set_path('roles');
        
        if (!$this->validate_type($this->data['roles'], 'array', '')) {
            return;
        }
        
        foreach ($this->data['roles'] as $i => $role) {
            $this->set_path("roles[$i]");
            
            if (!$this->validate_type($role, 'object', '')) {
                continue;
            }
            
            // Required fields
            if (!$this->validate_required_fields($role, ['name', 'capabilities'], '')) {
                continue;
            }
            
            // Validate field types
            $this->validate_type($role['name'], 'string', 'name');
            
            if (!$this->validate_type($role['capabilities'], 'object', 'capabilities')) {
                continue;
            }
            
            // Capabilities values should be strings
            foreach ($role['capabilities'] as $cap_key => $cap_value) {
                $this->validate_type($cap_value, 'string', "capabilities.$cap_key");
            }
        }
    }

    /**
     * Validate additional steps.
     */
    private function validate_additional_steps() {
        if (!isset($this->data['additionalStepsAfterExecution'])) {
            return; // Optional field
        }
        
        $this->set_path('additionalStepsAfterExecution');
        
        if (!$this->validate_type($this->data['additionalStepsAfterExecution'], 'array', '')) {
            return;
        }
        
        foreach ($this->data['additionalStepsAfterExecution'] as $i => $step) {
            $this->set_path("additionalStepsAfterExecution[$i]");
            
            if (!$this->validate_type($step, 'object', '')) {
                continue;
            }
            
            // Required field: step
            if (!isset($step['step'])) {
                $this->add_error('step', 'Required field step is missing');
                continue;
            }
            
            $this->validate_type($step['step'], 'string', 'step');
            
            // Validate based on step type
            switch ($step['step']) {
                case 'activatePlugin':
                    $this->validate_activate_plugin_step($step);
                    break;
                case 'activateTheme':
                    $this->validate_activate_theme_step($step);
                    break;
                case 'cp':
                    $this->validate_cp_step($step);
                    break;
                case 'defineConstants':
                    $this->validate_define_constants_step($step);
                    break;
                case 'importThemeStarterContent':
                    $this->validate_import_theme_starter_content_step($step);
                    break;
                case 'installPlugin':
                    $this->validate_install_plugin_step($step);
                    break;
                case 'installTheme':
                    $this->validate_install_theme_step($step);
                    break;
                case 'mkdir':
                    $this->validate_mkdir_step($step);
                    break;
                case 'mv':
                    $this->validate_mv_step($step);
                    break;
                case 'rm':
                    $this->validate_rm_step($step);
                    break;
                case 'rmdir':
                    $this->validate_rmdir_step($step);
                    break;
                case 'runPHP':
                    $this->validate_run_php_step($step);
                    break;
                case 'runSql':
                    $this->validate_run_sql_step($step);
                    break;
                case 'setSiteLanguage':
                    $this->validate_set_site_language_step($step);
                    break;
                case 'setSiteOptions':
                    $this->validate_set_site_options_step($step);
                    break;
                case 'unzip':
                    $this->validate_unzip_step($step);
                    break;
                case 'wp-cli':
                    $this->validate_wp_cli_step($step);
                    break;
                case 'writeFile':
                    $this->validate_write_file_step($step);
                    break;
                case 'writeFiles':
                    $this->validate_write_files_step($step);
                    break;
                default:
                    $this->add_error('step', "Unknown step type: {$step['step']}");
            }
        }
    }
    
    /**
     * Validate activatePlugin step.
     *
     * @param array $step The step to validate.
     */
    private function validate_activate_plugin_step($step) {
        if (!$this->validate_required_fields($step, ['pluginPath'], '')) {
            return;
        }
        
        $this->validate_type($step['pluginPath'], 'string', 'pluginPath');
    }
    
    /**
     * Validate activateTheme step.
     *
     * @param array $step The step to validate.
     */
    private function validate_activate_theme_step($step) {
        if (!$this->validate_required_fields($step, ['themeFolderName'], '')) {
            return;
        }
        
        $this->validate_type($step['themeFolderName'], 'string', 'themeFolderName');
    }
    
    /**
     * Validate cp step.
     *
     * @param array $step The step to validate.
     */
    private function validate_cp_step($step) {
        if (!$this->validate_required_fields($step, ['fromPath', 'toPath'], '')) {
            return;
        }
        
        $this->validate_type($step['fromPath'], 'string', 'fromPath');
        $this->validate_type($step['toPath'], 'string', 'toPath');
    }
    
    /**
     * Validate defineConstants step.
     *
     * @param array $step The step to validate.
     */
    private function validate_define_constants_step($step) {
        if (!$this->validate_required_fields($step, ['constants'], '')) {
            return;
        }
        
        if (!$this->validate_type($step['constants'], 'object', 'constants')) {
            return;
        }
        
        foreach ($step['constants'] as $const_name => $const_value) {
            $this->validate_type($const_value, 'string', "constants.$const_name");
        }
    }
    
    /**
     * Validate importThemeStarterContent step.
     *
     * @param array $step The step to validate.
     */
    private function validate_import_theme_starter_content_step($step) {
        if (isset($step['themeSlug'])) {
            $this->validate_type($step['themeSlug'], 'string', 'themeSlug');
        }
    }
    
    /**
     * Validate installPlugin step.
     *
     * @param array $step The step to validate.
     */
    private function validate_install_plugin_step($step) {
        if (!$this->validate_required_fields($step, ['plugin'], '')) {
            return;
        }
        
        // Validate plugin, which can be a string or an object
        if (is_string($step['plugin'])) {
            // Plugin is a string reference
            if (preg_match('/^https?:\/\/.*/', $step['plugin'])) {
                // URL
                $this->validate_url($step['plugin'], 'plugin');
            } else if (preg_match('/^(\/|\.\/).*/', $step['plugin'])) {
                // Execution context path
                $this->validate_execution_context_path($step['plugin'], 'plugin');
            } else if (!preg_match('/^[a-zA-Z0-9_-]+$/', $step['plugin']) && 
                       !preg_match('/^[a-zA-Z0-9_-]+@(latest|\d+\.\d+(\.\d+)?)$/', $step['plugin'])) {
                $this->add_error('plugin', 'Invalid plugin directory reference. Expected format: pluginslug or pluginslug@version');
            }
        } else if (is_array($step['plugin'])) {
            // Plugin is an object
            if (!isset($step['plugin']['source'])) {
                $this->add_error('plugin.source', 'Required field source is missing');
                return;
            }
            
            if (is_string($step['plugin']['source'])) {
                // Validate source as string reference
                if (preg_match('/^https?:\/\/.*/', $step['plugin']['source'])) {
                    // URL
                    $this->validate_url($step['plugin']['source'], 'plugin.source');
                } else if (preg_match('/^(\/|\.\/).*/', $step['plugin']['source'])) {
                    // Execution context path
                    $this->validate_execution_context_path($step['plugin']['source'], 'plugin.source');
                } else if (!preg_match('/^[a-zA-Z0-9_-]+$/', $step['plugin']['source']) && 
                           !preg_match('/^[a-zA-Z0-9_-]+@(latest|\d+\.\d+(\.\d+)?)$/', $step['plugin']['source'])) {
                    $this->add_error('plugin.source', 'Invalid plugin directory reference. Expected format: pluginslug or pluginslug@version');
                }
            } else {
                $this->add_error('plugin.source', 'Plugin source must be a string');
            }
            
            // Optional fields
            if (isset($step['plugin']['active'])) {
                $this->validate_type($step['plugin']['active'], 'boolean', 'plugin.active');
            }
            
            if (isset($step['plugin']['activationOptions'])) {
                if (!$this->validate_type($step['plugin']['activationOptions'], 'object', 'plugin.activationOptions')) {
                    return;
                }
                
                // Validate all activation options are JSON-serializable
                foreach ($step['plugin']['activationOptions'] as $key => $value) {
                    $this->validate_json_value($value, "plugin.activationOptions.$key");
                }
            }
            
            if (isset($step['plugin']['onError'])) {
                if (!is_string($step['plugin']['onError'])) {
                    $this->add_error('plugin.onError', 'onError must be a string');
                } else if (!in_array($step['plugin']['onError'], ['skip-plugin', 'throw'])) {
                    $this->add_error('plugin.onError', "onError must be one of: 'skip-plugin', 'throw'");
                }
            }
        } else {
            $this->add_error('plugin', 'Plugin must be a string or an object');
        }
    }
    
    /**
     * Validate installTheme step.
     *
     * @param array $step The step to validate.
     */
    private function validate_install_theme_step($step) {
        if (!$this->validate_required_fields($step, ['source'], '')) {
            return;
        }
        
        // Validate the theme source
        if (is_string($step['source'])) {
            // Theme source is a string
            if (preg_match('/^https?:\/\/.*/', $step['source'])) {
                // URL
                $this->validate_url($step['source'], 'source');
            } else if (preg_match('/^(\/|\.\/).*/', $step['source'])) {
                // Execution context path
                $this->validate_execution_context_path($step['source'], 'source');
            } else if (!preg_match('/^[a-zA-Z0-9_-]+$/', $step['source']) && 
                       !preg_match('/^[a-zA-Z0-9_-]+@(latest|\d+\.\d+(\.\d+)?)$/', $step['source'])) {
                $this->add_error('source', 'Invalid theme directory reference. Expected format: themeslug or themeslug@version');
            }
        } else {
            $this->add_error('source', 'Theme source must be a string');
        }
        
        // Optional fields
        if (isset($step['activate'])) {
            $this->validate_type($step['activate'], 'boolean', 'activate');
        }
        
        if (isset($step['importStarterContent'])) {
            $this->validate_type($step['importStarterContent'], 'boolean', 'importStarterContent');
        }
        
        if (isset($step['targetFolderName'])) {
            $this->validate_type($step['targetFolderName'], 'string', 'targetFolderName');
        }
    }
    
    /**
     * Validate mkdir step.
     *
     * @param array $step The step to validate.
     */
    private function validate_mkdir_step($step) {
        if (!$this->validate_required_fields($step, ['path'], '')) {
            return;
        }
        
        $this->validate_type($step['path'], 'string', 'path');
    }
    
    /**
     * Validate mv step.
     *
     * @param array $step The step to validate.
     */
    private function validate_mv_step($step) {
        if (!$this->validate_required_fields($step, ['fromPath', 'toPath'], '')) {
            return;
        }
        
        $this->validate_type($step['fromPath'], 'string', 'fromPath');
        $this->validate_type($step['toPath'], 'string', 'toPath');
    }
    
    /**
     * Validate rm step.
     *
     * @param array $step The step to validate.
     */
    private function validate_rm_step($step) {
        if (!$this->validate_required_fields($step, ['path'], '')) {
            return;
        }
        
        $this->validate_type($step['path'], 'string', 'path');
    }
    
    /**
     * Validate rmdir step.
     *
     * @param array $step The step to validate.
     */
    private function validate_rmdir_step($step) {
        if (!$this->validate_required_fields($step, ['path'], '')) {
            return;
        }
        
        $this->validate_type($step['path'], 'string', 'path');
    }
    
    /**
     * Validate runPHP step.
     *
     * @param array $step The step to validate.
     */
    private function validate_run_php_step($step) {
        // At least one of code, relativeUri, or scriptPath should be specified
        if (!isset($step['code']) && !isset($step['relativeUri']) && !isset($step['scriptPath'])) {
            $this->add_error('', 'At least one of code, relativeUri, or scriptPath must be specified');
            return;
        }
        
        // Validate optional fields
        if (isset($step['code'])) {
            $this->validate_type($step['code'], 'string', 'code');
        }
        
        if (isset($step['relativeUri'])) {
            $this->validate_type($step['relativeUri'], 'string', 'relativeUri');
        }
        
        if (isset($step['scriptPath'])) {
            $this->validate_type($step['scriptPath'], 'string', 'scriptPath');
        }
        
        if (isset($step['protocol'])) {
            $this->validate_type($step['protocol'], 'string', 'protocol');
        }
        
        if (isset($step['method'])) {
            $valid_methods = ['GET', 'POST', 'HEAD', 'OPTIONS', 'PATCH', 'PUT', 'DELETE'];
            if (!is_string($step['method'])) {
                $this->add_error('method', 'method must be a string');
            } else if (!in_array($step['method'], $valid_methods)) {
                $this->add_error('method', 'method must be one of: ' . implode(', ', $valid_methods));
            }
        }
        
        if (isset($step['headers'])) {
            if (!$this->validate_type($step['headers'], 'object', 'headers')) {
                return;
            }
            
            foreach ($step['headers'] as $header_key => $header_value) {
                $this->validate_type($header_value, 'string', "headers.$header_key");
            }
        }
        
        if (isset($step['body'])) {
            $this->validate_type($step['body'], 'string', 'body');
        }
        
        if (isset($step['env'])) {
            if (!$this->validate_type($step['env'], 'object', 'env')) {
                return;
            }
            
            foreach ($step['env'] as $env_key => $env_value) {
                $this->validate_type($env_value, 'string', "env.$env_key");
            }
        }
        
        if (isset($step['$_SERVER'])) {
            if (!$this->validate_type($step['$_SERVER'], 'object', '$_SERVER')) {
                return;
            }
            
            foreach ($step['$_SERVER'] as $server_key => $server_value) {
                $this->validate_type($server_value, 'string', "\$_SERVER.$server_key");
            }
        }
    }
    
    /**
     * Validate runSql step.
     *
     * @param array $step The step to validate.
     */
    private function validate_run_sql_step($step) {
        if (!$this->validate_required_fields($step, ['source'], '')) {
            return;
        }
        
        // Validate source (data reference)
        if (is_string($step['source'])) {
            // Validate as a data reference (URL or execution context path)
            if (preg_match('/^https?:\/\/.*/', $step['source'])) {
                // URL
                $this->validate_url($step['source'], 'source');
            } else if (preg_match('/^(\/|\.\/).*/', $step['source'])) {
                // Execution context path
                $this->validate_execution_context_path($step['source'], 'source');
            } else {
                $this->add_error('source', 'SQL source reference must be a URL or an execution context path');
            }
        } else {
            $this->add_error('source', 'SQL source must be a string');
        }
    }
    
    /**
     * Validate setSiteLanguage step.
     *
     * @param array $step The step to validate.
     */
    private function validate_set_site_language_step($step) {
        if (!$this->validate_required_fields($step, ['language'], '')) {
            return;
        }
        
        $this->validate_type($step['language'], 'string', 'language');
    }
    
    /**
     * Validate setSiteOptions step.
     *
     * @param array $step The step to validate.
     */
    private function validate_set_site_options_step($step) {
        if (!$this->validate_required_fields($step, ['options'], '')) {
            return;
        }
        
        if (!$this->validate_type($step['options'], 'object', 'options')) {
            return;
        }
        
        // Validate that all options are JSON-serializable
        foreach ($step['options'] as $option_key => $option_value) {
            $this->validate_json_value($option_value, "options.$option_key");
        }
    }
    
    /**
     * Validate unzip step.
     *
     * @param array $step The step to validate.
     */
    private function validate_unzip_step($step) {
        if (!$this->validate_required_fields($step, ['zipFile', 'extractToPath'], '')) {
            return;
        }
        
        // Validate zipFile
        if (is_string($step['zipFile'])) {
            // Validate as a data reference (URL or execution context path)
            if (preg_match('/^https?:\/\/.*/', $step['zipFile'])) {
                // URL
                $this->validate_url($step['zipFile'], 'zipFile');
            } else if (preg_match('/^(\/|\.\/).*/', $step['zipFile'])) {
                // Execution context path
                $this->validate_execution_context_path($step['zipFile'], 'zipFile');
            } else {
                $this->add_error('zipFile', 'Zip file reference must be a URL or an execution context path');
            }
        } else {
            $this->add_error('zipFile', 'Zip file must be a string reference to a URL or path');
        }
        
        // Validate extractToPath
        $this->validate_type($step['extractToPath'], 'string', 'extractToPath');
    }
    
    /**
     * Validate wp-cli step.
     *
     * @param array $step The step to validate.
     */
    private function validate_wp_cli_step($step) {
        if (!$this->validate_required_fields($step, ['command'], '')) {
            return;
        }
        
        $this->validate_type($step['command'], 'string', 'command');
        
        if (isset($step['wpCliPath'])) {
            $this->validate_type($step['wpCliPath'], 'string', 'wpCliPath');
        }
    }
    
    /**
     * Validate writeFile step.
     *
     * @param array $step The step to validate.
     */
    private function validate_write_file_step($step) {
        if (!$this->validate_required_fields($step, ['path', 'content'], '')) {
            return;
        }
        
        $this->validate_type($step['path'], 'string', 'path');
        
        // content can be a string or a data reference
        if (is_string($step['content'])) {
            if (preg_match('/^https?:\/\/.*/', $step['content'])) {
                // URL
                $this->validate_url($step['content'], 'content');
            } else if (preg_match('/^(\/|\.\/).*/', $step['content'])) {
                // Execution context path
                $this->validate_execution_context_path($step['content'], 'content');
            }
            // Plain string content is also valid
        } else if (is_array($step['content'])) {
            // Could be an inline file/directory or git path
            // We won't validate deeply for simplicity
        } else {
            $this->add_error('content', 'Content must be a string or a data reference object');
        }
    }
    
    /**
     * Validate writeFiles step.
     *
     * @param array $step The step to validate.
     */
    private function validate_write_files_step($step) {
        if (!$this->validate_required_fields($step, ['files'], '')) {
            return;
        }
        
        if (!$this->validate_type($step['files'], 'object', 'files')) {
            return;
        }
        
        // Validate each file
        foreach ($step['files'] as $path => $content) {
            // content can be a string or a data reference
            if (is_string($content)) {
                if (preg_match('/^https?:\/\/.*/', $content)) {
                    // URL
                    $this->validate_url($content, "files.$path");
                } else if (preg_match('/^(\/|\.\/).*/', $content)) {
                    // Execution context path
                    $this->validate_execution_context_path($content, "files.$path");
                }
                // Plain string content is also valid
            } else if (is_array($content)) {
                // Could be an inline file/directory or git path
                // We won't validate deeply for simplicity
            } else {
                $this->add_error("files.$path", 'Content must be a string or a data reference object');
            }
        }
    }

    /**
     * Validate schema reference.
     *
     * @param mixed $schema The schema reference to validate.
     */
    private function validate_schema_reference($schema) {
        $this->set_path('$schema');
        
        if (is_string($schema)) {
            // Schema can be a URL or execution context path
            if (preg_match('/^https?:\/\/.*/', $schema)) {
                // URL reference
                $this->validate_url($schema, '');
            } else if (preg_match('/^(\/|\.\/).*/', $schema)) {
                // Execution context path
                $this->validate_execution_context_path($schema, '');
            } else {
                $this->add_error('', 'Schema reference must be a URL or an execution context path');
            }
        } else {
            $this->add_error('', 'Schema reference must be a string');
        }
    }
    
    /**
     * Validate active theme.
     *
     * @param mixed $theme The theme to validate.
     */
    private function validate_active_theme($theme) {
        $this->set_path('activeTheme');
        
        if (is_string($theme)) {
            // Theme can be a URL, execution context path, or theme directory reference
            if (preg_match('/^https?:\/\/.*/', $theme)) {
                // URL reference
                $this->validate_url($theme, '');
            } else if (preg_match('/^(\/|\.\/).*/', $theme)) {
                // Execution context path
                $this->validate_execution_context_path($theme, '');
            } else if (!preg_match('/^[a-zA-Z0-9_-]+$/', $theme) && 
                       !preg_match('/^[a-zA-Z0-9_-]+@(latest|\d+\.\d+(\.\d+)?)$/', $theme)) {
                $this->add_error('', 'Invalid theme directory reference. Expected format: themeslug or themeslug@version');
            }
        } else {
            $this->add_error('', 'Active theme must be a string');
        }
    }

    /**
     * Set the blueprint validator to validate against the JSON schema.
     * 
     * This method initiates validation using the official JSON schema.
     *
     * @param array $data The blueprint data to validate.
     * @return array Array of errors, empty if validation passed.
     */
    public function validate_against_schema($data) {
        $this->validate($data);
        return $this->get_errors();
    }
}
