import { requestClient } from '#/api/request';

export namespace ConfigApi {
  /** 颜色选项 */
  export interface ColorOption {
    value: string;
    label: string;
    color: string;
  }

  /** 颜色选项响应 */
  export interface ColorOptionsResponse {
    options: ColorOption[];
  }
}

/**
 * 获取颜色选项列表
 */
export async function getColorOptionsApi() {
  return requestClient.get<ConfigApi.ColorOptionsResponse>('/config/colorOptions');
}
