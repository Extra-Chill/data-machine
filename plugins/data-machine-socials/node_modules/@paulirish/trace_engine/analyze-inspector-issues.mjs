// Copyright 2025 The Chromium Authors. All rights reserved.
// Use of this source code is governed by a BSD-style license that can be
// found in the LICENSE file.

// @ts-nocheck

/** @typedef {import('./models/issues_manager/issues_manager.js')} IssuesManager */
/** @typedef {import('./generated/protocol.js')} Protocol */

import fs from 'node:fs';

/** @type {IssuesManager} */
import * as IssuesManager from './models/issues_manager/issues_manager.js';

/**
 * @param {any} lighthouseArtifacts
 */
function analyzeLighthouseArtifacts(lighthouseArtifacts) {
  const protocolIssues = [];
  for (const [key, issues] of Object.entries(lighthouseArtifacts.InspectorIssues)) {
    const code = key[0].toUpperCase() + key.substring(1);

    for (const issue of issues) {
      protocolIssues.push({
        code,
        details: {[`${key}Details`]: issue},
      });
    }
  }

  // @ts-expect-error
  const issues = protocolIssues.flatMap(protocolIssue => IssuesManager.IssuesManager.createIssuesFromProtocolIssue(null, protocolIssue));
  return issues;
}

/**
 * @param {string} filename
 * @returns {ReturnType<analyzeLighthouseArtifacts>}
 */
export function analyzeInspectorIssues(filename) {
  const artifacts = JSON.parse(fs.readFileSync(filename, 'utf-8'));
  if (!artifacts.InspectorIssues) {
    throw new Error('expected Lighthouse artifacts');
  }

  return analyzeLighthouseArtifacts(artifacts);
}

// If run as CLI, parse the argv trace (or a fallback)
if (import.meta.url.endsWith(process?.argv[1])) {
  cli();
}

function cli() {
  const filename = process.argv.at(2);
  if (!filename) throw new Error('Provide filename');

  const issues = analyzeInspectorIssues(filename);
  console.log(issues);
}
