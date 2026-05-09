<?php

declare(strict_types=1);

namespace app\model\search;

use mall_base\base\BaseModel;

/**
 * 客户端搜索日志模型
 */
class SearchLog extends BaseModel
{
    protected $name = 'search_log';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
}
