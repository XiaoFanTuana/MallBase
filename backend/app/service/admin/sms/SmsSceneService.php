<?php

declare(strict_types=1);

namespace app\service\admin\sms;

use app\model\sms\SmsProvider;
use app\model\sms\SmsSceneBinding;
use app\model\sms\SmsSign;
use app\model\sms\SmsTemplate;
use app\service\sms\SmsScene;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 短信场景绑定 Service
 *
 * @extends BaseService<SmsSceneBinding>
 */
class SmsSceneService extends BaseService
{
    protected string $modelClass = SmsSceneBinding::class;

    /**
     * 列出所有内置场景 + 当前绑定情况(无绑定的也要返回行)
     */
    public function getList(): array
    {
        $bindings = $this->model()->select()->toArray();
        $byScene = [];
        foreach ($bindings as $row) {
            $byScene[$row['scene_code']] = $row;
        }

        $providerMap = SmsProvider::column('name', 'id');
        $templateMap = SmsTemplate::column('template_name', 'id');
        $signMap = SmsSign::column('sign_name', 'id');

        $list = [];
        foreach (SmsScene::allValues() as $code) {
            $row = $byScene[$code] ?? null;
            $list[] = [
                'scene_code' => $code,
                'scene_name' => SmsScene::textOf($code),
                'provider_id' => $row['provider_id'] ?? null,
                'provider_name' => $row ? ($providerMap[$row['provider_id']] ?? null) : null,
                'template_id' => $row['template_id'] ?? null,
                'template_name' => $row ? ($templateMap[$row['template_id']] ?? null) : null,
                'sign_id' => $row['sign_id'] ?? null,
                'sign_name' => $row ? ($signMap[$row['sign_id']] ?? null) : null,
                'status' => $row['status'] ?? 0,
                'update_time' => $row['update_time'] ?? null,
            ];
        }

        return $list;
    }

    public function bind(array $data): void
    {
        $sceneCode = (string) $data['scene_code'];
        if (!SmsScene::isValid($sceneCode)) {
            throw new BusinessException('未知场景');
        }

        $providerId = (int) $data['provider_id'];
        $provider = SmsProvider::find($providerId);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        $supportsRemote = SmsDriverFactory::supportsRemoteSignManagement($provider);
        $templateId = !empty($data['template_id']) ? (int) $data['template_id'] : 0;
        $signId = !empty($data['sign_id']) ? (int) $data['sign_id'] : 0;

        if ($templateId <= 0) {
            throw new BusinessException('请选择模板');
        }
        if ($signId <= 0) {
            throw new BusinessException('请选择签名');
        }

        $template = SmsTemplate::find($templateId);
        if ($template === null || $template->provider_id !== $providerId) {
            throw new BusinessException('模板与服务商不匹配');
        }
        $sign = SmsSign::find($signId);
        if ($sign === null || $sign->provider_id !== $providerId) {
            throw new BusinessException('签名与服务商不匹配');
        }

        // 支持远端管理的驱动(普通阿里云):仅接受 PASSED
        // 不支持远端管理的驱动(PNVS):接受 PASSED 或 LOCAL_ONLY(系统赠送本地登记后即可用)
        $allowedStatuses = $supportsRemote
            ? [SmsTemplate::AUDIT_PASSED]
            : [SmsTemplate::AUDIT_PASSED, SmsTemplate::AUDIT_LOCAL_ONLY];

        if (!in_array($template->audit_status, $allowedStatuses, true)) {
            throw new BusinessException(
                $supportsRemote
                    ? '模板尚未审核通过,不能绑定'
                    : '模板状态不可用,请确认本地登记完成'
            );
        }
        if (!in_array($sign->audit_status, $allowedStatuses, true)) {
            throw new BusinessException(
                $supportsRemote
                    ? '签名尚未审核通过,不能绑定'
                    : '签名状态不可用,请确认本地登记完成'
            );
        }

        // 占位符兼容校验:模板 ${xxx} 必须能被场景白名单覆盖,
        // 否则发送时阿里云 SendSms / SendSmsVerifyCode 会因 templateParam 错配报错
        $placeholders = SmsTemplate::extractPlaceholders((string) $template->template_content);
        $unsupported = array_values(array_diff($placeholders, SmsScene::availableParamNames()));
        if (!empty($unsupported)) {
            throw new BusinessException(
                '模板包含占位符 [' . implode(',', $unsupported) . '] 当前场景未提供;'
                . '请联系开发扩展 SmsScene::availableParamNames 或更换不含该占位符的模板'
            );
        }

        $row = $this->model()->where('scene_code', $sceneCode)->find();
        $payload = [
            'scene_code' => $sceneCode,
            'provider_id' => $providerId,
            'template_id' => $templateId,
            'sign_id' => $signId,
            'status' => (int) ($data['status'] ?? 1),
        ];
        if ($row === null) {
            $this->model()->save($payload);
        } else {
            $row->save($payload);
        }
    }

    public function unbind(string $sceneCode): void
    {
        $this->model()->where('scene_code', $sceneCode)->delete();
    }
}
