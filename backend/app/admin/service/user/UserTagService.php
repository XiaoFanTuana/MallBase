<?php
declare(strict_types=1);

namespace app\admin\service\user;

use app\admin\model\user\UserTag;
use app\admin\model\user\UserTagRelation;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use support\Log;

/**
 * 用户标签服务
 */
class UserTagService extends BaseService
{
    /**
     * 默认 Model 类名
     */
    protected string $modelClass = UserTag::class;

    /**
     * 获取标签列表
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->model()
            ->withSearch(['name', 'status'], $where)
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select();

        $total = $this->model()
            ->withSearch(['name', 'status'], $where)
            ->count();

        return [
            'list' => $list->toArray(),
            'total' => $total,
        ];
    }

    /**
     * 获取标签详情
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);

        if (!$info) {
            throw new BusinessException('标签不存在');
        }

        return $info->toArray();
    }

    /**
     * 创建标签
     */
    public function create(array $data): int
    {
        $tag = $this->model()->save($data);

        Log::info('创建用户标签', [
            'tag_id' => $tag->id,
            'name' => $data['name'],
        ]);

        return $tag->id;
    }

    /**
     * 更新标签
     */
    public function update(int $id, array $data): bool
    {
        $tag = $this->model()->find($id);

        if (!$tag) {
            throw new BusinessException('标签不存在');
        }

        $tag->save($data);

        Log::info('更新用户标签', [
            'tag_id' => $id,
            'data' => $data,
        ]);

        return true;
    }

    /**
     * 删除标签
     */
    public function delete(int $id): bool
    {
        $tag = $this->model()->find($id);

        if (!$tag) {
            throw new BusinessException('标签不存在');
        }

        // 检查是否有用户关联
        $userCount = $this->getUserCount($id);

        if ($userCount > 0) {
            throw new BusinessException('该标签下还有用户，无法删除');
        }

        $tag->delete();

        Log::info('删除用户标签', [
            'tag_id' => $id,
        ]);

        return true;
    }

    /**
     * 获取标签下的用户数
     */
    public function getUserCount(int $tagId): int
    {
        return UserTagRelation::where('tag_id', $tagId)->count();
    }

    /**
     * 批量给用户打标签
     */
    public function batchSetUsers(int $tagId, array $userIds): bool
    {
        $tag = $this->model()->find($tagId);

        if (!$tag) {
            throw new BusinessException('标签不存在');
        }

        $count = 0;
        foreach ($userIds as $userId) {
            $relation = UserTagRelation::where('user_id', $userId)
                ->where('tag_id', $tagId)
                ->find();

            if (!$relation) {
                UserTagRelation::create([
                    'user_id' => $userId,
                    'tag_id' => $tagId,
                ]);
                $count++;
            }
        }

        Log::info('批量给用户打标签', [
            'tag_id' => $tagId,
            'user_count' => $count,
        ]);

        return true;
    }

    /**
     * 移除用户标签
     */
    public function removeUser(int $tagId, int $userId): bool
    {
        $relation = UserTagRelation::where('user_id', $userId)
            ->where('tag_id', $tagId)
            ->find();

        if (!$relation) {
            throw new BusinessException('用户没有该标签');
        }

        $relation->delete();

        Log::info('移除用户标签', [
            'tag_id' => $tagId,
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * 获取用户的所有标签
     */
    public function getUserTags(int $userId): array
    {
        $relations = UserTagRelation::where('user_id', $userId)
            ->select();

        $tagIds = array_column($relations->toArray(), 'tag_id');

        if (empty($tagIds)) {
            return [];
        }

        $tags = $this->model()
            ->where('status', 1)
            ->whereIn('id', $tagIds)
            ->select();

        return $tags->toArray();
    }

    /**
     * 更新标签状态
     */
    public function updateStatus(int $id, int $status): bool
    {
        $tag = $this->model()->find($id);

        if (!$tag) {
            throw new BusinessException('标签不存在');
        }

        $tag->save(['status' => $status]);

        Log::info('更新标签状态', [
            'tag_id' => $id,
            'status' => $status,
        ]);

        return true;
    }
}
