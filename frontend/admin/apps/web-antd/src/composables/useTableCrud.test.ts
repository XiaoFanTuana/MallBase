import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useFormModal } from './useTableCrud';

const { messageSuccess } = vi.hoisted(() => ({
  messageSuccess: vi.fn(),
}));

vi.mock('ant-design-vue', () => ({
  message: {
    error: vi.fn(),
    success: messageSuccess,
  },
  Modal: {
    confirm: vi.fn(),
  },
}));

describe('useFormModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('validates the template-bound form before creating a record', async () => {
    const modal = useFormModal();
    const validate = vi.fn().mockResolvedValue(undefined);
    const create = vi.fn().mockResolvedValue(undefined);

    modal.bindFormRef({ validate });
    modal.formData.value = { name: 'primary-provider' };

    await modal.handleSubmit({ create });

    expect(validate).toHaveBeenCalledOnce();
    expect(create).toHaveBeenCalledWith({ name: 'primary-provider' });
    expect(messageSuccess).toHaveBeenCalledWith('创建成功');
  });
});
