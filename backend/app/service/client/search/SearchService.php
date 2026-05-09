<?php

declare(strict_types=1);

namespace app\service\client\search;

use app\model\search\SearchLog;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 客户端搜索服务
 *
 * @extends BaseService<SearchLog>
 */
class SearchService extends BaseService
{
    protected string $modelClass = SearchLog::class;

    private const DEFAULT_PLATFORM = 'h5';
    private const PLATFORMS = ['mp-weixin', 'h5-wechat', 'h5', 'app'];

    /**
     * 记录搜索关键词。
     *
     * @return array{keyword:string}
     */
    public function record(string $keyword, string $platform, ?int $userId, string $ip): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            throw new BusinessException('搜索关键词不能为空');
        }

        $keyword = mb_substr($keyword, 0, 50);
        $normalizedKeyword = $this->normalizeKeyword($keyword);
        $platform = $this->normalizePlatform($platform);
        $ipHash = $this->hashIp($ip);
        $now = date('Y-m-d H:i:s');

        $query = $this->model()
            ->where('normalized_keyword', $normalizedKeyword)
            ->where('platform', $platform);

        if ($userId !== null && $userId > 0) {
            $query->where('user_id', $userId);
        } else {
            $query->whereNull('user_id')->where('ip_hash', $ipHash);
        }

        $exists = $query->find();
        if ($exists !== null) {
            $exists->search_count = (int) $exists->search_count + 1;
            $exists->keyword = $keyword;
            $exists->last_search_time = $now;
            $exists->save();
        } else {
            $this->model()->save([
                'keyword' => $keyword,
                'normalized_keyword' => $normalizedKeyword,
                'user_id' => $userId !== null && $userId > 0 ? $userId : null,
                'platform' => $platform,
                'ip_hash' => $ipHash,
                'search_count' => 1,
                'last_search_time' => $now,
            ]);
        }

        return ['keyword' => $keyword];
    }

    /**
     * 获取近 7 天热搜榜。
     *
     * @return array<int, array{keyword:string, search_count:int}>
     */
    public function hot(int $limit = 10): array
    {
        $limit = max(1, min($limit, 30));
        $since = date('Y-m-d H:i:s', time() - 7 * 86400);

        $rows = $this->model()
            ->field('MAX(`keyword`) AS keyword, SUM(`search_count`) AS search_count, MAX(`last_search_time`) AS last_search_time')
            ->where('last_search_time', '>=', $since)
            ->group('normalized_keyword')
            ->orderRaw('SUM(`search_count`) DESC, MAX(`last_search_time`) DESC')
            ->limit($limit)
            ->select()
            ->toArray();

        return array_map(static function (array $row): array {
            return [
                'keyword' => (string) ($row['keyword'] ?? ''),
                'search_count' => (int) ($row['search_count'] ?? 0),
            ];
        }, $rows);
    }

    private function normalizeKeyword(string $keyword): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $keyword) ?? $keyword);
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = trim($platform);
        return in_array($platform, self::PLATFORMS, true) ? $platform : self::DEFAULT_PLATFORM;
    }

    private function hashIp(string $ip): string
    {
        $secret = (string) env('JWT_SECRET', 'mallbase-search');
        return hash_hmac('sha256', $ip, $secret);
    }
}
