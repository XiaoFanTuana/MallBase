<?php

declare(strict_types=1);

namespace app\controller\client\search;

use app\service\client\search\SearchService;
use mall_base\base\BaseController;

/**
 * 客户端搜索控制器
 *
 * @extends BaseController<SearchService>
 */
class SearchController extends BaseController
{
    protected string $serviceClass = SearchService::class;

    /**
     * 热搜榜单
     */
    public function hot()
    {
        $limit = (int) $this->request->param('limit', 10);

        return $this->success(['list' => $this->service()->hot($limit)], '获取成功');
    }

    /**
     * 记录搜索行为
     */
    public function log()
    {
        $keyword = (string) $this->request->param('keyword', '');
        $platform = (string) $this->request->param('platform', '');
        $userId = isset($this->request->user_id) ? (int) $this->request->user_id : null;

        $result = $this->service()->record($keyword, $platform, $userId, $this->request->ip());

        return $this->success($result, '记录成功');
    }
}
