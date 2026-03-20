import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(__dirname, '..');
const tokensPath = path.join(rootDir, '_shared', 'design', 'theme_tokens.json');
const tokens = JSON.parse(fs.readFileSync(tokensPath, 'utf8'));
const filledForegroundPairs = tokens.filledForegroundPairs ?? {};
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
  ribbonALightStart: '#B4C4CB',
  ribbonALightEnd: '#dcecf4',
  ribbonBLightStart: '#e9f1f6',
  ribbonBLightEnd: '#bdd0d9',
  ribbonCLightStart: '#dcecf4',
  ribbonCLightEnd: '#C1D6DF',
  ribbonADarkStart: '#49445b',
  ribbonADarkEnd: '#302938',
  ribbonBDarkStart: '#5d5a74',
  ribbonBDarkEnd: '#403d4f',
  ribbonCDarkStart: '#433B4A',
  ribbonCDarkEnd: '#362E3D',
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

function toDartFieldName(value) {
  return value
    .split(/[^a-zA-Z0-9]+/)
    .filter(Boolean)
    .map((part, index) => {
      const normalized = part.charAt(0).toUpperCase() + part.slice(1);
      if (index === 0) {
        return normalized.charAt(0).toLowerCase() + normalized.slice(1);
      }

      return normalized;
    })
    .join('');
}

function emitDartStringMap(name, map) {
  const fieldName = toDartFieldName(name);
  const entries = Object.entries(map)
    .map(([key, value]) => `    '${key}': '${value}',`)
    .join('\n');

  return `  static const Map<String, String> ${fieldName} = {\n${entries}\n  };`;
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

function hexToRgb(hex) {
  const normalized = hex.replace('#', '');

  return {
    red: Number.parseInt(normalized.slice(0, 2), 16),
    green: Number.parseInt(normalized.slice(2, 4), 16),
    blue: Number.parseInt(normalized.slice(4, 6), 16),
  };
}

function channelToLinear(value) {
  const normalized = value / 255;

  if (normalized <= 0.03928) {
    return normalized / 12.92;
  }

  return ((normalized + 0.055) / 1.055) ** 2.4;
}

function relativeLuminance(hex) {
  const { red, green, blue } = hexToRgb(hex);

  return (
    0.2126 * channelToLinear(red)
    + 0.7152 * channelToLinear(green)
    + 0.0722 * channelToLinear(blue)
  );
}

function contrastRatio(background, foreground) {
  const backgroundLuminance = relativeLuminance(background);
  const foregroundLuminance = relativeLuminance(foreground);
  const lighter = Math.max(backgroundLuminance, foregroundLuminance);
  const darker = Math.min(backgroundLuminance, foregroundLuminance);

  return (lighter + 0.05) / (darker + 0.05);
}

function validateFilledForegroundPairs(sectionName, values, pairs) {
  for (const [backgroundToken, foregroundToken] of Object.entries(pairs)) {
    if (!(backgroundToken in values)) {
      throw new Error(
        `Missing filled pair background token ${sectionName}.${backgroundToken}`,
      );
    }

    if (!(foregroundToken in values)) {
      throw new Error(
        `Missing filled pair foreground token ${sectionName}.${foregroundToken}`,
      );
    }

    if (!foregroundToken.startsWith('on')) {
      throw new Error(
        `Filled pair ${sectionName}.${backgroundToken} must map to an on-* token, received ${foregroundToken}`,
      );
    }

    const backgroundValue = values[backgroundToken];
    const foregroundValue = values[foregroundToken];
    const ratio = contrastRatio(backgroundValue, foregroundValue);

    if (ratio < 4.5) {
      throw new Error(
        `Filled pair ${sectionName}.${backgroundToken}/${foregroundToken} must meet WCAG AA 4.5:1, received ${ratio.toFixed(2)}:1`,
      );
    }
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

  validateFilledForegroundPairs(
    `web.${modeName}`,
    values,
    filledForegroundPairs.web?.[modeName] ?? {},
  );
}

for (const [modeName, values] of Object.entries(tokens.mobile)) {
  if (modeName === 'wave') {
    continue;
  }

  for (const [key, value] of Object.entries(values)) {
    assertApprovedHex(`mobile.${modeName}`, key, value);
  }

  validateFilledForegroundPairs(
    `mobile.${modeName}`,
    values,
    filledForegroundPairs.mobile?.[modeName] ?? {},
  );
}

const resolvedMobileWaveTokens = {
  ...(tokens.mobile.wave ?? {}),
  ...expectedWaveRibbonColors,
};

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
    dartLines.push(emitDartMap('wave', resolvedMobileWaveTokens, orderedWaveKeys));
    continue;
  }

  dartLines.push(emitDartMap(modeName, values));
}

for (const [platformName, modes] of Object.entries(filledForegroundPairs)) {
  for (const [modeName, pairs] of Object.entries(modes)) {
    dartLines.push(
      emitDartStringMap(
        `${platformName}_${modeName}_filled_foreground_pairs`,
        pairs,
      ),
    );
  }
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

export const webThemeTokens = ${JSON.stringify(tokens.web, null, 4)};

export const mobileThemeTokens = ${JSON.stringify(
  {
    ...tokens.mobile,
    wave: resolvedMobileWaveTokens,
  },
  null,
  4,
)};

export const filledForegroundPairs = ${JSON.stringify(
  filledForegroundPairs,
  null,
  4,
)};
`;

writeFile(webJsOutputPath, webJsContent);
