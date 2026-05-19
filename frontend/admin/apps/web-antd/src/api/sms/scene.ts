import { requestClient } from '#/api/request';

export namespace SmsSceneApi {
  export interface SceneItem {
    scene_code: string;
    scene_name: string;
    provider_id?: number;
    provider_name?: string;
    template_id?: number;
    template_name?: string;
    sign_id?: number;
    sign_name?: string;
    status: number;
    update_time?: string;
    /** 当前场景可用的占位符名称(后端按场景定义,只读下发) */
    available_params?: string[];
  }

  export interface BindParams {
    scene_code: string;
    provider_id: number;
    template_id: number;
    sign_id: number;
    status: number;
  }
}

export async function getSmsSceneListApi() {
  return requestClient.get<SmsSceneApi.SceneItem[]>('/sms/scene/list');
}

export async function bindSmsSceneApi(data: SmsSceneApi.BindParams) {
  return requestClient.post('/sms/scene/bind', data);
}

export async function unbindSmsSceneApi(sceneCode: string) {
  return requestClient.post('/sms/scene/unbind', { scene_code: sceneCode });
}
