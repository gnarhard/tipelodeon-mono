import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import assert from 'node:assert/strict';

const rootDir = path.resolve(import.meta.dirname, '..');
const dartOutputPath = path.join(
  rootDir,
  'mobile_app',
  'lib',
  'core',
  'theme',
  'generated_theme_tokens.g.dart',
);
const webCssOutputPath = path.join(
  rootDir,
  'web',
  'resources',
  'css',
  'generated',
  'songtipper-theme.generated.css',
);
const webJsOutputPath = path.join(
  rootDir,
  'web',
  'theme',
  'generated_theme_tokens.js',
);

test('theme token generator emits the pinned sunshade and text tokens', () => {
  execFileSync('node', ['scripts/generate_theme_tokens.mjs'], {
    cwd: rootDir,
    stdio: 'pipe',
  });

  const dartOutput = fs.readFileSync(dartOutputPath, 'utf8');
  const webCssOutput = fs.readFileSync(webCssOutputPath, 'utf8');
  const webJsOutput = fs.readFileSync(webJsOutputPath, 'utf8');

  assert.match(
    dartOutput,
    /static const Color sunshade_500 = Color\(0xFFF4903E\);/,
  );
  assert.match(
    dartOutput,
    /static const Color light_HEADING = Color\(0xFF302938\);/,
  );
  assert.match(
    dartOutput,
    /static const Color dark_HEADING = Color\(0xFFDCECF4\);/,
  );
  assert.match(
    dartOutput,
    /static const Color wave_RIBBON_BLIGHT_START = Color\(0xFFE9F1F6\);/,
  );

  assert.match(webCssOutput, /--st-text: #302938;/);
  assert.match(webCssOutput, /--st-sunshade: #f4903e;/);
  assert.match(webCssOutput, /--st-text: #dcecf4;/);

  assert.match(webJsOutput, /sunshade/);
  assert.match(webJsOutput, /success/);
  assert.doesNotMatch(webJsOutput, /amber/);
});
