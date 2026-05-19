<?php

declare(strict_types=1);

namespace app\job;

use app\model\sms\SmsProvider;
use app\model\sms\SmsSign;
use app\model\sms\SmsTemplate;
use app\service\admin\sms\SmsDriverFactory;
use mall_base\base\BaseJob;
use think\queue\Job as QueueJob;
use Throwable;

/**
 * 短信模板远端同步任务
 *
 * 职责:
 *  - 首次提交:本地 template_code 为空时,调用阿里云 AddSmsTemplate 写回 code 并置 pending
 *  - 状态拉取:本地 template_code 非空时,调用 QuerySmsTemplate 同步审核状态/原因
 *
 * 失败语义:
 *  - 提交失败 → 回退为 local_only,审核备注记录原始错误,用户可在列表手动重试
 *  - 状态查询失败 → 仅写 audit_reason,不动 audit_status
 *
 * 设计要点:
 *  - 不向 think-queue 抛出异常:同步连接(sync)下抛出会让 HTTP 请求一起失败,
 *    本地行已落库,直接吞掉异常并把错误写到 audit_reason 体感更稳。用户可点"同步"再派一次。
 *  - PNVS 等无远端管理 API 的驱动直接短路返回。
 */
class SmsTemplateSyncJob extends BaseJob
{
    private int $templateId = 0;

    /**
     * @param array<string, mixed> $data think-queue 通过 app->make 将 push 的 payload 注入到第一个构造参数
     */
    public function __construct(array $data = [])
    {
        parent::__construct();
        $this->templateId = (int) ($data['templateId'] ?? 0);
    }

    /**
     * think-queue 入口
     *
     * @param array<string, mixed> $data
     */
    public function fire(QueueJob $job, array $data): void
    {
        if ($this->templateId <= 0 && isset($data['templateId'])) {
            $this->templateId = (int) $data['templateId'];
        }

        try {
            $this->handle();
        } catch (Throwable $e) {
            $this->logger()->jobError($e);
        } finally {
            $job->delete();
        }
    }

    public function handle(): void
    {
        if ($this->templateId <= 0) {
            return;
        }

        $row = SmsTemplate::find($this->templateId);
        if ($row === null) {
            return;
        }

        $provider = SmsProvider::find($row->provider_id);
        if ($provider === null || !SmsDriverFactory::supportsRemoteSignManagement($provider)) {
            return;
        }

        $manager = SmsDriverFactory::manager($provider);
        $now = date('Y-m-d H:i:s');

        try {
            if ((string) $row->template_code === '') {
                $remark = (string) $row->remark;
                if ($remark === '') {
                    $remark = (string) $row->template_name;
                }
                // 阿里云新接口 CreateSmsTemplate 必填 RelatedSignName,取模板创建时所选签名;
                // sign_id 缺失(旧数据 / 导入模板)时回退到服务商下任一签名
                $signName = (string) SmsSign::where('id', (int) $row->sign_id)->value('sign_name');
                if ($signName === '') {
                    $signName = SmsSign::resolveRelatedName((int) $row->provider_id);
                }
                $remote = $manager->addTemplate([
                    'template_name' => (string) $row->template_name,
                    'template_content' => (string) $row->template_content,
                    'template_type' => (int) $row->template_type,
                    'remark' => $remark,
                    'related_sign_name' => $signName,
                ]);
                $row->template_code = $remote['template_code'];
                $row->audit_status = SmsTemplate::AUDIT_PENDING;
                $row->audit_reason = null;
            } else {
                $remote = $manager->queryTemplate((string) $row->template_code);
                $row->audit_status = $remote['audit_status'];
                $row->audit_reason = $remote['audit_reason'] ?? null;
            }
            $row->last_synced_at = $now;
            $row->save();
        } catch (Throwable $e) {
            if ((string) $row->template_code === '') {
                $row->audit_status = SmsTemplate::AUDIT_LOCAL_ONLY;
            }
            $row->audit_reason = $e->getMessage();
            $row->last_synced_at = $now;
            $row->save();
        }
    }
}
