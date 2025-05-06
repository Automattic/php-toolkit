## Types -> JSON Schema

Create a JSON schema file called blueprints-v2-schema.json that reflects the intent expressed with TypeScript types defined in @appendix-A-blueprint-v2-schema.ts @appendix-B-data-sources.ts. Preserve the structure, the comments etc. Anytime an array buffer is used, replace it with a string.

```typescript
// ...
```

## Types -> PHP steps data classes

(Works best with Gemini)

Create simple PHP classes to represent the steps from the typescript types below. Do create any additional data objects when needed. Flatten and simplyfy the data structures otherwise, e.g. the installPlugin step could embed the PluginDefinition directly. Each class's should have a constructor for the most important property/properties and getters/setters.

```typescript
// ...
```

## JSON Validator

Implement a large PHP class that validates whether a JSON file is compliant with  @schema.json . Do not use schema validation library. Rather, spell out all the checks. On errors, produce human readable messages pointing to specific parts of the document. Add unit tests and a fixtures directory with at least 5 valid and invalid Blueprints. No "simplified approach" is allowed. Implement the entire validation logic.
