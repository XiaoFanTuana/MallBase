<?php

declare (strict_types=1);

namespace app\admin\controller;

use app\admin\service\UploadService;
use mall_base\base\BaseController;

/**
 * 上传控制器
 */
class UploadController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = UploadService::class;

    /**
     * 获取上传配置（前端 Upload 组件使用）
     * GET /upload/config?type=image
     */
    public function config()
    {
        $type = $this->request->param('type', 'image');

        $config = $this->service()->getUploadConfig($type);
        return $this->success($config, '获取成功');
    }

    /**
     * 单文件上传（图片/文件通用）
     * 通过 type 参数区分验证规则，支持覆盖 max_size/max_count/accept_types
     * POST /upload/single?type=image&max_size=5&accept_types[]=image/jpeg
     * POST /upload/single?type=file&max_size=20&accept_types[]=application/pdf
     */
    public function single()
    {
        $file = $this->request->file('file');

        if (!$file) {
            return $this->error('请选择要上传的文件');
        }

        $rules  = $this->service()->resolveUploadRules(
            $this->request->param('type', 'image'),
            $this->request->param(['max_size', 'max_count', 'accept_types']),
        );
        $result = $this->service()->upload($file, $rules);

        return $this->success($result, '上传成功');
    }

    /**
     * 批量文件上传（图片/文件通用）
     * 通过 type 参数区分验证规则，支持覆盖 max_size/max_count/accept_types
     * POST /upload/batch?type=images&max_count=6&max_size=3
     */
    public function batch()
    {
        $files = $this->request->file('files');

        if (!$files) {
            return $this->error('请选择要上传的文件');
        }

        $rules   = $this->service()->resolveUploadRules(
            $this->request->param('type', 'images'),
            $this->request->param(['max_size', 'max_count', 'accept_types']),
        );
        $results = $this->service()->batchUpload($files, $rules);

        return $this->success($results, '上传成功');
    }
}