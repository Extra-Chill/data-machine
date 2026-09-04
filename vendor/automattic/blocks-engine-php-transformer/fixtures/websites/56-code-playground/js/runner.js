/* =========================================================
   FORKBENCH — Preview runner
   Assembles HTML/CSS/JS into a sandboxed iframe via srcdoc,
   injects a console shim that forwards console.* and runtime
   errors back to the parent over postMessage.
   ========================================================= */
(function (global) {
  'use strict';

  // Unique channel so we ignore unrelated messages.
  var CHANNEL = 'forkbench-console';

  // The shim is injected as the FIRST script so it captures
  // everything that follows. It is stringified verbatim.
  function shimSource() {
    return (
'(function(){' +
'  var CH = "' + CHANNEL + '";' +
'  function ser(v, depth){' +
'    depth = depth || 0;' +
'    try {' +
'      if (v === null) return "null";' +
'      if (v === undefined) return "undefined";' +
'      var t = typeof v;' +
'      if (t === "string") return depth === 0 ? v : JSON.stringify(v);' +
'      if (t === "number" || t === "boolean") return String(v);' +
'      if (t === "function") return "ƒ " + (v.name || "anonymous") + "()";' +
'      if (v instanceof Error) return v.name + ": " + v.message;' +
'      if (Array.isArray(v)) {' +
'        if (depth > 2) return "[…]";' +
'        return "[" + v.map(function(x){return ser(x, depth+1);}).join(", ") + "]";' +
'      }' +
'      if (t === "object") {' +
'        if (v.nodeType) return "<" + (v.nodeName||"node").toLowerCase() + ">";' +
'        if (depth > 2) return "{…}";' +
'        var ks = Object.keys(v);' +
'        var inner = ks.slice(0, 12).map(function(k){return k + ": " + ser(v[k], depth+1);});' +
'        if (ks.length > 12) inner.push("…" + (ks.length-12) + " more");' +
'        return "{ " + inner.join(", ") + " }";' +
'      }' +
'      return String(v);' +
'    } catch (e) { return "[unserializable]"; }' +
'  }' +
'  function send(level, args){' +
'    var parts = [];' +
'    for (var i=0;i<args.length;i++) parts.push(ser(args[i], 0));' +
'    try {' +
'      parent.postMessage({ source: CH, level: level, text: parts.join(" ") }, "*");' +
'    } catch(e){}' +
'  }' +
'  var orig = {};' +
'  ["log","info","warn","error","debug"].forEach(function(m){' +
'    orig[m] = console[m] ? console[m].bind(console) : function(){};' +
'    console[m] = function(){' +
'      var lvl = (m === "warn") ? "warn" : (m === "error") ? "error" : "log";' +
'      send(lvl, arguments);' +
'      orig[m].apply(console, arguments);' +
'    };' +
'  });' +
'  window.addEventListener("error", function(e){' +
'    var where = e.lineno ? (" (line " + e.lineno + ")") : "";' +
'    send("error", [ (e.message || "Script error") + where ]);' +
'  });' +
'  window.addEventListener("unhandledrejection", function(e){' +
'    send("error", [ "Uncaught (in promise): " + ser(e.reason, 0) ]);' +
'  });' +
'  parent.postMessage({ source: CH, level: "system", text: "__cleared__" }, "*");' +
'})();'
    );
  }

  function buildDoc(pen) {
    var html = pen.html || '';
    var css = pen.css || '';
    var js = pen.js || '';
    return (
'<!DOCTYPE html>\n' +
'<html>\n<head>\n<meta charset="utf-8">\n' +
'<meta name="viewport" content="width=device-width, initial-scale=1">\n' +
'<script>' + shimSource() + '<\/script>\n' +
'<style>\n' + css + '\n</style>\n' +
'</head>\n<body>\n' +
html + '\n' +
'<script>\ntry {\n' + js + '\n} catch (err) {\n' +
'  console.error(err && err.message ? err.message : String(err));\n}\n<\/script>\n' +
'</body>\n</html>'
    );
  }

  function Runner(iframe, onMessage) {
    this.iframe = iframe;
    var self = this;
    global.addEventListener('message', function (e) {
      var d = e.data;
      if (!d || d.source !== CHANNEL) return;
      onMessage(d);
    });
  }

  Runner.prototype.run = function (pen) {
    // Reassigning srcdoc fully tears down and recreates the document,
    // which restarts timers / animation frames cleanly.
    this.iframe.srcdoc = buildDoc(pen);
  };

  // Expose the combined document for export / download.
  Runner.prototype.buildExport = function (pen) {
    return (
'<!DOCTYPE html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n' +
'<meta name="viewport" content="width=device-width, initial-scale=1">\n' +
'<title>' + (pen.title || 'Forkbench export') + '</title>\n' +
'<style>\n' + (pen.css || '') + '\n</style>\n</head>\n<body>\n' +
(pen.html || '') + '\n<script>\n' + (pen.js || '') + '\n<\/script>\n</body>\n</html>\n'
    );
  };

  global.ForkbenchRunner = Runner;
})(window);
