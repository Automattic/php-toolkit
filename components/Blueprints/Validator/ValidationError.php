<?php

namespace WordPress\Blueprints\Validator;

/**
 * Represents a single validation error.
 */
class ValidationError {
    /**
     * @param string $pointer JSON Pointer like /steps/0/data/url
     * @param string $code short, stable key: required, type-mismatch …
     * @param string $message human sentence
     * @param array $context expected/actual/allowed, always associative
     * @param ValidationError[] $children nested causes
     */
    public function __construct(
        public string  $pointer,
        public string  $code,
        public string  $message,
        public array   $context = [],
        public array   $children = []
    ) {}

    /**
     * Gets the most probable cause of this validation error.
     * If this error has no children, it is the most probable cause.
     * Otherwise, it recursively calls getMostProbableCause on its children
     * and returns the one with the fewest descendants (naïve: first child if counts are equal).
     */
    public function getMostProbableCause(): ValidationError {
        if (empty($this->children)) {
            return $this;
        }

        $minChild = null;
        $minDescendantsCount = PHP_INT_MAX;

        // Find the child with the minimum number of its own *direct* children.
        // To implement "fewest descendants" fully, we'd need a recursive count here.
        // The current prompt says "choose the one with fewest descendants" but then suggests "count($a->children)".
        // Sticking to the simpler direct children count based on the example.
        foreach ($this->children as $child) {
            $currentChildDescendantsCount = count($child->children);
            if ($currentChildDescendantsCount < $minDescendantsCount) {
                $minDescendantsCount = $currentChildDescendantsCount;
                $minChild = $child;
            }
        }
        
        // If $minChild is still null (e.g. if children array was empty, though caught by initial check),
        // or if for some reason no child was selected, we might return $this or throw an error.
        // However, given the initial empty check, $minChild should be set if $this->children is not empty.
        // If all children have the same number of descendants, the first one encountered will be chosen.
        return $minChild->getMostProbableCause(); 
    }
}
