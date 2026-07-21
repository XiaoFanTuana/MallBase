<?php

namespace app\controller;

use mall_base\base\BaseController;

/**
 * 默认控制器
 * @extends BaseController<\mall_base\base\BaseService>
 */
class Index extends BaseController
{
    public function index()
    {
        return redirect('/client/');
    }
}
