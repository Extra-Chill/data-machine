export = lighthouse;
/**
 * @license
 * Copyright 2022 Google LLC
 * SPDX-License-Identifier: Apache-2.0
 */
/**
 * @typedef ExportType
 * @property {import('./index.js')['startFlow']} startFlow
 * @property {import('./index.js')['navigation']} navigation
 * @property {import('./index.js')['startTimespan']} startTimespan
 * @property {import('./index.js')['snapshot']} snapshot
 */
/** @type {import('./index.js')['default'] & ExportType} */
declare const lighthouse: typeof import("./index.js")["default"] & ExportType;
//# sourceMappingURL=index.d.cts.map