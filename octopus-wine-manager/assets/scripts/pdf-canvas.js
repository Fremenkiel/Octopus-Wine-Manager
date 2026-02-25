/* ─── Constants ─────────────────────────────────────────────────────── */

const CANVAS_W   = 595;
const CANVAS_H   = 380;
const ZONE_SPLIT = 240;   // 0–240 = header zone, 240–380 = wine row zone
const MARGIN     = 20;
const SNAP_PX    = 8;

/* ─── Canvas ────────────────────────────────────────────────────────── */

const canvas = new fabric.Canvas('pdfCanvas', {
  width:           CANVAS_W,
  height:          CANVAS_H,
  backgroundColor: '#ffffff',
  selection:       true,
});

// Start with toolbar greyed out
document.getElementById('owm-formatting-toolbar').classList.add('idle');

/* ─── Low-level helpers ──────────────────────────────────────────────── */

function makeLine(x1, y1, x2, y2, opts) {
  return new fabric.Line([x1, y1, x2, y2], Object.assign({
    selectable: false, evented: false, excludeFromExport: true, strokeWidth: 1,
  }, opts));
}

function makeLabel(text, left, top, color) {
  return new fabric.Text(text, {
    left, top,
    fontSize:    8,
    fill:        color || '#94a3b8',
    fontFamily:  'Arial',
    fontWeight:  '700',
    selectable:  false,
    evented:     false,
    excludeFromExport: true,
  });
}

/* ─── Decorations: zone separator + labels + reference line ─────────── */

const zoneSep    = makeLine(0, ZONE_SPLIT, CANVAS_W, ZONE_SPLIT, {
  stroke: '#94a3b8', strokeDashArray: [8, 4],
});
const lblHeader  = makeLabel('SECTION HEADERS  ·  H1 / H2 / H3', 8, 6);
const lblRow     = makeLabel('WINE ROW  ·  repeats for every wine entry', 8, ZONE_SPLIT + 6, '#64748b');
const refCenterX = makeLine(CANVAS_W / 2, 0, CANVAS_W / 2, CANVAS_H, {
  stroke: '#cbd5e1', strokeDashArray: [2, 6],
});

/* ─── Snap guides (blue, initially hidden) ───────────────────────────── */

const snapLeft    = makeLine(MARGIN,            0, MARGIN,            CANVAS_H, { stroke: '#3b82f6', visible: false });
const snapRight   = makeLine(CANVAS_W - MARGIN, 0, CANVAS_W - MARGIN, CANVAS_H, { stroke: '#3b82f6', visible: false });
const snapCenterX = makeLine(CANVAS_W / 2,      0, CANVAS_W / 2,      CANVAS_H, { stroke: '#3b82f6', strokeDashArray: [4, 4], visible: false });

// Zone-specific vertical (y-axis) centre snaps
const hZoneMid   = ZONE_SPLIT / 2;
const rZoneMid   = ZONE_SPLIT + (CANVAS_H - ZONE_SPLIT) / 2;
const snapHMid   = makeLine(0, hZoneMid, CANVAS_W, hZoneMid, { stroke: '#3b82f6', strokeDashArray: [4, 4], visible: false });
const snapRMid   = makeLine(0, rZoneMid, CANVAS_W, rZoneMid, { stroke: '#3b82f6', strokeDashArray: [4, 4], visible: false });

const snapGuides  = [snapLeft, snapRight, snapCenterX, snapHMid, snapRMid];
const decorations = [zoneSep, lblHeader, lblRow, refCenterX, ...snapGuides];

function hideSnapGuides() {
  snapGuides.forEach(g => g.set({ visible: false }));
}

/* ─── Add all decorations (called on init + after loadFromJSON) ─────── */

function addDecorations() {
  // Zone background objects behind content
  [zoneSep, refCenterX].forEach(d => { canvas.add(d); canvas.sendToBack(d); });
  // Snap guides in front of content
  snapGuides.forEach(g => { canvas.add(g); canvas.bringToFront(g); });
  // Labels always readable on top
  [lblHeader, lblRow].forEach(l => { canvas.add(l); canvas.bringToFront(l); });
}

addDecorations();

/* ─── Load saved template ────────────────────────────────────────────── */

if (window.owmTemplateData && window.owmTemplateData.template) {
  canvas.loadFromJSON(window.owmTemplateData.template, () => {
    addDecorations(); // loadFromJSON clears canvas; re-add decorations
    canvas.renderAll();
  });
}

/* ─── Snapping logic ─────────────────────────────────────────────────── */

canvas.on('object:moving', function (e) {
  const obj = e.target;
  if (obj.excludeFromExport) return;

  hideSnapGuides();

  const ow  = obj.getScaledWidth();
  const oh  = obj.getScaledHeight();
  const ol  = obj.left;
  const ocx = ol + ow / 2;
  const ocy = obj.top + oh / 2;

  // ── X-axis snaps ──────────────────────────────────────────────────
  if (Math.abs(ol - MARGIN) < SNAP_PX) {
    obj.set({ left: MARGIN });
    snapLeft.set({ visible: true });
  } else if (Math.abs(ol + ow - (CANVAS_W - MARGIN)) < SNAP_PX) {
    obj.set({ left: CANVAS_W - MARGIN - ow });
    snapRight.set({ visible: true });
  } else if (Math.abs(ocx - CANVAS_W / 2) < SNAP_PX) {
    obj.set({ left: CANVAS_W / 2 - ow / 2 });
    snapCenterX.set({ visible: true });
  }

  // ── Y-axis snaps (zone centres) ───────────────────────────────────
  if (Math.abs(ocy - hZoneMid) < SNAP_PX) {
    obj.set({ top: hZoneMid - oh / 2 });
    snapHMid.set({ visible: true });
  } else if (Math.abs(ocy - rZoneMid) < SNAP_PX) {
    obj.set({ top: rZoneMid - oh / 2 });
    snapRMid.set({ visible: true });
  }

  canvas.renderAll();
});

canvas.on('object:modified', function () { hideSnapGuides(); canvas.renderAll(); });
canvas.on('mouse:up',        function () { hideSnapGuides(); canvas.renderAll(); });

/* ─── Field palette ──────────────────────────────────────────────────── */

function headerLevelFor(role) {
  return { h1: 1, h2: 2, h3: 3 }[role] ?? 0;
}

// eslint-disable-next-line no-unused-vars
function addField(key, role) {
  const level = headerLevelFor(role);

  // Count existing objects of the same key+level for stagger
  const count  = canvas.getObjects('text').filter(o => o.placeholderKey === key && o.headerLevel === level).length;
  const stagger = count * 18;

  // Default style and position per role
  const defs = {
    h1:  { fontSize: 22, fontWeight: 'bold',   fontStyle: 'normal', fill: '#1e293b', top: 24  },
    h2:  { fontSize: 15, fontWeight: 'bold',   fontStyle: 'normal', fill: '#334155', top: 90  },
    h3:  { fontSize: 12, fontWeight: 'normal', fontStyle: 'italic', fill: '#64748b', top: 154 },
    row: { fontSize: 13, fontWeight: 'normal', fontStyle: 'normal', fill: '#1e293b', top: ZONE_SPLIT + 40 },
  };
  const d = defs[role] || defs.row;

  const text = new fabric.Text('{{' + key + '}}', {
    left:             MARGIN + stagger,
    top:              d.top  + stagger,
    fontFamily:       document.getElementById('owm-font-family').value || 'Arial',
    fontSize:         d.fontSize,
    fontWeight:       d.fontWeight,
    fontStyle:        d.fontStyle,
    fill:             d.fill,
    textAlign:        'left',
    placeholderKey:   key,
    headerLevel:      level,
    lockRotation:     true,
    hasRotatingPoint: false,
  });

  canvas.add(text);
  // Keep decorations on top after adding content
  snapGuides.forEach(g => canvas.bringToFront(g));
  canvas.bringToFront(lblHeader);
  canvas.bringToFront(lblRow);
  canvas.setActiveObject(text);
  canvas.renderAll();
}

/* ─── Toolbar helpers ────────────────────────────────────────────────── */

function getSelected() { return canvas.getActiveObject(); }

function applyToSelected(props) {
  const obj = getSelected();
  if (!obj) return;
  obj.set(props);
  canvas.renderAll();
}

function deleteSelected() {
  const obj = getSelected();
  if (obj && !obj.excludeFromExport) {
    canvas.remove(obj);
    canvas.discardActiveObject();
    canvas.renderAll();
  }
}

/* ─── Toolbar ↔ selection sync ───────────────────────────────────────── */

const toolbar = document.getElementById('owm-formatting-toolbar');

canvas.on('selection:created', syncToolbar);
canvas.on('selection:updated', syncToolbar);
canvas.on('selection:cleared', () => toolbar.classList.add('idle'));

function syncToolbar() {
  const obj = getSelected();
  if (!obj || obj.excludeFromExport) { toolbar.classList.add('idle'); return; }

  toolbar.classList.remove('idle');
  document.getElementById('owm-font-family').value = obj.fontFamily || 'Arial';
  document.getElementById('owm-font-size').value   = obj.fontSize   || 13;
  document.getElementById('owm-text-color').value  = toHex(obj.fill || '#1e293b');

  document.getElementById('owm-btn-bold').classList.toggle('active',   obj.fontWeight === 'bold');
  document.getElementById('owm-btn-italic').classList.toggle('active', obj.fontStyle  === 'italic');

  document.querySelectorAll('.owm-align-btn').forEach(btn =>
    btn.classList.toggle('active', btn.dataset.align === (obj.textAlign || 'left'))
  );
}

function toHex(color) {
  if (!color) return '#1e293b';
  if (color.startsWith('#')) return color.length === 4
    ? '#' + color[1]+color[1]+color[2]+color[2]+color[3]+color[3]
    : color;
  const m = color.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/);
  if (!m) return '#1e293b';
  return '#' + [m[1], m[2], m[3]].map(n => parseInt(n, 10).toString(16).padStart(2, '0')).join('');
}

/* ─── Toolbar event listeners ────────────────────────────────────────── */

document.getElementById('owm-font-family').addEventListener('change', function () {
  applyToSelected({ fontFamily: this.value });
});
document.getElementById('owm-font-size').addEventListener('change', function () {
  applyToSelected({ fontSize: parseInt(this.value, 10) });
});
document.getElementById('owm-text-color').addEventListener('input', function () {
  applyToSelected({ fill: this.value });
});
document.getElementById('owm-btn-bold').addEventListener('click', function () {
  const obj = getSelected(); if (!obj) return;
  const next = obj.fontWeight === 'bold' ? 'normal' : 'bold';
  applyToSelected({ fontWeight: next });
  this.classList.toggle('active', next === 'bold');
});
document.getElementById('owm-btn-italic').addEventListener('click', function () {
  const obj = getSelected(); if (!obj) return;
  const next = obj.fontStyle === 'italic' ? 'normal' : 'italic';
  applyToSelected({ fontStyle: next });
  this.classList.toggle('active', next === 'italic');
});
document.querySelectorAll('.owm-align-btn').forEach(btn => {
  btn.addEventListener('click', function () {
    applyToSelected({ textAlign: this.dataset.align });
    document.querySelectorAll('.owm-align-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
  });
});
document.getElementById('owm-btn-delete').addEventListener('click', deleteSelected);
document.addEventListener('keydown', e => {
  const tag = document.activeElement.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
  if (e.key === 'Delete' || e.key === 'Backspace') deleteSelected();
});

/* ─── Save ───────────────────────────────────────────────────────────── */

document.getElementById('owm-pdf-template-form').addEventListener('submit', () => {
  document.getElementById('owm_pdf_template_data').value =
    JSON.stringify(canvas.toJSON(['placeholderKey', 'headerLevel']));
});

/* ─── Generate PDF ───────────────────────────────────────────────────── */

document.getElementById('owm-generate-pdf').addEventListener('click', function () {
  const btn    = this;
  const status = document.getElementById('owm-pdf-status');

  btn.disabled       = true;
  btn.textContent    = 'Generating…';
  status.textContent = '';
  status.style.color = '';

  fetch(owmTemplateData.ajaxUrl, {
    method:  'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'owm_generate_pdf', nonce: owmTemplateData.nonce }),
  })
    .then(res => {
      const ct = res.headers.get('content-type') || '';
      if (!res.ok || !ct.includes('pdf')) {
        return res.text().then(t => { throw new Error(t.substring(0, 200)); });
      }
      return res.blob();
    })
    .then(blob => {
      const url = URL.createObjectURL(blob);
      const a   = Object.assign(document.createElement('a'), { href: url, download: 'wine-list.pdf' });
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      status.textContent = 'Downloaded!';
      setTimeout(() => { status.textContent = ''; }, 3000);
    })
    .catch(err => {
      status.textContent = 'Error: ' + err.message;
      status.style.color = '#dc2626';
    })
    .finally(() => {
      btn.disabled    = false;
      btn.textContent = 'Download Wine List PDF';
    });
});
