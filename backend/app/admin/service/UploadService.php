<?php

declare (strict_types=1);

namespace app\admin\service;

use mall_base\base\BaseService;
use mall_base\drivers\DriverManager;

/**
 * 上传服务
 */
class UploadService extends BaseService
{
    // ==================== 上传配置（前端获取） ====================

    /**
     * 获取上传配置（前端 Upload 组件使用）
     * 根据 type 参数返回对应的验证规则
     *
     * @param string $type 上传类型：image/images/file/files
     * @return array{max_size: float, max_count: int, accept_types: string[]}
     */
    public function getUploadConfig(string $type): array
    {
        $config = config('upload');
        $rules  = $config['rules'] ?? [];

        // 默认回退到 image
        if (!isset($rules[$type])) {
            $type = 'image';
        }

        return $this->normalizeRule($rules[$type] ?? []);
    }

    /**
     * 解析上传验证规则
     * 优先使用传入参数，没有则从配置文件按 type 获取默认规则
     * 后续可扩展为从后台设置获取
     *
     * @param string $type         上传类型（image/file/images/files）
     * @param array  $overrideRules 由 Controller 传入的请求参数，支持覆盖 max_size/max_count/accept_types
     * @return array{max_size: float, max_count: int, accept_types: string[]}
     */
    public function resolveUploadRules(string $type, array $overrideRules = []): array
    {
        $config = $this->getUploadConfig($type);

        return [
            'max_size'     => isset($overrideRules['max_size']) ? floatval($overrideRules['max_size']) : $config['max_size'],
            'max_count'    => isset($overrideRules['max_count']) ? intval($overrideRules['max_count']) : $config['max_count'],
            'accept_types' => isset($overrideRules['accept_types']) ? (array)$overrideRules['accept_types'] : $config['accept_types'],
        ];
    }

    // ==================== 上传功能 ====================

    /**
     * 上传文件（图片和文件统一入口）
     *
     * @param mixed $file  上传的文件对象
     * @param array $rules 验证规则 max_size(MB)/max_count/accept_types
     * @return array 返回文件路径信息
     */
    public function upload($file, array $rules = []): array
    {
        if (!$file) {
            throw new \Exception('文件不存在');
        }

        // 没有传入规则则使用默认图片规则
        if (empty($rules)) {
            $rules = $this->getUploadConfig('image');
        }

        $this->validateUploadFile($file, $rules);

        // 获取上传驱动
        $uploadDriver = $this->getUploadDriver();

        // 生成文件名和路径
        $extension  = strtolower(pathinfo($file->getOriginalName(), PATHINFO_EXTENSION));
        $fileName   = $this->generateFileName($extension);
        $subDir     = $this->getSubDirByAcceptTypes($rules['accept_types']);
        $objectName = $subDir . '/' . $this->generateDatePath() . '/' . $fileName;

        $tempPath = $file->getPathname();

        try {
            $uploadDriver->upload($tempPath, $objectName);
            return $uploadDriver->getFileInfo($objectName);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * 批量上传文件
     *
     * @param array $files 文件对象数组
     * @param array $rules 验证规则
     * @return array{results: array, errors: array}
     */
    public function batchUpload(array $files, array $rules = []): array
    {
        if (empty($rules)) {
            $rules = $this->getUploadConfig('images');
        }

        $maxCount = $rules['max_count'] ?? 9;
        if (count($files) > $maxCount) {
            throw new \Exception("最多上传{$maxCount}个文件");
        }

        $results = [];
        $errors  = [];

        foreach ($files as $key => $file) {
            try {
                $results[] = $this->upload($file, $rules);
            } catch (\Exception $e) {
                $errors[] = "文件 {$key}: " . $e->getMessage();
            }
        }

        if (empty($results)) {
            throw new \Exception(implode('; ', $errors));
        }

        return ['results' => $results, 'errors' => $errors];
    }

    // ==================== 验证 ====================

    /**
     * 验证上传文件
     *
     * @param mixed $file  上传的文件对象
     * @param array $rules 验证规则 [max_size(MB), accept_types(MIME数组)]
     */
    private function validateUploadFile($file, array $rules): void
    {
        // 检查文件大小（max_size 单位 MB）
        $maxSizeBytes = $rules['max_size'] * 1024 * 1024;
        if ($file->getSize() > $maxSizeBytes) {
            throw new \Exception("文件大小不能超过{$rules['max_size']}MB");
        }

        // 检查文件 MIME 类型
        $acceptTypes = $rules['accept_types'] ?? [];
        if (!empty($acceptTypes)) {
            $mimeType = $file->getMime();
            if (!in_array($mimeType, $acceptTypes, true)) {
                throw new \Exception('文件类型不允许，允许的类型: ' . implode(', ', $acceptTypes));
            }
        }
    }

    // ==================== 私有工具方法 ====================

    /**
     * 标准化规则配置
     */
    private function normalizeRule(array $rule): array
    {
        return [
            'max_size'     => floatval($rule['max_size'] ?? 2),
            'max_count'    => intval($rule['max_count'] ?? 1),
            'accept_types' => $rule['accept_types'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        ];
    }

    /**
     * 根据 accept_types 判断存储子目录
     *
     * @param string[] $acceptTypes
     * @return string
     */
    private function getSubDirByAcceptTypes(array $acceptTypes): string
    {
        $firstType = $acceptTypes[0] ?? '';
        if (str_starts_with($firstType, 'image/')) {
            return 'images';
        }
        return 'files';
    }

    /**
     * 获取上传驱动
     */
    private function getUploadDriver()
    {
        $driverName   = config('upload.driver', 'local');
        $driverConfig = config("upload.{$driverName}", []);

        return DriverManager::driver('upload', $driverName, $driverConfig);
    }

    /**
     * 生成随机文件名
     */
    private function generateFileName(string $extension = ''): string
    {
        $name = md5(uniqid((string)mt_rand(), true));

        if ($extension) {
            $name .= '.' . $extension;
        }

        return $name;
    }

    /**
     * 生成按日期分组的文件路径
     */
    private function generateDatePath(): string
    {
        return date('Y/m/d');
    }
}