import { createGenerator } from "ts-json-schema-generator";
import type Config from "ts-json-schema-generator";
import { writeFileSync } from "fs";
import { fileURLToPath } from "url";
import { dirname, resolve } from "path";

const scriptDir = dirname(fileURLToPath(import.meta.url));
const entry = resolve(scriptDir, "wsp/wsp-1-blueprint-v2-schema/appendix-A-blueprint-v2-schema.ts");
const out = resolve(scriptDir, "schema-v2.json");

const cfg: Config = {
  path: resolve(entry),
  tsconfig: resolve(scriptDir, "tsconfig.json"),
  type: "Blueprint",
  additionalProperties: false,
  skipTypeCheck: false,
};

const schema = createGenerator(cfg).createSchema("Blueprint");
const json = JSON.stringify(schema, null, 2);

writeFileSync(out, json);
console.log(`Updated JSON schema written to ${out}`);