<?php

declare (strict_types=1);

namespace app\admin\model;

use app\admin\model\auth\Admin;
use think\Model;
use think\model\concern\SoftDelete;

/**
 * 管理员操作日志模型
 */
class AdminOperationLog extends Model
{
    // use SoftDelete; // 如果需要软删除，取消注释

    protected $name = 'admin_operation_log';

    protected $pk = 'id';

    // 自动写入时间戳
    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false; // 不需要更新时间

    /**
     * 类型转换
     */
    protected $type = [
        'admin_id' => 'integer',
        'status' => 'integer',
        'duration' => 'float',
    ];

    /**
     * JSON 字段
     */
    protected $json = ['params', 'response'];

    /**
     * 关联管理员
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }

    /**
     * 搜索器：按管理员ID搜索
     */
    public function searchAdminIdAttr($query, $value)
    {
        if ($value) {
            $query->where('admin_id', $value);
        }
    }

    /**
     * 搜索器：按用户名搜索
     */
    public function searchUsernameAttr($query, $value)
    {
        if ($value) {
            $query->whereLike('username', "%{$value}%");
        }
    }

    /**
     * 搜索器：按路径搜索
     */
    public function searchPathAttr($query, $value)
    {
        if ($value) {
            $query->whereLike('path', "%{$value}%");
        }
    }

    /**
     * 搜索器：按IP搜索
     */
    public function searchIpAttr($query, $value)
    {
        if ($value) {
            $query->whereLike('ip', "%{$value}%");
        }
    }

    /**
     * 搜索器：按状态搜索
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== null && $value !== '') {
            $query->where('status', $value);
        }
    }

    /**
     * 搜索器：按时间范围搜索
     */
    public function searchTimeRangeAttr($query, $value)
    {
        if (!empty($value)) {
            $times = explode(' - ', $value);
            if (count($times) == 2) {
                $query->whereBetweenTime('created_at', $times[0], $times[1]);
            }
        }
    }
}