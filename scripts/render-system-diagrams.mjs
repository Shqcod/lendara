import { createRequire } from 'node:module';
import { createServer } from 'node:http';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { dirname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(resolve(root, 'frontend/package.json'));
const { chromium } = require('playwright');
const dist = resolve(root, '.tools/mermaid-render/node_modules/mermaid/dist');
const pkg = JSON.parse(await readFile(resolve(dist, '../package.json'), 'utf8'));
if (pkg.version !== '12.3.0') throw new Error('Renderer requires isolated Mermaid12.3.0');
const source = await readFile(resolve(root, 'docs/SYSTEM_OVERVIEW.md'), 'utf8');
const diagrams = [...source.matchAll(/```mermaid\r?\n([\s\S]*?)```/g)].map(m => m[1]);
const names = ['architecture', 'workflow', 'domain-relations'];
if (diagrams.length !== names.length) throw new Error('Expected three system diagrams');
const destination = resolve(root, 'docs/diagrams');
await mkdir(destination, { recursive: true });

// Serve only installed Mermaid assets on an owned ephemeral loopback port.
const server = createServer(async (request, response) => {
  try {
    const path = decodeURIComponent(new URL(request.url, 'http://localhost').pathname);
    if (path === '/') {
      response.setHeader('Content-Type', 'text/html');
      response.end('<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;background:white"></body></html>');
      return;
    }
    const file = resolve(dist, path.replace(/^\/+/, ''));
    if (!file.startsWith(dist + sep)) throw new Error('Outside renderer assets');
    response.setHeader('Content-Type', /\.m?js$/.test(file) ? 'application/javascript' : 'application/octet-stream');
    response.end(await readFile(file));
  } catch { response.writeHead(404); response.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
let browser;
try {
  browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 1 });
  await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const evidence = [];
  for (const [index, diagram] of diagrams.entries()) {
    await page.goto(origin);
    const result = await page.evaluate(async ({ diagram, name }) => {
      const { default: mermaid } = await import('/mermaid.esm.min.mjs');
      mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', layout: 'dagre', theme: 'base',
        deterministicIds: true, deterministicIDSeed: 'lendara', fontFamily: 'Arial',
        flowchart: { htmlLabels: false, useMaxWidth: false }, state: { useMaxWidth: false }, er: { useMaxWidth: false },
        themeVariables: { primaryColor: '#edf5f0', primaryTextColor: '#173f36', lineColor: '#46665e', edgeLabelBackground: '#ffffff', fontSize: '16px' },
        themeCSS: '.edgeLabel text, .edgeLabel tspan { paint-order: stroke; stroke: white; stroke-width: 4px; stroke-linejoin: round; }'
      });
      await mermaid.parse(diagram);
      const { svg } = await mermaid.render(name, diagram);
      document.body.innerHTML = svg;
      await document.fonts.ready;
      const element = document.querySelector('svg');
      const box = element.viewBox.baseVal;
      element.style.maxWidth = 'none';
      element.style.width = `${Math.ceil(box.width)}px`;
      element.style.height = `${Math.ceil(box.height)}px`;
      return { svg: element.outerHTML, width: Math.ceil(box.width), height: Math.ceil(box.height) };
    }, { diagram, name: names[index] });
    if (result.width < 1 || result.height < 1) throw new Error('Empty rendered diagram');
    await writeFile(resolve(destination, names[index] + '.svg'), result.svg + '\n');
    await page.locator('svg').screenshot({ path: resolve(destination, names[index] + '.png') });
    evidence.push({ name: names[index], width: result.width, height: result.height, parsed: true, rendered: true });
  }
  await writeFile(resolve(root, 'docs/validation/diagram-render-2026-11-16.json'), JSON.stringify({
    date: '2026-11-16', mermaid: pkg.version, browser: 'Chrome', external_requests_allowed: false, diagrams: evidence
  }, null, 2) + '\n');
  console.log(JSON.stringify({ diagrams_rendered: evidence.length, external_requests_allowed: false }));
} finally {
  if (browser) await browser.close();
  await new Promise(resolve => server.close(resolve));
}
