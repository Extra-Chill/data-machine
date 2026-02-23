// Copyright 2023 The Chromium Authors. All rights reserved.
// Use of this source code is governed by a BSD-style license that can be
// found in the LICENSE file.

import {strict as assert} from 'assert';
import fs from 'fs';
import test from 'node:test';

import {analyzeTrace} from '../analyze-trace.mjs';
// import {analyzeInspectorIssues} from '../analyze-inspector-issues.mjs';
import * as Trace from '../models/trace/trace.js';

const filename = './test/invalid-animation-events.json.gz';
const {parsedTrace: data, insights} = await analyzeTrace(filename);

test('key values are populated', t => {
  assert.equal((data.Screenshots.legacySyntheticScreenshots?.length ?? 0) > 2, true);
  assert.equal(data.Meta.threadsInProcess.size > 2, true);
  assert.equal(data.Meta.mainFrameNavigations.length > 0, true);
});

test('numeric values are set and look legit', t => {
  const shouldBeNumbers = [
    data.Meta.traceBounds.min,
    data.Meta.traceBounds.max,
    data.Meta.traceBounds.range,
    data.Meta.browserProcessId,
    data.Meta.browserThreadId,
    data.Meta.gpuProcessId,
    data.Meta.gpuThreadId,
    Array.from(data.Meta.topLevelRendererIds.values()).at(0),
    Array.from(data.Meta.frameByProcessId.keys()).at(0),
  ];
  for (const datum of shouldBeNumbers) {
    assert.equal(typeof datum, 'number');
    if (typeof datum !== 'number')
      throw new Error();
    assert.equal(isNaN(datum), false);
    assert.equal(datum > 10, true);
  }
});

test('string values are set and look legit', t => {
  const shouldBeStrings = [
    data.Meta.mainFrameId,
    data.Meta.mainFrameURL,
    Array.from(data.Meta.navigationsByFrameId.keys()).at(0),
    Array.from(data.Meta.navigationsByNavigationId.keys()).at(0),
    data.Meta.mainFrameId,
  ];

  for (const datum of shouldBeStrings) {
    assert.equal(typeof datum, 'string');
    if (typeof datum !== 'string')
      throw new Error();
    assert.equal(datum.length > 10, true);
  }
});

test('insights look ok', t => {
  if (insights === null) {
    throw new Error('insights null');
  }
  // First insightset with a navigation on it, to skip over the NO_NAV one.
  const insightSet = Array.from(insights.values()).find(is => is.navigation);
  if (typeof insightSet === 'undefined') {
    throw new Error();
  }
  const keys = Object.keys(insightSet.model);
  assert.deepStrictEqual(keys, [
    'INPBreakdown',
    'LCPBreakdown',
    'LCPDiscovery',
    'CLSCulprits',
    'RenderBlocking',
    'NetworkDependencyTree',
    'ImageDelivery',
    'DocumentLatency',
    'FontDisplay',
    'Viewport',
    'DOMSize',
    'ThirdParties',
    'DuplicatedJavaScript',
    'SlowCSSSelector',
    'ForcedReflow',
    'Cache',
    'ModernHTTP',
    'LegacyJavaScript',
  ]);
  for (const [insightName, insightItem] of Object.entries(insightSet.model)) {
    const msg = insightItem instanceof Error ?
        `${insightName} is an error. ${insightItem.toString()} ${insightItem.stack?.toString()}` :
        '';
    assert.ok(insightItem instanceof Error === false, msg);
    assert.ok(typeof insightItem === 'object', `insightName ${insightName} is not an object`);
  }

  const entityNames = insightSet.model.ThirdParties.entitySummaries.map(s => s.entity.name);
  const values = insightSet.model.ThirdParties.entitySummaries.values();
  const simplified = Object.fromEntries(values.map((v, i) => [entityNames[i], {transferSize: v.transferSize, mainThreadTime: v.mainThreadTime}]));

  const expected = {
    ahfhijdlegdabablpippeagghigmibma: { transferSize: 0, mainThreadTime: 3.953000009059906 },
    'paulirish.com': { transferSize: 142142, mainThreadTime: 17.631999850273132 },
    cjpalhdlnbpafiamejdnhcphjbkeiagm: { transferSize: 7390, mainThreadTime: 4.081999972462654 },
    jinjaccalgkegednnccohejagnlnfdag: { transferSize: 0, mainThreadTime: 1.2049999833106995 },
    noondiphcddnnabmjcihcjfbhfklnnep: { transferSize: 0, mainThreadTime: 2.2430000007152557 },
    'Google Tag Manager': { transferSize: 0, mainThreadTime: 0.2629999965429306 },
    'Google Fonts': { transferSize: 74145, mainThreadTime: 0 },
    Disqus: { transferSize: 1550, mainThreadTime: 0.4229999780654907 },
    'Google Analytics': { transferSize: 0, mainThreadTime: 0.2199999839067459 },
    bknnlbamapndemiekhkcnmdclnkijlhb: { transferSize: 0, mainThreadTime: 0.601999968290329 },
    kljjfejkagofbgklifblndjelgabcmig: { transferSize: 0, mainThreadTime: 0.27699998021125793 },
    nffaoalbilbmmfgbnbgppjihopabppdk: { transferSize: 0, mainThreadTime: 1.246999979019165 },
    obadmbiiipafnncogfkdfionggeckfia: { transferSize: 0, mainThreadTime: 1.696999967098236 },
  };
  assert.deepStrictEqual(simplified, expected);
});

test('bottom-up summary is good', t => {
  const parsedTrace = data;
  const visibleEvents = Trace.Helpers.Trace.VISIBLE_TRACE_EVENT_TYPES.values().toArray();
  const filter = new Trace.Extras.TraceFilter.VisibleEventsFilter(
      visibleEvents.concat([Trace.Types.Events.Name.SYNTHETIC_NETWORK_REQUEST]));
  const milliBounds = Trace.Helpers.Timing.traceWindowMilliSeconds(parsedTrace.Meta.traceBounds);


  const mainThreadProbably =
      Trace.Handlers.Threads.threadsInTrace(parsedTrace)
          .filter(t => t.type === Trace.Handlers.Threads.ThreadType.MAIN_THREAD && t.processIsOnMainFrame)
          .sort((a, b) => b.entries.length - a.entries.length)
          .at(0);
  if (!mainThreadProbably)
    assert.fail('No main thread found in trace');

  /** @param {Trace.Types.Events.Event} event  */
  const groupingFunction = event => event.name;

  const node = new Trace.Extras.TraceTree.BottomUpRootNode([...mainThreadProbably.entries], {
    textFilter: new Trace.Extras.TraceFilter.ExclusiveNameFilter([]),
    filters: [filter],
    startTime: milliBounds.min,
    endTime: milliBounds.max,
    eventGroupIdCallback: groupingFunction,
  });

  const bottomUpByName =
      Array.from(node.children().values())
          .map(c => [c.id.toString().padEnd(30), c.selfTime.toLocaleString().padStart(10) + 'ms'].join('\t'));

  assert.deepStrictEqual(bottomUpByName.join('\n'), `
RunTask                       	   105.929ms
V8.CompileCode                	     28.32ms
CpuProfiler::StartProfiling   	    20.116ms
FunctionCall                  	     4.239ms
ProfileCall                   	    25.086ms
EventDispatch                 	     1.235ms
MinorGC                       	     1.907ms
v8.compile                    	     1.729ms
v8.produceCache               	     0.165ms
EvaluateScript                	     3.073ms
ParseHTML                     	     9.719ms
UpdateLayoutTree              	    12.108ms
Layout                        	    36.788ms
PrePaint                      	    10.425ms
Paint                         	     8.374ms
Layerize                      	     2.419ms
Commit                        	     4.394ms
RunMicrotasks                 	     0.854ms
FireIdleCallback              	     0.129ms
HitTest                       	     0.087ms
ParseAuthorStyleSheet         	      0.23ms
FireAnimationFrame            	      0.14ms
TimerFire                     	     0.107ms
v8.evaluateModule             	     0.062ms
v8.produceModuleCache         	     0.005ms
Decode Image                  	     0.043ms
`.trim());
});


// TODO: needs more work...
// test('inspector issues ok', t => {
//   const artifactsPath = [
//     '../lighthouse/core/test/results/artifacts/artifacts.json',
//     '../../src/lighthouse/core/test/results/artifacts/artifacts.json',
//   ].find(f => fs.existsSync(f));
//   assert(artifactsPath);
//   const issues = analyzeInspectorIssues(artifactsPath);
//   assert.equal(issues.length, 4);
//   console.log(issues[0].getDescription());
// });
