/* =====================================================
   COLD START — theme JavaScript (progressive enhancement)
   Site works fully without JS; this adds polish only.
   ===================================================== */
(function () {
  'use strict';

  /* Active nav link based on current file */
  var current = (window.location.pathname.split('/').pop() || 'index.html');
  document.querySelectorAll('.nav a[href]').forEach(function (a) {
    var href = a.getAttribute('href').split('/').pop();
    if (href === current || (current === '' && href === 'index.html')) {
      a.classList.add('active');
      a.setAttribute('aria-current', 'page');
    }
  });

  /* Scroll reveal (respects reduced motion) */
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var revealEls = document.querySelectorAll('.reveal');
  if (!reduce && 'IntersectionObserver' in window && revealEls.length) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add('is-visible'); io.unobserve(e.target); }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -32px 0px' });
    revealEls.forEach(function (el) { io.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add('is-visible'); });
  }

  /* Estimated reading progress bar on article pages */
  var article = document.querySelector('.article .prose');
  if (article) {
    var bar = document.createElement('div');
    bar.setAttribute('aria-hidden', 'true');
    bar.style.cssText = 'position:fixed;top:0;left:0;height:3px;width:0;' +
      'background:var(--accent);z-index:40;transition:width .1s linear;';
    document.body.appendChild(bar);
    var update = function () {
      var rect = article.getBoundingClientRect();
      var total = article.offsetHeight - window.innerHeight;
      var scrolled = Math.min(Math.max(-rect.top, 0), Math.max(total, 1));
      bar.style.width = (total > 0 ? (scrolled / total) * 100 : 0) + '%';
    };
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update, { passive: true });
    update();
  }
})();
