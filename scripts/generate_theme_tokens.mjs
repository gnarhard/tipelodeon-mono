import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(__dirname, '..');
const tokensPath = path.join(rootDir, '_shared', 'design', 'theme_tokens.json');
const tokens = JSON.parse(fs.readFileSync(tokensPath, 'utf8'));
const expectedWaveRibbonColors = {
  ribbonALightStart: '#dcecf4',
  ribbonALightEnd: '#cddce3',
  ribbonBLightStart: '#e9f1f6',
  ribbonBLightEnd: '#bdd0d9',
  ribbonCLightStart: '#cddce3',
  ribbonCLightEnd: '#dcecf4',
  ribbonADarkStart: '#49445b',
  ribbonADarkEnd: '#302938',
  ribbonBDarkStart: '#5d5a74',
  ribbonBDarkEnd: '#403d4f',
  ribbonCDarkStart: '#2d2633',
  ribbonCDarkEnd: '#302938',
};
const orderedWaveKeys = [
  'textureAsset',
  'textureOpacityLight',
  'textureOpacityDark',
  'textureTintLight',
  'textureTintDark',
  'backgroundTopLight',
  'backgroundBottomLight',
  'backgroundTopDark',
  'backgroundBottomDark',
  'ambientLight',
  'ambientDark',
  'ribbonALightStart',
  'ribbonALightEnd',
  'ribbonBLightStart',
  'ribbonBLightEnd',
  'ribbonCLightStart',
  'ribbonCLightEnd',
  'ribbonADarkStart',
  'ribbonADarkEnd',
  'ribbonBDarkStart',
  'ribbonBDarkEnd',
  'ribbonCDarkStart',
  'ribbonCDarkEnd',
];

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

function ensureDir(filePath) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
}

function hexToDartColor(value) {
  return `Color(0xFF${value.replace('#', '').toUpperCase()})`;
}

function toDartConstName(value) {
  return value
    .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
    .replace(/[^a-zA-Z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .toUpperCase();
}

function emitDartMap(name, map, orderedKeys) {
  const keys = orderedKeys ?? Object.keys(map);
  const entries = keys
    .map((key) => {
      if (!(key in map)) {
        throw new Error(`Missing ${name} token: ${key}`);
      }

      const value = map[key];
      const constName = toDartConstName(key);
      if (typeof value === 'number') {
        return `  static const double ${name}_${constName} = ${value};`;
      }

      if (typeof value === 'string' && value.startsWith('#')) {
        return `  static const Color ${name}_${constName} = ${hexToDartColor(value)};`;
      }

      return `  static const String ${name}_${constName} = '${value}';`;
    })
    .join('\n');

  return entries;
}

function emitCssVariables(selector, values) {
  const lines = Object.entries(values).map(
    ([key, value]) => `  --st-${key.replace(/[A-Z]/g, (match) => `-${match.toLowerCase()}`)}: ${value};`,
  );

  return `${selector} {\n${lines.join('\n')}\n}`;
}

function writeFile(filePath, content) {
  ensureDir(filePath);
  fs.writeFileSync(filePath, content);
}

for (const [key, expectedValue] of Object.entries(expectedWaveRibbonColors)) {
  if (tokens.mobile.wave?.[key] !== expectedValue) {
    throw new Error(
      `Expected mobile.wave.${key} to be ${expectedValue} in _shared/design/theme_tokens.json`,
    );
  }
}

const dartLines = [
  '// GENERATED CODE - DO NOT EDIT BY HAND.',
  '// Source: _shared/design/theme_tokens.json',
  '',
  "import 'package:flutter/material.dart';",
  '',
  'final class GeneratedThemePalettes {',
];

for (const [paletteName, values] of Object.entries(tokens.palettes)) {
  for (const [shade, value] of Object.entries(values)) {
    dartLines.push(
      `  static const Color ${paletteName}_${shade} = ${hexToDartColor(value)};`,
    );
  }
}

for (const [modeName, values] of Object.entries(tokens.mobile)) {
  if (modeName === 'wave') {
    dartLines.push(emitDartMap('wave', values, orderedWaveKeys));
    continue;
  }

  dartLines.push(emitDartMap(modeName, values));
}

dartLines.push('}');
dartLines.push('');

writeFile(dartOutputPath, `${dartLines.join('\n')}\n`);

const cssLines = [
  '/* GENERATED FILE - DO NOT EDIT BY HAND. */',
  '/* Source: _shared/design/theme_tokens.json */',
  '',
  emitCssVariables(':root', tokens.web.light),
  '',
  '@media (prefers-color-scheme: dark) {',
  emitCssVariables('  :root', tokens.web.dark),
  '}',
  '',
];

writeFile(webCssOutputPath, `${cssLines.join('\n')}\n`);

const webJsContent = `// GENERATED FILE - DO NOT EDIT BY HAND.
// Source: _shared/design/theme_tokens.json

export const tailwindPalettes = ${JSON.stringify(
  {
    indigo: tokens.palettes.brass,
    amber: tokens.palettes.brass,
    fuchsia: tokens.palettes.brass,
    emerald: tokens.palettes.accent,
    gray: tokens.palettes.neutral,
    slate: tokens.palettes.neutral,
  },
  null,
  4,
)};
`;

writeFile(webJsOutputPath, webJsContent);
