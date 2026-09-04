/* ============================================================
   join.js — application form handler.
   No server (file://). Responds with an eerie auto-acknowledgement.
   ============================================================ */
(function () {
  'use strict';
  var form = document.querySelector('.application');
  if (!form) return;
  var notice = document.getElementById('ap-notice');

  var replies = [
    'APPLICATION RECEIVED. The carrier logged your name before you finished typing it.',
    'TRANSMISSION ACKNOWLEDGED. Chair five remains vacant. We will be in contact on a frequency you already know.',
    'RECEIVED. You said you listen from somewhere. So do we. We are closer than that.',
    'FILED. The watch thanks you. Do not switch off your set tonight.'
  ];

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var name = (form.querySelector('#ap-name') || {}).value || '';
    name = name.trim();
    var msg = replies[Math.floor(Math.random() * replies.length)];
    if (name) {
      msg = msg.replace('your name', '"' + name + '"');
      msg += '\n\nWelcome to the Watch, ' + name + '. We have been expecting an application like yours.';
    }
    notice.hidden = false;
    notice.style.whiteSpace = 'pre-wrap';
    notice.textContent = msg;
    notice.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (window.WLC && window.WLC.whisper) {
      window.WLC.whisper('we received it. all of it.');
    }
  });
})();
