import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(__dirname, '..');
const tokensPath = path.join(rootDir, '_shared', 'design', 'theme_tokens.json');
const tokens = JSON.parse(fs.readFileSync(tokensPath, 'utf8'));
const allowedThemeHexes = new Set([
  '#ffb375',
  '#ffcba0',
  '#dcecf4',
  '#cddce3',
  '#4e435b',
  '#302938',
  '#2d2633',
  '#6f9072',
  '#d9e6da',
  '#c86455',
  '#f4d4ce',
  '#5f7fa2',
  '#d7e3ee',
]);
const allowedWebWaveValues = new Set([
  'rgba(48, 41, 56, 0.07)',
  'rgba(48, 41, 56, 0.11)',
  'rgba(220, 236, 244, 0.08)',
  'rgba(220, 236, 244, 0.12)',
]);
const expectedWaveRibbonColors = {
  ribbonALightStart: '#cddce3',
  ribbonALightEnd: '#dcecf4',
  ribbonBLightStart: '#e9f1f6',
  ribbonBLightEnd: '#bdd0d9',
  ribbonCLightStart: '#dcecf4',
  ribbonCLightEnd: '#cddce3',
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

function assertApprovedHex(sectionName, key, value) {
  if (!allowedThemeHexes.has(value.toLowerCase())) {
    throw new Error(
      `Expected ${sectionName}.${key} to use an approved base or accent color, received ${value}`,
    );
  }
}

for (const [paletteName, values] of Object.entries(tokens.palettes)) {
  for (const [shade, value] of Object.entries(values)) {
    assertApprovedHex(`palettes.${paletteName}`, shade, value);
  }
}

for (const [modeName, values] of Object.entries(tokens.web)) {
  for (const [key, value] of Object.entries(values)) {
    if (key === 'waveBandPrimary' || key === 'waveBandSecondary') {
      if (!allowedWebWaveValues.has(value)) {
        throw new Error(
          `Expected web.${modeName}.${key} to keep the pinned wave color, received ${value}`,
        );
      }
      continue;
    }

    assertApprovedHex(`web.${modeName}`, key, value);
  }
}

for (const [modeName, values] of Object.entries(tokens.mobile)) {
  if (modeName === 'wave') {
    continue;
  }

  for (const [key, value] of Object.entries(values)) {
    assertApprovedHex(`mobile.${modeName}`, key, value);
  }
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
    const constName = toDartConstName(shade);
    dartLines.push(
      `  static const Color ${paletteName}_${constName} = ${hexToDartColor(value)};`,
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
    apricot: tokens.palettes.apricot,
    light: tokens.palettes.light,
    dark: tokens.palettes.dark,
    success: tokens.palettes.success,
    danger: tokens.palettes.danger,
    info: tokens.palettes.info,
  },
  null,
  4,
)};
`;

writeFile(webJsOutputPath, webJsContent);
