/* =========================================================
   LATTICE — Starter templates / seed data
   Real, meaningful datasets (no lorem ipsum). Each template is
   a list of {cell, raw, fmt} so the formula engine has plenty
   to chew on: SUM, AVERAGE, IF, percentages, nested formulas.

   window.Lattice.Templates
   ========================================================= */
(function () {
  'use strict';

  // helper: build a flat cell list from a 2D array of {v,fmt} | string
  function grid(name, startRow, rowsArr, columnFmts) {
    const cells = [];
    rowsArr.forEach((row, ri) => {
      row.forEach((c, ci) => {
        if (c == null) return;
        const raw = typeof c === 'object' ? c.v : c;
        if (raw === '' || raw == null) return;
        const fmt = typeof c === 'object' ? (c.fmt || null) : (columnFmts ? columnFmts[ci] : null);
        cells.push({ r: startRow + ri, c: ci, raw: String(raw), fmt });
      });
    });
    return { name, cells };
  }

  const B = { bold: true };
  const CUR = { numfmt: 'currency' };
  const PCT = { numfmt: 'percent' };
  const HDR = { bold: true, align: 'center' };
  const RGT = { align: 'right' };

  /* ── 1. MONTHLY HOUSEHOLD BUDGET ─────────────────────────── */
  function budget() {
    const rows = [
      [{ v: 'LATTICE — Monthly Budget · March 2026', fmt: { bold: true } }],
      [],
      [{ v: 'Category', fmt: HDR }, { v: 'Budgeted', fmt: HDR }, { v: 'Actual', fmt: HDR }, { v: 'Difference', fmt: HDR }, { v: '% Used', fmt: HDR }],
      ['Salary (net)',        { v: 5200, fmt: CUR }, { v: 5200, fmt: CUR }, { v: '=C4-B4', fmt: CUR }, { v: '=IF(B4=0,0,C4/B4)', fmt: PCT }],
      ['Rent',                { v: 1750, fmt: CUR }, { v: 1750, fmt: CUR }, { v: '=B5-C5', fmt: CUR }, { v: '=C5/B5', fmt: PCT }],
      ['Groceries',           { v: 600,  fmt: CUR }, { v: 642.18, fmt: CUR }, { v: '=B6-C6', fmt: CUR }, { v: '=C6/B6', fmt: PCT }],
      ['Utilities',           { v: 240,  fmt: CUR }, { v: 211.40, fmt: CUR }, { v: '=B7-C7', fmt: CUR }, { v: '=C7/B7', fmt: PCT }],
      ['Transit & Fuel',      { v: 180,  fmt: CUR }, { v: 205.30, fmt: CUR }, { v: '=B8-C8', fmt: CUR }, { v: '=C8/B8', fmt: PCT }],
      ['Internet & Phone',    { v: 130,  fmt: CUR }, { v: 129.99, fmt: CUR }, { v: '=B9-C9', fmt: CUR }, { v: '=C9/B9', fmt: PCT }],
      ['Dining Out',          { v: 250,  fmt: CUR }, { v: 318.75, fmt: CUR }, { v: '=B10-C10', fmt: CUR }, { v: '=C10/B10', fmt: PCT }],
      ['Subscriptions',       { v: 65,   fmt: CUR }, { v: 71.96,  fmt: CUR }, { v: '=B11-C11', fmt: CUR }, { v: '=C11/B11', fmt: PCT }],
      ['Savings transfer',    { v: 800,  fmt: CUR }, { v: 800,    fmt: CUR }, { v: '=B12-C12', fmt: CUR }, { v: '=C12/B12', fmt: PCT }],
      [],
      [{ v: 'Total expenses', fmt: B }, { v: '=SUM(B5:B12)', fmt: { bold: true, numfmt: 'currency' } }, { v: '=SUM(C5:C12)', fmt: { bold: true, numfmt: 'currency' } }, { v: '=SUM(D5:D12)', fmt: { bold: true, numfmt: 'currency' } }, { v: '=C14/B14', fmt: PCT }],
      [{ v: 'Net (income − spend)', fmt: B }, { v: '=B4-B14', fmt: { bold: true, numfmt: 'currency' } }, { v: '=C4-C14', fmt: { bold: true, numfmt: 'currency' } }],
      [],
      [{ v: 'Avg actual / category', fmt: B }, { v: '=AVERAGE(C5:C12)', fmt: CUR }],
      [{ v: 'Biggest expense', fmt: B }, { v: '=MAX(C5:C12)', fmt: CUR }],
      [{ v: 'Over budget?', fmt: B }, { v: '=IF(C14>B14,"YES — review","On track")' }],
    ];
    return grid('Monthly Budget — March 2026', 0, rows);
  }

  /* ── 2. FREELANCE INVOICE ────────────────────────────────── */
  function invoice() {
    const rows = [
      [{ v: 'STUDIO NORTHWIND — Invoice #2026-114', fmt: { bold: true } }],
      [{ v: 'Bill to: Greenfield Robotics, Inc.' }],
      [{ v: 'Issued', fmt: B }, { v: '=TODAY()', fmt: { numfmt: 'date' } }, { v: 'Due (Net 30)', fmt: B }, { v: '=TODAY()+30', fmt: { numfmt: 'date' } }],
      [],
      [{ v: 'Description', fmt: HDR }, { v: 'Qty', fmt: HDR }, { v: 'Rate', fmt: HDR }, { v: 'Amount', fmt: HDR }],
      ['UX research & interviews', { v: 12, fmt: RGT }, { v: 145, fmt: CUR }, { v: '=B6*C6', fmt: CUR }],
      ['Wireframes & prototyping', { v: 28, fmt: RGT }, { v: 130, fmt: CUR }, { v: '=B7*C7', fmt: CUR }],
      ['Design system build',      { v: 40, fmt: RGT }, { v: 155, fmt: CUR }, { v: '=B8*C8', fmt: CUR }],
      ['Front-end implementation', { v: 56, fmt: RGT }, { v: 165, fmt: CUR }, { v: '=B9*C9', fmt: CUR }],
      ['QA & handoff',             { v: 9,  fmt: RGT }, { v: 120, fmt: CUR }, { v: '=B10*C10', fmt: CUR }],
      [],
      [{ v: 'Subtotal', fmt: B }, null, null, { v: '=SUM(D6:D10)', fmt: CUR }],
      [{ v: 'Tax rate', fmt: B }, null, null, { v: 0.0825, fmt: PCT }],
      [{ v: 'Tax', fmt: B }, null, null, { v: '=D12*D13', fmt: CUR }],
      [{ v: 'Discount', fmt: B }, null, null, { v: 250, fmt: CUR }],
      [{ v: 'TOTAL DUE', fmt: { bold: true } }, null, null, { v: '=D12+D14-D15', fmt: { bold: true, numfmt: 'currency' } }],
      [],
      [{ v: 'Hours billed', fmt: B }, { v: '=SUM(B6:B10)', fmt: { numfmt: 'integer' } }],
      [{ v: 'Blended rate', fmt: B }, { v: '=ROUND(D12/B18,2)', fmt: CUR }],
    ];
    return grid('Invoice #2026-114', 0, rows);
  }

  /* ── 3. 30-DAY HABIT TRACKER ─────────────────────────────── */
  function habits() {
    const habitsList = ['Workout', 'Read 20 min', 'No sugar', 'Inbox zero', 'Walk 8k steps'];
    // a believable pattern of 1/0 across 14 days
    const data = [
      [1,1,0,1,1,1,0,1,1,1,0,1,1,1],
      [1,0,1,1,0,1,1,1,0,1,1,1,0,1],
      [0,0,1,1,1,0,0,1,1,0,1,1,1,0],
      [1,1,1,0,1,1,1,1,0,1,1,0,1,1],
      [1,1,1,1,0,1,1,0,1,1,1,1,1,0],
    ];
    const rows = [];
    rows.push([{ v: 'LATTICE — 14-Day Habit Tracker', fmt: { bold: true } }]);
    rows.push([]);
    const header = [{ v: 'Habit', fmt: HDR }];
    for (let d = 1; d <= 14; d++) header.push({ v: 'D' + d, fmt: { bold: true, align: 'center' } });
    header.push({ v: 'Done', fmt: HDR });
    header.push({ v: 'Rate', fmt: HDR });
    header.push({ v: 'Streak?', fmt: HDR });
    rows.push(header);

    habitsList.forEach((h, i) => {
      const row = [{ v: h, fmt: B }];
      data[i].forEach(v => row.push({ v: v, fmt: { align: 'center' } }));
      const rn = i + 4; // 1-based row number
      row.push({ v: `=SUM(B${rn}:O${rn})`, fmt: { align: 'center', bold: true } });
      row.push({ v: `=P${rn}/14`, fmt: PCT });
      row.push({ v: `=IF(Q${rn}>=0.8,"Strong","Build it")` });
      rows.push(row);
    });
    rows.push([]);
    const totRow = rows.length + 1;
    const row = [{ v: 'Daily total', fmt: B }];
    for (let d = 0; d < 14; d++) {
      const colLetter = String.fromCharCode(66 + d); // B..O
      row.push({ v: `=SUM(${colLetter}4:${colLetter}8)`, fmt: { align: 'center' } });
    }
    rows.push(row);
    rows.push([{ v: 'Best habit (rate)', fmt: B }, { v: '=MAX(Q4:Q8)', fmt: PCT }]);
    rows.push([{ v: 'Total checkmarks', fmt: B }, { v: '=SUM(P4:P8)', fmt: { numfmt: 'integer', bold: true } }]);
    return grid('14-Day Habit Tracker', 0, rows);
  }

  /* ── 4. PROJECT SPRINT TRACKER ───────────────────────────── */
  function project() {
    const tasks = [
      ['Auth & onboarding flow', 'Priya', 'Done',        8, 8],
      ['Billing integration',    'Marco', 'In progress', 13, 9],
      ['Dashboard redesign',     'Lena',  'In progress', 8, 5],
      ['CSV export engine',      'Sam',   'Blocked',     5, 1],
      ['Mobile nav',             'Priya', 'Todo',        5, 0],
      ['API rate limiting',      'Marco', 'Todo',        3, 0],
      ['Docs & changelog',       'Lena',  'In progress', 2, 1],
      ['Perf budget audit',      'Sam',   'Done',        5, 5],
    ];
    const rows = [
      [{ v: 'LATTICE — Sprint 24 Tracker (2-week)', fmt: { bold: true } }],
      [],
      [{ v: 'Task', fmt: HDR }, { v: 'Owner', fmt: HDR }, { v: 'Status', fmt: HDR }, { v: 'Est', fmt: HDR }, { v: 'Done', fmt: HDR }, { v: '% Complete', fmt: HDR }, { v: 'Remaining', fmt: HDR }],
    ];
    tasks.forEach((t, i) => {
      const rn = i + 4;
      rows.push([
        t[0], t[1], t[2],
        { v: t[3], fmt: { align: 'center' } },
        { v: t[4], fmt: { align: 'center' } },
        { v: `=IF(D${rn}=0,0,E${rn}/D${rn})`, fmt: PCT },
        { v: `=D${rn}-E${rn}`, fmt: { align: 'center' } },
      ]);
    });
    const first = 4, last = 3 + tasks.length;
    rows.push([]);
    rows.push([
      { v: 'Totals', fmt: B }, null, null,
      { v: `=SUM(D${first}:D${last})`, fmt: { bold: true, align: 'center' } },
      { v: `=SUM(E${first}:E${last})`, fmt: { bold: true, align: 'center' } },
      { v: `=E${last + 2}/D${last + 2}`, fmt: PCT },
      { v: `=SUM(G${first}:G${last})`, fmt: { align: 'center' } },
    ]);
    rows.push([{ v: 'Tasks done', fmt: B }, { v: `=COUNTIF(C${first}:C${last},"Done")`, fmt: { align: 'center' } }]);
    rows.push([{ v: 'Tasks blocked', fmt: B }, { v: `=COUNTIF(C${first}:C${last},"Blocked")`, fmt: { align: 'center' } }]);
    rows.push([{ v: 'Avg completion', fmt: B }, { v: `=AVERAGE(F${first}:F${last})`, fmt: PCT }]);
    rows.push([{ v: 'Sprint health', fmt: B }, { v: `=IF(F${last + 2}>0.6,"On pace","At risk")` }]);
    return grid('Sprint 24 Tracker', 0, rows);
  }

  /* ── 5. The default seed shown on first load (a budget) ───── */
  function defaultSheet() { return budget(); }

  window.Lattice.Templates = {
    list: [
      { id: 'budget',  title: 'Monthly Budget',   desc: 'Income vs. spend with % used, totals, AVERAGE, MAX & an over-budget IF flag.', build: budget,  rows: 8, cols: 5 },
      { id: 'invoice', title: 'Freelance Invoice', desc: 'Line items, qty × rate, tax %, discount, TODAY() due date and a blended-rate ROUND.', build: invoice, rows: 5, cols: 4 },
      { id: 'habits',  title: '14-Day Habit Tracker', desc: 'Daily checkmarks summed per habit & per day, completion % and a streak IF.', build: habits, rows: 5, cols: 14 },
      { id: 'project', title: 'Sprint Tracker', desc: 'Tasks, owners, status, estimate vs. done, COUNTIF status counts and sprint-health flag.', build: project, rows: 8, cols: 7 },
    ],
    byId(id) { return (this.list.find(t => t.id === id) || this.list[0]).build(); },
    defaultSheet,
  };
})();
