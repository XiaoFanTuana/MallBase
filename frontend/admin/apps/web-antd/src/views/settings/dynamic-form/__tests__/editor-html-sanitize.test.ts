import { describe, expect, it } from 'vitest';

import { sanitizeEditorHtml } from '../editor-html-sanitize';

describe('sanitizeEditorHtml', () => {
  it('keeps common rich text tags', () => {
    expect(sanitizeEditorHtml('<p>Hello <strong>World</strong></p>')).toBe(
      '<p>Hello <strong>World</strong></p>',
    );
  });

  it('removes executable html and unsafe urls', () => {
    const html =
      '<p><img src="x" onerror="alert(1)"><script>alert(2)</script><a href="javascript:alert(3)">link</a></p>';

    const sanitized = sanitizeEditorHtml(html);

    expect(sanitized).not.toContain('onerror');
    expect(sanitized).not.toContain('<script>');
    expect(sanitized).not.toContain('javascript:');
    expect(sanitized).toContain('<img src="x">');
    expect(sanitized).toContain('<a>link</a>');
  });

  it('removes svg payloads, inline handlers and unsafe styling', () => {
    const html =
      '<svg><a href="javascript:alert(1)">bad</a></svg><p style="position:fixed" onclick="alert(2)">safe text</p>';

    const sanitized = sanitizeEditorHtml(html);

    expect(sanitized).toBe('<p>safe text</p>');
  });
});
