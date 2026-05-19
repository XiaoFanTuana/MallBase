<?php

declare(strict_types=1);

namespace app\service\admin\sms;

use app\model\sms\SmsProvider;
use app\model\sms\SmsTemplate;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use Throwable;

/**
 * 短信模板 Service
 *
 * @extends BaseService<SmsTemplate>
 */
class SmsTemplateService extends BaseService
{
    protected string $modelClass = SmsTemplate::class;

    public function getList(array $where, int $page, int $limit): array
    {
        $query = $this->model()
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('template_name|template_code', "%{$where['keyword']}%");
            })
            ->when(!empty($where['provider_id']), function ($q) use ($where) {
                $q->where('provider_id', (int) $where['provider_id']);
            })
            ->when(!empty($where['audit_status']), function ($q) use ($where) {
                $q->where('audit_status', $where['audit_status']);
            });

        $total = $query->count();
        $list = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return compact('total', 'list');
    }

    public function getInfo(int $id): array
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }
        return $row->toArray();
    }

    /**
     * 创建本地记录 + 调用阿里云 AddSmsTemplate
     *
     * 流程分支:
     *  - 支持远端管理的驱动(普通阿里云短信):调远端 AddSmsTemplate,远端返回 template_code 写回
     *  - 不支持远端管理的驱动(PNVS,模板由阿里云预置):
     *      template_code 必填(用户在控制台「赠送模板配置」查到),
     *      同一服务商下不可重复,直接入库为 local_only
     */
    public function create(array $data): int
    {
        $provider = SmsProvider::find($data['provider_id']);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        $payload = [
            'provider_id' => (int) $data['provider_id'],
            'template_name' => trim($data['template_name']),
            'template_type' => (int) ($data['template_type'] ?? 0),
            'template_content' => (string) ($data['template_content'] ?? ''),
            'remark' => $data['remark'] ?? null,
            'template_code' => '',
            'audit_status' => SmsTemplate::AUDIT_LOCAL_ONLY,
            'audit_reason' => null,
        ];

        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            // PNVS 等无远端模板管理 API 的驱动:
            //  - template_code 必填且同服务商下唯一
            //  - template_content 必填(用于场景绑定时校验占位符 ⊆ 场景白名单)
            $templateCode = trim((string) ($data['template_code'] ?? ''));
            if ($templateCode === '') {
                throw new BusinessException('PNVS 模板编码必填,请在号码认证控制台查看赠送模板编码后输入');
            }
            if ($payload['template_content'] === '') {
                throw new BusinessException('PNVS 模板内容必填,请抄录阿里云控制台显示的模板原文,系统据此校验场景参数是否匹配');
            }
            $exists = $this->model()
                ->where('provider_id', $payload['provider_id'])
                ->where('template_code', $templateCode)
                ->find();
            if ($exists !== null) {
                throw new BusinessException("模板编码 [{$templateCode}] 已存在于当前服务商,请勿重复录入");
            }
            $payload['template_code'] = $templateCode;
            $payload['audit_reason'] = 'PNVS 系统赠送模板,无需远端审核';
        } else {
            if ($payload['template_content'] === '') {
                throw new BusinessException('模板内容必填');
            }
            try {
                $manager = SmsDriverFactory::manager($provider);
                $remote = $manager->addTemplate([
                    'template_name' => $payload['template_name'],
                    'template_content' => $payload['template_content'],
                    'template_type' => $payload['template_type'],
                    'remark' => $payload['remark'] ?? '',
                ]);
                $payload['template_code'] = $remote['template_code'];
                $payload['audit_status'] = SmsTemplate::AUDIT_PENDING;
                $payload['last_synced_at'] = date('Y-m-d H:i:s');
            } catch (Throwable $e) {
                $payload['audit_reason'] = $e->getMessage();
            }
        }

        $row = $this->model();
        $row->save($payload);
        return (int) $row->id;
    }

    public function update(int $id, array $data): void
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }
        $provider = SmsProvider::find($row->provider_id);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }

        $newData = [
            'template_name' => trim($data['template_name']),
            'template_type' => (int) ($data['template_type'] ?? $row->template_type),
            'template_content' => (string) ($data['template_content'] ?? $row->template_content),
            'remark' => $data['remark'] ?? null,
        ];

        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            // PNVS:模板由阿里云预置,本地只维护引用信息;
            // template_code 可更新但需同服务商下唯一;
            // template_content 必填(发送时按其占位符构造 templateParam)
            $newCode = isset($data['template_code']) ? trim((string) $data['template_code']) : (string) $row->template_code;
            if ($newCode === '') {
                throw new BusinessException('PNVS 模板编码必填');
            }
            if ($newData['template_content'] === '') {
                throw new BusinessException('PNVS 模板内容必填,请抄录阿里云控制台显示的模板原文');
            }
            if ($newCode !== (string) $row->template_code) {
                $dup = $this->model()
                    ->where('provider_id', $row->provider_id)
                    ->where('template_code', $newCode)
                    ->where('id', '<>', $row->id)
                    ->find();
                if ($dup !== null) {
                    throw new BusinessException("模板编码 [{$newCode}] 已存在于当前服务商,请勿重复录入");
                }
            }
            $newData['template_code'] = $newCode;
            $newData['audit_status'] = SmsTemplate::AUDIT_LOCAL_ONLY;
            $newData['audit_reason'] = 'PNVS 系统赠送模板,无需远端审核';
            $row->save($newData);
            return;
        }

        // 已提交远端的模板才调修改接口;local_only 状态走"重新创建"路径
        if ($row->template_code !== '') {
            try {
                $manager = SmsDriverFactory::manager($provider);
                $manager->modifyTemplate((string) $row->template_code, $newData);
                $newData['audit_status'] = SmsTemplate::AUDIT_PENDING;
                $newData['audit_reason'] = null;
                $newData['last_synced_at'] = date('Y-m-d H:i:s');
            } catch (Throwable $e) {
                $newData['audit_reason'] = $e->getMessage();
            }
        } else {
            try {
                $manager = SmsDriverFactory::manager($provider);
                $remote = $manager->addTemplate($newData);
                $newData['template_code'] = $remote['template_code'];
                $newData['audit_status'] = SmsTemplate::AUDIT_PENDING;
                $newData['audit_reason'] = null;
                $newData['last_synced_at'] = date('Y-m-d H:i:s');
            } catch (Throwable $e) {
                $newData['audit_reason'] = $e->getMessage();
            }
        }

        $row->save($newData);
    }

    public function delete(int $id): void
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }

        if ($row->template_code !== '') {
            $provider = SmsProvider::find($row->provider_id);
            if ($provider !== null && SmsDriverFactory::supportsRemoteSignManagement($provider)) {
                try {
                    $manager = SmsDriverFactory::manager($provider);
                    $manager->deleteTemplate((string) $row->template_code);
                } catch (Throwable) {
                    // 远端删除失败不阻塞本地清理
                }
            }
        }

        $row->delete();
    }

    /**
     * 从阿里云导入已经存在并审核通过的模板(只查询,不调 AddSmsTemplate)
     *
     * 适用场景:
     *  - 你在阿里云控制台已经申请并审核通过的模板,想接入 mallbase
     *  - 旧线上数据迁移
     *
     * PNVS 服务商不支持导入:其模板由平台预置无 QuerySmsTemplate API,请走 create() 本地登记
     */
    public function importFromRemote(int $providerId, string $templateCode): int
    {
        $provider = SmsProvider::find($providerId);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }
        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            throw new BusinessException('PNVS 服务商不支持导入,请使用「新增模板」在本地登记');
        }

        $exists = $this->model()
            ->where('provider_id', $providerId)
            ->where('template_code', $templateCode)
            ->find();
        if ($exists !== null) {
            throw new BusinessException("模板编码 [{$templateCode}] 已存在本地,请直接点击同步状态");
        }

        $manager = SmsDriverFactory::manager($provider);
        $remote = $manager->queryTemplate($templateCode);

        $row = $this->model();
        $row->save([
            'provider_id' => $providerId,
            'template_name' => $remote['template_name'] ?: $templateCode,
            'template_code' => $templateCode,
            'template_type' => $remote['template_type'],
            'template_content' => $remote['template_content'] ?: '(远端未返回内容)',
            'remark' => '从阿里云导入',
            'audit_status' => $remote['audit_status'],
            'audit_reason' => $remote['audit_reason'] ?? null,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $row->id;
    }

    public function syncStatus(int $id): array
    {
        $row = $this->model()->find($id);
        if ($row === null) {
            throw new BusinessException('模板不存在');
        }
        $provider = SmsProvider::find($row->provider_id);
        if ($provider === null) {
            throw new BusinessException('服务商不存在');
        }
        if (!SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            throw new BusinessException('PNVS 模板为系统赠送,无需同步');
        }
        if ($row->template_code === '') {
            throw new BusinessException('模板未提交远端,无法查询状态');
        }

        try {
            $manager = SmsDriverFactory::manager($provider);
            $remote = $manager->queryTemplate((string) $row->template_code);
            $row->audit_status = $remote['audit_status'];
            $row->audit_reason = $remote['audit_reason'];
            $row->last_synced_at = date('Y-m-d H:i:s');
            $row->save();
            return $row->toArray();
        } catch (Throwable $e) {
            throw new BusinessException('同步失败: ' . $e->getMessage());
        }
    }

    public function syncAll(int $providerId): array
    {
        $rows = $this->model()->where('provider_id', $providerId)->where('template_code', '<>', '')->select();
        $success = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $this->syncStatus((int) $row->id);
                $success++;
            } catch (Throwable) {
                $failed++;
            }
        }
        return ['success' => $success, 'failed' => $failed];
    }

    /**
     * 按 id 列表批量同步模板状态
     *
     * 与 syncAll 的区别:
     *  - syncAll 按 provider 整体扫,跳过 template_code 为空的记录
     *  - syncBatch 严格按入参 id 数组执行,对非法 id / PNVS 行(syncStatus 会主动抛"无需同步") 计入 failed
     *
     * @param array<int> $ids
     * @return array{success:int, failed:int}
     */
    public function syncBatch(array $ids): array
    {
        $success = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                $failed++;
                continue;
            }
            try {
                $this->syncStatus($id);
                $success++;
            } catch (Throwable) {
                $failed++;
            }
        }
        return ['success' => $success, 'failed' => $failed];
    }
}
