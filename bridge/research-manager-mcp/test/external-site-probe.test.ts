import assert from 'node:assert/strict';
import test from 'node:test';
import { analyseHtml } from '../src/external-site-probe.js';

test('external HTML analysis extracts bounded SEO/GEO discovery signals without rendering',()=>{
  const html=`<!doctype html>
  <html lang="es"><head>
    <title> Emmake — Consultoría digital </title>
    <meta name="description" content="Estrategia digital e IA para empresas.">
    <meta name="robots" content="index,follow">
    <meta property="og:title" content="Emmake">
    <meta property="og:url" content="https://example.test/">
    <link rel="canonical" href="https://example.test/">
    <link rel="alternate" hreflang="es" href="https://example.test/">
    <link rel="alternate" hreflang="en" href="https://example.test/en/">
    <script type="application/ld+json">{"@context":"https://schema.org"}</script>
  </head><body><h1>Consultoría digital</h1></body></html>`;

  const result=analyseHtml(html);
  assert.equal(result.title,'Emmake — Consultoría digital');
  assert.equal(result.meta_description,'Estrategia digital e IA para empresas.');
  assert.equal(result.meta_robots,'index,follow');
  assert.equal(result.canonical,'https://example.test/');
  assert.equal(result.html_lang,'es');
  assert.equal(result.h1_count,1);
  assert.equal(result.json_ld_count,1);
  assert.equal(result.hreflang_count,2);
  assert.deepEqual(result.hreflang,[
    {hreflang:'es',href:'https://example.test/'},
    {hreflang:'en',href:'https://example.test/en/'},
  ]);
  assert.equal(result.og_url,'https://example.test/');
  assert.equal(result.og_title,'Emmake');
});

test('external HTML analysis stays bounded on repeated discovery tags',()=>{
  const links=Array.from({length:80},(_,index)=>`<link rel="alternate" hreflang="x-${index}" href="https://example.test/${index}/">`).join('');
  const result=analyseHtml(`<html lang="es"><head>${links}</head><body><h1>A</h1><h1>B</h1></body></html>`);
  assert.equal(result.hreflang_count,50);
  assert.equal((result.hreflang as unknown[]).length,50);
  assert.equal(result.h1_count,2);
});
