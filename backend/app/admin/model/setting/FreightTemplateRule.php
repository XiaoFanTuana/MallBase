<?php
declare(strict_types=1);

namespace app\admin\model\setting;

use mall_base\base\BaseModel;

/**
 * 运费模板规则模型
 */
class FreightTemplateRule extends BaseModel
{
    protected $name = 'freight_template_rule';
    protected $json = ['region_ids', 'region_codes', 'region_names', 'region_path_texts'];
    protected $jsonAssoc = true;
}
