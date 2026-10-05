import { test } from 'node:test';
import assert from 'node:assert/strict';
import { escapeHtml } from './html.js';

test('escapeHtml neutralise les cinq caractères dangereux', () => {
  assert.equal(escapeHtml(`<script>alert("x")</script> & 'y'`), '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#39;y&#39;');
});

test('escapeHtml laisse le texte ordinaire et les accents intacts', () => {
  assert.equal(escapeHtml('Répétition à 20h30'), 'Répétition à 20h30');
});

test('escapeHtml accepte les nombres et traite null et undefined comme une chaîne vide', () => {
  assert.equal(escapeHtml(42), '42');
  assert.equal(escapeHtml(null), '');
  assert.equal(escapeHtml(undefined), '');
});

test('escapeHtml ne ré-échappe pas deux fois à chaque appel indépendant', () => {
  assert.equal(escapeHtml(escapeHtml('<b>')), '&amp;lt;b&amp;gt;');
});
