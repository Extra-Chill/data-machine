import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import { dirname, isAbsolute, join, normalize } from 'node:path';
import type { AssetFile, ThemeModel } from './types.js';

type FileContent = string | Uint8Array;

export async function writeTheme(model: ThemeModel, outDir: string): Promise<string[]> {
  await mkdir(outDir, { recursive: true });
  await Promise.all([
    mkdir(join(outDir, 'templates'), { recursive: true }),
    mkdir(join(outDir, 'parts'), { recursive: true }),
    mkdir(join(outDir, 'patterns'), { recursive: true }),
  ]);

  const files: Array<[string, FileContent]> = [
    ['style.css', model.styleCss],
    ...(model.functionsPhp ? [['functions.php', model.functionsPhp] as [string, FileContent]] : []),
    ['theme.json', JSON.stringify(model.themeJson, null, 2) + '\n'],
    ...recordFiles('templates', model.templates),
    ...recordFiles('parts', model.parts),
    ...recordFiles('patterns', model.patterns),
    ...jsonRecordFiles('styles/blocks', model.styleBlocks ?? {}),
  ];

  for (const asset of model.assets) {
    files.push([safeRelativePath(asset.relPath), await assetContent(asset)]);
  }

  const written: string[] = [];
  for (const [relativePath, content] of files) {
    const safePath = safeRelativePath(relativePath);
    await atomicWrite(join(outDir, safePath), content);
    written.push(safePath);
  }

  return written;
}

function recordFiles(baseDir: string, files: Record<string, string>): Array<[string, string]> {
  return Object.entries(files)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([key, content]) => [recordRelativePath(baseDir, key), content]);
}

function jsonRecordFiles(
  baseDir: string,
  files: Record<string, Record<string, unknown>>
): Array<[string, string]> {
  return Object.entries(files)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([key, content]) => [
      recordRelativePath(baseDir, key),
      JSON.stringify(content, null, 2) + '\n',
    ]);
}

function recordRelativePath(baseDir: string, key: string): string {
  const trimmed = key.trim();
  if (trimmed.startsWith(`${baseDir}/`) || trimmed.includes('/')) {
    return safeRelativePath(trimmed);
  }

  const filename = /\.[a-z0-9]+$/i.test(trimmed) ? trimmed : `${trimmed}.html`;
  return safeRelativePath(join(baseDir, filename));
}

async function assetContent(asset: AssetFile): Promise<FileContent> {
  if (asset.bytes) return asset.bytes;
  if (asset.sourcePath) return readFile(asset.sourcePath);
  return new Uint8Array();
}

async function atomicWrite(path: string, content: FileContent): Promise<void> {
  await mkdir(dirname(path), { recursive: true });
  const tmpPath = `${path}.${process.pid}.${Date.now()}.tmp`;

  try {
    await writeFile(tmpPath, content);
    await rename(tmpPath, path);
  } catch (error) {
    await removeTmpBestEffort(tmpPath);
    throw error;
  }
}

async function removeTmpBestEffort(path: string): Promise<void> {
  try {
    const { rm } = await import('node:fs/promises');
    await rm(path, { force: true });
  } catch {
    // The original write/rename failure is the actionable error.
  }
}

function safeRelativePath(path: string): string {
  const input = path.replace(/\\/g, '/');
  const normalized = normalize(input).replace(/\\/g, '/');
  if (
    !normalized ||
    normalized === '.' ||
    isAbsolute(input) ||
    normalized === '..' ||
    normalized.startsWith('../')
  ) {
    throw new Error(`theme path must stay relative to outDir: ${path}`);
  }
  return normalized;
}
