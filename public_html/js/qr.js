/* Gerador de QR code (ISO/IEC 18004), modo byte, correção de erro nível M, versões 1 a 13.
   Feito para o código Pix "copia e cola". Uso: qrSvg(texto) devolve um <svg> pronto. */
(function (w) {
  'use strict';
  // [EC por bloco, [[qtd de blocos, bytes de dados por bloco], ...]] para o nível M
  var BLOCKS = [null,
    [10, [[1, 16]]], [16, [[1, 28]]], [26, [[1, 44]]], [18, [[2, 32]]], [24, [[2, 43]]],
    [16, [[4, 27]]], [18, [[4, 31]]], [22, [[2, 38], [2, 39]]], [22, [[3, 36], [2, 37]]],
    [26, [[4, 43], [1, 44]]], [30, [[1, 50], [4, 51]]], [22, [[6, 36], [2, 37]]], [22, [[8, 37], [1, 38]]]];
  var ALIGN = [null, [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46],
    [6, 28, 50], [6, 30, 54], [6, 32, 58], [6, 34, 62]];

  function gmul(x, y) { var z = 0; for (var i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11D); z ^= ((y >>> i) & 1) * x; } return z; }
  function rsDivisor(deg) {
    var r = []; for (var i = 0; i < deg; i++) r.push(0); r[deg - 1] = 1; var root = 1;
    for (i = 0; i < deg; i++) { for (var j = 0; j < deg; j++) { r[j] = gmul(r[j], root); if (j + 1 < deg) r[j] ^= r[j + 1]; } root = gmul(root, 2); }
    return r;
  }
  function rsRemainder(data, div) {
    var r = div.map(function () { return 0; });
    data.forEach(function (b) { var f = b ^ r.shift(); r.push(0); div.forEach(function (c, i) { r[i] ^= gmul(c, f); }); });
    return r;
  }
  function utf8(s) { var u = unescape(encodeURIComponent(s)), a = []; for (var i = 0; i < u.length; i++) a.push(u.charCodeAt(i)); return a; }

  function encode(text) {
    var bytes = utf8(text), ver, cap;
    for (ver = 1; ver <= 13; ver++) {
      cap = BLOCKS[ver][1].reduce(function (s, g) { return s + g[0] * g[1]; }, 0);
      if (4 + (ver < 10 ? 8 : 16) + bytes.length * 8 <= cap * 8) break;
    }
    if (ver > 13) throw new Error('Texto grande demais para o QR code.');
    // fluxo de bits
    var bits = [];
    function put(v, n) { for (var i = n - 1; i >= 0; i--) bits.push((v >>> i) & 1); }
    put(4, 4); put(bytes.length, ver < 10 ? 8 : 16); bytes.forEach(function (b) { put(b, 8); });
    put(0, Math.min(4, cap * 8 - bits.length)); while (bits.length % 8) bits.push(0);
    var data = []; for (var i = 0; i < bits.length; i += 8) { var v = 0; for (var k = 0; k < 8; k++) v = (v << 1) | bits[i + k]; data.push(v); }
    for (var pad = 0xEC; data.length < cap; pad ^= 0xEC ^ 0x11) data.push(pad);
    // blocos + correção de erro, intercalados
    var ec = BLOCKS[ver][0], div = rsDivisor(ec), blocks = [], pos = 0;
    BLOCKS[ver][1].forEach(function (g) { for (var n = 0; n < g[0]; n++) { var d = data.slice(pos, pos + g[1]); pos += g[1]; blocks.push({ d: d, e: rsRemainder(d, div) }); } });
    var out = [], maxD = Math.max.apply(null, blocks.map(function (b) { return b.d.length; }));
    for (i = 0; i < maxD; i++) blocks.forEach(function (b) { if (i < b.d.length) out.push(b.d[i]); });
    for (i = 0; i < ec; i++) blocks.forEach(function (b) { out.push(b.e[i]); });

    var size = ver * 4 + 17, mod = [], fn = [];
    for (i = 0; i < size; i++) { mod.push(new Array(size).fill(false)); fn.push(new Array(size).fill(false)); }
    function set(x, y, d) { mod[y][x] = d; fn[y][x] = true; }
    // padrões fixos
    for (i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
    [[3, 3], [size - 4, 3], [3, size - 4]].forEach(function (c) {
      for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++) {
        var x = c[0] + dx, y = c[1] + dy, dist = Math.max(Math.abs(dx), Math.abs(dy));
        if (x >= 0 && x < size && y >= 0 && y < size) set(x, y, dist !== 2 && dist !== 4);
      }
    });
    var al = ALIGN[ver], last = al.length - 1;
    al.forEach(function (ay, a) { al.forEach(function (ax, b) {
      if ((a === 0 && b === 0) || (a === 0 && b === last) || (a === last && b === 0)) return;
      for (var dy = -2; dy <= 2; dy++) for (var dx = -2; dx <= 2; dx++) set(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
    }); });
    function drawFormat(mask) {
      var d = (0 << 3) | mask, r = d; // nível M = 00
      for (var i = 0; i < 10; i++) r = (r << 1) ^ ((r >>> 9) * 0x537);
      var b = ((d << 10) | r) ^ 0x5412, bit = function (n) { return ((b >>> n) & 1) === 1; };
      for (i = 0; i <= 5; i++) set(8, i, bit(i));
      set(8, 7, bit(6)); set(8, 8, bit(7)); set(7, 8, bit(8));
      for (i = 9; i < 15; i++) set(14 - i, 8, bit(i));
      for (i = 0; i < 8; i++) set(size - 1 - i, 8, bit(i));
      for (i = 8; i < 15; i++) set(8, size - 15 + i, bit(i));
      set(8, size - 8, true);
    }
    drawFormat(0);
    if (ver >= 7) {
      var r = ver; for (i = 0; i < 12; i++) r = (r << 1) ^ ((r >>> 11) * 0x1F25);
      var vb = (ver << 12) | r;
      for (i = 0; i < 18; i++) { var bt = ((vb >>> i) & 1) === 1, a = size - 11 + i % 3, c = Math.floor(i / 3); set(a, c, bt); set(c, a, bt); }
    }
    // dados em zigue-zague
    var idx = 0;
    for (var right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (var vert = 0; vert < size; vert++) for (var j = 0; j < 2; j++) {
        var x = right - j, up = ((right + 1) & 2) === 0, y = up ? size - 1 - vert : vert;
        if (!fn[y][x] && idx < out.length * 8) { mod[y][x] = ((out[idx >>> 3] >>> (7 - (idx & 7))) & 1) === 1; idx++; }
      }
    }
    // máscara: escolhe a de menor penalidade
    var MASKS = [function (x, y) { return (x + y) % 2 === 0; }, function (x, y) { return y % 2 === 0; }, function (x) { return x % 3 === 0; },
      function (x, y) { return (x + y) % 3 === 0; }, function (x, y) { return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; },
      function (x, y) { return x * y % 2 + x * y % 3 === 0; }, function (x, y) { return (x * y % 2 + x * y % 3) % 2 === 0; },
      function (x, y) { return ((x + y) % 2 + x * y % 3) % 2 === 0; }];
    function applyMask(m) { for (var y = 0; y < size; y++) for (var x = 0; x < size; x++) if (!fn[y][x] && MASKS[m](x, y)) mod[y][x] = !mod[y][x]; }
    function penalty() {
      var p = 0, dark = 0, x, y;
      for (y = 0; y < size; y++) for (var dir = 0; dir < 2; dir++) {
        var run = 0, prev = null;
        for (x = 0; x < size; x++) { var m = dir ? mod[x][y] : mod[y][x]; if (m === prev) { run++; if (run === 5) p += 3; else if (run > 5) p++; } else { run = 1; prev = m; } }
      }
      for (y = 0; y < size - 1; y++) for (x = 0; x < size - 1; x++) { var c = mod[y][x]; if (c === mod[y][x + 1] && c === mod[y + 1][x] && c === mod[y + 1][x + 1]) p += 3; }
      for (y = 0; y < size; y++) for (x = 0; x < size; x++) if (mod[y][x]) dark++;
      return p + Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
    }
    var best = 0, bestP = Infinity;
    for (var m = 0; m < 8; m++) { applyMask(m); drawFormat(m); var pp = penalty(); if (pp < bestP) { bestP = pp; best = m; } applyMask(m); }
    applyMask(best); drawFormat(best);
    return mod;
  }

  w.qrMatrix = encode;
  w.qrSvg = function (text, label) {
    var mod = encode(text), n = mod.length, q = 4, d = '';
    for (var y = 0; y < n; y++) for (var x = 0; x < n; x++) if (mod[y][x]) d += 'M' + (x + q) + ' ' + (y + q) + 'h1v1h-1z';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + (n + 2 * q) + ' ' + (n + 2 * q) + '" shape-rendering="crispEdges" role="img" aria-label="' + (label || 'QR code') + '">' +
      '<rect width="100%" height="100%" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
  };
})(window);
