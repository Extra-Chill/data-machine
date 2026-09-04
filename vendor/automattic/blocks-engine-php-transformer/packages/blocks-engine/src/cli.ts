#!/usr/bin/env node

import { existsSync, realpathSync, statSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import type { Readable } from 'node:stream';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { convert } from './convert.js';
import { BlocksEngineError } from './errors.js';
import { siteToTheme } from './theme/site-to-theme.js';
import type { SiteToThemeOptions, ThemeBuildResult } from './theme/types.js';

interface WritableLike {
  write(chunk: string | Uint8Array): unknown;
}

/** Thrown when the CLI is invoked incorrectly (bad flags, missing arguments). The handler reprints usage. */
class CliUsageError extends Error {}

type ConvertHtml = (html: string, ctx: { url: string }) => Promise<string>;
type SiteToThemeImpl = (srcDir: string, options?: SiteToThemeOptions) => Promise<ThemeBuildResult>;

export interface RunCliOptions {
  stdin?: Readable;
  stdout?: WritableLike;
  stderr?: WritableLike;
  convertHtml?: ConvertHtml;
  siteToThemeImpl?: SiteToThemeImpl;
  pathExists?: (path: string) => boolean;
  isDirectory?: (path: string) => boolean;
}

async function readStdin(stdin: Readable): Promise<string> {
  let html = '';

  for await (const chunk of stdin) {
    html += typeof chunk === 'string' ? chunk : Buffer.from(chunk).toString('utf8');
  }

  return html;
}

export async function runCli(argv: string[], options: RunCliOptions = {}): Promise<number> {
  const stdin = options.stdin ?? process.stdin;
  const stdout = options.stdout ?? process.stdout;
  const stderr = options.stderr ?? process.stderr;
  const convertHtml = options.convertHtml ?? convert;
  const siteToThemeImpl = options.siteToThemeImpl ?? siteToTheme;
  const pathExists = options.pathExists ?? existsSync;
  const isDirectory =
    options.isDirectory ??
    ((path: string) => {
      try {
        return statSync(path).isDirectory();
      } catch {
        return false;
      }
    });

  try {
    const command = argv[0];
    if (command === undefined || command === '--help' || command === '-h') {
      stdout.write(usage());
      return 0;
    }

    if (command === 'convert') {
      return await runConvert(argv.slice(1), { stdin, stdout, convertHtml, pathExists });
    }

    const themeArgs = command === 'theme' ? argv.slice(1) : argv;
    return await runTheme(themeArgs, { stdout, stderr, siteToThemeImpl, pathExists, isDirectory });
  } catch (error) {
    if (error instanceof BlocksEngineError) {
      stderr.write(`${error.message}\n${error.hint}\n`);
      return 1;
    }

    if (error instanceof CliUsageError) {
      stderr.write(`${error.message}\n\n${usage()}`);
      return 1;
    }

    const message = error instanceof Error ? error.message : String(error);
    stderr.write(`${message}\n`);
    return 1;
  }
}

async function runConvert(
  argv: string[],
  options: {
    stdin: Readable;
    stdout: WritableLike;
    convertHtml: ConvertHtml;
    pathExists: (path: string) => boolean;
  }
): Promise<number> {
  const fileArg = argv[0];
  if (fileArg !== undefined && !options.pathExists(resolve(fileArg))) {
    throw new Error(`File ${fileArg} not found.`);
  }

  const input =
    fileArg === undefined
      ? {
          html: await readStdin(options.stdin),
          url: pathToFileURL(resolve(process.cwd(), 'stdin')).href,
        }
      : {
          html: await readFile(resolve(fileArg), 'utf8'),
          url: pathToFileURL(resolve(fileArg)).href,
        };

  options.stdout.write(await options.convertHtml(input.html, { url: input.url }));
  return 0;
}

async function runTheme(
  argv: string[],
  options: {
    stdout: WritableLike;
    stderr: WritableLike;
    siteToThemeImpl: SiteToThemeImpl;
    pathExists: (path: string) => boolean;
    isDirectory: (path: string) => boolean;
  }
): Promise<number> {
  const { srcDir, themeOptions, outDirExplicit } = parseThemeArgs(argv);

  if (!options.pathExists(srcDir)) {
    throw new Error(`Source directory ${srcDir} not found.`);
  }

  if (!options.isDirectory(srcDir)) {
    throw new Error(
      `${srcDir} is a file, not a directory. To convert a single HTML file, run: blocks-engine convert ${srcDir}`
    );
  }

  // The default output dir is created in the current directory; refuse to touch
  // an existing one so a stray _block-theme/ is never silently overwritten.
  if (!outDirExplicit && themeOptions.outDir !== undefined && options.pathExists(themeOptions.outDir)) {
    throw new Error(
      `Default output directory ${themeOptions.outDir} already exists. Pass --out <dir> to choose where to write the theme.`
    );
  }

  const result = await options.siteToThemeImpl(srcDir, themeOptions);

  options.stdout.write(`wrote theme to ${result.outDir} (${result.written.length} files)\n`);
  for (const warning of result.warnings) {
    options.stderr.write(`${warning}\n`);
  }

  return 0;
}

function parseThemeArgs(argv: string[]): {
  srcDir: string;
  themeOptions: SiteToThemeOptions;
  outDirExplicit: boolean;
} {
  const srcDir = argv[0];
  if (srcDir === undefined) {
    throw new CliUsageError('Missing <srcDir> for theme command.');
  }

  const flags: { outDir?: string; slug?: string; name?: string } = {};

  for (let index = 1; index < argv.length; index += 1) {
    const arg = argv[index];
    if (arg === '--out' || arg === '--slug' || arg === '--name') {
      const value = argv[index + 1];
      if (value === undefined) {
        throw new CliUsageError(`Missing value for ${arg}.`);
      }
      setThemeFlag(flags, arg, value);
      index += 1;
    } else if (arg.startsWith('--out=') || arg.startsWith('--slug=') || arg.startsWith('--name=')) {
      const equalsIndex = arg.indexOf('=');
      setThemeFlag(flags, arg.slice(0, equalsIndex), arg.slice(equalsIndex + 1));
    } else if (arg.startsWith('--')) {
      throw new CliUsageError(`Unknown option: ${arg}`);
    } else {
      throw new CliUsageError(`Unexpected argument: ${arg}`);
    }
  }

  const themeMeta: NonNullable<SiteToThemeOptions['themeMeta']> = {};
  if (flags.slug !== undefined) themeMeta.slug = flags.slug;
  if (flags.name !== undefined) themeMeta.name = flags.name;

  return {
    srcDir,
    outDirExplicit: flags.outDir !== undefined,
    themeOptions: {
      outDir: flags.outDir ?? resolve(process.cwd(), '_block-theme'),
      themeMeta,
    },
  };
}

function setThemeFlag(
  flags: { outDir?: string; slug?: string; name?: string },
  name: string,
  value: string
): void {
  if (name === '--out') {
    flags.outDir = value;
  } else if (name === '--slug') {
    flags.slug = value;
  } else {
    flags.name = value;
  }
}

function usage(): string {
  return `Usage:
  blocks-engine theme <srcDir> [--out <dir>] [--slug <slug>] [--name <name>]
  blocks-engine <srcDir> [--out <dir>] [--slug <slug>] [--name <name>]
  blocks-engine convert [file]
  blocks-engine --help | -h

Commands:
  theme    Build a whole-site block theme from a source directory.
  convert  Convert one HTML file, or stdin, to block markup.

Options:
  --out <dir>    Write the generated theme to a directory
                 (default: ./_block-theme; the command exits if it already exists).
  --slug <slug>  Set the generated theme slug.
  --name <name>  Set the generated theme name.
`;
}

function realpathOrResolve(path: string): string {
  try {
    return realpathSync(path);
  } catch {
    return resolve(path);
  }
}

function isDirectInvocation(argv1: string | undefined): boolean {
  return argv1 !== undefined && realpathOrResolve(argv1) === realpathOrResolve(fileURLToPath(import.meta.url));
}

if (isDirectInvocation(process.argv[1])) {
  void runCli(process.argv.slice(2)).then((exitCode) => {
    process.exitCode = exitCode;
  });
}
