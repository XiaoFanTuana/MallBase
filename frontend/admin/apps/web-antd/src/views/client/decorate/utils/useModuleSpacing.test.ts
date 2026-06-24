import { describe, expect, it } from 'vitest';

import {
  getModulePaddingOverall,
  getModulePaddingSide,
  syncModulePaddingCompat,
  updateModulePaddingAll,
  updateModulePaddingSide,
} from './useModuleSpacing';

describe('useModuleSpacing', () => {
  it('updates all padding sides from one value', () => {
    const module = { config: { paddingX: 10, paddingY: 4 } };

    updateModulePaddingAll(module, 28);

    expect(module.config).toMatchObject({
      padding: 28,
      paddingBottom: 28,
      paddingLeft: 28,
      paddingRight: 28,
      paddingTop: 28,
      paddingX: 28,
      paddingY: 28,
      padding_bottom: 28,
      padding_left: 28,
      padding_right: 28,
      padding_top: 28,
      padding_x: 28,
      padding_y: 28,
    });
    expect(getModulePaddingOverall(module)).toBe(28);
  });

  it('keeps right and left padding independent after side updates', () => {
    const module = { config: { paddingX: 28, paddingY: 28 } };

    updateModulePaddingSide(module, 'paddingRight', 80);

    expect(getModulePaddingSide(module, 'paddingLeft')).toBe(28);
    expect(getModulePaddingSide(module, 'paddingRight')).toBe(80);
    expect(module.config.paddingLeft).toBe(28);
    expect(module.config.paddingRight).toBe(80);

    updateModulePaddingSide(module, 'paddingLeft', 10);

    expect(getModulePaddingSide(module, 'paddingLeft')).toBe(10);
    expect(getModulePaddingSide(module, 'paddingRight')).toBe(80);
    expect(module.config.paddingLeft).toBe(10);
    expect(module.config.paddingRight).toBe(80);
    expect(module.config.paddingX).toBe(45);
  });

  it('uses four-side fields before legacy paddingX and paddingY', () => {
    const module = {
      config: {
        paddingBottom: 12,
        paddingLeft: 10,
        paddingRight: 80,
        paddingTop: 20,
        paddingX: 28,
        paddingY: 28,
      },
    };

    syncModulePaddingCompat(module.config);

    expect(getModulePaddingSide(module, 'paddingTop')).toBe(20);
    expect(getModulePaddingSide(module, 'paddingRight')).toBe(80);
    expect(getModulePaddingSide(module, 'paddingBottom')).toBe(12);
    expect(getModulePaddingSide(module, 'paddingLeft')).toBe(10);
    expect(module.config.paddingX).toBe(45);
    expect(module.config.paddingY).toBe(16);
  });
});
