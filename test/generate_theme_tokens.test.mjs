import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import assert from 'node:assert/strict';

const rootDir = path.resolve(import.meta.dirname, '..');
const dartOutputPath = path.join(
  rootDir,
  'app',
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
  'tipelodeon-theme.generated.css',
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

  // Pewter Sage palette — dark-mode accent is brighter sage,
  // light-mode primary is a darker sage (#3F7A5A) for WCAG contrast.
  assert.match(
    dartOutput,
    /static const Color apricot_NORMAL = Color\(0xFF7EC096\);/,
  );
  assert.match(
    dartOutput,
    /static const Color success_NORMAL = Color\(0xFF7ABA9A\);/,
  );
  assert.match(
    dartOutput,
    /static const Color info_NORMAL = Color\(0xFF7A9FC4\);/,
  );
  assert.match(
    dartOutput,
    /static const Color dark_SEPARATOR = Color\(0xFF404752\);/,
  );
  assert.match(
    dartOutput,
    /static const Color light_PRIMARY_CONTAINER = Color\(0xFF9CC5AC\);/,
  );
  assert.match(
    dartOutput,
    /static const Color light_PRIMARY = Color\(0xFF3F7A5A\);/,
  );
  assert.match(
    dartOutput,
    /static const Color dark_SURFACE = Color\(0xFF1A1D22\);/,
  );
  assert.match(
    dartOutput,
    /static const Color wave_RIBBON_BLIGHT_START = Color\(0xFFEBEEF2\);/,
  );
  assert.doesNotMatch(
    dartOutput,
    /Color\(0xFFFFB375\)|Color\(0xFFFFCBA0\)|Color\(0xFF302938\)/,
  );

  assert.match(webCssOutput, /--st-surface: #f1f2f5;/);
  assert.match(webCssOutput, /--st-surface-strong: #e4e6eb;/);
  assert.match(webCssOutput, /--st-apricot: #7ec096;/);
  assert.match(webCssOutput, /--st-text: #1d2128;/);
  assert.match(webCssOutput, /--st-line: #e4e6eb;/);
  assert.match(webCssOutput, /--st-line-strong: #525966;/);
  assert.match(webCssOutput, /--st-primary-container: #9cc5ac;/);
  assert.match(webCssOutput, /--st-success-container: #c8e4d2;/);
  assert.match(webCssOutput, /--st-on-success-container: #1d2128;/);
  assert.doesNotMatch(
    webCssOutput,
    /#ffb375|#ffcba0|#302938|#2d2633|#dcecf4|#cddce3/i,
  );

  assert.match(webJsOutput, /apricot/);
  assert.match(webJsOutput, /light/);
  assert.match(webJsOutput, /dark/);
  assert.match(webJsOutput, /success/);
  assert.match(webJsOutput, /info/);
  assert.match(webJsOutput, /webThemeTokens/);
  assert.match(webJsOutput, /mobileThemeTokens/);
  assert.match(webJsOutput, /filledForegroundPairs/);
  assert.match(webJsOutput, /"successContainer": "onSuccessContainer"/);
  assert.doesNotMatch(webJsOutput, /"50":/);
  assert.doesNotMatch(webJsOutput, /neutral/);
  assert.doesNotMatch(webJsOutput, /amber/);

  assert.match(
    dartOutput,
    /static const Map<String, String> webLightFilledForegroundPairs = \{/,
  );
  assert.match(
    dartOutput,
    /'secondaryContainer': 'onSecondaryContainer'/,
  );
});
