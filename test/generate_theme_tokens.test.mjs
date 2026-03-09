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

test('theme token generator emits only the pinned theme families and wave colors', () => {
  execFileSync('node', ['scripts/generate_theme_tokens.mjs'], {
    cwd: rootDir,
    stdio: 'pipe',
  });

  const dartOutput = fs.readFileSync(dartOutputPath, 'utf8');
  const webCssOutput = fs.readFileSync(webCssOutputPath, 'utf8');
  const webJsOutput = fs.readFileSync(webJsOutputPath, 'utf8');

  assert.match(
    dartOutput,
    /static const Color apricot_500 = Color\(0xFFFFB375\);/,
  );
  assert.match(
    dartOutput,
    /static const Color success_500 = Color\(0xFF6F9072\);/,
  );
  assert.match(
    dartOutput,
    /static const Color info_500 = Color\(0xFF5F7FA2\);/,
  );
  assert.match(
    dartOutput,
    /static const Color light_PRIMARY_CONTAINER = Color\(0xFFFFCBA0\);/,
  );
  assert.match(
    dartOutput,
    /static const Color dark_SURFACE = Color\(0xFF302938\);/,
  );
  assert.match(
    dartOutput,
    /static const Color wave_RIBBON_BLIGHT_START = Color\(0xFFE9F1F6\);/,
  );
  assert.doesNotMatch(
    dartOutput,
    /Color\(0xFFC46D2C\)|Color\(0xFF10B981\)|Color\(0xFF92A1AF\)/,
  );

  assert.match(webCssOutput, /--st-surface: #cddce3;/);
  assert.match(webCssOutput, /--st-apricot: #ffb375;/);
  assert.match(webCssOutput, /--st-text: #dcecf4;/);
  assert.match(webCssOutput, /--st-line-strong: #cddce3;/);
  assert.doesNotMatch(
    webCssOutput,
    /#e88d4d|#c46d2c|#92a1af|#778093|#10b981/i,
  );

  assert.match(webJsOutput, /apricot/);
  assert.match(webJsOutput, /success/);
  assert.match(webJsOutput, /info/);
  assert.doesNotMatch(webJsOutput, /amber/);
});
